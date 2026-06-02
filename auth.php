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

        // Migration: add missing columns
        $columns = $db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
        $columnNames = array_column($columns, 'name');

        if (!in_array('email', $columnNames)) {
            $db->exec("ALTER TABLE users ADD COLUMN email TEXT");
        }
        if (!in_array('confirmation_token', $columnNames)) {
            $db->exec("ALTER TABLE users ADD COLUMN confirmation_token TEXT");
        }
        if (!in_array('is_confirmed', $columnNames)) {
            $db->exec("ALTER TABLE users ADD COLUMN is_confirmed INTEGER DEFAULT 0");
            // Set existing users to confirmed to avoid lockout
            $db->exec("UPDATE users SET is_confirmed = 1");
        }

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
        $email = trim($data['email'] ?? '');

        if (empty($user) || empty($pass) || empty($email)) {
            echo json_encode(['success' => false, 'error' => 'Username, password ed email richiesti']);
            break;
        }

        $db = getDb();
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $token = bin2hex(random_bytes(16));
        try {
            // Check if email already exists
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Email già registrata']);
                break;
            }

            $stmt = $db->prepare("INSERT INTO users (username, password_hash, email, confirmation_token) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user, $hash, $email, $token]);

            // Send confirmation email
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
            $confirmLink = "$protocol://$host" . dirname($_SERVER['PHP_SELF']) . "/auth.php?action=confirm&token=$token";
            $subject = "Conferma la tua registrazione";
            $message = "Ciao $user,\n\nGrazie per esserti registrato. Per favore conferma il tuo account cliccando sul link seguente:\n$confirmLink\n\nGrazie!";
            $headers = "From: noreply@$host";

            if (!mail($email, $subject, $message, $headers)) {
                // If mail fails, we might want to inform the user but the account is created
                // In some cases we might want to delete the user, but for now let's just add a note
                echo json_encode(['success' => true, 'note' => 'Account creato, ma c\'è stato un errore nell\'invio dell\'email. Contatta l\'amministratore.']);
            } else {
                echo json_encode(['success' => true]);
            }
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                echo json_encode(['success' => false, 'error' => 'Username già esistente']);
            } else {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        }
        break;

    case 'confirm':
        $token = $_GET['token'] ?? '';
        if (empty($token)) {
            echo "Token non valido";
            break;
        }

        $db = getDb();
        try {
            $stmt = $db->prepare("UPDATE users SET is_confirmed = 1, confirmation_token = NULL WHERE confirmation_token = ?");
            $stmt->execute([$token]);

            if ($stmt->rowCount() > 0) {
                header('Location: login.html?confirmed=1');
                exit;
            } else {
                echo "Token non valido o già utilizzato";
            }
        } catch (PDOException $e) {
            echo "Errore: " . $e->getMessage();
        }
        break;

    case 'login':
        $data = json_decode(file_get_contents('php://input'), true);
        $user = trim($data['username'] ?? '');
        $pass = $data['password'] ?? '';

        $db = getDb();
        try {
            $stmt = $db->prepare("SELECT password_hash, is_confirmed FROM users WHERE username = ?");
            $stmt->execute([$user]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && password_verify($pass, $row['password_hash'])) {
                if (!$row['is_confirmed']) {
                    echo json_encode(['success' => false, 'error' => 'Account non confermato. Controlla la tua email.']);
                    break;
                }
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
