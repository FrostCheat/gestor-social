<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
date_default_timezone_set('America/Bogota');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/config/logger.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/middleware/auth.php';

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        logError('Fatal error', $error);
        http_response_code(500);
        echo json_encode(['error' => 'Internal server error']);
        exit;
    }
});

set_exception_handler(function ($e) {
    logError('Uncaught exception: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
});

$uri      = strtok($_SERVER['REQUEST_URI'], '?');
$uri      = preg_replace('#^/api#', '', $uri);
$uri      = '/' . ltrim($uri, '/');

$method   = $_SERVER['REQUEST_METHOD'];
$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$segments = array_values(array_filter(explode('/', $uri)));
$resource = $segments[0] ?? '';
$id       = isset($segments[1]) && is_numeric($segments[1]) ? (int)$segments[1] : null;
$action   = isset($segments[1]) && !is_numeric($segments[1]) ? $segments[1] : ($segments[2] ?? null);
if ($id !== null && isset($segments[2])) $action = $segments[2];

if ($resource === 'health') {
    echo json_encode(['status' => 'ok', 'time' => date('c'), 'system' => 'Servicio Social']);
    exit;
}

if ($resource === 'sse') {
    require __DIR__ . '/sse.php';
    exit;
}

$controllers = [
    'auth'          => __DIR__ . '/controllers/auth.php',
    'students'      => __DIR__ . '/controllers/students.php',
    'hours'         => __DIR__ . '/controllers/hours.php',
    'zones'         => __DIR__ . '/controllers/zones.php',
    'certificates'  => __DIR__ . '/controllers/certificates.php',
    'notifications' => __DIR__ . '/controllers/notifications.php',
];

if (isset($controllers[$resource]) && file_exists($controllers[$resource])) {
    try {
        require $controllers[$resource];
    } catch (PDOException $e) {
        logDatabaseError('controller/' . $resource, $e);
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
    } catch (Exception $e) {
        logError('Controller error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Not found', 'path' => $uri]);
