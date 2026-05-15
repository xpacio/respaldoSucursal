#!/usr/bin/env php
<?php
declare(strict_types=1);
namespace App\Cli;

require_once __DIR__ . '/shared_client.php';
require_once __DIR__ . '/shared_core.php';

use App\Constants;
use App\Log;
use App\Hash;
use App\Chunk;
use App\Platform;
use App\HttpClient;

class Client {
    private array $locations = [];
    private string $configPath;
    private HttpClient $http;

    public function __construct(string $cfgPath) {
        $this->configPath = $cfgPath;
        $data = \App\ClientConfig::load($cfgPath);
        $this->locations = $data['locations'] ?? [];
        $this->http = new HttpClient(Constants::DEFAULT_URL);
    }

    public function showStatus(): void {
        Log::info("= ===== Estado ===== =");
        try {
            $health = $this->http->req('health', 'system', []);
            Log::info("Servidor: " . ($health['ok'] ? 'ONLINE' : 'OFFLINE'));
        } catch (\Throwable $e) { Log::error("Health check failed."); }

        foreach ($this->locations as $loc) {
            Log::info(sprintf("Ubicacion encontrada : [%s] | Path: %s", $loc['rbfid'], $loc['base']));
        }
        Log::info("- - - - - - - - - - - - - - - - - -");
    }

    public function scanAndCreateConfig(): void {
        Log::info("=== Buscando Instalaciones ===");
        $locations = Platform::scanDisk();
        
        if (empty($locations)) {
            Log::error("No se localizo ninguna instalacion. Asegúrate de tener un directorio /pvsi con archivo .rbfid o rbf/rbf.ini.");
            return;
        }
        
        Log::info("hay " . count($locations) . " ubicacione(s):");
        foreach ($locations as $loc) {
            Log::info(sprintf("  - [%s] Carpeta de trabajo: %s | Temporal: %s", $loc['rbfid'], $loc['base'], $loc['work']));
        }
        
        // Cargar config existente para preservar otras configuraciones
        $config = \App\ClientConfig::load($this->configPath);
        $config['locations'] = $locations;
        $config['watch_files'] = Constants::$WATCH_FILES;
        $config['files_version'] = substr(md5(implode(',', Constants::$WATCH_FILES)),0, 8);
        
        if (\App\ClientConfig::save($this->configPath, $config)) {
            Log::info("Configuracion guardada en : {$this->configPath}");
        } else {
            Log::error("No se pudo guardar la configuracion: {$this->configPath}");
        }
    }    

    public function listServicesInfo(): void {
        if (empty($this->locations)) {
            Log::info("No hay instalaciones configuradas. buscando...");
            $this->scanAndCreateConfig();
            $data = \App\ClientConfig::load($this->configPath);
            $this->locations = $data['locations'] ?? [];
        }
        if (empty($this->locations)) {
            Log::error("No se ubicaron instalaciones. Revise los directorios de pvsi.");
            return;
        }
        foreach ($this->locations as $loc) {
            $this->listServices($loc['rbfid']);
        }
    }

    public function listServices(string $rbfid): void {
        Log::info("Consultando los servicios para [$rbfid]...");
        try {
            $res = $this->http->req('list_services', $rbfid, []);
            if (!$res['ok']) {
                Log::error("Error: " . ($res['error'] ?? 'Unknown error'));
                return;
            }

            if (empty($res['services'])) {
                Log::error("$rbfid no tiene servicios configurados.");
                return;
            }

            Log::info(sprintf("%-20s | %-10s | %-8s | %-19s | %-10s", "Servicio", "Tipo", "Freq", "Ultima ejecucion", "Estado"));
            Log::info(str_repeat("-", 80));
            foreach ($res['services'] as $svc) {
                Log::info(sprintf(
                    "%-20s | %-10s | %-8d | %-19s | %-10s",
                    $svc['name'], $svc['type'], $svc['frequency_seconds'],
                    $svc['last_execution'] ?? 'Never', $svc['last_status'] ?? 'N/A'
                ));
            }
        } catch (\Throwable $e) { Log::error("Fallo al listar los servicios: " . $e->getMessage()); }
    }

    public function runOrchestrator(): void {
        if (empty($this->locations)) {
            Log::info("No locations found. Running disk scan...");
            $this->scanAndCreateConfig();
            $data = \App\ClientConfig::load($this->configPath);
            $this->locations = $data['locations'] ?? [];
        }
        if (empty($this->locations)) {
            Log::error("No locations found after scan. Check /pvsi directories.");
            return;
        }
        Log::info("Orchestrator started with " . count($this->locations) . " locations.");
        while (true) {
            $pollStartedAt = time(); // Marca inicial del poll
            
            // Enviar heartbeat al iniciar ciclo
            foreach ($this->locations as $loc) {
                try {
                    $this->http->req('heartbeat', $loc['rbfid'], [
                        'status' => 'running',
                        'system_info' => ['cycle_start' => date('H:i:s')]
                    ]);
                } catch (\Throwable $e) { /* ignorar errores */ }
            }
            
            foreach ($this->locations as $loc) {
                $rbfid = $loc['rbfid'];
                try {
                    $res = $this->http->req('schedule', $rbfid, []);
                    if ($res['ok'] && !empty($res['services'])) {
                        foreach ($res['services'] as $svc) {
                            $this->executeService($svc['name'], $rbfid);
                        }
                    }
                } catch (\Throwable $e) { Log::error("Orchestrator Error ($rbfid): " . $e->getMessage()); }
            }
            
            // Lógica de espera: mantener ciclos de 50s
            $elapsed = time() - $pollStartedAt;
            
            if ($elapsed > 50) {
                // Caso atraso: recuperar en 10s
                Log::info("Overdue execution ({$elapsed}s > 50s), re-polling in 10s");
                sleep(10);
            } else {
                // Caso normal: completar ciclo de 50s
                $sleepSecs = 50 - $elapsed;
                if ($sleepSecs < 10) $sleepSecs = 10; // Mínimo 10s
                Log::debug("Maintaining 50s cycle, sleeping {$sleepSecs}s (elapsed: {$elapsed}s)");
                sleep($sleepSecs);
            }
        }
    }

    public function executeService(string $service, string $rbfid): void {
        // Send heartbeat before executing
        try {
            $this->http->req('heartbeat', $rbfid, [
                'status' => 'running',
                'system_info' => ['service' => $service, 'start' => date('H:i:s')]
            ]);
        } catch (\Throwable $e) { /* ignore errors */ }
        
        $start = microtime(true);
        $GLOBALS['totalBytesTransferred'] = 0;
        $GLOBALS['totalChunks'] = 0;
        $GLOBALS['totalSizeIncrease'] = 0;
        $loc = null;
        foreach ($this->locations as $l) { if ($l['rbfid'] === $rbfid) { $loc = $l; break; } }
        if (!$loc) return;

        try {
            Log::info("=== Service Start: $service ($rbfid) ===");
            $res = $this->http->req('service_config', $rbfid, ['service' => $service]);
            if (!$res['ok']) throw new \Exception($res['error'] ?? 'Config error');

            $cfg = $res['config'] ?? [];
            $direction = $cfg['direction'] ?? 'upload';
            
            Log::info("[DEBUG] Service Config: direction=$direction, source=" . ($cfg['source'] ?? '{base}') . 
                ", temp=" . ($cfg['temp'] ?? '%tmp%') . ", files=" . json_encode($cfg['files'] ?? []) . 
                ", recursive=" . ($cfg['recursive'] ?? false ? 'true' : 'false') . 
                ", exclude=" . ($cfg['exclude'] ?? '') . ", maxage=" . ($cfg['maxage'] ?? 'null'));
            $data = ($direction === 'download') 
                ? $this->transferDownload($service, $loc, $cfg) 
                : $this->transferUpload($service, $loc, $cfg);
            
            $finalStatus = (count($data['sync_missing']) > 0) ? 'partial' : 'success';
            if (count($data['sync_ok']) === 0 && count($data['sync_missing']) > 0) $finalStatus = 'failed';
            
            Log::info("[RESULT] files_count=" . $data['files_count'] . 
                ", sync_ok=" . count($data['sync_ok']) . 
                ", sync_missing=" . count($data['sync_missing']) . 
                ", sync_excluded=" . count($data['sync_excluded'] ?? []) . 
                ", files_sync=" . $data['files_sync']);
            if (!empty($data['sync_ok'])) Log::info("[OK] " . implode(', ', $data['sync_ok']));
            if (!empty($data['sync_missing'])) Log::warn("[MISSING] " . implode(', ', $data['sync_missing']));

            $this->http->req('service_result', $rbfid, [
                'service' => $service, 'status' => $finalStatus, 'results' => $data,
                'execution_time_ms' => (int)((microtime(true) - $start) * 1000)
            ]);
            
            Log::info("=== Service End: $service | Status: $finalStatus ===");
            $execMs = (int)((microtime(true) - $start) * 1000);
            $bytes = $GLOBALS['totalBytesTransferred'] ?? 0;
            $chunks = $GLOBALS['totalChunks'] ?? 0;
            $sizeInc = $GLOBALS['totalSizeIncrease'] ?? 0;
            $fmtBytes = $bytes < 1048576 ? round($bytes/1024,2).' KB' : round($bytes/1048576,2).' MB';
            $fmtSize = $sizeInc < 1048576 ? round($sizeInc/1024,2).' KB' : round($sizeInc/1048576,2).' MB';
            Log::info("  Time: {$execMs}ms | Data: $fmtBytes | Chunks: $chunks | Size +: $fmtSize");
            
            // Compression savings summary
            $uncompressed = $GLOBALS['totalUncompressed'] ?? 0;
            $compressed = $GLOBALS['totalCompressed'] ?? 0;
            if ($uncompressed > 0 && $compressed > 0) {
                $saved = $uncompressed - $compressed;
                $savedPct = round(($saved / $uncompressed) * 100, 1);
                $fmtOriginal = $uncompressed < 1048576 ? round($uncompressed/1024,2).' KB' : round($uncompressed/1048576,2).' MB';
                $fmtCompressed = $compressed < 1048576 ? round($compressed/1024,2).' KB' : round($compressed/1048576,2).' MB';
                $fmtSaved = $saved < 1048576 ? round($saved/1024,2).' KB' : round($saved/1048576,2).' MB';
                Log::info("  Compression: $fmtOriginal → $fmtCompressed (saved: $fmtSaved, $savedPct%)");
            }
        } catch (\Throwable $e) { Log::error("Service Error ($service): " . $e->getMessage()); }
    }

    private function transferUpload(string $service, array $loc, array $cfg): array {
        // source puede ser {base} (usa la base local) o una ruta fija como c:\otra_carpeta
        $sourceTemplate = $cfg['source'] ?? '{base}';
        $source = str_replace('{base}', $loc['base'], $sourceTemplate);
        
        // temp usa %tmp% y {service}
        $tempTemplate = $cfg['temp'] ?? '%tmp%/respaldoSucursal/{service}';
        $work = str_replace(['%tmp%', '{service}', '{base}'], [sys_get_temp_dir(), $service, $loc['base']], $tempTemplate);
        
        // Crear directorio temporal si no existe
        if (!is_dir($work)) mkdir($work, 0755, true);

        // Verificar que client_source existe
        if (!is_dir($source)) {
            Log::error("Source directory does not exist: $source");
            return ['files_count' => 0, 'sync_ok' => [], 'sync_missing' => [], 'files_sync' => 0, 'error' => "Source directory not found: $source"];
        }

        $recursive = $cfg['recursive'] ?? false;
        $excludeMasks = $cfg['exclude'] ?? '';
        $excludeList = $excludeMasks ? array_map('trim', explode(',', $excludeMasks)) : [];
        $maxage = $cfg['maxage'] ?? null;
        $files = $cfg['files'] ?? Constants::$WATCH_FILES;
        $filesList = is_array($files) ? $files : explode(',', $files);
        
        Log::info("[UPLOAD] Source: $source, Work: $work, Recursive: " . ($recursive ? 'yes' : 'no') . 
            ", Exclude: " . ($excludeMasks ?: 'none') . ", MaxAge: " . ($maxage ?? 'none') . 
            ", Files(" . count($filesList) . "): " . implode(', ', array_map('trim', $filesList)));
        
        $results = [
            'files_count' => 0,
            'sync_ok' => [],
            'sync_missing' => [],
            'sync_excluded' => [],
            'files_sync' => 0
        ];

        foreach ($filesList as $item) {
            $item = trim($item);
            if (empty($item)) continue;
            
            // Detectar si es máscara (contiene * o ?)
            if (strpos($item, '*') !== false || strpos($item, '?') !== false) {
                // Máscara: usar robocopy directamente
                $robocopyFlag = $recursive ? '/S' : '/E';
                $robocopyCmd = 'robocopy ' . escapeshellarg($source) . ' ' . escapeshellarg($work) . ' ' . escapeshellarg($item) . ' ' . escapeshellarg($robocopyFlag) . ' /R:1 /W:1 /NJH /NJS /NDL /NC /NS';
                
                if ($maxage) {
                    $robocopyCmd .= ' /maxage:' . (int)$maxage;
                }
                
                Log::info("--- Processing mask: $item (recursive: $recursive, maxage: $maxage) ---");
                exec($robocopyCmd);
                
                // Procesar archivos copiados y aplicar exclude
                $this->processUploadedFiles($service, $loc, $work, $excludeList, $results);
                
                $results['files_count']++;
            } else {
                // Archivo individual: lógica existente
                $fUpper = strtoupper($item);
                $dstPath = $work . DIRECTORY_SEPARATOR . $item;
                
                // Extraer carpeta y archivo para preservar estructura
                $parts = explode('/', str_replace('\\', '/', $item));
                $fileBaseName = array_pop($parts);
                $subPath = implode(DIRECTORY_SEPARATOR, $parts);
                $dstPath = $work . ($subPath ? DIRECTORY_SEPARATOR . $subPath : '') . DIRECTORY_SEPARATOR . strtoupper($fileBaseName);
                
                // Crear carpetas destino si no existen
                $dstDir = dirname($dstPath);
                if (!is_dir($dstDir)) mkdir($dstDir, 0755, true);
                
                // Buscar archivo origen (case-insensitive)
                $srcReal = $this->findFileCaseInsensitive($source, $item);
                
                if (!$srcReal) {
                    Log::info("File not found: $item");
                    $results['sync_missing'][] = $fUpper;
                    continue;
                }

                Log::info("--- Processing: $fUpper (found: $srcReal) ---");
                
                copy($srcReal, $dstPath);
                $results['files_count']++;
                
                if (file_exists($dstPath)) {
                    $this->uploadFile($service, $loc, $fUpper, $dstPath);
                    $results['sync_ok'][] = $fUpper;
                    $results['files_sync']++;
                }
            }
        }

        if (!empty($results['sync_missing'])) {
            $this->http->req('missing', $loc['rbfid'], ['service' => $service, 'missing_files' => $results['sync_missing']]);
        }

        return $results;
    }

    private function processUploadedFiles(string $service, array $loc, string $workDir, array $excludeList, array &$results): void {
        if (empty($excludeList)) return;
        
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workDir));
        foreach ($files as $file) {
            if (!$file->isFile()) continue;
            
            $filename = $file->getFilename();
            foreach ($excludeList as $mask) {
                if ($this->matchMask($filename, trim($mask))) {
                    Log::info("Excluding file (matched mask $mask): " . $filename);
                    $results['sync_excluded'][] = $filename;
                    unlink($file->getPathname());
                    break;
                }
            }
        }
    }

    private function matchMask(string $filename, string $mask): bool {
        $regex = str_replace(['.', '*', '?'], ['\.', '.*', '.'], $mask);
        return preg_match('/^' . $regex . '$/i', $filename);
    }

    private function transferDownload(string $service, array $loc, array $cfg): array {
        // En download, dest es la carpeta local donde ya pueden existir archivos descargados antes
        $destTemplate = $cfg['dest'] ?? '{base}';
        $dest = str_replace(['{base}','{rbfid}'], [$loc['base'], $loc['rbfid']], $destTemplate);
        
        $tempTemplate = $cfg['temp'] ?? '%tmp%/respaldoSucursal/{service}';
        $work = str_replace(['%tmp%', '{service}', '{base}'], [sys_get_temp_dir(), $service, $loc['base']], $tempTemplate);
        
        if (!is_dir($work)) mkdir($work, 0755, true);

        Log::info("[DOWNLOAD] Dest: $dest, Work: $work");

        // Listar archivos locales desde DEST para comparar hashes con el servidor
        $files = $cfg['files'] ?? [];
        $filesList = is_array($files) ? $files : explode(',', $files);
        $localFiles = [];
        
        foreach ($filesList as $f) {
            $f = trim($f);
            if (empty($f) || strpos($f, '*') !== false) continue;
            
            $p = $dest . DIRECTORY_SEPARATOR . $f;
            if (file_exists($p)) {
                $localFiles[] = [
                    'filename' => strtoupper($f),
                    'size' => filesize($p),
                    'hash' => Hash::toBase64(Hash::computeFile($p)),
                    'mtime' => filemtime($p)
                ];
            }
        }
        
        // Solicitar lista de archivos a recibir del servidor
        Log::info("Requesting download list for service: $service");
        $res = $this->http->req('download_list', $loc['rbfid'], [
            'service' => $service,
            'files' => $localFiles
        ]);
        
        if (!$res['ok']) {
            Log::error("Download list error: " . ($res['error'] ?? 'Unknown'));
            return ['files_count' => 0, 'sync_ok' => [], 'sync_missing' => [], 'files_sync' => 0];
        }
        
        $results = [
            'files_count' => 0,
            'sync_ok' => [],
            'sync_missing' => [],
            'files_sync' => 0
        ];
        
        $filesToReceive = $res['files'] ?? [];
        Log::info("Files to receive: " . count($filesToReceive));
        
        foreach ($filesToReceive as $fInfo) {
            $filename = $fInfo['filename'] ?? '';
            $fileSize = (int)($fInfo['size'] ?? 0);
            
            if (empty($filename)) continue;
            
            // Extraer subcarpeta si existe
            $parts = explode('/', str_replace('\\', '/', $filename));
            $fileBaseName = array_pop($parts);
            $subPath = implode(DIRECTORY_SEPARATOR, $parts);
            $workFile = $work . DIRECTORY_SEPARATOR . $filename;
            $destFile = $dest . DIRECTORY_SEPARATOR . $filename;
            
            // Crear carpetas
            if (!is_dir(dirname($workFile))) mkdir(dirname($workFile), 0755, true);
            
            $chunkSize = \App\Chunk::size($fileSize);
            $totalChunks = (int)ceil($fileSize / $chunkSize);
            
            $fmtBytes = $fileSize < 1048576 ? round($fileSize/1024,2).' KB' : round($fileSize/1048576,2).' MB';
            Log::info("Downloading: $filename ($fmtBytes, $totalChunks chunks)");
            
            // Descargar chunks y escribir directo a archivo temporal
            $fh = fopen($workFile, 'wb');
            $hashCtx = hash_init('xxh3');
            $downloaded = 0;
            
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkRes = $this->http->req('download_file', $loc['rbfid'], [
                    'service' => $service,
                    'filename' => $filename,
                    'chunk_index' => $i,
                    'size' => $fileSize
                ]);
                
                if (!($chunkRes['ok'] ?? false)) {
                    Log::error("  Chunk $i/$totalChunks failed for $filename");
                    break;
                }
                
                $data = base64_decode($chunkRes['data'] ?? '');
                $dataLen = strlen($data);
                if ($dataLen > 0) {
                    fwrite($fh, $data);
                    hash_update($hashCtx, $data);
                    $downloaded += $dataLen;
                    $pct = round(($i + 1) / $totalChunks * 100, 1);
                    $chunkHash = \App\Hash::toBase64(hash('xxh3', $data));
                    Log::info("  [$pct%] Chunk $i/$totalChunks ($chunkHash, " . number_format($dataLen) . " bytes)");
                }
            }
            fclose($fh);
            
            $fileHash = \App\Hash::toBase64(hash_final($hashCtx));
            
            // Mover a destino final
            $destDir = dirname($destFile);
            if (!is_dir($destDir)) @mkdir($destDir, 0755, true);

            rename($workFile, $destFile);
            $destSize = filesize($destFile);
            $destSizeFmt = $destSize < 1048576 ? round($destSize/1024,2).' KB' : round($destSize/1048576,2).' MB';
            Log::info("  Saved: $destFile ($destSizeFmt, hash: $fileHash)");
            $results['sync_ok'][] = strtoupper($filename);
            $results['files_sync']++;
            $results['files_count']++;
        }
        
        return $results;
    }

    private function findFileCaseInsensitive(string $dir, string $filename): ?string {
        $fullPath = $dir . DIRECTORY_SEPARATOR . $filename;
        if (file_exists($fullPath)) return $fullPath;
        
        if (Platform::isWindows() && is_dir($dir)) {
            $files = scandir($dir);
            $filenameLower = strtolower($filename);
            foreach ($files as $f) {
                if (strtolower($f) === $filenameLower) {
                    return $dir . DIRECTORY_SEPARATOR . $f;
                }
            }
        }
        return null;
    }

    private function uploadFile(string $service, array $loc, string $file, string $wp): void {

        $size = filesize($wp);
        $h = Hash::computeFile($wp);
        $cs = Chunk::size($size);
        $chs = []; $fh = fopen($wp, 'rb');
        while ($chunk = fread($fh, $cs)) { $chs[] = Hash::toBase64(hash('xxh3', $chunk)); }
        fclose($fh);
        $totalChunks = count($chs);
        Log::debug($loc['work'] . " $size :: $h :: $cs x $totalChunks");

        
        while (true) {
            // Log::debug("  Checking sync status for $file...");
            $req = $this->http->req('sync', $loc['rbfid'], [
                'service' => $service,
                'files' => [['filename' => $file, 'hash_completo' => Hash::toBase64($h), 'chunk_hashes' => $chs, 'mtime' => filemtime($wp), 'size' => $size]]
            ]);
            
            // Accumulate total size increase from all sync responses
            if (!empty($req['file_changes'])) {
                foreach ($req['file_changes'] as $fc) {
                    $GLOBALS['totalSizeIncrease'] = ($GLOBALS['totalSizeIncrease'] ?? 0) + ($fc['diff_bytes'] ?? 0);
                }
            }
            
            if (empty($req['needs_upload'])) {
                Log::info("  File $file is synchronized.");
                // Show file changes if available
                if (!empty($req['file_changes'])) {
                    foreach ($req['file_changes'] as $fc) {
                        $file = $fc['file'] ?? '?';
                        $hash = $fc['hash'] ?? 'N/A';
                        $dest = $fc['dest'] ?? 'N/A';
                        $oldFmt = $fc['old_size_fmt'] ?? '?';
                        $newFmt = $fc['new_size_fmt'] ?? '?';
                        $diffFmt = $fc['diff_fmt'] ?? '?';
                        $pct = $fc['growth_pct'] ?? 0;
                        $timeFmt = $fc['time_diff_fmt'] ?? '?';
                        Log::info("  [$file] Hash: $hash | Dest: $dest");
                        Log::info("  Size: $oldFmt -> $newFmt (diff: $diffFmt, $pct%)");
                        Log::info("  Time diff: $timeFmt");
                    }
                }
                break; 
            }
            
            $chunksToUpload = count($req['needs_upload']);
            $desface = number_format(($chunksToUpload / $totalChunks) * 100, 2);
            Log::info("Sincronizando $file: $chunksToUpload chunks pendientes ($desface% de desface)");
            
            $chunksToUpload = count($req['needs_upload']);
            $desfase = number_format(($chunksToUpload / $totalChunks) * 100, 2);
            Log::info("Sincronizando $file: $chunksToUpload chunks pendientes ($desfase% de desfase)");

            foreach ($req['needs_upload'] as $chunkIdx) {
                $off = $chunkIdx * $cs;
                $data = file_get_contents($wp, false, null, $off, min($cs, $size - $off));
                $dataLen = strlen($data);
                $GLOBALS['totalBytesTransferred'] = ($GLOBALS['totalBytesTransferred'] ?? 0) + $dataLen;
                $attempts = 0; $success = false;
                while ($attempts < 3 && !$success) {
                    $compressed = gzcompress($data, 6);
                    $compressedLen = strlen($compressed);
                    $ratio = round(($compressedLen / $dataLen) * 100, 1);
                    $GLOBALS['totalUncompressed'] = ($GLOBALS['totalUncompressed'] ?? 0) + $dataLen;
                    $GLOBALS['totalCompressed'] = ($GLOBALS['totalCompressed'] ?? 0) + $compressedLen;
                    
                    $res = $this->http->req('upload', $loc['rbfid'], [
                        'service' => $service,
                        'filename' => $file, 'chunk_index' => $chunkIdx, 
                        'chunk_hash' => Hash::toBase64(hash('xxh3', $data)), 
                        'data' => base64_encode($compressed), 'size' => $size,
                        'compressed' => true
                    ]);

                    if ($res['ok'] ?? false){ 
                        $chunksToUpload--;
                        $GLOBALS['totalChunks'] = ($GLOBALS['totalChunks'] ?? 0) + 1;
                        $progreso = number_format((($totalChunks - $chunksToUpload) / $totalChunks) * 100, 1);
                        Log::info(sprintf("  [%s%%] Uploaded chunk %d de %s (compression: %s%%, %s -> %s bytes)", 
                            $progreso, $chunkIdx, $file, $ratio, $dataLen, $compressedLen));
                        $success = true; 
                    } else {
                        $attempts++;
                        Log::info(" Reintentando chunk $chunkIdx (intento $attempts)");
                    };
                }
                if (!$success) throw new \Exception("Failed to upload chunk $chunkIdx of $file after 3 attempts");
            }
        }
    }
}

// --- CLI Entry Point ---
        Log::info("cli.php version: 0.60430j");
$client = new Client('config.json');
$args = $argv;
array_shift($args); // Quitar nombre del script

if (empty($args)) {
    $client->showStatus();
    echo "Uso: php cli.php [comando] | -{nombreServicio} [{rbfid}]\n";
    echo "Comandos :\n";
    echo "s | start | main\n";
    echo "b | buscar | scan | scann\n";
    echo "l | ls \n";
    exit(0);
}

$cmd = $args[0];
if (in_array($cmd,['s','start','main'])) {
    $client->runOrchestrator();
} elseif ($cmd === '' || $cmd === 'info') {
    $client->showStatus();
    $client->listServicesInfo();
} elseif ($cmd === 'infos') {
    echo "metodo no desarrollado";
} elseif ( in_array($cmd,['b','buscar','scan','scann']) ) {
    $client->scanAndCreateConfig();
} elseif ( in_array($cmd,['l','ls']) ) {
    $rbfid = $args[1] ?? '';
    if (empty($rbfid)) die("Error: Se requiere RBFID.\n");
    $client->listServices($rbfid);
} elseif (str_starts_with($cmd, '-')) {
    // Soporta "-service descargaVales roton" o "-descargaVales roton"
    $serviceName = ltrim($cmd, '-');
    $rbfid = $args[1] ?? '';

    if (empty($serviceName) || empty($rbfid)) {
        die("Error: Se requiere nombre de servicio y RBFID.\n");
    }
    $client->executeService($serviceName, $rbfid);
} else {
    echo "Parametro no reconocido: $cmd\n";
}