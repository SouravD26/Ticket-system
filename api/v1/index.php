<?php
/**
 * Mobile API v1 - front controller.
 *
 * Every endpoint is reachable two ways, so the app works with or without
 * mod_rewrite:
 *   GET  /attendence/api/v1/attendance/today
 *   GET  /attendence/api/v1/index.php?route=attendance/today
 */
declare(strict_types=1);

require_once __DIR__ . '/core.php';

// ---------------------------------------------------------------- CORS

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Any uncaught problem still has to come back as JSON.
set_exception_handler(function (Throwable $e): void {
    error_log('API v1 exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fail('Internal server error.', 500, 'server_error');
});
set_error_handler(function (int $no, string $str, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $no)) return false;
    throw new ErrorException($str, 0, $no, $file, $line);
});

// ---------------------------------------------------------------- route

function current_route(): string {
    $route = (string)($_GET['route'] ?? '');
    if ($route === '') {
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
        // Strip everything up to and including /api/v1
        if (preg_match('#/api/v1/?(.*)$#i', $path, $m)) $route = $m[1];
    }
    $route = trim(preg_replace('#/+#', '/', $route), '/');
    if (stripos($route, 'index.php') === 0) $route = trim(substr($route, 9), '/');
    return strtolower($route);
}

$route   = current_route();
$segments = $route === '' ? [] : explode('/', $route);

// A trailing numeric segment is an id: attendance/history/12 -> id = 12
if (count($segments) > 1 && ctype_digit(end($segments))) {
    $_GET['id'] = (int)array_pop($segments);
    $route = implode('/', $segments);
}

if ($route === '' || $route === 'index.php') {
    require __DIR__ . '/routes/meta.php';
    route_index();
}

// group/action -> routes/<group>.php, handler <group>_<action>()
$group  = $segments[0] ?? '';
$action = $segments[1] ?? 'index';

$allowed = ['auth', 'profile', 'attendance', 'leave', 'employees', 'admin', 'master', 'meta', 'files'];
if (!in_array($group, $allowed, true)) {
    fail('Unknown endpoint: ' . $route, 404, 'not_found');
}

$file = __DIR__ . '/routes/' . $group . '.php';
if (!is_file($file)) fail('Unknown endpoint: ' . $route, 404, 'not_found');
require $file;

$handler = $group . '_' . str_replace('-', '_', $action);
if (!function_exists($handler)) {
    fail('Unknown endpoint: ' . $route, 404, 'not_found');
}

$handler($conn);

// A handler must always respond; reaching here means it did not.
fail('Endpoint produced no response.', 500, 'server_error');
