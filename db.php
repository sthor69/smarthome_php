<?php
require_once 'logger.php';

$dbPath = '/var/www/smarthome/sensor_data.db';
if (!file_exists(dirname($dbPath))) {
    $dbPath = __DIR__ . '/sensor_data.db';
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
            applyMigrations($db);
        }

        return $db;
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        write_log('ERROR', "Database error: " . $msg);

        if (strpos($msg, 'readonly') !== false || strpos($msg, 'attempt to write a readonly database') !== false) {
            $diag = "";
            if (file_exists($dbPath) && !is_writable($dbPath)) {
                $diag .= " (File non scrivibile)";
            }
            if (!is_writable(dirname($dbPath))) {
                $diag .= " (Directory non scrivibile)";
            }
            $msg .= $diag;
        }

        if (php_sapi_name() !== 'cli') {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Errore database: ' . $msg]);
            exit;
        } else {
            throw $e;
        }
    }
}

function applyMigrations($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE,
        applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $migrations = [
        '001_initial_schema' => function($db) {
            $db->exec("CREATE TABLE IF NOT EXISTS measurements (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                temperature REAL,
                humidity REAL,
                battery_voltage REAL,
                usb_powered INTEGER
            )");
            $db->exec("CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE,
                password_hash TEXT
            )");
        },
        '002_user_auth_columns' => function($db) {
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
                $db->exec("UPDATE users SET is_confirmed = 1");
            }
        },
        '003_restore_admin' => function($db) {
            $adminUser = 'sthor69';
            $adminHash = '$2y$10$LI10bOQn6shZsTYU35gGlOo92rtg0armiv7ZyCl/8iaGC/xNAma62';
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$adminUser]);
            if (!$stmt->fetch()) {
                $stmt = $db->prepare("INSERT INTO users (username, password_hash, email, is_confirmed) VALUES (?, ?, 'sthor69@example.com', 1)");
                $stmt->execute([$adminUser, $adminHash]);
                write_log('INFO', "Admin user '$adminUser' restored via migration.");
            }
        },
        '004_issue_52_cleanup' => function($db) {
            // Check if it was already done via marker file to avoid re-running if not needed,
            // but the migration system handles this better.
            $db->exec("DELETE FROM users WHERE username != 'sthor69'");
            write_log('INFO', "Issue #52 account erasure executed via migration.");
        },
        '005_smtp_settings' => function($db) {
            $db->exec("CREATE TABLE IF NOT EXISTS settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT UNIQUE,
                value TEXT
            )");
            $defaults = [
                'smtp_host' => 'smtp.gmail.com',
                'smtp_port' => '587',
                'smtp_auth' => '1',
                'smtp_secure' => 'tls',
                'smtp_username' => '',
                'smtp_password' => '',
                'smtp_from_email' => '',
                'smtp_from_name' => 'SmartHome Monitor'
            ];
            $stmt = $db->prepare("INSERT OR IGNORE INTO settings (name, value) VALUES (?, ?)");
            foreach ($defaults as $key => $value) {
                $stmt->execute([$key, $value]);
            }
            write_log('INFO', "SMTP settings table created and seeded.");
        }
    ];

    foreach ($migrations as $name => $func) {
        $stmt = $db->prepare("SELECT 1 FROM schema_migrations WHERE name = ?");
        $stmt->execute([$name]);
        if (!$stmt->fetch()) {
            try {
                $db->beginTransaction();
                $func($db);
                $stmt = $db->prepare("INSERT INTO schema_migrations (name) VALUES (?)");
                $stmt->execute([$name]);
                $db->commit();
                write_log('INFO', "Migration '$name' applied successfully.");
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                write_log('ERROR', "Migration '$name' failed: " . $e->getMessage());
                throw $e;
            }
        }
    }
}
?>
