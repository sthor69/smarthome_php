<?php
require_once 'logger.php';

$dbPath = '/var/www/smarthome/sensor_data.db';
if (!file_exists(dirname($dbPath))) {
    $dbPath = __DIR__ . '/var/www/smarthome/sensor_data.db';
}

function getDb($readOnly = false) {
    global $dbPath;
    try {
        $options = [];
        if ($readOnly) {
            $options[PDO::SQLITE_ATTR_OPEN_FLAGS] = PDO::SQLITE_OPEN_READONLY;
        }

        $db = new PDO("sqlite:$dbPath", null, null, $options);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if (!$readOnly) {
            // Ensure measurements table exists
            $db->exec("CREATE TABLE IF NOT EXISTS measurements (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                temperature REAL,
                humidity REAL,
                battery_voltage REAL,
                usb_powered INTEGER
            )");

            // Ensure users table exists
            $db->exec("CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE,
                password_hash TEXT
            )");

            // Migration: add missing columns to users
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

            // Ensure sthor69 exists (restoration if erased)
            $adminUser = 'sthor69';
            $adminHash = '$2y$10$LI10bOQn6shZsTYU35gGlOo92rtg0armiv7ZyCl/8iaGC/xNAma62'; // Hash for 'Gualano0,'

            $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$adminUser]);
            if (!$stmt->fetch()) {
                $stmt = $db->prepare("INSERT INTO users (username, password_hash, email, is_confirmed) VALUES (?, ?, 'sthor69@example.com', 1)");
                $stmt->execute([$adminUser, $adminHash]);
                write_log('INFO', "Admin user '$adminUser' restored.");
            }

            // Migration: one-time account erasure for Issue #52
            $markerFile = dirname($dbPath) . '/logs/.users_erased_v52';
            if (!file_exists($markerFile)) {
                $db->exec("DELETE FROM users WHERE username != 'sthor69'");
                if (!is_dir(dirname($markerFile))) {
                    mkdir(dirname($markerFile), 0775, true);
                }
                touch($markerFile);
                write_log('INFO', "One-time account erasure migration executed. Only 'sthor69' was preserved.");
            }
        }

        return $db;
    } catch (PDOException $e) {
        write_log('ERROR', "Database error: " . $e->getMessage());
        if (php_sapi_name() !== 'cli') {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Errore database: ' . $e->getMessage()]);
            exit;
        } else {
            throw $e;
        }
    }
}
?>
