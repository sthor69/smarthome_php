<?php
header('Content-Type: application/json');

// Path to the SQLite database
$dbPath = '/var/www/html/sensor_data.db';

// Check if database exists
if (!file_exists($dbPath)) {
    echo json_encode(['error' => 'Database not found']);
    exit;
}

try {
    $db = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get the last 100 measurements
    $stmt = $db->query("SELECT timestamp, temperature, humidity FROM measurements ORDER BY timestamp DESC LIMIT 100");
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Return results in chronological order for the chart
    echo json_encode(array_reverse($results));

} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
