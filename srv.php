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
        
        $pathInfo = $_SERVER['PATH_INFO'] ?? (isset($_SERVER['REQUEST_URI']) ? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '/');
        $path = trim(preg_replace('#^/api(/index\.php)?#', '', $pathInfo), '/');
        $parts = explode('/', $path);

        // Estandarización: Acción y RBFID provienen primordialmente de la URI
        $action = strtolower(trim($parts[0] ?? ''));
        $rbfid = $parts[1] ?? $_SERVER['HTTP_X_RBFID'] ?? $body['rbfid'] ?? '';
        $token = $_SERVER['HTTP_X_TOTP_TOKEN'] ?? $_SERVER['HTTP_X_TOKEN'] ?? $body['totp_token'] ?? '';
        
        Log::add("Action: [$action] | RBFID: " . ($rbfid ?: 'none') . " | Path: $path");

        if ($action !== 'health' && $action !== 'download' && (empty($rbfid) || empty($token))) {
            Log::error("Auth failed: Missing RBFID or Token");
            self::err('Auth required', 401);
        }
        if ($action !== 'health' && $action !== 'download' && !Totp::verify($this->db, $rbfid, $token)) {
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
            case 'list_services': $this->listServices($rbfid); break;
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
            $serviceName = $b['service'] ?? '';
            $paths = $this->paths($r, $serviceName);
            Log::info("Sync: Using paths - work: {$paths['work']}, base: {$paths['base']}, service: $serviceName");
            $needs = [];
            
            // Debug: mostrar tamaño de archivo recibido
            $debugSizes = [];
            foreach ($b['files'] ?? [] as $f) {
                $debugSizes[] = ($f['filename'] ?? '?') . '=' . ($f['size'] ?? 0);
            }
            Log::debug("Sync: File sizes from client: " . implode(', ', $debugSizes));
            
            foreach ($b['files'] ?? [] as $f) {
                $name = $f['filename'] ?? '';
                $hash = $f['hash_completo'] ?? '';
                $chunkHashes = $f['chunk_hashes'] ?? [];
                $cnt = count($chunkHashes);
                $fileSize = $f['size'] ?? 0;
                $fileMtime = $f['mtime'] ?? 0;
                
                if (!$name || !$hash || empty($chunkHashes) || $cnt === 0)
                    continue;
                    
                $srv = $this->db->q("SELECT file_hash, file_mtime, status, file_size FROM files WHERE rbfid = :r AND file_name = :n", [':r' => $r, ':n' => $name]);
                
                // Si el archivo estaba marcado como 'missing' pero el cliente lo envió, 
                // continuaremos para actualizar su estado a 'completed' o 'pending'.
                
                $chunkDir = $paths['work'] . '/.chunks/' . $name;
                $destFile = $paths['base'] . '/' . $name;
                $isCompleted = ($srv && $srv['status'] === 'completed' && file_exists($destFile));
                $hashMatches = ($srv && trim((string)$srv['file_hash']) === trim((string)$hash));
                $tempExists = is_dir($chunkDir) && count(glob($chunkDir . '/*')) > 0;

                // Si el hash es igual y el archivo físico existe en destino, SALTAMOS.
                if ($isCompleted && $hashMatches) {
                    Log::debug("Sync: Skipping $name (already exists in destination and hash matches)");
                    continue;
                }

                $sizeChanged = $srv && $srv['file_size'] !== null && (int)$srv['file_size'] !== (int)$fileSize;

                // REINICIO DE SINCRONIZACIÓN: 
                if ($sizeChanged || !$hashMatches || (!$isCompleted && !$tempExists)) {
                    Log::info("Sync: Resetting/Starting $name (Hash match: " . ($hashMatches?'YES':'NO') . ", Completed: " . ($isCompleted?'YES':'NO') . ", Temp: " . ($tempExists?'YES':'NO') . ", SizeChanged: " . ($sizeChanged?'YES':'NO') . ")");
                    
                    $this->db->exec("DELETE FROM file_chunks WHERE rbfid = :r AND file_name = :n", [':r' => $r, ':n' => $name]);
                    
                    $pendingChunks = (int)$cnt; // Por defecto, todos pendientes
                    
                    // CASO ESPECIAL: Archivo en destino, mismo tamaño, pero hash cambió
                    if (!$hashMatches && $isCompleted && file_exists($destFile) && !$sizeChanged) {
                        // Comparar chunks del archivo destino con los nuevos hashes del cliente
                        Log::info("Sync: File unchanged size but hash differs. Comparing dest file chunks for $name");
                        
                        if (!is_dir($chunkDir)) {
                            @mkdir($chunkDir, 0755, true);
                        }
                        
                        $pendingChunks = 0;
                        $chunkSize = \App\Chunk::size($fileSize);
                        
                        $fh = fopen($destFile, 'rb');
                        for ($i = 0; $i < $cnt; $i++) {
                            $expectedHash = $chunkHashes[$i] ?? '';
                            $offset = $i * $chunkSize;
                            $length = min($chunkSize, $fileSize - $offset);
                            
                            if ($length <= 0) continue;
                            
                            fseek($fh, $offset);
                            $chunkData = fread($fh, $length);
                            $chunkHash = \App\Hash::toBase64(hash('xxh3', $chunkData));
                            
                            // Guardar chunk en .chunks/ para reutilización
                            file_put_contents($chunkDir . '/' . $i, $chunkData);
                            
                            if ($chunkHash === $expectedHash) {
                                $this->db->exec("INSERT INTO file_chunks (rbfid, file_name, chunk_index, chunk_hash, status, updated_at) VALUES (:rbfid, :file, :idx, :hash, 'received', NOW())", 
                                        [':rbfid' => $r, ':file' => $name, ':idx' => $i, ':hash' => $expectedHash]);
                            } else {
                                $this->db->exec("INSERT INTO file_chunks (rbfid, file_name, chunk_index, chunk_hash, status, updated_at) VALUES (:rbfid, :file, :idx, :hash, 'pending', NOW())", 
                                        [':rbfid' => $r, ':file' => $name, ':idx' => $i, ':hash' => $expectedHash]);
                                $pendingChunks++;
                                Log::debug("Sync: Chunk $i differs (Local: $chunkHash, Remote: $expectedHash)");
                            }
                        }
                        fclose($fh);
                        
                    } else {
                        // CASO: Tamaño cambió o no hay archivo en destino → limpiar y marcar todos pendientes
                        if (is_dir($chunkDir)) {
                            array_map('unlink', glob($chunkDir . '/*'));
                            rmdir($chunkDir);
                        }
                        
                        Log::info("Sync: Full re-upload needed for $name (size changed or no dest file)");
                        
                        for ($i = 0; $i < $cnt; $i++) {
                            $this->db->exec("INSERT INTO file_chunks (rbfid, file_name, chunk_index, chunk_hash, status, updated_at) VALUES (:rbfid, :file, :idx, :hash, 'pending', NOW())", 
                                [':rbfid' => $r, ':file' => $name, ':idx' => $i, ':hash' => $chunkHashes[$i] ?? '']);
                        }
                    }
                    
                    // Actualizar registro principal del archivo: guardamos el hash meta y ponemos estado pending
                    $this->db->exec("INSERT INTO files (rbfid, file_name, chunk_count, chunk_pending, file_hash, status, updated_at, file_size, file_mtime) VALUES (:r, :n, :c, :p, :h, 'pending', NOW(), :s, :m) ON CONFLICT (rbfid, file_name) DO UPDATE SET chunk_count = :c, chunk_pending = :p, file_hash = :h, file_size = :s, file_mtime = :m, status = 'pending', updated_at = NOW()", 
                        [':r' => $r, ':n' => $name, ':c' => $cnt, ':p' => $pendingChunks, ':h' => $hash, ':s' => $fileSize, ':m' => $fileMtime]);
                    
                    Log::info("Sync: [$r] $name -> Expecting $cnt chunks | Target Hash: $hash");
                }
                
                // Obtener TODOS los chunks pendientes para este archivo (más eficiente)
                $pending = $this->db->qa("SELECT chunk_index FROM file_chunks WHERE rbfid = :r AND file_name = :n AND status != 'received' ORDER BY chunk_index", [':r' => $r, ':n' => $name]);
                foreach ($pending as $p) {
                    $needs[] = (int) $p['chunk_index'];
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
                
                // Si el archivo falta, eliminamos rastro de chunks incompletos
                $this->db->exec("DELETE FROM file_chunks WHERE rbfid = :r AND file_name = :f", [':r' => $r, ':f' => $fileUpper]);

                // Verificar si el archivo ya existe en la base de datos
                $existing = $this->db->q("SELECT status FROM files WHERE rbfid = :r AND file_name = :f", [':r' => $r, ':f' => $fileUpper]);
                
                if ($existing) {
                    // Si existe pero no está como 'missing', actualizar estado
                    if ($existing['status'] !== 'missing') {
                        $this->db->exec("UPDATE files SET status = 'missing', chunk_pending = 0, file_hash = NULL, updated_at = NOW() WHERE rbfid = :r AND file_name = :f", 
                            [':r' => $r, ':f' => $fileUpper]);
                        Log::debug("Updated file $fileUpper status to 'missing' for client $r");
                    }
                } else {
                    // Insertar nuevo registro con estado 'missing'
                    $this->db->exec("INSERT INTO files (rbfid, file_name, status, chunk_pending, updated_at) VALUES (:r, :f, 'missing', 0, NOW())", 
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
            $serviceName = $b['service'] ?? '';
            $file = $b['filename'] ?? '';
            $idx = max(0, (int) ($b['chunk_index'] ?? 0));
            $sz = max(0, (int) ($b['size'] ?? 0));
            $hash = $b['chunk_hash'] ?? '';
            if ($sz > 1073741824)
                self::err('File too large');
        
            $fileInfo = $this->db->q("SELECT chunk_count, chunk_pending, file_size FROM files WHERE rbfid=:r AND file_name=:f", [':r' => $r, ':f' => $file]);
            if (!$fileInfo) {
                self::err('Sesión de sincronización no encontrada para este archivo', 400);
            }

            $data = base64_decode($b['data'] ?? '');
            if (!$file || !$data)
                self::err('Missing fields');
            if (strlen($data) > 10485760)
                self::err('Chunk too large');

            $paths = $this->paths($r, $serviceName);
            if (!$paths) {
                Log::error("Upload: Paths not found for $r");
                self::err('Client not found', 404);
            }

            Log::info("Upload: Using paths - work: {$paths['work']}, base: {$paths['base']}, service: $serviceName");

            Log::info("Upload: [$r] $file | Chunk $idx | Size: " . strlen($data) . " bytes");
            $chunkDir = $paths['work'] . '/.chunks/' . $file;
            if (!is_dir($chunkDir)) {
                @mkdir($chunkDir, 0755, true);
            }
            $chunkPath = $chunkDir . '/' . $idx;
            if (file_put_contents($chunkPath, $data) !== strlen($data)) {
                Log::error("Upload: Failed to save chunk $idx for $file");
                self::err('Save failed');
            }

            $computedHash = hash('xxh3', $data);
            $decodedHash = \App\Hash::fromBase64($hash);
            $hashMatch = ($computedHash === $decodedHash);
            Log::debug("Upload Hash Check: computed=$computedHash, decoded=$decodedHash, match=" . ($hashMatch ? 'YES' : 'NO'));
            
            if (!$hashMatch) {
                Log::error("Chunk $idx hash mismatch for $file. Expected base64: $hash, computed hex: $computedHash, decoded hex: $decodedHash");
                $this->db->rollBack();
                self::json(['ok' => false, 'error' => 'Chunk hash mismatch', 'retry' => true]);
            }

            // Marcar este chunk como recibido en la base de datos
            $this->db->exec("UPDATE file_chunks SET status='received', updated_at=NOW() WHERE rbfid=:r AND file_name=:f AND chunk_index=:idx", 
                [':r' => $r, ':f' => $file, ':idx' => $idx]);

            // Recalcular chunks pendientes de forma real (evita desincronización por reintentos o concurrencia)
            $pendingCount = (int) $this->db->q("SELECT COUNT(*) as total FROM file_chunks WHERE rbfid=:r AND file_name=:f AND status != 'received'", 
                [':r' => $r, ':f' => $file])['total'];
            
            $this->db->exec("UPDATE files SET chunk_pending=:p WHERE rbfid=:r AND file_name=:f", [':p' => $pendingCount, ':r' => $r, ':f' => $file]);

            if ($pendingCount > 0) {
                // Buscar cualquier otro chunk que falte (no necesariamente el siguiente en índice)
                $next = $this->db->q("SELECT chunk_index FROM file_chunks WHERE rbfid=:r AND file_name=:f AND status != 'received' ORDER BY chunk_index LIMIT 1", 
                    [':r' => $r, ':f' => $file]);
                $nextChunk = $next ? (int)$next['chunk_index'] : 0;
                
                $this->db->commit();
                self::json(['ok' => true, 'status' => 'received', 'next_chunk' => $nextChunk]);
            } else {
                // No quedan pendientes, confirmar transacción y proceder a finalizar ensamblaje
                $this->db->commit();
                $this->finalize($r, $file, $paths);
            }
        } catch (\Throwable $e) {
            $this->db->rollBack();
            self::err("Upload Error: " . $e->getMessage());
        }
    }
    private function finalize(string $r, string $f, array $paths): void
    {
        $row = $this->db->q("SELECT file_hash, file_size, chunk_count FROM files WHERE rbfid=:r AND file_name=:f", [':r' => $r, ':f' => $f]);
        $target = $row['file_hash'] ?? '';
        $expectedSize = (int)($row['file_size'] ?? 0);
        $chunkCount = (int)($row['chunk_count'] ?? 0);
        $wp = $paths['work'] . '/' . $f;
        $chunkDir = $paths['work'] . '/.chunks/' . $f;
        
        // Verify all chunks exist on disk
        $missingChunks = [];
        for ($i = 0; $i < $chunkCount; $i++) {
            if (!file_exists($chunkDir . '/' . $i)) {
                $missingChunks[] = $i;
            }
        }
        
        if (!empty($missingChunks)) {
            Log::error("Finalize: Missing chunks for $f: " . implode(', ', $missingChunks));
            foreach ($missingChunks as $idx) {
                $this->db->exec("UPDATE file_chunks SET status='pending' WHERE rbfid=:r AND file_name=:f AND chunk_index=:idx",
                    [':r' => $r, ':f' => $f, ':idx' => $idx]);
            }
            $nextChunk = $missingChunks[0];
            $pendingCount = count($missingChunks);
            $this->db->exec("UPDATE files SET chunk_pending=:p WHERE rbfid=:r AND file_name=:f", [':p' => $pendingCount, ':r' => $r, ':f' => $f]);
            self::json(['ok' => true, 'status' => 'missing_chunks', 'next_chunk' => $nextChunk]);
            return;
        }
        
        // Assemble chunks atomically
        $assemblyFile = $wp . '.assembling';
        $chunkSize = \App\Chunk::size($expectedSize);
        
        $fh = fopen($assemblyFile, 'wb');
        if (!$fh) {
            Log::error("Finalize: Cannot create assembly file for $f");
            self::json(['ok' => false, 'error' => 'Cannot create assembly file']);
            return;
        }
        
        for ($i = 0; $i < $chunkCount; $i++) {
            $chunkData = file_get_contents($chunkDir . '/' . $i);
            fwrite($fh, $chunkData);
        }
        fclose($fh);
        
        // Verify final hash
        $actual = \App\Hash::computeFile($assemblyFile);
        $actualBase64 = \App\Hash::toBase64($actual);
        
        Log::debug("Finalize: $f | Target: $target, Actual: $actualBase64");
        
        if ($target === $actualBase64) {
            // Success - move to work dir, then to destination
            rename($assemblyFile, $wp);
            
            if (!is_dir($paths['base'])) {
                mkdir($paths['base'], 0755, true);
            }
            $dp = $paths['base'] . '/' . $f;
            
            if (file_exists($dp)) {
                if (filesize($wp) < (filesize($dp) * 0.90)) {
                    Log::info("Finalize: Archiving old version of $f");
                    $this->archiveHistorical($r, $f, $dp, $paths);
                } else {
                    unlink($dp);
                }
            }
            
            rename($wp, $dp);
            $this->db->exec("UPDATE files SET status='completed', chunk_pending=0 WHERE rbfid=:r AND file_name=:f", [':r' => $r, ':f' => $f]);
            
            // Cleanup chunks - both DB and disk
            $this->db->exec("DELETE FROM file_chunks WHERE rbfid=:r AND file_name=:f", [':r' => $r, ':f' => $f]);
            array_map('unlink', glob($chunkDir . '/*'));
            rmdir($chunkDir);
            
            Log::info("File $f finalized successfully for $r");
            self::json(['ok' => true, 'status' => 'complete']);
            return;
        }
        
        // Hash mismatch - rehash each chunk to find bad ones
        Log::error("Finalize: Hash mismatch for $f (expected: $target, got: $actualBase64)");
        
        $badChunks = [];
        $fh = fopen($assemblyFile, 'rb');
        for ($i = 0; $i < $chunkCount; $i++) {
            $offset = $i * $chunkSize;
            $length = min($chunkSize, $expectedSize - $offset);
            if ($length <= 0) continue;
            
            fseek($fh, $offset);
            $chunkData = fread($fh, $length);
            $chunkHash = \App\Hash::toBase64(hash('xxh3', $chunkData));
            
            $expectedChunkHash = $this->db->q("SELECT chunk_hash FROM file_chunks WHERE rbfid=:r AND file_name=:f AND chunk_index=:idx",
                [':r' => $r, ':f' => $f, ':idx' => $i])['chunk_hash'] ?? '';
            
            if ($chunkHash !== $expectedChunkHash) {
                $badChunks[] = $i;
                $this->db->exec("UPDATE file_chunks SET status='pending' WHERE rbfid=:r AND file_name=:f AND chunk_index=:idx",
                    [':r' => $r, ':f' => $f, ':idx' => $i]);
            } else {
                $this->db->exec("UPDATE file_chunks SET status='received' WHERE rbfid=:r AND file_name=:f AND chunk_index=:idx",
                    [':r' => $r, ':f' => $f, ':idx' => $i]);
            }
        }
        fclose($fh);
        
        // Update pending count
        $pendingCount = count($badChunks);
        $this->db->exec("UPDATE files SET chunk_pending=:p WHERE rbfid=:r AND file_name=:f", [':p' => $pendingCount, ':r' => $r, ':f' => $f]);
        
        // Clean up assembly file
        unlink($assemblyFile);
        
        if (!empty($badChunks)) {
            $nextChunk = $badChunks[0];
            Log::info("Finalize: Found " . count($badChunks) . " bad chunks for $f, next: $nextChunk");
            self::json(['ok' => true, 'status' => 'rehash', 'next_chunk' => $nextChunk, 'bad_chunks' => $badChunks]);
        } else {
            // All chunks match but final hash doesn't - inconsistent state
            Log::error("Finalize: All chunks match but final hash differs for $f");
            if (file_exists($wp)) unlink($wp);
            $this->db->exec("UPDATE files SET status='failed' WHERE rbfid=:r AND file_name=:f", [':r' => $r, ':f' => $f]);
            self::json(['ok' => false, 'error' => 'Hash mismatch - inconsistent state', 'status' => 'error']);
        }
    }

    private function archiveHistorical(string $r, string $f, string $dp, array $paths): void
    {
        $e = $paths['emp'] ?? '_';
        $p = $paths['plaza'] ?? '_';
        $cerradosBase = "/srv/cerrados/$e/$p/$r/" . strtoupper($f);
        
        if (!is_dir($cerradosBase)) @mkdir($cerradosBase, 0755, true);

        // Filtrar solo carpetas con formato YYMMDD o YYMMDD-YYMMDD
        $folders = array_filter(scandir($cerradosBase), fn($d) => preg_match('/^\d{6}(-\d{6})?$/', $d));
        sort($folders);
        $lastFolder = end($folders);

        $yesterdayTs = strtotime('-1 day');
        $yesterdayStr = date('ymd', $yesterdayTs);
        $folderName = "";

        if (!$lastFolder) {
            // Primera vez: YYMMDD de ayer
            $folderName = $yesterdayStr;
        } else {
            // Siguientes: (Día después del último archivado) - (Ayer)
            $parts = explode('-', $lastFolder);
            $lastEndStr = end($parts);
            $lastEndDate = "20" . substr($lastEndStr, 0, 2) . "-" . substr($lastEndStr, 2, 2) . "-" . substr($lastEndStr, 4, 2);
            $startDateStr = date('ymd', strtotime($lastEndDate . ' +1 day'));
            
            if ($startDateStr >= $yesterdayStr) {
                $folderName = $yesterdayStr;
            } else {
                $folderName = $startDateStr . '-' . $yesterdayStr;
            }
        }

        $targetDir = "$cerradosBase/$folderName";
        if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);

        Log::info("Archive: Moving historical version to $targetDir/$f");
        rename($dp, "$targetDir/$f");
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
        
        // Opción C: Primero verificar si hay personalizaciones en service_config
        $hasCustomConfig = $this->db->q(
            "SELECT 1 FROM service_config WHERE client_rbfid = :r LIMIT 1",
            [':r' => $r]
        );
        
        if ($hasCustomConfig) {
            // Usar service_config con JOIN (personalizaciones existen)
            $sql = "SELECT s.name, s.type, 
                           CASE 
                             WHEN cs.config IS NULL OR cs.config = '{}'::jsonb THEN s.default_config 
                             ELSE cs.config 
                           END as config, 
                           COALESCE(cs.frequency_seconds, s.default_frequency_seconds) as frequency_seconds 
                    FROM service_config cs
                    JOIN services s ON s.id = cs.service_id
                    WHERE cs.client_rbfid = :r AND cs.enabled = true";
        } else {
            // Fallback: usar solo services por defecto
            $sql = "SELECT s.name, s.type, 
                           s.default_config as config, 
                           s.default_frequency_seconds as frequency_seconds 
                    FROM services s
                    WHERE s.enabled = true";
        }
        
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

        if ($hasCustomConfig) {
            // Modo Orquestador: Solo lo que ya toca ejecutar (solo para service_config)
            $sql .= " AND (cs.next_execution IS NULL OR cs.next_execution <= NOW())";
        }
        
        $services = $this->db->qa($sql, $params);
        
        foreach ($services as $svc) {
            $this->updateNextExecution($r, $svc['name'], (int)$svc['frequency_seconds']);
        }
        
        self::json(['ok' => true, 'services' => $services]);
    }

    private function listServices(string $r): void
    {
        // Opción C: Verificar si hay personalizaciones
        $hasCustomConfig = $this->db->q(
            "SELECT 1 FROM service_config WHERE client_rbfid = :r LIMIT 1",
            [':r' => $r]
        );
        
        if ($hasCustomConfig) {
            // Usar service_config con personalizaciones
            $sql = "SELECT s.name, s.type, 
                           COALESCE(cs.frequency_seconds, s.default_frequency_seconds) as frequency_seconds,
                           cs.last_execution,
                           cs.next_execution,
                           (SELECT status FROM service_history sh 
                            WHERE sh.client_rbfid = cs.client_rbfid AND sh.service_name = s.name 
                            ORDER BY sh.completed_at DESC LIMIT 1) as last_status
                    FROM service_config cs
                    JOIN services s ON s.id = cs.service_id
                    WHERE cs.client_rbfid = :r AND cs.enabled = true
                    ORDER BY s.name";
        } else {
            // Fallback: usar solo services
            $sql = "SELECT s.name, s.type, 
                           s.default_frequency_seconds as frequency_seconds,
                           NULL as last_execution,
                           NULL as next_execution,
                           (SELECT status FROM service_history sh 
                            WHERE sh.client_rbfid = :r AND sh.service_name = s.name 
                            ORDER BY sh.completed_at DESC LIMIT 1) as last_status
                    FROM services s
                    WHERE s.enabled = true
                    ORDER BY s.name";
        }
        
        self::json(['ok' => true, 'services' => $this->db->qa($sql, [':r' => $r])]);
    }

    private function updateNextExecution(string $r, string $serviceName, int $seconds): void
    {
        // Solo actualizar si existe en service_config
        $exists = $this->db->q(
            "SELECT 1 FROM service_config cs JOIN services s ON s.id = cs.service_id 
             WHERE cs.client_rbfid = :r AND s.name = :n",
            [':r' => $r, ':n' => $serviceName]
        );
        
        if ($exists) {
            $this->db->exec("UPDATE service_config 
                            SET next_execution = NOW() + (:s || ' seconds')::interval,
                                last_execution = NOW()
                            FROM services s
                            WHERE service_config.service_id = s.id 
                            AND service_config.client_rbfid = :r 
                            AND s.name = :n", 
                            [':r' => $r, ':n' => $serviceName, ':s' => $seconds]);
        }
        // Si no existe en service_config, omitir (usa valores por defecto de services)
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
        
        // Opción C: Buscar primero si hay personalización en service_config
        $hasCustom = $this->db->q(
            "SELECT cs.config, cs.frequency_seconds 
             FROM service_config cs JOIN services s ON s.id = cs.service_id
             WHERE cs.client_rbfid = :r AND s.name = :n AND cs.enabled = true",
            [':r' => $r, ':n' => $name]
        );
        
        if ($hasCustom) {
            // Usar service_config (hay personalización)
            $row = $this->db->q(
                "SELECT s.type, s.name, s.files, s.direction, s.temp, s.dest, s.source, s.recursive, s.exclude, s.maxage,
                        COALESCE(cs.config, '{}'::jsonb) as client_cfg,
                        COALESCE(cs.frequency_seconds, s.default_frequency_seconds) as frequency_seconds
                 FROM service_config cs JOIN services s ON s.id = cs.service_id
                 WHERE cs.client_rbfid = :r AND s.name = :n AND cs.enabled = true",
                [':r' => $r, ':n' => $name]
            );
        } else {
            // Fallback: usar solo services (valores por defecto)
            $row = $this->db->q(
                "SELECT s.type, s.name, s.files, s.direction, s.temp, s.dest, s.source, s.recursive, s.exclude, s.maxage,
                        s.default_config as client_cfg,
                        s.default_frequency_seconds as frequency_seconds
                 FROM services s
                 WHERE s.name = :n AND s.enabled = true",
                [':n' => $name]
            );
        }
        
        if (!$row) self::err("Service '$name' not found or disabled", 404);
        
        $paths = $this->paths($r, $name);
        $ctx = ['rbfid' => $r, 'emp' => $paths['emp'] ?? '_', 'plaza' => $paths['plaza'] ?? '_'];
        
        // Usar columnas de services (prioridad) o client_cfg (JSONB)
        $cfg = [];
        $cfg['files'] = $row['files'] ? explode(',', $row['files']) : ($row['client_cfg']['files'] ?? []);
        $cfg['direction'] = $row['direction'] ?? ($row['client_cfg']['direction'] ?? 'upload');
        $cfg['source'] = $row['source'] ?? ($row['client_cfg']['source'] ?? '{base}');
        $cfg['recursive'] = $row['recursive'] ?? ($row['client_cfg']['recursive'] ?? false);
        $cfg['exclude'] = $row['exclude'] ?? ($row['client_cfg']['exclude'] ?? '');
        $cfg['maxage'] = $row['maxage'] ?? ($row['client_cfg']['maxage'] ?? null);
        
        // temp y dest se envían con placeholders para que el cliente los procese
        // {service} va en temp para el cliente, no se resuelve aquí
        $cfg['temp'] = $row['temp'] ?? ($row['client_cfg']['temp'] ?? '%tmp%/respaldoSucursal/{service}');
        $cfg['dest'] = $row['dest'] ?? ($row['client_cfg']['dest'] ?? '/srv/qbck/{emp}/{plaza}/{rbfid}');

        self::json(['ok' => true, 'service' => $row['name'], 'type' => $row['type'], 'config' => $cfg]);
    }

    private function downloadList(string $r, array $b): void
    {
        $serviceName = $b['service'] ?? '';
        $clientFiles = $b['files'] ?? [];
        
        $paths = $this->paths($r, $serviceName);
        if (!$paths) self::err('Client not found');
        $ctx = ['rbfid' => $r, 'emp' => $paths['emp'], 'plaza' => $paths['plaza']];

        // Opción C: Verificar si hay personalización
        $hasCustom = $this->db->q(
            "SELECT cs.config FROM service_config cs JOIN services s ON s.id = cs.service_id
             WHERE cs.client_rbfid = :r AND s.name = :n AND cs.enabled = true",
            [':r' => $r, ':n' => $serviceName]
        );
        
        if ($hasCustom) {
            // Usar service_config
            $row = $this->db->q(
                "SELECT s.files, s.dest, s.source, s.direction,
                        COALESCE(cs.config, '{}'::jsonb) as client_cfg
                 FROM service_config cs JOIN services s ON s.id = cs.service_id
                 WHERE cs.client_rbfid = :r AND s.name = :n AND cs.enabled = true",
                [':r' => $r, ':n' => $serviceName]
            );
        } else {
            // Fallback: usar services por defecto
            $row = $this->db->q(
                "SELECT s.files, s.dest, s.source, s.direction,
                        s.default_config as client_cfg
                 FROM services s
                 WHERE s.name = :n AND s.enabled = true",
                [':n' => $serviceName]
            );
        }
        
        if (!$row) self::err("Service '$serviceName' not found or disabled", 404);
        
        // Solo procesar si direction es download
        if ($row['direction'] !== 'download') {
            self::err("Service '$serviceName' is not configured for download");
        }
        
        // En download, source es la carpeta del servidor (dest en upload es donde llegan los archivos)
        $sourceDir = $this->resolvePath(
            $row['source'] ?? ($row['client_cfg']['source'] ?? $row['dest'] ?? "/srv/vales/{emp}/{plaza}/{rbfid}"),
            $ctx
        );
        if (!is_dir($sourceDir)) { self::json(['ok' => true, 'files' => []]); return; }

        $targetFiles = $row['files'] ? explode(',', $row['files']) : [];
        $filesToSend = [];
        $clientFileMap = [];
        
        // Mapear archivos del cliente por nombre
        foreach ($clientFiles as $cf) {
            $clientFileMap[strtoupper($cf['filename'])] = $cf;
        }
        
        foreach ($targetFiles as $f) {
            $f = trim($f);
            if (empty($f)) continue;
            
            // Ignorar máscaras en download por ahora (solo archivos específicos)
            if (strpos($f, '*') !== false) continue;
            
            $p = $sourceDir . '/' . $f;
            if (!file_exists($p)) continue;
            
            $localHash = \App\Hash::toBase64(\App\Hash::computeFile($p));
            $clientHash = $clientFileMap[strtoupper($f)]['hash'] ?? '';
            
            // Si hashes diferentes o cliente no tiene el archivo, agregarlo a enviar
            if ($localHash !== $clientHash) {
                $filesToSend[] = [
                    'filename' => $f,
                    'size' => filesize($p),
                    'mtime' => filemtime($p),
                    'hash' => $localHash
                ];
            }
        }
        
        $chunkSize = \App\Chunk::size(0);
        self::json(['ok' => true, 'files' => $filesToSend, 'chunk_size' => $chunkSize]);
    }

    private function downloadFile(string $r, array $b): void
    {
        $filename  = $b['filename'] ?? '';
        $chunkIdx  = (int)($b['chunk_index'] ?? 0);
        $serviceName = $b['service'] ?? '';
        
        $paths = $this->paths($r, $serviceName);
        if (!$paths) self::err('Client not found');
        $ctx = ['rbfid' => $r, 'emp' => $paths['emp'], 'plaza' => $paths['plaza']];

        $row = $this->db->q(
            "SELECT s.dest, s.source, s.direction,
                    COALESCE(cs.config, '{}'::jsonb) as client_cfg
             FROM service_config cs JOIN services s ON s.id = cs.service_id
             WHERE cs.client_rbfid = :r AND s.name = :n AND cs.enabled = true",
            [':r' => $r, ':n' => $serviceName]
        );
        if (!$row) self::err("Service not found", 404);
        
        if ($row['direction'] !== 'download') {
            self::err("Service not configured for download");
        }
        
        $sourceDir = $this->resolvePath(
            $row['source'] ?? ($row['client_cfg']['source'] ?? $row['dest'] ?? "/srv/vales/{emp}/{plaza}/{rbfid}"),
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
    private function paths(string $r, string $serviceName = ''): ?array
    {
        $c = $this->db->q("SELECT emp, plaza FROM clients WHERE rbfid=:r", [':r' => $r]);
        if (!$c)
            return null;
        $e = $c['emp'] ?: '_';
        $p = $c['plaza'] ?: '_';
        
        $workDir = "/tmp/respaldoSucursal/$r";
        $baseDir = "/srv/qbck/$e/$p/$r";
        
        if ($serviceName) {
            $svc = $this->db->q("SELECT dest FROM services WHERE name = :n", [':n' => $serviceName]);
            if ($svc && $svc['dest']) {
                $baseDir = $svc['dest'];
                $baseDir = str_replace(['{emp}', '{plaza}', '{rbfid}'], [$e, $p, $r], $baseDir);
            }
        }
        
        return ['emp' => $e, 'plaza' => $p, 'base' => $baseDir, 'work' => $workDir];
    }
}

// --- Execution ---
try {
    (new Server())->route();
} catch (\Throwable $e) {
    \App\Log::error("FATAL: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    \App\Api\Server::err('Internal Server Error', 500);
}