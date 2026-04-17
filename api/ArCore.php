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
    public function handleRequest(string $resource): void {
        $this->log("handleRequest: resource=$resource");
        
        $body = $this->router->getBody();
        $action = $body['action'] ?? '';
        
        if ($resource === '' || $resource === null) {
            $action = $action ?: ($body['action'] ?? 'sync');
        }

        $this->log("Action: $action");

        switch ($action) {
            case 'init':     $this->handleInit($body); break;
            case 'register': $this->handleRegister($body); break;
            case 'config':   $this->handleConfig($body); break;
            case 'sync':     $this->handleSync($body); break;
            case 'upload':   $this->handleUpload($body); break;
            case 'status':   $this->handleStatus($body); break;
            case 'history':  $this->handleHistory($body); break;
            case 'download': $this->handleDownload($body); break;
            case 'query_chunks': $this->handleQueryChunks($body); break;
            default:
                $this->jsonResponse(['ok' => false, 'error' => 'Accion no reconocida', 'code' => 'INVALID_ACTION'], 400);
        }
    }

    private function handleInit(array $body): void {
        $rbfid = $body['rbfid'] ?? '';
        if (empty($rbfid)) {
            $this->jsonResponse(['ok' => false, 'error' => 'RBFID requerido', 'code' => 'MISSING_RBFID'], 400);
        }
        
        $client = $this->client->getClientStatus($rbfid);
        
        if (!$client) {
            $this->db->execute("INSERT INTO ar_clients (rbfid, enabled, registered_at) VALUES (:rbfid, false, NOW())", [':rbfid' => $rbfid]);
            $this->db->execute("INSERT INTO clients (rbfid, enabled, created_at) VALUES (:rbfid, false, NOW())", [':rbfid' => $rbfid]);
            header('X-Timestamp: ');
            $this->jsonResponse(['ok' => true, 'rbfid' => $rbfid, 'timestamp' => '', 'enabled' => false, 'latent' => true]);
        }
        
        $timestamp = time();
        $timestampStr = (string)$timestamp;

        if (!$client['enabled']) {
            header('X-Timestamp: ');
            $this->jsonResponse(['ok' => true, 'rbfid' => $rbfid, 'timestamp' => '', 'enabled' => false, 'latent' => true]);
        }

        header('X-Timestamp: ' . $timestampStr);
        $this->jsonResponse(['ok' => true, 'rbfid' => $rbfid, 'timestamp' => $timestampStr, 'enabled' => true]);
    }

    private function handleRegister(array $body): void {
        $rbfid = $body['rbfid'] ?? '';
        $totp = $body['totp_token'] ?? '';

        if (empty($rbfid) || empty($totp)) {
            $this->jsonResponse(['ok' => false, 'error' => 'RBFID y TOTP requeridos'], 400);
        }

        $validation = TotpValidator::validate($this->db->getDb(), $rbfid, $totp);
        if (!$validation['ok']) {
            $this->jsonResponse(['ok' => false, 'error' => $validation['error']], 401);
        }

        try {
            $this->client->registerClient($rbfid);
            $client = $this->client->getClientStatus($rbfid);
            $this->jsonResponse(['ok' => true, 'rbfid' => $rbfid, 'enabled' => $client['enabled'] ?? false]);
        } catch (Exception $e) {
            $this->jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function handleConfig(array $body): void {
        $rbfid = $body['rbfid'] ?? '';
        $clientVersion = $body['files_version'] ?? '';

        $files = $this->db->fetchAll("SELECT file_name FROM ar_global_files WHERE enabled = true ORDER BY file_name");
        $filesList = array_column($files, 'file_name');
        $serverVersion = substr(md5(implode(',', $filesList)), 0, 8);

        $response = ['ok' => true, 'rbfid' => $rbfid, 'files_version' => $serverVersion];
        if ($clientVersion !== $serverVersion) {
            $response['files'] = $filesList;
        }
        $this->jsonResponse($response);
    }

    private function handleSync(array $body): void {
        $rbfid = $body['rbfid'] ?? $_SERVER['HTTP_X_RBFID'] ?? '';
        $timestamp = $body['timestamp'] ?? $_SERVER['HTTP_X_TIMESTAMP'] ?? '';
        $token = $body['totp_token'] ?? $_SERVER['HTTP_X_TOKEN'] ?? '';
        $files = $body['files'] ?? [];

        if (empty($rbfid) || empty($token)) {
            $this->jsonResponse(['ok' => false, 'error' => 'Faltan datos de autenticacion'], 401);
        }

        // Validar auth
        if (!empty($timestamp)) {
            $seed = substr((string)$timestamp, 0, -2);
            $expected = Hash::compute($seed . $rbfid)->toBase64();
            if ($token !== $expected) $this->jsonResponse(['ok' => false, 'error' => 'Token invalido'], 401);
        } else {
            $validation = TotpValidator::validate($this->db->getDb(), $rbfid, $token);
            if (!$validation['ok']) $this->jsonResponse($validation, 401);
        }

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

    private function handleUpload(array $body): void {
        $rbfid = $body['rbfid'] ?? '';
        $totp = $body['totp_token'] ?? '';
        $filename = $body['filename'] ?? '';
        $chunkIdx = (int)($body['chunk_index'] ?? 0);
        $hash = $body['hash_xxh3'] ?? '';
        $data = $body['data'] ?? '';

        $validation = $this->auth->validate($rbfid, $totp);
        if (!$validation['ok']) $this->jsonResponse($validation, 401);

        if (empty($filename) || empty($hash) || empty($data)) {
            $this->jsonResponse(['ok' => false, 'error' => 'Faltan campos'], 400);
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

    private function handleStatus(array $body): void {
        $rbfid = $body['rbfid'] ?? '';
        if (empty($rbfid)) $this->jsonResponse(['ok' => false, 'error' => 'RBFID requerido'], 400);
        
        $client = $this->client->getClientStatus($rbfid);
        if (!$client) $this->jsonResponse(['ok' => false, 'error' => 'No encontrado'], 404);
        
        $files = $this->client->getClientFiles($rbfid);
        $this->jsonResponse(['ok' => true, 'client' => $client, 'files' => $files]);
    }

    private function handleHistory(array $body): void {
        $rbfid = $body['rbfid'] ?? '';
        $limit = (int)($body['limit'] ?? 50);
        if (empty($rbfid)) $this->jsonResponse(['ok' => false, 'error' => 'RBFID requerido'], 400);
        
        $history = $this->db->fetchAll("SELECT id, file_name, chunk_count, updated_at FROM ar_files WHERE rbfid = :rbfid ORDER BY updated_at DESC LIMIT :limit", [':rbfid' => $rbfid, ':limit' => $limit]);
        $this->jsonResponse(['ok' => true, 'history' => $history]);
    }

    private function handleDownload(array $body): void {
        $exe_path = '/srv/zigRespaldoSucursal/zig-out/bin/ar.exe';
        if (!file_exists($exe_path)) $this->jsonResponse(['ok' => false, 'error' => 'No encontrado'], 404);

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="ar.exe"');
        header('Content-Length: ' . filesize($exe_path));
        readfile($exe_path);
        exit;
    }

    private function handleQueryChunks(array $body): void {
        $rbfid = $body['rbfid'] ?? '';
        $filename = $body['filename'] ?? '';
        $totp = $body['totp_token'] ?? '';

        $validation = TotpValidator::validate($this->db->getDb(), $rbfid, $totp);
        if (!$validation['ok']) $this->jsonResponse($validation, 401);

        $paths = $this->getClientPaths($rbfid);
        if (!$paths) $this->jsonResponse(['ok' => false, 'error' => 'No encontrado'], 404);

        $faltantes = $this->db->fetchAll("SELECT chunk_index, hash_xxh3, status, error_count FROM ar_file_hashes WHERE rbfid = :rbfid AND file_name = :file AND status != 'received' ORDER BY chunk_index", [':rbfid' => $rbfid, ':file' => $filename]);

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
