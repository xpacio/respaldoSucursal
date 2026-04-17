<?php declare(strict_types=1);
namespace App\Api;

require_once __DIR__ . '/shared.php';
use App\DB;
use App\Config;
use App\Totp;
use App\Log;
use App\Storage;
use App\JsonRes;

class Server
{
    use JsonRes;
    private DB $db;
    public function __construct()
    {
        $this->db = new DB(Config::getDb());
        Log::init(__DIR__ . '/logs', true);
        Log::add('Request started');
    }

    public function route(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS')
            exit(0);
        $path = trim(preg_replace('#^/api(/index\.php)?#', '', $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)), '/');
        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        $action = explode('/', $path)[0] ?? ($body['action'] ?? 'sync');
        $rbfid = explode('/', $path)[1] ?? ($_SERVER['HTTP_X_RBFID'] ?? ($body['rbfid'] ?? ''));
        $token = $_SERVER['HTTP_X_TOTP_TOKEN'] ?? ($_SERVER['HTTP_X_TOKEN'] ?? ($body['totp_token'] ?? ''));
        Log::add("Action: $action | RBFID: $rbfid");

        if ($action !== 'health' && (empty($rbfid) || empty($token)))
            self::err('Auth required', 401);
        if ($action !== 'health' && !Totp::verify($this->db, $rbfid, $token))
            self::err('Token dinamico invalido', 401);

        match ($action) {
            'health' => self::json(['ok' => true, 'status' => 'healthy']),
            'init' => $this->init($rbfid),
            'register' => $this->register($rbfid),
            'config' => $this->config($rbfid, $body),
            'sync' => $this->sync($rbfid, $body),
            'upload' => $this->upload($rbfid, $body, explode('/', $path)),
            'status' => $this->status($rbfid),
            'history' => $this->history($rbfid, $body),
            'download' => $this->download(),
            default => self::err("Action '$action' invalid", 400)
        };
    }

    private function init(string $r): void
    {
        if (empty($r))
            self::err('RBFID required');
        $c = $this->db->q("SELECT enabled FROM clients WHERE rbfid = :r", [':r' => $r]);
        if (!$c) {
            $this->db->exec("INSERT INTO clients (rbfid, enabled, created_at) VALUES (:r, false, NOW())", [':r' => $r]);
            Log::info("Client $r auto-registered (latent)");
            self::json(['ok' => true, 'rbfid' => $r, 'enabled' => false, 'latent' => true]);
        }
        Log::info("Client $r initialized");
        self::json(['ok' => true, 'rbfid' => $r, 'enabled' => (bool) ($c['enabled'] ?? false)]);
    }
    private function register(string $r): void
    {
        $this->db->exec("INSERT INTO clients (rbfid, enabled, created_at) VALUES (:r, true, NOW()) ON CONFLICT (rbfid) DO UPDATE SET enabled = true", [':r' => $r]);
        Log::info("Client $r registered");
        self::json(['ok' => true]);
    }
    private function config(string $r, array $b): void
    {
        $files = array_column($this->db->qa("SELECT file_name FROM ar_global_files WHERE enabled = true"), 'file_name');
        $sv = substr(md5(implode(',', $files)), 0, 8);
        $res = ['ok' => true, 'rbfid' => $r, 'files_version' => $sv];
        if (($b['files_version'] ?? '') !== $sv)
            $res['files'] = $files;
        Log::add("Config sent (v: $sv)");
        self::json($res);
    }
    private function sync(string $r, array $b): void
    {
        try {
            $this->db->begin();
            $syncId = $this->db->insert("INSERT INTO ar_sync_history (rbfid, status, started_at) VALUES (:r, 'pending', NOW())", [':r' => $r]);
            $paths = $this->paths($r);
            $needs = [];
            foreach ($b['files'] ?? [] as $f) {
                $name = $f['filename'] ?? '';
                $hash = $f['hash_completo'] ?? '';
                if (!$name || !$hash)
                    continue;
                $srv = $this->db->q("SELECT hash_xxh3, file_mtime FROM ar_files WHERE rbfid = :r AND file_name = :n", [':r' => $r, ':n' => $name]);
                if ($srv && !empty($f['mtime']) && (int) $srv['file_mtime'] > (int) $f['mtime'])
                    continue;
                if (!$srv || $srv['hash_xxh3'] !== $hash) {
                    $cnt = max(1, count($f['chunk_hashes'] ?? []));
                    $this->db->exec("INSERT INTO ar_files (rbfid, file_name, chunk_count, hash_esperado, updated_at, file_size, file_mtime) VALUES (:r, :n, :c, :h, NOW(), :s, :m) ON CONFLICT (rbfid, file_name) DO UPDATE SET chunk_count = :c, hash_xxh3 = NULL, hash_esperado = :h, updated_at = NOW()", [':r' => $r, ':n' => $name, ':c' => $cnt, ':h' => $hash, ':s' => $f['size'], ':m' => $f['mtime']]);
                    foreach ($f['chunk_hashes'] ?? [] as $idx => $ch)
                        $this->db->exec("INSERT INTO ar_file_hashes (rbfid, file_name, chunk_index, hash_xxh3, status, updated_at) VALUES (:rbfid, :file, :idx, :hash, 'pending', NOW()) ON CONFLICT (rbfid, file_name, chunk_index) DO UPDATE SET hash_xxh3 = EXCLUDED.hash_xxh3, status = CASE WHEN ar_file_hashes.hash_xxh3 = EXCLUDED.hash_xxh3 THEN ar_file_hashes.status ELSE 'pending' END, updated_at = NOW()", [':rbfid' => $r, ':file' => $name, ':idx' => $idx, ':hash' => $ch]);
                }
                $nx = $this->db->q("SELECT chunk_index FROM ar_file_hashes WHERE rbfid = :r AND file_name = :n AND status != 'received' ORDER BY chunk_index LIMIT 1", [':r' => $r, ':n' => $name]);
                if ($nx)
                    $needs[] = ['file' => $name, 'chunk' => (int) $nx['chunk_index'], 'work_path' => $paths['work'] . '/' . $name, 'dest_path' => $paths['base'] . '/' . $name, 'md5' => $hash];
            }
            $this->db->commit();
            Log::info("Sync complete. Pending: " . count($needs));
            self::json(['ok' => true, 'sync_id' => $syncId, 'needs_upload' => $needs, 'rate_delay' => 3000]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            self::err("Sync Error: " . $e->getMessage());
        }
    }
    private function upload(string $r, array $b, array $p): void
    {
        try {
            $this->db->begin();
            $file = $p[1] ?? ($b['filename'] ?? '');
            $idx = max(0, (int) ($p[2] ?? ($b['chunk_index'] ?? 0)));
            $sz = max(0, (int) ($b['size'] ?? 0));
            $hash = $p[3] ?? ($b['hash_xxh3'] ?? '');
            if ($sz > 5368709120)
                self::err('File too large');
            $data = base64_decode($b['data'] ?? '');
            if (!$file || !$data)
                self::err('Missing fields');
            if (strlen($data) > 10485760)
                self::err('Chunk too large');
            $paths = $this->paths($r);
            if (!$paths)
                self::err('Client not found', 404);
            if (!Storage::saveChunk($paths['work'], $file, $idx, $idx * \App\Chunk::size((int) ($b['size'] ?? strlen($data))), $data))
                self::err('Save failed');
            if (hash('xxh3', $data) !== $hash) {
                $this->db->exec("UPDATE ar_file_hashes SET status='failed' WHERE rbfid=:r AND file_name=:f AND chunk_index=:i", [':r' => $r, ':f' => $file, ':i' => $idx]);
                $this->db->commit();
                Log::error("Chunk $idx hash mismatch for $file");
                self::json(['ok' => true, 'status' => 'failed']);
            }
            $this->db->exec("UPDATE ar_file_hashes SET status='received' WHERE rbfid=:r AND file_name=:f AND chunk_index=:i", [':r' => $r, ':f' => $file, ':i' => $idx]);
            $nx = $this->db->q("SELECT chunk_index FROM ar_file_hashes WHERE rbfid=:r AND file_name=:f AND status!='received' LIMIT 1", [':r' => $r, ':f' => $file]);
            if ($nx) {
                $this->db->commit();
                Log::debug("Chunk $idx received ($file)");
                self::json(['ok' => true, 'status' => 'received', 'next_chunk' => (int) $nx['chunk_index']]);
            }
            $this->finalize($r, $file, $paths);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            self::err("Upload Error: " . $e->getMessage());
        }
    }
    private function finalize(string $r, string $f, array $paths): void
    {
        $wp = $paths['work'] . '/' . $f;
        $esp = $this->db->q("SELECT hash_esperado FROM ar_files WHERE rbfid=:r AND file_name=:f", [':r' => $r, ':f' => $f])['hash_esperado'];
        $act = \App\Hash::computeFile($wp);
        if ($esp === $act) {
            if (!is_dir($paths['base']))
                mkdir($paths['base'], 0755, true);
            $dp = $paths['base'] . '/' . $f;
            if (file_exists($dp))
                unlink($dp);
            rename($wp, $dp);
            $this->db->exec("UPDATE ar_files SET hash_xxh3=:h WHERE rbfid=:r AND file_name=:f", [':h' => $act, ':r' => $r, ':f' => $f]);
            Log::add("File finalized & verified");
            self::json(['ok' => true, 'status' => 'complete']);
        }
        Log::add("Final hash mismatch");
        self::json(['ok' => true, 'status' => 'error']);
    }
    private function status(string $r): void
    {
        $c = $this->db->q("SELECT * FROM clients WHERE rbfid=:r", [':r' => $r]);
        if (!$c)
            self::err('Not found', 404);
        self::json(['ok' => true, 'client' => $c, 'files' => $this->db->qa("SELECT file_name, updated_at FROM ar_files WHERE rbfid=:r", [':r' => $r])]);
    }
    private function history(string $r, array $b): void
    {
        self::json(['ok' => true, 'history' => $this->db->qa("SELECT id, file_name, updated_at FROM ar_files WHERE rbfid=:r ORDER BY updated_at DESC LIMIT :l", [':r' => $r, ':l' => (int) ($b['limit'] ?? 50)])]);
    }
    private function download(): void
    {
        $p = '/srv/zigRespaldoSucursal/zig-out/bin/ar.exe';
        if (!file_exists($p))
            self::err('File not found', 404);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="ar.exe"');
        header('Content-Length: ' . filesize($p));
        readfile($p);
        exit;
    }
    private function paths(string $r): ?array
    {
        $c = $this->db->q("SELECT emp, plaza FROM clients WHERE rbfid=:r", [':r' => $r]);
        if (!$c)
            return null;
        $e = $c['emp'] ?: '_';
        $p = $c['plaza'] ?: '_';
        return ['emp' => $e, 'plaza' => $p, 'base' => "/srv/qbck/$e/$p/$r", 'work' => "/tmp/ar/$r"];
    }
}

// --- Execution ---
try {
    (new Server())->route();
} catch (\Throwable $e) {
    \App\Log::add('Fatal: ' . $e->getMessage());
    \App\Api\Server::err('Internal Server Error', 500);
}