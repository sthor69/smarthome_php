<?php
require_once 'logger.php';
session_start();
if (!isset($_SESSION['username'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['error' => 'Non autorizzato']);
    exit;
}

header('Content-Type: application/json');
date_default_timezone_set('Europe/Rome');

$dbPath = '/var/www/smarthome/sensor_data.db';


if (!file_exists($dbPath)) {
    write_log('ERROR', "Database not found at $dbPath");
    echo json_encode(['error' => 'Database non trovato']);
    exit;
}

try {
    $db = new PDO("sqlite:$dbPath", null, null, [
                  PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY
                  ]);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $hours = isset($_GET['hours']) ? intval($_GET['hours']) : 0;
    $start = $_GET['start'] ?? null;
    $end   = $_GET['end']   ?? null;

    $count = 0;
    if ($start && $end) {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM measurements WHERE timestamp BETWEEN :start AND :end");
        $countStmt->execute([':start' => $start, ':end' => $end]);
        $count = $countStmt->fetchColumn();
    } elseif ($hours > 0) {
        $threshold = gmdate('Y-m-d H:i:s', time() - ($hours * 3600));
        $countStmt = $db->prepare("SELECT COUNT(*) FROM measurements WHERE timestamp >= :threshold");
        $countStmt->execute([':threshold' => $threshold]);
        $count = $countStmt->fetchColumn();
    } else {
        $count = $db->query("SELECT COUNT(*) FROM measurements")->fetchColumn();
    }

    $samplingRate = ($count > 1500) ? ceil($count / 1000) : 1;

    if ($start && $end) {
        write_log('INFO', "Fetching data between $start and $end for user '{$_SESSION['username']}' (sampling: $samplingRate)");
        if ($samplingRate > 1) {
            $stmt = $db->prepare("
                SELECT timestamp, temperature, humidity FROM (
                    SELECT timestamp, temperature, humidity,
                    ROW_NUMBER() OVER (ORDER BY timestamp ASC) as rn
                    FROM measurements
                    WHERE timestamp BETWEEN :start AND :end
                ) WHERE (rn - 1) % :rate = 0
            ");
            $stmt->execute([':start' => $start, ':end' => $end, ':rate' => $samplingRate]);
        } else {
            $stmt = $db->prepare("SELECT timestamp, temperature, humidity FROM measurements WHERE timestamp BETWEEN :start AND :end ORDER BY timestamp ASC");
            $stmt->execute([':start' => $start, ':end' => $end]);
        }
    } elseif ($hours > 0) {
        write_log('INFO', "Fetching data for last $hours hours for user '{$_SESSION['username']}' (sampling: $samplingRate)");
        if ($samplingRate > 1) {
            $stmt = $db->prepare("
                SELECT timestamp, temperature, humidity FROM (
                    SELECT timestamp, temperature, humidity,
                    ROW_NUMBER() OVER (ORDER BY timestamp ASC) as rn
                    FROM measurements
                    WHERE timestamp >= :threshold
                ) WHERE (rn - 1) % :rate = 0
            ");
            $stmt->execute([':threshold' => $threshold, ':rate' => $samplingRate]);
        } else {
            $stmt = $db->prepare("SELECT timestamp, temperature, humidity FROM measurements WHERE timestamp >= :threshold ORDER BY timestamp ASC");
            $stmt->execute([':threshold' => $threshold]);
        }
    } else {
        write_log('INFO', "Fetching all data for user '{$_SESSION['username']}' (sampling: $samplingRate)");
        if ($samplingRate > 1) {
            $stmt = $db->prepare("
                SELECT timestamp, temperature, humidity FROM (
                    SELECT timestamp, temperature, humidity,
                    ROW_NUMBER() OVER (ORDER BY timestamp ASC) as rn
                    FROM measurements
                ) WHERE (rn - 1) % :rate = 0
            ");
            $stmt->execute([':rate' => $samplingRate]);
        } else {
            $stmt = $db->query("SELECT timestamp, temperature, humidity FROM measurements ORDER BY timestamp ASC");
        }
    }

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $utcTz = new DateTimeZone('UTC');
    $romeTz = new DateTimeZone('Europe/Rome');

    foreach ($results as &$row) {
        $dt = new DateTime($row['timestamp'], $utcTz);
        $dt->setTimezone($romeTz);
        $row['timestamp'] = $dt->format('Y-m-d H:i:s');
    }

    echo json_encode([
        'data' => $results,
        'samplingRate' => $samplingRate
    ]);

} catch (Throwable $e) {
    write_log('ERROR', "Data fetch error for user '{$_SESSION['username']}': " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
