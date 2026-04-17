<?php
declare(strict_types=1);

namespace App\Api;

use App\Services\StorageService;
use App\Services\DatabaseService;
use App\Traits\ResponseTrait;
use App\Traits\LoggingTrait;
use App\Router;
use App\TotpValidator;
use App\Cli\Chunk;
use App\Hash;
use Exception;

/**
 * ArCore Facade
 * 
 * Manages API routing using a REST-like structure: /api/{action}/{rbfid}/{extra}
 * Also maintains backward compatibility with legacy body-based actions.
 */
class ArCore {
    use ResponseTrait, LoggingTrait;

    private static ?ArCore $instance = null;
    
    public StorageService $storage;
    public DatabaseService $db;
    public AuthService $auth;
    public UploadService $upload;
    public ClientService $client;
    public ChunkService $chunk;
    public SyncService $sync;
    public $router;

    private function __construct($router) {
        $this->router = $router;
        $this->storage = new StorageService();
        $this->db = new DatabaseService($router->db);
        $this->auth = new AuthService($router->db);
        $this->upload = new UploadService($this->db, $this->storage);
        $this->client = new ClientService($this->db);
        $this->chunk = new ChunkService($this->db);
        $this->sync = new SyncService($this->db);
    }

    public static function getInstance($router = null): ArCore {
        if (self::$instance === null && $router !== null) {
            self::$instance = new ArCore($router);
        }
        return self::$instance;
    }
    
    public static function reset(): void {
        self::$instance = null;
    }

    /**
     * Entry point for API requests (Facade)
     */
    public function handleRequest(string $path): void {
        $this->log("handleRequest: path=$path");
        
        $segments = array_values(array_filter(explode('/', $path)));
        $body = $this->router->getBody();
        
        // 1. Determine Action
        $action = $segments[0] ?? ($body['action'] ?? 'sync');
        
        // 2. Determine RBFID (URL -> Header -> Body)
        $rbfid = $segments[1] ?? ($_SERVER['HTTP_X_RBFID'] ?? ($body['rbfid'] ?? ''));
        
        // 3. Determine TOTP Token (URL -> Header -> Body)
        $token = $segments[2] ?? ($_SERVER['HTTP_X_TOTP_TOKEN'] ?? ($_SERVER['HTTP_X_TOKEN'] ?? ($body['totp_token'] ?? '')));

        // Special case: if segments[2] looks like a hash or chunk, it's not the token.
        // We assume token is the 3rd segment only for specific actions if provided.
        // Actually, let's stick to Headers or Body for TOTP for simplicity and security.
        $token = $_SERVER['HTTP_X_TOTP_TOKEN'] ?? ($_SERVER['HTTP_X_TOKEN'] ?? ($body['totp_token'] ?? ''));

        $this->log("Action detected: $action | Client: $rbfid");

        // Prepare request context
        $context = [
            'action' => $action,
            'rbfid'  => $rbfid,
            'token'  => $token,
            'body'   => $body,
            'params' => array_slice($segments, 1) // Everything after action
        ];

        // --- GLOBAL SECURITY MIDDLEWARE ---
        // Verify TOTP for all actions EXCEPT 'health' and 'download'
        $publicActions = ['health', 'download', 'init']; 
        
        if (!in_array($action, $publicActions)) {
            if (empty($rbfid) || empty($token)) {
                $this->jsonResponse(['ok' => false, 'error' => 'Autenticacion requerida (RBFID y X-TOTP-Token)'], 401);
            }

            // Validar auth
            $timestamp = $_SERVER['HTTP_X_TIMESTAMP'] ?? ($body['timestamp'] ?? '');
            if (!empty($timestamp)) {
                $seed = substr((string)$timestamp, 0, -2);
                $expected = Hash::compute($seed . $rbfid)->toBase64();
                if ($token !== $expected) $this->jsonResponse(['ok' => false, 'error' => 'Token dinamico invalido', 'code' => 'AUTH_ERROR'], 401);
            } else {
                $validation = TotpValidator::validate($this->db->getDb(), $rbfid, $token);
                if (!$validation['ok']) $this->jsonResponse($validation, 401);
            }
        }

        switch ($action) {
            case 'health':       $this->handleHealth($context); break;
            case 'init':         $this->handleInit($context); break;
            case 'register':     $this->handleRegister($context); break;
            case 'config':       $this->handleConfig($context); break;
            case 'sync':         $this->handleSync($context); break;
            case 'upload':       $this->handleUpload($context); break;
            case 'status':       $this->handleStatus($context); break;
            case 'history':      $this->handleHistory($context); break;
            case 'download':     $this->handleDownload($context); break;
            case 'query_chunks': $this->handleQueryChunks($context); break;
            default:
                $this->jsonResponse(['ok' => false, 'error' => "Accion '$action' no reconocida", 'code' => 'INVALID_ACTION'], 400);
        }
    }

    private function handleHealth(array $ctx): void {
        $this->jsonResponse([
            'ok' => true, 
            'status' => 'healthy',
            'message' => 'Use el timestamp de esta respuesta para generar su TOTP'
        ]);
    }

    private function handleInit(array $ctx): void {
        $rbfid = $ctx['rbfid'];
        if (empty($rbfid)) {
            $this->jsonResponse(['ok' => false, 'error' => 'RBFID requerido', 'code' => 'MISSING_RBFID'], 400);
        }
        
        $client = $this->client->getClientStatus($rbfid);
        
        if (!$client) {
            $this->db->execute("INSERT INTO ar_clients (rbfid, enabled, registered_at) VALUES (:rbfid, false, NOW())", [':rbfid' => $rbfid]);
            $this->db->execute("INSERT INTO clients (rbfid, enabled, created_at) VALUES (:rbfid, false, NOW())", [':rbfid' => $rbfid]);
            $this->jsonResponse(['ok' => true, 'rbfid' => $rbfid, 'enabled' => false, 'latent' => true]);
        }

        if (!$client['enabled']) {
            $this->jsonResponse(['ok' => true, 'rbfid' => $rbfid, 'enabled' => false, 'latent' => true]);
        }

        $this->jsonResponse(['ok' => true, 'rbfid' => $rbfid, 'enabled' => true]);
    }

    private function handleRegister(array $ctx): void {
        try {
            $this->client->registerClient($ctx['rbfid']);
            $client = $this->client->getClientStatus($ctx['rbfid']);
            $this->jsonResponse(['ok' => true, 'rbfid' => $ctx['rbfid'], 'enabled' => $client['enabled'] ?? false]);
        } catch (Exception $e) {
            $this->jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function handleConfig(array $ctx): void {
        $clientVersion = $ctx['body']['files_version'] ?? '';
        $files = $this->db->fetchAll("SELECT file_name FROM ar_global_files WHERE enabled = true ORDER BY file_name");
        $filesList = array_column($files, 'file_name');
        $serverVersion = substr(md5(implode(',', $filesList)), 0, 8);

        $response = ['ok' => true, 'rbfid' => $ctx['rbfid'], 'files_version' => $serverVersion];
        if ($clientVersion !== $serverVersion) {
            $response['files'] = $filesList;
        }
        $this->jsonResponse($response);
    }

    private function handleSync(array $ctx): void {
        $rbfid = $ctx['rbfid'];
        $body = $ctx['body'];
        $files = $body['files'] ?? [];

        $slots = $this->getSlots();
        $rateDelay = $slots['available'] > 0 ? 3000 : 10000;

        $syncId = $this->db->insert("INSERT INTO ar_sync_history (rbfid, status, started_at) VALUES (:rbfid, 'pending', NOW())", [':rbfid' => $rbfid]);
        $clientPaths = $this->getClientPaths($rbfid);
        $needsUpload = [];

        foreach ($files as $file) {
            $filename = $file['filename'] ?? '';
            $hashCompleto = $file['hash_completo'] ?? '';
            $chunkHashes = $file['chunk_hashes'] ?? [];
            $mtime = (int)($file['mtime'] ?? 0);
            $size = (int)($file['size'] ?? 0);

            if (empty($filename) || empty($hashCompleto)) continue;

            $serverFile = $this->db->fetchOne("SELECT hash_xxh3, file_mtime FROM ar_files WHERE rbfid = :rbfid AND file_name = :file", [':rbfid' => $rbfid, ':file' => $filename]);

            if ($serverFile && $mtime > 0 && (int)$serverFile['file_mtime'] > $mtime) continue;

            if (!$serverFile || $serverFile['hash_xxh3'] !== $hashCompleto) {
                $chunkCnt = max(1, count($chunkHashes));
                $this->db->execute(
                    "INSERT INTO ar_files (rbfid, file_name, chunk_count, hash_esperado, updated_at, file_size, file_mtime)
                     VALUES (:rbfid, :file, :cnt, :esperado, NOW(), :size, :mtime)
                     ON CONFLICT (rbfid, file_name) DO UPDATE SET chunk_count = :cnt, hash_xxh3 = NULL, hash_esperado = :esperado, updated_at = NOW(), file_size = :size, file_mtime = :mtime",
                    [':rbfid' => $rbfid, ':file' => $filename, ':cnt' => $chunkCnt, ':esperado' => $hashCompleto, ':size' => $size, ':mtime' => $mtime]
                );

                foreach ($chunkHashes as $idx => $ch) {
                    $this->db->execute(
                        "INSERT INTO ar_file_hashes (rbfid, file_name, chunk_index, hash_xxh3, status, updated_at)
                         VALUES (:rbfid, :file, :idx, :hash, 'pending', NOW())
                         ON CONFLICT (rbfid, file_name, chunk_index) DO UPDATE SET hash_xxh3 = :hash, status = 'pending', updated_at = NOW()",
                        [':rbfid' => $rbfid, ':file' => $filename, ':idx' => $idx, ':hash' => $ch]
                    );
                }

                $next = $this->db->fetchOne("SELECT chunk_index FROM ar_file_hashes WHERE rbfid = :rbfid AND file_name = :file AND status != 'received' ORDER BY chunk_index LIMIT 1", [':rbfid' => $rbfid, ':file' => $filename]);
                if ($next) {
                    $needsUpload[] = [
                        'file' => $filename,
                        'chunk' => $next['chunk_index'],
                        'work_path' => ($clientPaths['work_dir'] ?? '') . '/' . $filename,
                        'dest_path' => ($clientPaths['base_dir'] ?? '') . '/' . $filename,
                        'md5' => $hashCompleto
                    ];
                }
            }
        }

        $this->jsonResponse([
            'ok' => true,
            'sync_id' => $syncId,
            'needs_upload' => $needsUpload,
            'rate_delay' => $rateDelay,
            'slots_used' => $slots['used'],
            'slots_available' => $slots['available']
        ]);
    }

    private function handleUpload(array $ctx): void {
        $rbfid = $ctx['rbfid'];
        $body = $ctx['body'];
        $params = $ctx['params']; // Elements after /upload/
        
        // Params from URL: /{rbfid}/{filename}/{index}/{hash}
        $filename = $params[1] ?? ($body['filename'] ?? '');
        $chunkIdx = (int)($params[2] ?? ($body['chunk_index'] ?? 0));
        $hash     = $params[3] ?? ($body['hash_xxh3'] ?? '');
        $data     = $body['data'] ?? '';

        if (empty($filename) || empty($hash) || empty($data)) {
            $this->jsonResponse(['ok' => false, 'error' => 'Faltan campos (filename, hash o data)'], 400);
        }

        $paths = $this->getClientPaths($rbfid);
        if (!$paths) $this->jsonResponse(['ok' => false, 'error' => 'Cliente no encontrado'], 404);

        try {
            $result = $this->upload->handle($rbfid, $filename, $chunkIdx, $hash, $data, $paths);
            $this->jsonResponse(['ok' => true, 'file' => $filename, 'chunk' => $chunkIdx, ...$result]);
        } catch (Exception $e) {
            $this->jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    private function handleStatus(array $ctx): void {
        $client = $this->client->getClientStatus($ctx['rbfid']);
        if (!$client) $this->jsonResponse(['ok' => false, 'error' => 'No encontrado'], 404);
        
        $files = $this->client->getClientFiles($ctx['rbfid']);
        $this->jsonResponse(['ok' => true, 'client' => $client, 'files' => $files]);
    }

    private function handleHistory(array $ctx): void {
        $limit = (int)($ctx['body']['limit'] ?? 50);
        $history = $this->db->fetchAll("SELECT id, file_name, chunk_count, updated_at FROM ar_files WHERE rbfid = :rbfid ORDER BY updated_at DESC LIMIT :limit", [':rbfid' => $ctx['rbfid'], ':limit' => $limit]);
        $this->jsonResponse(['ok' => true, 'history' => $history]);
    }

    private function handleDownload(array $ctx): void {
        $exe_path = '/srv/zigRespaldoSucursal/zig-out/bin/ar.exe';
        if (!file_exists($exe_path)) $this->jsonResponse(['ok' => false, 'error' => 'No encontrado'], 404);

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="ar.exe"');
        header('Content-Length: ' . filesize($exe_path));
        readfile($exe_path);
        exit;
    }

    private function handleQueryChunks(array $ctx): void {
        $filename = $ctx['params'][1] ?? ($ctx['body']['filename'] ?? '');
        $paths = $this->getClientPaths($ctx['rbfid']);
        if (!$paths) $this->jsonResponse(['ok' => false, 'error' => 'No encontrado'], 404);

        $faltantes = $this->db->fetchAll("SELECT chunk_index, hash_xxh3, status, error_count FROM ar_file_hashes WHERE rbfid = :rbfid AND file_name = :file AND status != 'received' ORDER BY chunk_index", [':rbfid' => $ctx['rbfid'], ':file' => $filename]);

        $next = null;
        foreach ($faltantes as $ch) {
            if ($ch['status'] === 'failed' && $ch['error_count'] >= 5) continue;
            if ($next === null) { $next = $ch['chunk_index']; break; }
        }

        $this->jsonResponse(['ok' => true, 'file' => $filename, 'chunk' => $next]);
    }

    // --- Helpers ---

    private function getSlots(): array {
        $result = $this->db->fetchOne("SELECT COUNT(*) as used FROM ar_sessions WHERE status = 'active' AND updated_at > NOW() - INTERVAL '5 minutes'");
        $used = (int)($result['used'] ?? 0);
        return ['used' => $used, 'available' => max(0, 10 - $used)];
    }

    private function getClientPaths(string $rbfid): ?array {
        $client = $this->db->fetchOne("SELECT emp, plaza FROM clients WHERE rbfid = :rbfid", [':rbfid' => $rbfid]);
        if (!$client) return null;
        $emp = !empty($client['emp']) ? $client['emp'] : '_';
        $plaza = !empty($client['plaza']) ? $client['plaza'] : '_';
        return [
            'emp' => $emp, 'plaza' => $plaza,
            'base_dir' => "/srv/qbck/$emp/$plaza/$rbfid",
            'work_dir' => "/tmp/ar/$rbfid",
        ];
    }
}
