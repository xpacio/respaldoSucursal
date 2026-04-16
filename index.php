<?php
/**
 * Project-specific server entry point
 *
 * Simplified to expose only the current API routes without legacy UI or auth plumbing.
 */

require_once __DIR__ . '/shared/Logger.php';
require_once __DIR__ . '/shared/Constants.php';

$logDir = __DIR__ . '/logs';
$verbose = !isset($_GET['quiet']) || $_GET['quiet'] !== '1';
Logger::init($logDir, $verbose);
//Logger::setQuiet(true);

Logger::info("=== API Request: " . ($_SERVER['REQUEST_URI'] ?? 'unknown') . " ===");

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

class SimpleClientService {
    private $db;
    public function __construct($db) { $this->db = $db; }
    public function getClientStatus(string $rbfid): ?array {
        error_log("SimpleClientService.getClientStatus: $rbfid");
        $result = $this->db->fetchOne(
            "SELECT c.rbfid, c.emp, c.plaza, c.enabled, ar.registered_at, ar.last_sync_at
             FROM clients c
             LEFT JOIN ar_clients ar ON ar.rbfid = c.rbfid
             WHERE c.rbfid = :rbfid",
            [':rbfid' => $rbfid]
        );
        error_log("SimpleClientService.getClientStatus result: " . json_encode($result));
        return $result;
    }
    public function getClientFiles(string $rbfid): array {
        return $this->db->fetchAll(
            "SELECT file_name, chunk_count, updated_at FROM ar_files WHERE rbfid = :rbfid",
            [':rbfid' => $rbfid]
        );
    }
    public function registerClient(string $rbfid): void {
        $existing = $this->db->fetchOne("SELECT rbfid FROM ar_clients WHERE rbfid = :rbfid", [':rbfid' => $rbfid]);
        if (!$existing) {
            $this->db->execute(
                "INSERT INTO ar_clients (rbfid, enabled, registered_at) VALUES (:rbfid, true, NOW())",
                [':rbfid' => $rbfid]
            );
        }
    }
}
$router->clientService = new SimpleClientService($db);

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

error_log("index.php: dispatching with path=$path");
$router->dispatch($path);
