<?php
require_once 'logger.php';
session_start();
if (!isset($_SESSION['username'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo "Errore: Non autorizzato";
    exit;
}

date_default_timezone_set('Europe/Rome');
$dbPath = '/var/www/smarthome/sensor_data.db';

if (!file_exists($dbPath)) {
    write_log('ERROR', "Database not found at $dbPath");
    header('HTTP/1.1 500 Internal Server Error');
    echo "Errore: Database non trovato";
    exit;
}

try {
    $db = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $hours = isset($_GET['hours']) ? intval($_GET['hours']) : 0;
    $start = $_GET['start'] ?? null;
    $end   = $_GET['end']   ?? null;

    if ($start && $end) {
        write_log('INFO', "Exporting data between $start and $end for user '{$_SESSION['username']}'");
        $stmt = $db->prepare("
            SELECT timestamp, temperature, humidity
            FROM measurements
            WHERE timestamp BETWEEN :start AND :end
            ORDER BY timestamp ASC
        ");
        $stmt->execute([':start' => $start, ':end' => $end]);
    } elseif ($hours > 0) {
        write_log('INFO', "Exporting data for last $hours hours for user '{$_SESSION['username']}'");
        $threshold = gmdate('Y-m-d H:i:s', time() - ($hours * 3600));
        $stmt = $db->prepare("
            SELECT timestamp, temperature, humidity
            FROM measurements
            WHERE timestamp >= :threshold
            ORDER BY timestamp ASC
        ");
        $stmt->execute([':threshold' => $threshold]);
    } else {
        write_log('INFO', "Exporting all data for user '{$_SESSION['username']}'");
        $stmt = $db->query("
            SELECT timestamp, temperature, humidity
            FROM measurements
            ORDER BY timestamp ASC
        ");
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=sensor_data.csv');

    $output = fopen('php://output', 'w');

    // Add BOM for Excel UTF-8 compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // Header row
    fputcsv($output, ['Data e Ora (Italian Time)', 'Temperatura (°C)', 'Umidità (%)'], ';');

    $utcTz = new DateTimeZone('UTC');
    $romeTz = new DateTimeZone('Europe/Rome');

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $dt = new DateTime($row['timestamp'], $utcTz);
        $dt->setTimezone($romeTz);
        fputcsv($output, [$dt->format('Y-m-d H:i:s'), $row['temperature'], $row['humidity']], ';');
    }

    fclose($output);

} catch (Throwable $e) {
    write_log('ERROR', "Data export error for user '{$_SESSION['username']}': " . $e->getMessage());
    http_response_code(500);
    echo "Errore: " . $e->getMessage();
}
?>
