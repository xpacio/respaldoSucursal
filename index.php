<?php declare(strict_types=1);
namespace App\Api;

require_once __DIR__ . '/shared_server.php';
use App\DB;
use App\Config;
use App\TotpServer as Totp;
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
        
        // Obtener body JSON
        $body = [];
        $input = file_get_contents('php://input');
        if (!empty($input)) {
            $body = json_decode($input, true) ?: [];
        }
        
        $pathInfo = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : (isset($_SERVER['REQUEST_URI']) ? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '/');
        $path = trim(preg_replace('#^/api(/index\.php)?#', '', $pathInfo), '/');
        $parts = explode('/', $path);
        $action = (!empty($parts[0])) ? $parts[0] : (isset($body['action']) ? $body['action'] : 'sync');
        $rbfid = (!empty($parts[1])) ? $parts[1] : (isset($_SERVER['HTTP_X_RBFID']) ? $_SERVER['HTTP_X_RBFID'] : (isset($body['rbfid']) ? $body['rbfid'] : ''));
        $token = isset($_SERVER['HTTP_X_TOTP_TOKEN']) ? $_SERVER['HTTP_X_TOTP_TOKEN'] : (isset($_SERVER['HTTP_X_TOKEN']) ? $_SERVER['HTTP_X_TOKEN'] : (isset($body['totp_token']) ? $body['totp_token'] : ''));
        
        $action = strtolower(trim($action));
        Log::add("Action: [$action] | RBFID: $rbfid | Path: $path");

        if ($action !== 'health' && (empty($rbfid) || empty($token))) {
            Log::error("Auth failed: Missing RBFID or Token");
            self::err('Auth required', 401);
        }
        if ($action !== 'health' && !Totp::verify($this->db, $rbfid, $token)) {
            Log::error("Auth failed: Invalid TOTP token for $rbfid");
            self::err('Token dinamico invalido', 401);
        }

        switch ($action) {
            case 'health': self::json(['ok' => true, 'status' => 'healthy']); break;
            case 'init': $this->init($rbfid); break;
            case 'register': $this->register($rbfid); break;
            case 'config': $this->config($rbfid, $body); break;
            case 'sync': $this->sync($rbfid, $body); break;
            case 'upload': $this->upload($rbfid, $body, explode('/', $path)); break;
            case 'missing': $this->missing($rbfid, $body); break;
            case 'status': $this->status($rbfid); break;
            case 'history': $this->history($rbfid, $body); break;
            case 'download': $this->download(); break;
            case 'schedule': $this->schedule($rbfid, $body); break;
            case 'service_result': $this->serviceResult($rbfid, $body); break;
            case 'heartbeat': $this->heartbeat($rbfid, $body); break;
            case 'metrics': $this->metrics($rbfid, $body); break;
            case 'service_config': $this->serviceConfig($rbfid, $body); break;
            case 'download_list': $this->downloadList($rbfid, $body); break;
            case 'download_file': $this->downloadFile($rbfid, $body); break;
            default: self::err("Action '$action' invalid", 400);
        }
    }

    private function init(string $r): void
    {
        if (empty($r))
            self::err('RBFID required');
        $c = $this->db->q("SELECT enabled FROM clients WHERE rbfid = :r", [':r' => $r]);
        if (!$c) {
            $this->db->exec("INSERT INTO clients (rbfid, enabled) VALUES (:r, false)", [':r' => $r]);
            Log::info("Client $r auto-registered (latent)");
            self::json(['ok' => true, 'rbfid' => $r, 'enabled' => false, 'latent' => true]);
        }
        Log::info("Client $r initialized (exists: " . ($c ? 'yes' : 'no') . ")");
        self::json(['ok' => true, 'rbfid' => $r, 'enabled' => (bool) ($c['enabled'] ?? false)]);
    }
    private function register(string $r): void
    {
        $this->db->exec("INSERT INTO clients (rbfid, enabled) VALUES (:r, true) ON CONFLICT (rbfid) DO UPDATE SET enabled = true", [':r' => $r]);
        Log::info("Client $r registered");
        self::json(['ok' => true]);
    }
    private function config(string $r, array $b): void
    {
        $files = array_column($this->db->qa("SELECT file_name FROM catalog WHERE enabled = true"), 'file_name');
        $sv = substr(md5(implode(',', $files)), 0, 8);
        $res = ['ok' => true, 'rbfid' => $r, 'files_version' => $sv];
        if (($b['files_version'] ?? '') !== $sv) {
            $sentVersion = $b['files_version'] ?? 'none';
            Log::debug("Config: Versions mismatch (sent: $sentVersion, current: $sv). Sending " . count($files) . " files.");
            $res['files'] = $files;
        } else {
            Log::debug("Config: Versions match ($sv). No files sent.");
        }
        Log::info("Config sent to $r (v: $sv)");
        self::json($res);
    }
    private function sync(string $r, array $b): void
    {
        try {
            $this->db->begin();
            $paths = $this->paths($r);
            $needs = [];
            
            foreach ($b['files'] ?? [] as $f) {
                $name = $f['filename'] ?? '';
                $hash = $f['hash_completo'] ?? '';
                $chunkHashes = $f['chunk_hashes'] ?? [];
                $fileSize = $f['size'] ?? 0;
                $fileMtime = $f['mtime'] ?? 0;
                
                if (!$name || !$hash || empty($chunkHashes))
                    continue;
                    
                $srv = $this->db->q("SELECT file_hash, file_mtime, status FROM files WHERE rbfid = :r AND file_name = :n", [':r' => $r, ':n' => $name]);
                
                // Si el archivo estaba marcado como 'missing' pero el cliente lo envió, 
                // continuaremos para actualizar su estado a 'completed' o 'pending'.
                
                // REGLA: El hash es el árbitro final. Si el hash es idéntico, el archivo no cambió.
                // El mtime solo se usa como dato informativo, nunca para decidir si se sube.
                if ($srv && $srv['file_hash'] === $hash && $srv['status'] === 'completed') {
                    Log::debug("Sync: Skipping $name (hash identical, file unchanged)");
                    continue;
                }
                
                // OPTIMIZACIÓN: Si el archivo ya existe completado en destino, copiarlo a work para patching
                $destFile = $paths['base'] . '/' . $name;
                $workFile = $paths['work'] . '/' . $name;
                $hasExistingFile = false;
                
                if ($srv && $srv['status'] === 'completed' && file_exists($destFile)) {
                    Log::debug("Sync: File $name exists at destination, copying to work for patching");
                    if (!is_dir($paths['work'])) {
                        mkdir($paths['work'], 0755, true);
                    }
                    if (copy($destFile, $workFile)) {
                        $hasExistingFile = true;
                        Log::debug("Sync: Copied $name from destination to work directory");
                    }
                }
                
                // Si el hash cambió (o no existe) → necesitamos actualizar
                if (!$srv || $srv['file_hash'] !== $hash) {
                    $cnt = count($chunkHashes);
                    Log::info("Sync: File $name needs update ($cnt chunks)");
                    
                    // Eliminar registros antiguos
                    $this->db->exec("DELETE FROM file_chunks WHERE rbfid = :r AND file_name = :n", [':r' => $r, ':n' => $name]);
                    
                    // OPTIMIZACIÓN: Si tenemos archivo existente, comparar chunks individualmente
                    $pendingChunks = $cnt; // Por defecto, todos pendientes
                    $firstPendingChunk = 0;
                    
                    if ($hasExistingFile && file_exists($workFile)) {
                        $fh_truncate = fopen($workFile, 'r+b');
                        if ($fh_truncate) {
                            ftruncate($fh_truncate, $fileSize);
                            fclose($fh_truncate);
                        }

                        $chunkSize = \App\Chunk::size($fileSize);
                        $pendingChunks = 0;
                        $firstPendingChunk = null;
                        
                        Log::info("Sync Patching [$r] $name: Comparing $cnt chunks (Size: $chunkSize)");
                        
                        for ($i = 0; $i < $cnt; $i++) {
                            $offset = $i * $chunkSize;
                            $length = min($chunkSize, $fileSize - $offset);
                            if ($length <= 0) continue;
                            
                            $chunkData = file_get_contents($workFile, false, null, $offset, $length);
                            $chunkHash = \App\Hash::toBase64(hash('xxh3', $chunkData));
                            $expectedHash = $chunkHashes[$i] ?? '';
                            
                            if ($chunkHash === $expectedHash) {
                                $this->db->exec("INSERT INTO file_chunks (rbfid, file_name, chunk_index, chunk_hash, status, updated_at) VALUES (:rbfid, :file, :idx, :hash, 'received', NOW())", 
                                    [':rbfid' => $r, ':file' => $name, ':idx' => $i, ':hash' => $expectedHash]);
                            } else {
                                $this->db->exec("INSERT INTO file_chunks (rbfid, file_name, chunk_index, chunk_hash, status, updated_at) VALUES (:rbfid, :file, :idx, :hash, 'pending', NOW())", 
                                    [':rbfid' => $r, ':file' => $name, ':idx' => $i, ':hash' => $expectedHash]);
                                $pendingChunks++;
                                if ($firstPendingChunk === null) $firstPendingChunk = $i;
                                Log::debug("Sync: Chunk $i differs (Local: $chunkHash, Remote: $expectedHash)");
                            }
                        }
                    } else {
                        // Sin archivo existente, todos los chunks pendientes
                        for ($i = 0; $i < $cnt; $i++) {
                            $this->db->exec("INSERT INTO file_chunks (rbfid, file_name, chunk_index, chunk_hash, status, updated_at) VALUES (:rbfid, :file, :idx, :hash, 'pending', NOW())", 
                                [':rbfid' => $r, ':file' => $name, ':idx' => $i, ':hash' => $chunkHashes[$i] ?? '']);
                        }
                    }
                    
                    // Actualizar registro principal del archivo: guardamos el hash meta y ponemos estado pending
                    $this->db->exec("INSERT INTO files (rbfid, file_name, chunk_count, chunk_pending, file_hash, status, updated_at, file_size, file_mtime) VALUES (:r, :n, :c, :p, :h, 'pending', NOW(), :s, :m) ON CONFLICT (rbfid, file_name) DO UPDATE SET chunk_count = :c, chunk_pending = :p, file_hash = :h, status = 'pending', updated_at = NOW()", 
                        [':r' => $r, ':n' => $name, ':c' => $cnt, ':p' => $pendingChunks, ':h' => $hash, ':s' => $fileSize, ':m' => $fileMtime]);
                    
                    Log::info("Sync: [$r] $name -> Expecting $cnt chunks | Target Hash: $hash");
                }
                
                // Obtener primer chunk pendiente
                $nx = $this->db->q("SELECT chunk_index FROM file_chunks WHERE rbfid = :r AND file_name = :n AND status != 'received' ORDER BY chunk_index LIMIT 1", [':r' => $r, ':n' => $name]);
                if ($nx) {
                $needs[] = ['file' => $name, 'chunk' => (int) $nx['chunk_index'],
                            'work_path' => $paths['work'] . '/' . $name,
                            'dest_path' => $paths['base'] . '/' . $name];
                }
            }
            
            $this->db->commit();
            Log::info("Sync complete for $r. Pending files: " . count($needs));
            self::json(['ok' => true, 'needs_upload' => $needs, 'rate_delay' => 3000]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            self::err("Sync Error: " . $e->getMessage());
        }
    }
    private function missing(string $r, array $b): void
    {
        try {
            $missingFiles = $b['missing_files'] ?? [];
            if (empty($missingFiles)) {
                self::json(['ok' => true, 'message' => 'No missing files reported']);
                return;
            }
            
            Log::info("Client $r reported " . count($missingFiles) . " missing files: " . implode(', ', $missingFiles));
            
            $this->db->begin();
            
            foreach ($missingFiles as $file) {
                // Normalizar nombre a mayúsculas
                $fileUpper = strtoupper($file);
                
                // Verificar si el archivo ya existe en la base de datos
                $existing = $this->db->q("SELECT status FROM files WHERE rbfid = :r AND file_name = :f", [':r' => $r, ':f' => $fileUpper]);
                
                if ($existing) {
                    // Si existe pero no está como 'missing', actualizar estado
                    if ($existing['status'] !== 'missing') {
                        $this->db->exec("UPDATE files SET status = 'missing', updated_at = NOW() WHERE rbfid = :r AND file_name = :f", 
                            [':r' => $r, ':f' => $fileUpper]);
                        Log::debug("Updated file $fileUpper status to 'missing' for client $r");
                    }
                } else {
                    // Insertar nuevo registro con estado 'missing'
                    $this->db->exec("INSERT INTO files (rbfid, file_name, status, updated_at) VALUES (:r, :f, 'missing', NOW())", 
                        [':r' => $r, ':f' => $fileUpper]);
                    Log::debug("Added missing file $fileUpper for client $r");
                }
            }
            
            $this->db->commit();
            Log::info("Missing files processed for client $r");
            self::json(['ok' => true, 'message' => 'Missing files recorded']);
            
        } catch (\Throwable $e) {
            if (isset($this->db)) {
                $this->db->rollBack();
            }
            Log::error("Error processing missing files: " . $e->getMessage());
            self::err("Missing files error: " . $e->getMessage());
        }
    }
    private function upload(string $r, array $b, array $p): void
    {
        try {
            $this->db->begin();
            $file = $b['filename'] ?? '';
            $idx = max(0, (int) ($b['chunk_index'] ?? 0));
            $sz = max(0, (int) ($b['size'] ?? 0));
            $hash = $b['chunk_hash'] ?? '';
            if ($sz > 5368709120)
                self::err('File too large');
            $data = base64_decode($b['data'] ?? '');
            if (!$file || !$data)
                self::err('Missing fields');
            if (strlen($data) > 10485760)
                self::err('Chunk too large');
            $paths = $this->paths($r);
            if (!$paths) {
                Log::error("Upload: Paths not found for $r");
                self::err('Client not found', 404);
            }
            Log::info("Upload: [$r] $file | Chunk $idx | Size: " . strlen($data) . " bytes");
            if (!Storage::saveChunk($paths['work'], $file, $idx, $idx * \App\Chunk::size((int) ($b['size'] ?? strlen($data))), $data)) {
                Log::error("Upload: Storage::saveChunk failed for $file index $idx");
                self::err('Save failed');
            }
            if (hash('xxh3', $data) !== \App\Hash::fromBase64($hash)) {
                $this->db->exec("UPDATE file_chunks SET status='failed' WHERE rbfid=:r AND file_name=:f", [':r' => $r, ':f' => $file]);
                $this->db->commit();
                Log::error("Chunk $idx hash mismatch for $file");
                self::json(['ok' => true, 'status' => 'failed']);
            }
            // Obtener el chunk_count esperado para este archivo
            $fileInfo = $this->db->q("SELECT chunk_count, chunk_pending FROM files WHERE rbfid=:r AND file_name=:f", [':r' => $r, ':f' => $file]);
            $totalChunks = $fileInfo['chunk_count'] ?? 1;
            $currentPending = $fileInfo['chunk_pending'] ?? 1;
            
            // Decrementar chunk_pending al recibir un chunk exitoso
            $newPending = max(0, $currentPending - 1);
            
            // Buscar siguiente chunk pendiente (no asumir secuencial)
            $nextPending = $this->db->q("SELECT chunk_index FROM file_chunks WHERE rbfid=:r AND file_name=:f AND status != 'received' AND chunk_index > :idx ORDER BY chunk_index LIMIT 1", 
                [':r' => $r, ':f' => $file, ':idx' => $idx]);
            $nextChunk = $nextPending ? (int) $nextPending['chunk_index'] : null;
            
            // Si hay más chunks pendientes
            if ($nextChunk !== null && $newPending > 0) {
                // Actualizar el chunk actual como recibido
                $this->db->exec("UPDATE file_chunks SET status='received', updated_at=NOW() WHERE rbfid=:r AND file_name=:f AND chunk_index=:idx", 
                    [':r' => $r, ':f' => $file, ':idx' => $idx]);
                $this->db->exec("UPDATE files SET chunk_pending=:p WHERE rbfid=:r AND file_name=:f", [':p' => $newPending, ':r' => $r, ':f' => $file]);
                $this->db->commit();
                Log::debug("Chunk $idx received ($file), next: $nextChunk, pending: $newPending");
                self::json(['ok' => true, 'status' => 'received', 'next_chunk' => $nextChunk]);
            } else {
                // Último chunk recibido, marcar como recibido para proceder a finalizar
                $this->db->exec("UPDATE file_chunks SET status='received', updated_at=NOW() WHERE rbfid=:r AND file_name=:f AND chunk_index=:idx", 
                    [':r' => $r, ':f' => $file, ':idx' => $idx]);
                $this->db->exec("UPDATE files SET chunk_pending=0, status='completed' WHERE rbfid=:r AND file_name=:f", [':r' => $r, ':f' => $file]);
                $this->finalize($r, $file, $paths);
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            $this->db->rollBack();
            self::err("Upload Error: " . $e->getMessage());
        }
    }
    private function finalize(string $r, string $f, array $paths): void
    {
        $wp = $paths['work'] . '/' . $f;
        $row = $this->db->q("SELECT file_hash FROM files WHERE rbfid=:r AND file_name=:f", [':r' => $r, ':f' => $f]);
        $target = $row['file_hash'] ?? '';
        $actual = \App\Hash::computeFile($wp);
        $actualBase64 = \App\Hash::toBase64($actual);

        if ($target === $actualBase64) {
            if (!is_dir($paths['base'])) {
                Log::debug("Finalize: Creating base dir {$paths['base']}");
                mkdir($paths['base'], 0755, true);
            }
            $dp = $paths['base'] . '/' . $f;
            if (file_exists($dp)) {
                Log::debug("Finalize: Removing old file $dp");
                unlink($dp);
            }
            Log::info("Finalize: Moving verified file to $dp");
            rename($wp, $dp);
            $this->db->exec("UPDATE files SET status='completed', chunk_pending=0 WHERE rbfid=:r AND file_name=:f", [':r' => $r, ':f' => $f]);
            Log::info("File $f finalized & verified for $r");
            self::json(['ok' => true, 'status' => 'complete']);
            return;
        }

        // Caso de Error de Hash Final
        Log::error("File $f: Final hash mismatch (exp: $target, act: $actualBase64)");
        if (file_exists($wp)) unlink($wp);
        $this->db->exec("UPDATE files SET status='failed' WHERE rbfid=:r AND file_name=:f", [':r' => $r, ':f' => $f]);
        self::json(['ok' => false, 'error' => 'Hash mismatch', 'status' => 'error']);
    }
    private function status(string $r): void
    {
        $c = $this->db->q("SELECT * FROM clients WHERE rbfid=:r", [':r' => $r]);
        if (!$c)
            self::err('Not found', 404);
        self::json(['ok' => true, 'client' => $c, 'files' => $this->db->qa("SELECT file_name, updated_at FROM files WHERE rbfid=:r", [':r' => $r])]);
    }
    private function history(string $r, array $b): void
    {
        self::json(['ok' => true, 'history' => $this->db->qa("SELECT id, file_name, updated_at FROM files WHERE rbfid=:r ORDER BY updated_at DESC LIMIT :l", [':r' => $r, ':l' => (int) ($b['limit'] ?? 50)])]);
    }
    private function schedule(string $r, array $b): void
    {
        $specificService = $b['service'] ?? null;
        
        $sql = "SELECT s.name, s.type, 
                       CASE 
                         WHEN cs.config IS NULL OR cs.config = '{}'::jsonb THEN s.default_config 
                         ELSE cs.config 
                       END as config, 
                       COALESCE(cs.frequency_seconds, s.default_frequency_seconds) as frequency_seconds 
                FROM service_config cs
                JOIN services s ON s.id = cs.service_id
                WHERE cs.client_rbfid = :r AND cs.enabled = true";
        
        $params = [':r' => $r];

        if ($specificService) {
            // Modo Manual: Buscar el servicio solicitado sin importar el horario
            $sql .= " AND s.name = :n";
            $params[':n'] = $specificService;
            $row = $this->db->q($sql, $params);
            
            if (!$row) {
                self::json(['ok' => false, 'error' => 'Servicio no encontrado o deshabilitado']);
                return;
            }
            
            // Actualizar cronograma para este servicio específico
            $this->updateNextExecution($r, $specificService, (int)$row['frequency_seconds']);
            
            Log::info("Debug Config Raw ($specificService): " . ($row['config'] ?? 'NULL'));
            
            $decodedCfg = json_decode($row['config'] ?? '{}', true);
            if ($decodedCfg === null && json_last_error() !== JSON_ERROR_NONE) {
                Log::error("JSON Decode Error: " . json_last_error_msg());
            }

            self::json(['ok' => true, 'name' => $row['name'], 'type' => $row['type'], 'config' => $decodedCfg]);
            return;
        }

        // Modo Orquestador: Solo lo que ya toca ejecutar
        $sql .= " AND (cs.next_execution IS NULL OR cs.next_execution <= NOW())";
        $services = $this->db->qa($sql, $params);
        
        foreach ($services as $svc) {
            $this->updateNextExecution($r, $svc['name'], (int)$svc['frequency_seconds']);
        }
        
        self::json(['ok' => true, 'services' => $services]);
    }

    private function updateNextExecution(string $r, string $serviceName, int $seconds): void
    {
        $this->db->exec("UPDATE service_config 
                        SET next_execution = NOW() + (:s || ' seconds')::interval,
                            last_execution = NOW()
                        FROM services s
                        WHERE service_config.service_id = s.id 
                        AND service_config.client_rbfid = :r 
                        AND s.name = :n", 
                        [':r' => $r, ':n' => $serviceName, ':s' => $seconds]);
    }

    private function serviceResult(string $r, array $b): void
    {
        $name = $b['service'] ?? 'unknown';
        $status = $b['status'] ?? 'unknown';
        $results = $b['results'] ?? [];
        $timeMs = (int)($b['execution_time_ms'] ?? 0);
        
        $this->db->exec("INSERT INTO service_history (client_rbfid, service_name, status, results, execution_time_ms, completed_at)
                        VALUES (:r, :n, :s, :res, :t, NOW())",
                        [':r' => $r, ':n' => $name, ':s' => $status, ':res' => json_encode($results), ':t' => $timeMs]);
                        
        self::json(['ok' => true]);
    }

    private function heartbeat(string $r, array $b): void
    {
        $status = $b['status'] ?? 'unknown';
        $running = $b['services_running'] ?? [];
        $info = $b['system_info'] ?? [];
        
        $this->db->exec("INSERT INTO service_health (client_rbfid, last_heartbeat, orchestrator_status, services_running, system_info)
                        VALUES (:r, NOW(), :s, :run, :info)
                        ON CONFLICT (client_rbfid) DO UPDATE SET
                        last_heartbeat = NOW(), orchestrator_status = :s, services_running = :run, system_info = :info",
                        [':r' => $r, ':s' => $status, ':run' => json_encode($running), ':info' => json_encode($info)]);
                        
        self::json(['ok' => true]);
    }

    private function metrics(string $r, array $b): void
    {
        // Actualizar system_info con nuevas métricas (merge JSONB)
        $this->db->exec("UPDATE service_health SET system_info = system_info || :m, last_heartbeat = NOW() WHERE client_rbfid = :r", 
            [':r' => $r, ':m' => json_encode($b)]);
        self::json(['ok' => true]);
    }

    private function resolvePath(string $tpl, array $ctx): string
    {
        foreach ($ctx as $k => $v)
            $tpl = str_replace("{{$k}}", (string)$v, $tpl);
        return $tpl;
    }

    private function serviceConfig(string $r, array $b): void
    {
        $name = $b['service'] ?? '';
        $row = $this->db->q(
            "SELECT COALESCE(cs.config, s.default_config) as config, 
                    COALESCE(cs.frequency_seconds, s.default_frequency_seconds) as frequency_seconds, 
                    s.type, s.name
             FROM service_config cs JOIN services s ON s.id = cs.service_id
             WHERE cs.client_rbfid = :r AND s.name = :n AND cs.enabled = true",
            [':r' => $r, ':n' => $name]
        );
        if (!$row) self::err("Service '$name' not configured for $r", 404);
        $paths = $this->paths($r);
        $ctx = ['rbfid' => $r, 'emp' => $paths['emp'] ?? '_', 'plaza' => $paths['plaza'] ?? '_'];
        $cfg = json_decode($row['config'] ?? '{}', true) ?: [];
        foreach ($cfg as $k => $v)
            if (is_string($v)) $cfg[$k] = $this->resolvePath($v, $ctx);
        self::json(['ok' => true, 'service' => $row['name'], 'type' => $row['type'], 'config' => $cfg]);
    }

    private function downloadList(string $r, array $b): void
    {
        $serviceName = $b['service'] ?? '';
        $paths = $this->paths($r);
        if (!$paths) self::err('Client not found');
        $ctx = ['rbfid' => $r, 'emp' => $paths['emp'], 'plaza' => $paths['plaza']];

        $row = $this->db->q(
            "SELECT COALESCE(cs.config, s.default_config) as config 
             FROM service_config cs JOIN services s ON s.id = cs.service_id
             WHERE cs.client_rbfid = :r AND s.name = :n",
            [':r' => $r, ':n' => $serviceName]
        );
        $cfg = json_decode($row['config'] ?? '{}', true) ?: [];
        $sourceDir = $this->resolvePath(
            $cfg['server_source'] ?? "/srv/vales/{emp}/{plaza}/{rbfid}",
            $ctx
        );
        if (!is_dir($sourceDir)) { self::json(['ok' => true, 'files' => []]); return; }

        $targetFiles = $cfg['files'] ?? ['EISYENC.DBF', 'EISYPAR.DBF'];
        $files = [];
        foreach ($targetFiles as $f) {
            $p = $sourceDir . '/' . $f;
            if (file_exists($p))
                $files[] = ['filename' => $f, 'size' => filesize($p), 'mtime' => filemtime($p),
                            'hash' => \App\Hash::toBase64(\App\Hash::computeFile($p))];
        }
        self::json(['ok' => true, 'files' => $files]);
    }

    private function downloadFile(string $r, array $b): void
    {
        $filename  = $b['filename'] ?? '';
        $chunkIdx  = (int)($b['chunk_index'] ?? 0);
        $serviceName = $b['service'] ?? '';
        $paths = $this->paths($r);
        if (!$paths) self::err('Client not found');
        $ctx = ['rbfid' => $r, 'emp' => $paths['emp'], 'plaza' => $paths['plaza']];

        $row = $this->db->q(
            "SELECT COALESCE(cs.config, s.default_config) as config 
             FROM service_config cs JOIN services s ON s.id = cs.service_id
             WHERE cs.client_rbfid = :r AND s.name = :n",
            [':r' => $r, ':n' => $serviceName]
        );
        $cfg = json_decode($row['config'] ?? '{}', true) ?: [];
        $sourceDir = $this->resolvePath(
            $cfg['server_source'] ?? "/srv/vales/{emp}/{plaza}/{rbfid}",
            $ctx
        );
        $p = $sourceDir . '/' . $filename;
        if (!file_exists($p)) self::err('File not found', 404);

        $fileSize  = filesize($p);
        $chunkSize = \App\Chunk::size($fileSize);
        $offset    = $chunkIdx * $chunkSize;
        if ($offset >= $fileSize) self::err('Invalid chunk index');

        $data = file_get_contents($p, false, null, $offset, min($chunkSize, $fileSize - $offset));
        self::json(['ok' => true, 'data' => base64_encode($data),
                    'chunk_hash' => \App\Hash::toBase64(hash('xxh3', $data))]);
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
    \App\Log::error("FATAL: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    \App\Api\Server::err('Internal Server Error', 500);
}