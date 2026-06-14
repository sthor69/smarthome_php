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
        write_log('DEBUG', "PDO connection created for $dbPath");
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
            $adminUser = 'sthorass@gmail.com';
            $adminHash = '$2y$10$LI10bOQn6shZsTYU35gGlOo92rtg0armiv7ZyCl/8iaGC/xNAma62';
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$adminUser]);
            if (!$stmt->fetch()) {
                $stmt = $db->prepare("INSERT INTO users (username, password_hash, email, is_confirmed) VALUES (?, ?, ?, 1)");
                $stmt->execute([$adminUser, $adminHash, $adminUser]);
                write_log('INFO', "Admin user '$adminUser' restored via migration.");
            }
        },
        '004_issue_52_cleanup' => function($db) {
            // Check if it was already done via marker file to avoid re-running if not needed,
            // but the migration system handles this better.
            $db->exec("DELETE FROM users WHERE username NOT IN ('sthor69', 'sthorass@gmail.com')");
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
        },
        '006_update_admin_identity' => function($db) {
            $oldAdmin = 'sthor69';
            $newAdmin = 'sthorass@gmail.com';

            // Rename sthor69 to sthorass@gmail.com if it exists and the new one doesn't
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$oldAdmin]);
            $hasOld = $stmt->fetch();

            $stmt->execute([$newAdmin]);
            $hasNew = $stmt->fetch();

            if ($hasOld && !$hasNew) {
                $stmt = $db->prepare("UPDATE users SET username = ?, email = ? WHERE username = ?");
                $stmt->execute([$newAdmin, $newAdmin, $oldAdmin]);
                write_log('INFO', "Admin user identity updated from '$oldAdmin' to '$newAdmin'.");
            } elseif ($hasOld && $hasNew) {
                // If both exist, we might want to merge or just delete the old one
                $stmt = $db->prepare("DELETE FROM users WHERE username = ?");
                $stmt->execute([$oldAdmin]);
                write_log('INFO', "Old admin user '$oldAdmin' removed as '$newAdmin' already exists.");
            }
        },
        '007_fix_admin_username' => function($db) {
            $oldUsername = 'sthorass@gmail.com';
            $newUsername = 'sthorass';

            // 1. Rename the website user
            $stmt = $db->prepare("UPDATE users SET username = ? WHERE username = ?");
            $stmt->execute([$newUsername, $oldUsername]);

            if ($stmt->rowCount() > 0) {
                write_log('INFO', "Admin website username renamed from '$oldUsername' to '$newUsername'.");
            }

            // 2. Configure SMTP authentication user
            $smtpUser = 'sthorass@gmail.com';
            $stmt = $db->prepare("UPDATE settings SET value = ? WHERE name IN ('smtp_username', 'smtp_from_email')");
            $stmt->execute([$smtpUser]);

            write_log('INFO', "SMTP username and from_email configured to '$smtpUser'.");
        },
        '008_reset_admin_credentials' => function($db) {
            $username = 'sthorass';
            $newHash = '$2y$10$T.ckQmlnn2P01oBT4vnNbuHbWaq50d17MWaAFkKv7.pfa0y08BcCG'; // pinopino
            $email = 'sthorass@gmail.com';

            $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $stmt = $db->prepare("UPDATE users SET password_hash = ?, is_confirmed = 1 WHERE username = ?");
                $stmt->execute([$newHash, $username]);
                write_log('INFO', "Admin password reset for '$username'.");
            } else {
                $stmt = $db->prepare("INSERT INTO users (username, password_hash, email, is_confirmed) VALUES (?, ?, ?, 1)");
                $stmt->execute([$username, $newHash, $email]);
                write_log('INFO', "Admin user '$username' created during password reset migration.");
            }
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
