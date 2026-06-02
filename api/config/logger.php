<?php
function writeLog(string $message, string $type = 'error', $data = null): void {
    if (in_array($type, ['info', 'debug'])) return;

    $logDir = __DIR__ . '/../logs/';
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);

    $logFile  = $logDir . 'error.log';
    $date     = date('Y-m-d H:i:s');
    $ip       = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
    $uri      = $_SERVER['REQUEST_URI'] ?? 'CLI';

    $entry = "[{$date}] [" . strtoupper($type) . "] [IP:{$ip}] [URI:{$uri}] {$message}";
    if ($data !== null) {
        $entry .= "\nData: " . (is_array($data) || is_object($data) ? json_encode($data) : $data);
    }
    $entry .= "\n" . str_repeat('-', 60) . "\n";

    error_log($entry, 3, $logFile);

    if (file_exists($logFile) && filesize($logFile) > 5242880) {
        $backup = $logDir . 'error_' . date('YmdHis') . '.log';
        rename($logFile, $backup);
        $files = glob($logDir . 'error_*.log');
        if ($files) {
            usort($files, fn($a, $b) => filemtime($a) - filemtime($b));
            while (count($files) > 5) unlink(array_shift($files));
        }
    }
}

function logError(string $message, $data = null): void   { writeLog($message, 'error', $data); }
function logWarning(string $message, $data = null): void { writeLog($message, 'warning', $data); }
function logInfo(string $message, $data = null): void    {}
function logDebug(string $message, $data = null): void   {}

function logDatabaseError(string $action, $error, $query = null): void {
    writeLog("DB Error in {$action}: " . $error->getMessage(), 'database', [
        'code'  => $error->getCode(),
        'query' => $query,
    ]);
}

function logControllerError(string $controller, string $action, $error, $data = null): void {
    writeLog("Controller Error {$controller}::{$action}: " . $error->getMessage(), 'controller', $data);
}
