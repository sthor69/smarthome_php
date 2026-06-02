<?php
session_start();
header('Content-Type: application/json');

$dbPath = '/var/www/smarthome/sensor_data.db';

function getDb() {
    global $dbPath;
    try {
        $db = new PDO("sqlite:$dbPath");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Ensure table exists
        $db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, password_hash TEXT)");
        return $db;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Errore database: ' . $e->getMessage()]);
        exit;
    }
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'register':
        $data = json_decode(file_get_contents('php://input'), true);
        $user = trim($data['username'] ?? '');
        $pass = $data['password'] ?? '';

        if (empty($user) || empty($pass)) {
            echo json_encode(['success' => false, 'error' => 'Username e password richiesti']);
            break;
        }

        $db = getDb();
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        try {
            $stmt = $db->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
            $stmt->execute([$user, $hash]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                echo json_encode(['success' => false, 'error' => 'Username già esistente']);
            } else {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        }
        break;

    case 'login':
        $data = json_decode(file_get_contents('php://input'), true);
        $user = trim($data['username'] ?? '');
        $pass = $data['password'] ?? '';

        $db = getDb();
        try {
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE username = ?");
            $stmt->execute([$user]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && password_verify($pass, $row['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['username'] = $user;
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Credenziali non valide']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'logout':
        session_destroy();
        echo json_encode(['success' => true]);
        break;

    case 'status':
        if (isset($_SESSION['username'])) {
            echo json_encode(['logged_in' => true, 'username' => $_SESSION['username']]);
        } else {
            echo json_encode(['logged_in' => false]);
        }
        break;

    default:
        echo json_encode(['error' => 'Azione non valida']);
}
