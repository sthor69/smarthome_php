<?php
require_once 'logger.php';
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
        write_log('ERROR', "Database error: " . $e->getMessage());
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
            write_log('WARNING', "Registration failed: missing fields for user '$user'");
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
                write_log('WARNING', "Registration failed: email '$email' already exists");
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
                write_log('ERROR', "Account created for '$user', but failed to send confirmation email to '$email'");
                echo json_encode(['success' => true, 'note' => 'Account creato, ma c\'è stato un errore nell\'invio dell\'email. Contatta l\'amministratore.']);
            } else {
                write_log('INFO', "User registered successfully: '$user' ($email)");
                echo json_encode(['success' => true]);
            }
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                write_log('WARNING', "Registration failed: username '$user' already exists");
                echo json_encode(['success' => false, 'error' => 'Username già esistente']);
            } else {
                write_log('ERROR', "Registration error for '$user': " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        }
        break;

    case 'confirm':
        $token = $_GET['token'] ?? '';
        if (empty($token)) {
            write_log('WARNING', "Account confirmation failed: empty token");
            echo "Token non valido";
            break;
        }

        $db = getDb();
        try {
            $stmt = $db->prepare("UPDATE users SET is_confirmed = 1, confirmation_token = NULL WHERE confirmation_token = ?");
            $stmt->execute([$token]);

            if ($stmt->rowCount() > 0) {
                write_log('INFO', "Account confirmed successfully with token '$token'");
                header('Location: login.html?confirmed=1');
                exit;
            } else {
                write_log('WARNING', "Account confirmation failed: invalid or used token '$token'");
                echo "Token non valido o già utilizzato";
            }
        } catch (PDOException $e) {
            write_log('ERROR', "Account confirmation error: " . $e->getMessage());
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
                    write_log('WARNING', "Login attempt for unconfirmed account: '$user'");
                    echo json_encode(['success' => false, 'error' => 'Account non confermato. Controlla la tua email.']);
                    break;
                }
                session_regenerate_id(true);
                $_SESSION['username'] = $user;
                write_log('INFO', "User logged in: '$user'");
                echo json_encode(['success' => true]);
            } else {
                write_log('WARNING', "Invalid login attempt for user: '$user'");
                echo json_encode(['success' => false, 'error' => 'Credenziali non valide']);
            }
        } catch (PDOException $e) {
            write_log('ERROR', "Login error for '$user': " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'logout':
        $user = $_SESSION['username'] ?? 'unknown';
        session_destroy();
        write_log('INFO', "User logged out: '$user'");
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
