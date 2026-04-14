<?php
/**
 * Project-specific server entry point
 *
 * Simplified to expose only the current API routes without legacy UI or auth plumbing.
 */

// CORS headers
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, X-User-Id, X-TOTP-Token, Content-Type');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/shared/autoload.php';
require_once __DIR__ . '/shared/Config/config.php';
require_once __DIR__ . '/shared/Config/Database.php';
require_once __DIR__ . '/shared/Router.php';

$config = require __DIR__ . '/shared/Config/config.php';
$db = new Database($config['db']);

$router = Router::getInstance();
$router->db = $db;
$router->logger = null;

$path = $_SERVER['PATH_INFO'] ?? '/';
if (empty($path) || $path === '/') {
    $path = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($path, PHP_URL_PATH);
}
$path = preg_replace('#^/api/index\.php#', '', $path);
$path = preg_replace('#^/api#', '', $path);
if (empty($path)) {
    $path = '/';
}

if ($path === '/' || $path === '/health') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'status' => 'healthy']);
    exit;
}

$router->dispatch($path);
