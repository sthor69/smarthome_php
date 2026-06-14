<?php
require_once 'logger.php';
require_once 'db.php';

require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

session_start();
header('Content-Type: application/json');

function isAdmin() {
    return isset($_SESSION['username']) && $_SESSION['username'] === 'sthorass';
}

function logAttempt($db, $username, $email, $status, $error = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = $db->prepare("INSERT INTO user_creation_attempts (username, email, ip_address, status, error_message) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$username, $email, $ip, $status, $error]);
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'register':
        $data = json_decode(file_get_contents('php://input'), true);
        $regUsername = trim($data['username'] ?? '');
        $pass = $data['password'] ?? '';
        $email = trim($data['email'] ?? '');

        $db = getDb();
        if (empty($regUsername) || empty($pass) || empty($email)) {
            logAttempt($db, $regUsername, $email, 'FAILED', 'Missing fields');
            write_log('WARNING', "Registration failed: missing fields for user '$regUsername'");
            echo json_encode(['success' => false, 'error' => 'Username, password ed email richiesti']);
            break;
        }

        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $token = bin2hex(random_bytes(16));
        try {
            $db->beginTransaction();

            // Check if email already exists
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $db->rollBack();
                logAttempt($db, $regUsername, $email, 'FAILED', 'Email already exists');
                write_log('WARNING', "Registration failed: email '$email' already exists");
                echo json_encode(['success' => false, 'error' => 'Email già registrata']);
                break;
            }

            // Record registration IP and user data
            $regIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $stmt = $db->prepare("INSERT INTO users (username, password_hash, email, confirmation_token, registration_ip) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$regUsername, $hash, $email, $token, $regIp]);

            // Get SMTP settings
            $stmtS = $db->query("SELECT name, value FROM settings");
            $settings = $stmtS->fetchAll(PDO::FETCH_KEY_PAIR);

            // Verify mandatory SMTP settings are present
            if (empty($settings['smtp_host']) || empty($settings['smtp_username']) || empty($settings['smtp_password'])) {
                $db->rollBack();
                write_log('ERROR', "Registration failed for '$regUsername': SMTP not configured. Host: " . ($settings['smtp_host'] ?: 'MISSING') . ", User: " . ($settings['smtp_username'] ?: 'MISSING'));
                echo json_encode(['success' => false, 'error' => 'Servizio email non configurato. Contatta l\'amministratore.']);
                break;
            }

            // Send confirmation email via PHPMailer
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
            $confirmLink = "$protocol://$host" . dirname($_SERVER['PHP_SELF']) . "/auth.php?action=confirm&token=$token";

            $mail = new PHPMailer(true);
            $mail->SMTPDebug = SMTP::DEBUG_LOWLEVEL;
            $mail->Debugoutput = function($str, $level) {
                write_log('DEBUG-SMTP', trim($str));
            };
            try {
                $mail->isSMTP();
                $mail->Host       = $settings['smtp_host'];
                $mail->SMTPAuth   = $settings['smtp_auth'] == '1';
                $mail->Username   = $settings['smtp_username'];
                $mail->Password   = $settings['smtp_password'];
                $mail->SMTPSecure = $settings['smtp_secure'] === 'none' ? false : $settings['smtp_secure'];
                $mail->Port       = $settings['smtp_port'];

                $fromEmail = !empty($settings['smtp_from_email']) ? $settings['smtp_from_email'] : $settings['smtp_username'];
                $fromName = !empty($settings['smtp_from_name']) ? $settings['smtp_from_name'] : 'SmartHome Monitor';
                $mail->setFrom($fromEmail, $fromName);

                $mail->addAddress($email, $regUsername);

                $mail->isHTML(false);
                $mail->Subject = "Conferma la tua registrazione";
                $mail->Body    = "Ciao $regUsername,\n\nGrazie per esserti registrato. Per favore conferma il tuo account cliccando sul link seguente:\n$confirmLink\n\nGrazie!";

                $mail->send();

                $db->commit();
                logAttempt($db, $regUsername, $email, 'SUCCESS');
                write_log('INFO', "User registered successfully: '$regUsername' ($email)");
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $db->rollBack();
                $smtpUserLog = $settings['smtp_username'] ?: 'NOT SET';
                $smtpHostLog = $settings['smtp_host'] ?: 'NOT SET';
                logAttempt($db, $regUsername, $email, 'FAILED', "SMTP Error: {$mail->ErrorInfo}");
                write_log('ERROR', "Registration failed for '$regUsername' (SMTP Host: $smtpHostLog, SMTP User: $smtpUserLog): failed to send confirmation email. Mailer Error: {$mail->ErrorInfo}");
                echo json_encode(['success' => false, 'error' => 'Errore nell\'invio dell\'email di conferma. Riprova più tardi.']);
            }
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($e->getCode() == '23000') {
                logAttempt($db, $regUsername, $email, 'FAILED', 'Username already exists');
                write_log('WARNING', "Registration failed: username '$regUsername' already exists");
                echo json_encode(['success' => false, 'error' => 'Username già esistente']);
            } else {
                logAttempt($db, $regUsername, $email, 'FAILED', $e->getMessage());
                write_log('ERROR', "Registration error for '$regUsername': " . $e->getMessage());
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

    case 'list_users':
        if (!isAdmin()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Accesso negato']);
            break;
        }
        write_log('INFO', "Admin '{$_SESSION['username']}' requested user list");
        $db = getDb();
        $stmt = $db->query("SELECT id, username, email, is_confirmed, created_at, updated_at, registration_ip FROM users ORDER BY created_at DESC");
        echo json_encode(['success' => true, 'users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    case 'delete_user':
        if (!isAdmin()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Accesso negato']);
            break;
        }
        write_log('INFO', "Admin '{$_SESSION['username']}' initiated user deletion");
        $data = json_decode(file_get_contents('php://input'), true);
        $userId = $data['id'] ?? null;
        if (!$userId) {
            echo json_encode(['success' => false, 'error' => 'ID utente mancante']);
            break;
        }
        $db = getDb();
        // Check if it's the admin
        $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $u = $stmt->fetch();
        if ($u && $u['username'] === 'sthorass') {
            echo json_encode(['success' => false, 'error' => 'Non puoi eliminare l\'account amministratore']);
            break;
        }
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        echo json_encode(['success' => true]);
        break;

    case 'list_attempts':
        if (!isAdmin()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Accesso negato']);
            break;
        }
        write_log('INFO', "Admin '{$_SESSION['username']}' requested registration attempts list");
        $db = getDb();
        $stmt = $db->query("SELECT a.*, u.is_confirmed FROM user_creation_attempts a LEFT JOIN users u ON a.username = u.username ORDER BY a.timestamp DESC");
        echo json_encode(['success' => true, 'attempts' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    default:
        echo json_encode(['error' => 'Azione non valida']);
}
