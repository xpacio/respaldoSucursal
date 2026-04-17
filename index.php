<?php
/**
 * Project-specific server entry point (Facade)
 *
 * Modernized entry point that delegates all API logic to the App\Api\ArCore facade.
 */

require_once __DIR__ . '/shared/autoload.php';

use App\Logger;
use App\Router;
use App\Config\Database;
use App\Api\ArCore;

// 1. Initialize Logging
$logDir = __DIR__ . '/logs';
$verbose = !isset($_GET['quiet']) || $_GET['quiet'] !== '1';
Logger::init($logDir, $verbose);

Logger::info("=== API Request: " . ($_SERVER['REQUEST_URI'] ?? 'unknown') . " ===");

// 2. CORS Headers
header("Access-Control-Allow-Origin: *");
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, X-User-Id, X-TOTP-Token, Content-Type');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// 3. Initialize Database
$config = require __DIR__ . '/shared/Config/config.php';
$db = new Database($config['db']);

// 4. Initialize Router
$router = Router::getInstance();
$router->db = $db;

// 5. Detect and Clean Path
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Prefer PATH_INFO, fallback to REQUEST_URI
$path = !empty($pathInfo) ? $pathInfo : $requestUri;

// Clean /api and /api/index.php prefixes
$cleanPath = preg_replace('#^/api(/index\.php)?#', '', $path);
$resource = ltrim($cleanPath, '/');

// DEBUG: Log path information
error_log("API Path Debug: PATH_INFO='$pathInfo', REQUEST_URI='{$_SERVER['REQUEST_URI']}', parsed='$requestUri', clean='$cleanPath', resource='$resource'");

// NEVER default to health - let ArCore handle empty path based on action in body
// ArCore::handleRequest will use action from body if path is empty
try {
    $api = ArCore::getInstance($router);
    $api->handleRequest($resource);
} catch (\Exception $e) {
    Logger::err("Entry point error: " . $e->getMessage());
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Internal Server Error']);
}
