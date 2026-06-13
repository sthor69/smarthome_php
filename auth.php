<?php
require_once 'logger.php';
require_once 'db.php';

require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

session_start();
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'register':
        $data = json_decode(file_get_contents('php://input'), true);
        $regUsername = trim($data['username'] ?? '');
        $pass = $data['password'] ?? '';
        $email = trim($data['email'] ?? '');

        if (empty($regUsername) || empty($pass) || empty($email)) {
            write_log('WARNING', "Registration failed: missing fields for user '$regUsername'");
            echo json_encode(['success' => false, 'error' => 'Username, password ed email richiesti']);
            break;
        }

        $db = getDb();
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $token = bin2hex(random_bytes(16));
        try {
            $db->beginTransaction();

            // Check if email already exists
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $db->rollBack();
                write_log('WARNING', "Registration failed: email '$email' already exists");
                echo json_encode(['success' => false, 'error' => 'Email già registrata']);
                break;
            }

            $stmt = $db->prepare("INSERT INTO users (username, password_hash, email, confirmation_token) VALUES (?, ?, ?, ?)");
            $stmt->execute([$regUsername, $hash, $email, $token]);

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
            try {
                $mail->isSMTP();
                $mail->Host       = $settings['smtp_host'];
                $mail->SMTPAuth   = $settings['smtp_auth'] == '1';
                $mail->Username   = $settings['smtp_username'];
                $mail->Password   = $settings['smtp_password'];
                $mail->SMTPSecure = $settings['smtp_secure'] === 'none' ? false : $settings['smtp_secure'];
                $mail->Port       = $settings['smtp_port'];

                $mail->setFrom($settings['smtp_from_email'] ?: $settings['smtp_username'], $settings['smtp_from_name']);
                $mail->addAddress($email, $regUsername);

                $mail->isHTML(false);
                $mail->Subject = "Conferma la tua registrazione";
                $mail->Body    = "Ciao $regUsername,\n\nGrazie per esserti registrato. Per favore conferma il tuo account cliccando sul link seguente:\n$confirmLink\n\nGrazie!";

                $mail->send();

                $db->commit();
                write_log('INFO', "User registered successfully: '$regUsername' ($email)");
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $db->rollBack();
                $smtpUserLog = $settings['smtp_username'] ?: 'NOT SET';
                $smtpHostLog = $settings['smtp_host'] ?: 'NOT SET';
                write_log('ERROR', "Registration failed for '$regUsername' (SMTP Host: $smtpHostLog, SMTP User: $smtpUserLog): failed to send confirmation email. Mailer Error: {$mail->ErrorInfo}");
                echo json_encode(['success' => false, 'error' => 'Errore nell\'invio dell\'email di conferma. Riprova più tardi.']);
            }
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($e->getCode() == '23000') {
                write_log('WARNING', "Registration failed: username '$regUsername' already exists");
                echo json_encode(['success' => false, 'error' => 'Username già esistente']);
            } else {
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

    default:
        echo json_encode(['error' => 'Azione non valida']);
}
