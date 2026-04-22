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
    private HttpClient $http;

    public function __construct(string $cfgPath) {
        $data = \App\ClientConfig::load($cfgPath);
        $this->locations = $data['locations'] ?? [];
        $this->http = new HttpClient(Constants::DEFAULT_URL);
    }

    public function showStatus(): void {
        Log::info("=== Status Mode ===");
        try {
            $health = $this->http->req('health', 'system', []);
            Log::info("Server Health: " . ($health['ok'] ? 'ONLINE' : 'OFFLINE'));
        } catch (\Throwable $e) { Log::error("Health check failed."); }

        foreach ($this->locations as $loc) {
            Log::info(sprintf("Location Found: [%s] | Path: %s", $loc['rbfid'], $loc['base']));
        }
        Log::info("====================");
    }

    public function listServices(string $rbfid): void {
        Log::info("Fetching services for [$rbfid]...");
        try {
            $res = $this->http->req('list_services', $rbfid, []);
            if (!$res['ok']) {
                Log::error("Error: " . ($res['error'] ?? 'Unknown error'));
                return;
            }

            if (empty($res['services'])) {
                Log::info("No services configured or enabled for $rbfid.");
                return;
            }

            Log::info(sprintf("%-20s | %-10s | %-8s | %-19s | %-10s", "Service", "Type", "Freq(s)", "Last Execution", "Status"));
            Log::info(str_repeat("-", 80));
            foreach ($res['services'] as $svc) {
                Log::info(sprintf(
                    "%-20s | %-10s | %-8d | %-19s | %-10s",
                    $svc['name'], $svc['type'], $svc['frequency_seconds'],
                    $svc['last_execution'] ?? 'Never', $svc['last_status'] ?? 'N/A'
                ));
            }
        } catch (\Throwable $e) { Log::error("Failed to list services: " . $e->getMessage()); }
    }

    public function runOrchestrator(): void {
        Log::info("Orchestrator started with " . count($this->locations) . " locations.");
        while (true) {
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
            sleep(Constants::POLL_SEC);
        }
    }

    public function executeService(string $service, string $rbfid): void {
        $start = microtime(true);
        $loc = null;
        foreach ($this->locations as $l) { if ($l['rbfid'] === $rbfid) { $loc = $l; break; } }
        if (!$loc) return;

        try {
            Log::info("Service Start: $service ($rbfid)");
            $res = $this->http->req('service_config', $rbfid, ['service' => $service]);
            if (!$res['ok']) throw new \Exception($res['error'] ?? 'Config error');

            $cfg = $res['config'] ?? [];
            $data = $this->transferUpload($service, $loc, $cfg);
            
            $finalStatus = (count($data['sync_missing']) > 0) ? 'partial' : 'success';
            if (count($data['sync_ok']) === 0 && count($data['sync_missing']) > 0) $finalStatus = 'failed';

            $this->http->req('service_result', $rbfid, [
                'service' => $service, 'status' => $finalStatus, 'results' => $data,
                'execution_time_ms' => (int)((microtime(true) - $start) * 1000)
            ]);
        } catch (\Throwable $e) { Log::error("Service Error ($service): " . $e->getMessage()); }
    }

    private function transferUpload(string $service, array $loc, array $cfg): array {
        // client_source puede ser {base} (usa la base local) o una ruta fija como c:\otra_carpeta
        $clientSourceTemplate = $cfg['client_source'] ?? '{base}';
        $source = str_replace('{base}', $loc['base'], $clientSourceTemplate);
        
        // client_temp usa %tmp% y {service}
        $clientTempTemplate = $cfg['client_temp'] ?? '%tmp%/respaldoSucursal/{service}';
        $work = str_replace(['%tmp%', '{service}', '{base}'], [sys_get_temp_dir(), $service, $loc['base']], $clientTempTemplate);
        
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

            if (empty($req['needs_upload'])) {
                Log::info("  File $file is synchronized.");
                break; 
            }
            
            $chunksToUpload = count($req['needs_upload']);
            $desfase = number_format(($chunksToUpload / $totalChunks) * 100, 2);
            Log::info("Sincronizando $file: $chunksToUpload chunks pendientes ($desfase% de desfase)");

            foreach ($req['needs_upload'] as $chunkIdx) {
                $off = $chunkIdx * $cs;
                $data = file_get_contents($wp, false, null, $off, min($cs, $size - $off));
                $attempts = 0; $success = false;
                while ($attempts < 3 && !$success) {
                    $res = $this->http->req('upload', $loc['rbfid'], [
                        'service' => $service,
                        'filename' => $file, 'chunk_index' => $chunkIdx, 
                        'chunk_hash' => Hash::toBase64(hash('xxh3', $data)), 
                        'data' => base64_encode($data), 'size' => $size
                    ]);

                    if ($res['ok'] ?? false){ 
                        $chunksToUpload--;
                        $progreso = number_format((($totalChunks - $chunksToUpload) / $totalChunks) * 100, 1);
                        Log::info(sprintf("  [%s%%] Uploaded chunk %d de %s", $progreso, $chunkIdx, $file));
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
$client = new Client('config.json');
$args = $argv;
array_shift($args); // Quitar nombre del script

if (empty($args)) {
    $client->showStatus();
    echo "Uso: php cli.php [--main | -ls {rbfid} | -service {nombre} {rbfid} | -{nombre} {rbfid}]\n";
    exit(0);
}

$cmd = $args[0];
if ($cmd === '--main') {
    $client->runOrchestrator();
} elseif ($cmd === '-ls' || $cmd === '-list_services') {
    $rbfid = $args[1] ?? '';
    if (empty($rbfid)) die("Error: Se requiere RBFID.\n");
    $client->listServices($rbfid);
} elseif (str_starts_with($cmd, '-')) {
    // Soporta "-service descargaVales roton" o "-descargaVales roton"
    $serviceName = ($cmd === '-service') ? ($args[1] ?? '') : ltrim($cmd, '-');
    $rbfid = ($cmd === '-service') ? ($args[2] ?? '') : ($args[1] ?? '');

    if (empty($serviceName) || empty($rbfid)) {
        die("Error: Se requiere nombre de servicio y RBFID.\n");
    }
    $client->executeService($serviceName, $rbfid);
} else {
    echo "Parametro no reconocido: $cmd\n";
}