<?php
$dbPath = '/var/www/html/sensor_data.db';

if (!file_exists($dbPath)) {
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
        $stmt = $db->prepare("
            SELECT timestamp, temperature, humidity
            FROM measurements
            WHERE timestamp BETWEEN :start AND :end
            ORDER BY timestamp ASC
        ");
        $stmt->execute([':start' => $start, ':end' => $end]);
    } elseif ($hours > 0) {
        $stmt = $db->prepare("
            SELECT timestamp, temperature, humidity
            FROM measurements
            WHERE timestamp >= datetime('now', '-' || :hours || ' hours')
            ORDER BY timestamp ASC
        ");
        $stmt->execute([':hours' => $hours]);
    } else {
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
    fputcsv($output, ['Data e Ora', 'Temperatura (°C)', 'Umidità (%)'], ';');

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Convert UTC to local if needed? For now, we use the DB value as is, which is UTC.
        // Usually, Excel users expect some standard format.
        fputcsv($output, [$row['timestamp'], $row['temperature'], $row['humidity']], ';');
    }

    fclose($output);

} catch (PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo "Errore DB: " . $e->getMessage();
}
?>
