<?php
date_default_timezone_set('Europe/Rome');

function write_log($level, $message) {
    $logFile = __DIR__ . '/logs/website..log';
    $timestamp = date('Y-m-d H:i:s');
    $scriptName = basename($_SERVER['PHP_SELF'] ?? 'unknown');
    $logEntry = "$timestamp [$level] [$scriptName] $message" . PHP_EOL;

    // Ensure the directory exists
    if (!is_dir(dirname($logFile))) {
        mkdir(dirname($logFile), 0775, true);
    }

    file_put_contents($logFile, $logEntry, FILE_APPEND);
}
?>
