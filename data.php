<?php
header('Content-Type: application/json');

$dbPath = '/var/www/html/sensor_data.db';


if (!file_exists($dbPath)) {
    echo json_encode(['error' => 'Database non trovato']);
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

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($results);

} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
