<?php
require_once 'logger.php';
require_once 'db.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non autorizzato']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDb();

    if ($method === 'GET') {
        $stmt = $db->query("SELECT name, value FROM settings");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        echo json_encode(['success' => true, 'settings' => $settings]);
    } elseif ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['settings'])) {
            echo json_encode(['success' => false, 'error' => 'Dati non validi']);
            exit;
        }

        $db->beginTransaction();
        $stmt = $db->prepare("UPDATE settings SET value = ? WHERE name = ?");
        foreach ($data['settings'] as $name => $value) {
            $stmt->execute([$value, $name]);
        }
        $db->commit();
        write_log('INFO', "Settings updated by user '{$_SESSION['username']}'");
        echo json_encode(['success' => true]);
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Metodo non consentito']);
    }
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    write_log('ERROR', "Settings API error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
