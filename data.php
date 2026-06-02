<?php
session_start();
if (!isset($_SESSION['username'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['error' => 'Non autorizzato']);
    exit;
}

header('Content-Type: application/json');
date_default_timezone_set('Europe/Rome');

$dbPath = '/var/www/html/sensor_data.db';


if (!file_exists($dbPath)) {
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

    if ($start && $end) {
        $stmt = $db->prepare("
            SELECT timestamp, temperature, humidity
            FROM measurements
            WHERE timestamp BETWEEN :start AND :end
            ORDER BY timestamp ASC
        ");
        $stmt->execute([':start' => $start, ':end' => $end]);
    } elseif ($hours > 0) {
        $threshold = gmdate('Y-m-d H:i:s', time() - ($hours * 3600));
        $stmt = $db->prepare("
            SELECT timestamp, temperature, humidity
            FROM measurements
            WHERE timestamp >= :threshold
            ORDER BY timestamp ASC
        ");
        $stmt->execute([':threshold' => $threshold]);
    } else {
        $stmt = $db->query("
            SELECT timestamp, temperature, humidity
            FROM measurements
            ORDER BY timestamp ASC
        ");
    }

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $utcTz = new DateTimeZone('UTC');
    $romeTz = new DateTimeZone('Europe/Rome');

    foreach ($results as &$row) {
        $dt = new DateTime($row['timestamp'], $utcTz);
        $dt->setTimezone($romeTz);
        $row['timestamp'] = $dt->format('Y-m-d H:i:s');
    }

    echo json_encode($results);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
