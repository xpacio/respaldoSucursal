<?php
/**
 * Project-specific server entry point (Consolidated)
 */

namespace {
    require_once __DIR__ . '/shared.php';
}

namespace App\Api {
    use App\Services\StorageService;
    use App\Services\DatabaseService;
    use App\Traits\ResponseTrait;
    use App\TotpValidator;
    use App\Utilities\Chunk;
    use App\Hash;
    use Exception;

    class ArCore {
        use ResponseTrait;
        private static ?ArCore $instance = null;
        public StorageService $storage;
        public DatabaseService $db;
        public AuthService $auth;
        public UploadService $upload;
        public ClientService $client;
        public ChunkService $chunk;
        public SyncService $sync;

        private function __construct($dbConn) {
            $this->storage = new StorageService();
            $this->db = new DatabaseService($dbConn);
            $this->auth = new AuthService($dbConn);
            $this->upload = new UploadService($this->db, $this->storage);
            $this->client = new ClientService($this->db);
            $this->chunk = new ChunkService($this->db);
            $this->sync = new SyncService($this->db);
        }

        public static function getInstance($db = null): ArCore {
            if (self::$instance === null && $db !== null) self::$instance = new ArCore($db);
            return self::$instance;
        }

        public function handleRequest(string $path): void {
            $segments = array_values(array_filter(explode('/', $path)));
            $raw = file_get_contents('php://input');
            $body = !empty($raw) ? json_decode($raw, true) : [];
            if (!is_array($body)) $body = [];

            $action = $segments[0] ?? ($body['action'] ?? 'sync');
            $rbfid = $segments[1] ?? ($_SERVER['HTTP_X_RBFID'] ?? ($body['rbfid'] ?? ''));
            $token = $_SERVER['HTTP_X_TOTP_TOKEN'] ?? ($_SERVER['HTTP_X_TOKEN'] ?? ($body['totp_token'] ?? ''));

            $context = ['action' => $action, 'rbfid' => $rbfid, 'token' => $token, 'body' => $body, 'params' => array_slice($segments, 1)];

            if (!in_array($action, ['health', 'download', 'init'])) {
                if (empty($rbfid) || empty($token)) $this->jsonResponse(['ok' => false, 'error' => 'Auth required'], 401);
                $ts = $_SERVER['HTTP_X_TIMESTAMP'] ?? ($body['timestamp'] ?? '');
                if (!empty($ts)) {
                    $expected = TotpValidator::generate($rbfid, (int)$ts);
                    if ($token !== $expected) $this->jsonResponse(['ok' => false, 'error' => 'Token dinamico invalido'], 401);
                } else {
                    $val = TotpValidator::validate($this->db->getDb(), $rbfid, $token);
                    if (!$val['ok']) $this->jsonResponse($val, 401);
                }
            }

            switch ($action) {
                case 'health':   $this->jsonResponse(['ok' => true, 'status' => 'healthy']); break;
                case 'init':     $this->handleInit($context); break;
                case 'register': $this->handleRegister($context); break;
                case 'config':   $this->handleConfig($context); break;
                case 'sync':     $this->handleSync($context); break;
                case 'upload':   $this->handleUpload($context); break;
                case 'status':   $this->handleStatus($context); break;
                case 'history':  $this->handleHistory($context); break;
                case 'download': $this->handleDownload($context); break;
                default:         $this->jsonResponse(['ok' => false, 'error' => "Action '$action' invalid"], 400);
            }
        }

        private function handleInit($ctx): void {
            $rbfid = $ctx['rbfid']; if (empty($rbfid)) $this->jsonResponse(['ok' => false, 'error' => 'RBFID required'], 400);
            $c = $this->client->getClientStatus($rbfid);
            if (!$c) {
                $this->db->execute("INSERT INTO ar_clients (rbfid, enabled, registered_at) VALUES (:rbfid, false, NOW())", [':rbfid' => $rbfid]);
                $this->db->execute("INSERT INTO clients (rbfid, enabled, created_at) VALUES (:rbfid, false, NOW())", [':rbfid' => $rbfid]);
                $this->jsonResponse(['ok' => true, 'rbfid' => $rbfid, 'enabled' => false, 'latent' => true]);
            }
            $this->jsonResponse(['ok' => true, 'rbfid' => $rbfid, 'enabled' => $c['enabled'] ?? false]);
        }

        private function handleRegister($ctx): void {
            try { $this->client->registerClient($ctx['rbfid']); $this->jsonResponse(['ok' => true]); }
            catch (Exception $e) { $this->jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500); }
        }

        private function handleConfig($ctx): void {
            $cv = $ctx['body']['files_version'] ?? '';
            $files = array_column($this->db->fetchAll("SELECT file_name FROM ar_global_files WHERE enabled = true"), 'file_name');
            $sv = substr(md5(implode(',', $files)), 0, 8);
            $res = ['ok' => true, 'rbfid' => $ctx['rbfid'], 'files_version' => $sv];
            if ($cv !== $sv) $res['files'] = $files;
            $this->jsonResponse($res);
        }

        private function handleSync($ctx): void {
            $rbfid = $ctx['rbfid']; $files = $ctx['body']['files'] ?? [];
            $syncId = $this->db->insert("INSERT INTO ar_sync_history (rbfid, status, started_at) VALUES (:rbfid, 'pending', NOW())", [':rbfid' => $rbfid]);
            $paths = $this->getClientPaths($rbfid); $needsUpload = [];
            foreach ($files as $f) {
                $name = $f['filename'] ?? ''; $hash = $f['hash_completo'] ?? ''; $cHashes = $f['chunk_hashes'] ?? [];
                if (!$name || !$hash) continue;
                $srv = $this->db->fetchOne("SELECT hash_xxh3, file_mtime FROM ar_files WHERE rbfid = :rbfid AND file_name = :file", [':rbfid' => $rbfid, ':file' => $name]);
                if ($srv && (int)($f['mtime'] ?? 0) > 0 && (int)$srv['file_mtime'] > (int)$f['mtime']) continue;
                if (!$srv || $srv['hash_xxh3'] !== $hash) {
                    $cnt = max(1, count($cHashes));
                    $this->db->execute("INSERT INTO ar_files (rbfid, file_name, chunk_count, hash_esperado, updated_at, file_size, file_mtime) VALUES (:rbfid, :file, :cnt, :esp, NOW(), :sz, :mt) ON CONFLICT (rbfid, file_name) DO UPDATE SET chunk_count = :cnt, hash_xxh3 = NULL, hash_esperado = :esp, updated_at = NOW()", [':rbfid' => $rbfid, ':file' => $name, ':cnt' => $cnt, ':esp' => $hash, ':sz' => $f['size'], ':mt' => $f['mtime']]);
                    foreach ($cHashes as $idx => $ch) $this->db->execute("INSERT INTO ar_file_hashes (rbfid, file_name, chunk_index, hash_xxh3, status, updated_at) VALUES (:rbfid, :file, :idx, :hash, 'pending', NOW()) ON CONFLICT (rbfid, file_name, chunk_index) DO UPDATE SET hash_xxh3 = :hash, status = 'pending', updated_at = NOW()", [':rbfid' => $rbfid, ':file' => $name, ':idx' => $idx, ':hash' => $ch]);
                }
                $next = $this->db->fetchOne("SELECT chunk_index FROM ar_file_hashes WHERE rbfid = :rbfid AND file_name = :file AND status != 'received' ORDER BY chunk_index LIMIT 1", [':rbfid' => $rbfid, ':file' => $name]);
                if ($next) $needsUpload[] = ['file' => $name, 'chunk' => (int)$next['chunk_index'], 'work_path' => $paths['work_dir'].'/'.$name, 'dest_path' => $paths['base_dir'].'/'.$name, 'md5' => $hash];
            }
            $this->jsonResponse(['ok' => true, 'sync_id' => $syncId, 'needs_upload' => $needsUpload, 'rate_delay' => 3000]);
        }

        private function handleUpload($ctx): void {
            $rbfid = $ctx['rbfid']; $body = $ctx['body']; $p = $ctx['params'];
            $file = $p[1] ?? ($body['filename'] ?? ''); $idx = (int)($p[2] ?? ($body['chunk_index'] ?? 0)); $hash = $p[3] ?? ($body['hash_xxh3'] ?? ''); $data = $body['data'] ?? '';
            if (!$file || !$hash || !$data) $this->jsonResponse(['ok' => false, 'error' => 'Missing fields'], 400);
            $paths = $this->getClientPaths($rbfid); if (!$paths) $this->jsonResponse(['ok' => false, 'error' => 'Not found'], 404);
            $res = $this->upload->handle($rbfid, $file, $idx, $hash, $data, $paths);
            $this->jsonResponse(['ok' => true, 'file' => $file, 'chunk' => $idx, ...$res]);
        }

        private function handleStatus($ctx): void {
            $c = $this->client->getClientStatus($ctx['rbfid']); if (!$c) $this->jsonResponse(['ok' => false], 404);
            $this->jsonResponse(['ok' => true, 'client' => $c, 'files' => $this->client->getClientFiles($ctx['rbfid'])]);
        }

        private function handleHistory($ctx): void {
            $h = $this->db->fetchAll("SELECT id, file_name, updated_at FROM ar_files WHERE rbfid = :rbfid ORDER BY updated_at DESC LIMIT :l", [':rbfid' => $ctx['rbfid'], ':l' => (int)($ctx['body']['limit'] ?? 50)]);
            $this->jsonResponse(['ok' => true, 'history' => $h]);
        }

        private function handleDownload($ctx): void {
            $p = '/srv/zigRespaldoSucursal/zig-out/bin/ar.exe';
            if (!file_exists($p)) $this->jsonResponse(['ok' => false], 404);
            header('Content-Type: application/octet-stream'); header('Content-Disposition: attachment; filename="ar.exe"'); header('Content-Length: '.filesize($p));
            readfile($p); exit;
        }

        private function getClientPaths($rbfid): ?array {
            $c = $this->db->fetchOne("SELECT emp, plaza FROM clients WHERE rbfid = :rbfid", [':rbfid' => $rbfid]);
            if (!$c) return null; $e = $c['emp'] ?: '_'; $p = $c['plaza'] ?: '_';
            return ['emp' => $e, 'plaza' => $p, 'base_dir' => "/srv/qbck/$e/$p/$rbfid", 'work_dir' => "/tmp/ar/$rbfid"];
        }
    }

    class AuthService {
        private $db; public function __construct($db) { $this->db = $db; }
        public function validate($id, $t) { return TotpValidator::validate($this->db, $id, $t); }
    }

    class ChunkService {
        private $db; public function __construct($db) { $this->db = $db; }
    }

    class ClientService {
        private $db; public function __construct($db) { $this->db = $db; }
        public function getClientStatus($id) { return $this->db->fetchOne("SELECT c.*, ar.registered_at FROM clients c LEFT JOIN ar_clients ar ON ar.rbfid = c.rbfid WHERE c.rbfid = :id", [':id' => $id]); }
        public function getClientFiles($id) { return $this->db->fetchAll("SELECT file_name, updated_at FROM ar_files WHERE rbfid = :id", [':id' => $id]); }
        public function registerClient($id) { $this->db->execute("INSERT INTO ar_clients (rbfid, enabled, registered_at) VALUES (:id, true, NOW()) ON CONFLICT (rbfid) DO UPDATE SET enabled = true", [':id' => $id]); }
    }

    class SyncService {
        private $db; public function __construct($db) { $this->db = $db; }
    }

    class UploadService {
        private $db; private $storage; public function __construct($db, $s) { $this->db = $db; $this->storage = $s; }
        public function handle($rbfid, $file, $idx, $hash, $data, $paths): array {
            $bin = base64_decode($data); if (!$bin) throw new Exception('Invalid data');
            $f = $this->db->fetchOne("SELECT file_size FROM ar_files WHERE rbfid = :rbfid AND file_name = :file", [':rbfid' => $rbfid, ':file' => $file]);
            $sz = (int)($f['file_size'] ?? strlen($bin));
            $chunkSz = Chunk::calculateChunkSize($sz); $off = $idx * $chunkSz;
            if (!$this->storage->saveChunk($paths['work_dir'], $file, $idx, $off, $bin)) throw new Exception('Save failed');
            if (hash('xxh3', $bin) !== $this->db->fetchOne("SELECT hash_xxh3 FROM ar_file_hashes WHERE rbfid = :rbfid AND file_name = :file AND chunk_index = :idx", [':rbfid' => $rbfid, ':file' => $file, ':idx' => $idx])['hash_xxh3']) {
                $this->db->execute("UPDATE ar_file_hashes SET status = 'failed' WHERE rbfid = :rbfid AND file_name = :file AND chunk_index = :idx", [':rbfid' => $rbfid, ':file' => $file, ':idx' => $idx]);
                return ['status' => 'failed'];
            }
            $this->db->execute("UPDATE ar_file_hashes SET status = 'received' WHERE rbfid = :rbfid AND file_name = :file AND chunk_index = :idx", [':rbfid' => $rbfid, ':file' => $file, ':idx' => $idx]);
            $next = $this->db->fetchOne("SELECT chunk_index FROM ar_file_hashes WHERE rbfid = :rbfid AND file_name = :file AND status != 'received' LIMIT 1", [':rbfid' => $rbfid, ':file' => $file]);
            return $next ? ['status' => 'received', 'next_chunk' => $next['chunk_index']] : $this->finalize($rbfid, $file, $paths['work_dir'], $paths['base_dir']);
        }
        private function finalize($rbfid, $file, $w, $b): array {
            $wp = $w.'/'.$file; $esp = $this->db->fetchOne("SELECT hash_esperado FROM ar_files WHERE rbfid = :rbfid AND file_name = :file", [':rbfid' => $rbfid, ':file' => $file])['hash_esperado'];
            $act = $this->storage->getHashStreaming($wp);
            if ($esp === $act) {
                if (!is_dir($b)) mkdir($b, 0755, true); $dp = $b.'/'.$file;
                if (file_exists($dp)) unlink($dp); rename($wp, $dp);
                $this->db->execute("UPDATE ar_files SET hash_xxh3 = :h WHERE rbfid = :r AND file_name = :f", [':h' => $act, ':r' => $rbfid, ':f' => $file]);
                return ['status' => 'complete'];
            }
            return ['status' => 'error'];
        }
    }
}

namespace App\Services {
    class StorageService {
        public function saveChunk($w, $f, $i, $o, $d): bool {
            if (!is_dir($w)) mkdir($w, 0755, true); $p = $w.'/'.$f;
            $fh = fopen($p, 'c+b'); if (!$fh) return false;
            if (flock($fh, LOCK_EX)) { fseek($fh, $o); fwrite($fh, $d); fflush($fh); flock($fh, LOCK_UN); fclose($fh); return true; }
            fclose($fh); return false;
        }
        public function getHashStreaming($p): string {
            $ctx = hash_init('xxh3'); $fh = fopen($p, 'rb');
            while (!feof($fh)) hash_update($ctx, fread($fh, 8192));
            fclose($fh); return hash_final($ctx);
        }
    }
}

namespace {
    use App\Logger;
    use App\Config\Database;
    use App\Api\ArCore;

    // --- Entry Point Logic ---
    $logDir = __DIR__ . '/logs';
    $verbose = !isset($_GET['quiet']) || $_GET['quiet'] !== '1';
    Logger::init($logDir, $verbose);

    header("Access-Control-Allow-Origin: *");
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, X-User-Id, X-TOTP-Token, Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

    $cfg = \App\Config::getDbConfig();
    $db = new Database($cfg);
    
    $path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $cleanPath = preg_replace('#^/api(/index\.php)?#', '', $path);
    $resource = ltrim($cleanPath, '/') ?: 'health';

    try {
        $api = ArCore::getInstance($db);
        $api->handleRequest($resource);
    } catch (\Exception $e) {
        Logger::err("Entry point error: " . $e->getMessage());
        header('Content-Type: application/json'); http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Internal Server Error']);
    }
}
