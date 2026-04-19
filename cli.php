<?php declare(strict_types=1);
namespace App;

require_once __DIR__ . '/shared_client.php';

// --- CLIENT LOGIC ---
class Client
{
    private string $cfgPath;
    private array $locations = [];
    private HttpClient $http;

    public function __construct(string $cfgPath) {
        $this->cfgPath = $cfgPath;
        $data = ClientConfig::load($cfgPath);
        if (!$data && PHP_SAPI === 'cli' && !str_contains(implode(' ', $_SERVER['argv']), '-discover')) {
            $this->discover($cfgPath);
            $data = ClientConfig::load($cfgPath);
        }
        $this->locations = $data['locations'] ?? [];
        $this->http = new HttpClient(Constants::DEFAULT_URL);
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
                            Log::info("Orchestrator: Spawning service {$svc['name']} for $rbfid");
                            ServiceRunner::run($svc['name'], $rbfid);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::error("Orchestrator Error ($rbfid): " . $e->getMessage());
                }
            }
            sleep(60);
        }
    }

    public function executeService(string $service, string $rbfid): void {
        $start = microtime(true);
        $loc = null;
        foreach ($this->locations as $l) {
            if ($l['rbfid'] === $rbfid) { $loc = $l; break; }
        }

        try {
            if ($service === 'discover') {
                $this->discover($this->cfgPath);
                return;
            }

            if (!$loc) throw new \Exception("Sucursal '$rbfid' no encontrada en config.json. Ejecute -discover.");
            
            // 1. VALIDACIÓN PREVIA: Consultar al servidor antes de loguear inicio
            $res = $this->http->req('schedule', $rbfid, ['service' => $service]);
            
            if (!($res['ok'] ?? false)) {
                // Si el servidor falla, mostramos error en consola y terminamos sin ensuciar logs
                throw new \Exception("El servicio '$service' no existe o no está habilitado para $rbfid.");
            }

            // 2. LOG FORMAL: Solo si el servicio es válido
            Log::info("Service Start: $service ($rbfid)");
            
            $type = $res['type'] ?? '';
            $cfg = $res['config'] ?? [];
            $results = [];

            if ($type === 'upload' || $type === 'download') {
                $results = $this->serviceTransfer($service, $rbfid, $type, $cfg, $loc);
            } elseif ($service === 'monitoreo') {
                $results = ['cpu' => $this->serviceMonitoreoCpu(), 'disk' => $this->serviceMonitoreoDisk()];
            } elseif ($service === 'info') {
                $results = $this->serviceSistemaInfo();
            } else {
                throw new \Exception("Tipo de servicio desconocido: $type");
            }

            // Enviar resultados al servidor
            $this->http->req('service_result', $rbfid, [
                'service' => $service,
                'status' => 'success',
                'results' => $results,
                'execution_time_ms' => (int)((microtime(true) - $start) * 1000)
            ]);
            Log::info("Service End: $service. Status: success.");

        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            // Si es un error de configuración inicial, solo a consola
            if (str_contains($msg, 'no existe') || str_contains($msg, 'no encontrada')) {
                echo "❌ Error: $msg" . PHP_EOL;
            } else {
                // Errores de ejecución sí van al log y al servidor
                Log::error("Service Error ($service): $msg");
                $this->http->req('service_result', $rbfid, [
                    'service' => $service,
                    'status' => 'failed',
                    'results' => ['error' => $msg],
                    'execution_time_ms' => (int)((microtime(true) - $start) * 1000)
                ]);
            }
        }
        Log::flush();
    }

    // --- GENERIC TRANSFER ENGINE ---
    private function resolveClientPath(string $tpl, array $loc): string {
        return str_replace(['{base}', '{rbfid}'], [$loc['base'], $loc['rbfid']], $tpl);
    }

    private function serviceTransfer(string $service, string $rbfid, string $type, array $cfg, array $loc): array {
        return ($type === 'download')
            ? $this->transferDownload($service, $loc, $cfg)
            : $this->transferUpload($loc, $cfg);
    }

    private function transferUpload(string $service, array $loc, array $cfg): array {
        $source = isset($cfg['client_source']) ? $this->resolveClientPath($cfg['client_source'], $loc) : $loc['base'];
        
        // Separar archivos temporales por servicio para evitar colisiones
        $workBase = ($loc['work'] ?? $loc['base'] . DIRECTORY_SEPARATOR . '.ar_work');
        $temp = isset($cfg['client_temp']) 
            ? $this->resolveClientPath($cfg['client_temp'], $loc) 
            : $workBase . DIRECTORY_SEPARATOR . $service;

        $uploadLoc = ['rbfid' => $loc['rbfid'], 'base' => $source, 'work' => $temp];
        Log::info("Processing Service: $service | Source: $source | Temp: $temp");
        
        $resSync = $this->syncWorkFiles($uploadLoc, $files = ($cfg['files'] ?? null));
        
        $uploaded = 0;
        $targetFiles = $files ?? Constants::$WATCH_FILES;
        foreach ($targetFiles as $f) {
            $wp = $temp . DIRECTORY_SEPARATOR . strtoupper($f);
            if (!file_exists($wp)) continue;
            $st = stat($wp);
            $this->uploadFile($service, $uploadLoc, strtoupper($f), $wp, (int)$st['mtime'], (int)$st['size']);
            $uploaded++;
        }
        return ['direction' => 'upload', 'files_processed' => $uploaded, 'missing' => count($resSync['missing'])];
    }

    private function transferDownload(string $service, array $loc, array $cfg): array {
        $rbfid = $loc['rbfid'];
        $clientDest = isset($cfg['client_dest']) ? $this->resolveClientPath($cfg['client_dest'], $loc) : $loc['base'];

        $resList = $this->http->req('download_list', $rbfid, ['service' => $service]);
        if (empty($resList['files'])) return ['direction' => 'download', 'status' => 'nothing_to_download'];

        if (!is_dir($clientDest)) mkdir($clientDest, 0755, true);
        $downloaded = 0;
        foreach ($resList['files'] as $f) {
            $name = $f['filename'];
            $destPath = $clientDest . DIRECTORY_SEPARATOR . $name;
            if (file_exists($destPath) && Hash::toBase64(Hash::computeFile($destPath)) === $f['hash']) continue;

            $tempPath = $destPath . '.tmp';
            $fh = fopen($tempPath, 'wb');
            $chunkSize = Chunk::size($f['size']);
            $total = (int)ceil($f['size'] / max(1, $chunkSize));
            for ($i = 0; $i < $total; $i++) {
                $res = $this->http->req('download_file', $rbfid, ['filename' => $name, 'chunk_index' => $i, 'service' => $service]);
                if (!($res['ok'] ?? false)) throw new \Exception("Chunk $i failed for $name");
                $data = base64_decode($res['data']);
                if (Hash::toBase64(hash('xxh3', $data)) !== $res['chunk_hash']) throw new \Exception("Hash mismatch chunk $i of $name");
                fwrite($fh, $data);
            }
            fclose($fh);
            if (Hash::toBase64(Hash::computeFile($tempPath)) !== $f['hash']) {
                unlink($tempPath);
                throw new \Exception("Final hash mismatch for $name");
            }
            rename($tempPath, $destPath);
            touch($destPath, $f['mtime']);
            $downloaded++;
        }
        return ['direction' => 'download', 'files_downloaded' => $downloaded];
    }

    // --- MONITOREO ---
    private function serviceMonitoreoDisk(): array {
        $disks = [];
        if (Platform::isWindows()) {
            $output = [];
            exec('wmic logicaldisk get caption,size,freespace /format:csv', $output);
            foreach ($output as $line) {
                if (empty(trim($line)) || str_starts_with($line, 'Node')) continue;
                $parts = explode(',', $line);
                if (count($parts) >= 4) {
                    $disks[] = ['drive' => $parts[1], 'free_gb' => round((float)$parts[2]/1073741824, 2), 'total_gb' => round((float)$parts[3]/1073741824, 2)];
                }
            }
        }
        return ['disks' => $disks];
    }

    private function serviceMonitoreoCpu(): array {
        if (Platform::isWindows()) {
            $output = [];
            exec('wmic cpu get loadpercentage', $output);
            return ['load' => (int)($output[1] ?? 0)];
        }
        return ['load' => sys_getloadavg()[0]];
    }

    private function serviceSistemaInfo(): array {
        return ['hostname' => gethostname(), 'os' => PHP_OS, 'php' => PHP_VERSION];
    }

    private function syncWorkFiles(array $loc, ?array $customFiles = null): array {
        $base = $loc['base']; $work = $loc['work']; $rbfid = $loc['rbfid'];
        $updated = 0; $missing = [];
        $files = $customFiles ?? Constants::$WATCH_FILES;
        if (!is_dir($work)) mkdir($work, 0755, true);
        foreach ($files as $file) {
            $fileUpper = strtoupper($file);
            $src = file_exists($base . DIRECTORY_SEPARATOR . $fileUpper) ? $base . DIRECTORY_SEPARATOR . $fileUpper : null;
            if (!$src) { $missing[] = $fileUpper; continue; }
            $dst = $work . DIRECTORY_SEPARATOR . $fileUpper;
            if (!file_exists($dst) || stat($src)['size'] !== stat($dst)['size']) {
                if (copy($src, $dst)) { touch($dst, stat($src)['mtime']); $updated++; }
            }
        }
        if (!empty($missing)) $this->http->req('missing', $rbfid, ['missing_files' => $missing]);
        return ['updated' => $updated, 'missing' => $missing];
    }

    private function uploadFile(string $service, array $loc, string $file, string $wp, int $mtime, int $size): void {
        $h = Hash::computeFile($wp);
        $cs = Chunk::size($size);
        $chs = []; $fh = fopen($wp, 'rb');
        while (!feof($fh)) {
            $chunk = fread($fh, $cs);
            if ($chunk === false || $chunk === '') break;
            $chs[] = Hash::toBase64(hash('xxh3', $chunk));
        }
        fclose($fh);
        
        $req = $this->http->req('sync', $loc['rbfid'], ['files' => [['filename' => $file, 'hash_completo' => Hash::toBase64($h), 'chunk_hashes' => $chs, 'mtime' => $mtime, 'size' => $size]]]);
        
        $uploadResp = [];
        foreach ($req['needs_upload'] ?? [] as $t) {
            Log::info("[$service] Uploading $file | Chunk {$t['chunk']} | Path: $wp");
            $off = $t['chunk'] * $cs;
            $d = file_get_contents($wp, false, null, $off, min($cs, $size - $off));
            $uploadResp = $this->http->req('upload', $loc['rbfid'], [
                'filename' => $file, 
                'chunk_index' => $t['chunk'], 
                'chunk_hash' => Hash::toBase64(hash('xxh3', $d)), 
                'data' => base64_encode($d), 
                'size' => $size
            ]);
        }
        while (isset($uploadResp['next_chunk'])) {
            $idx = $uploadResp['next_chunk'];
            Log::info("[$service] Uploading $file | Chunk $idx (Next)");
            $off = $idx * $cs;
            $d = file_get_contents($wp, false, null, $off, min($cs, $size - $off));
            $uploadResp = $this->http->req('upload', $loc['rbfid'], [
                'filename' => $file, 
                'chunk_index' => $idx, 
                'chunk_hash' => Hash::toBase64(hash('xxh3', $d)), 
                'data' => base64_encode($d), 
                'size' => $size
            ]);
        }
    }

    public function discover(string $cfgPath): void {
        Log::info("Iniciando escaneo de discos...");
        $locs = Platform::scanDisk();
        if (empty($locs)) {
            echo "❌ Error: No se detectaron sucursales." . PHP_EOL;
            exit(1);
        }
        $this->locations = $locs;
        ClientConfig::save($cfgPath, ['locations' => $this->locations, 'watch_files' => Constants::$WATCH_FILES]);
        echo "✅ " . count($locs) . " sucursales encontradas y guardadas en config.json" . PHP_EOL;
    }

    public function showStatusAndExit(): void {
        if (empty($this->locations)) {
            echo "Sin sucursales configuradas. Ejecute php cli.php -discover" . PHP_EOL;
            return;
        }
        foreach ($this->locations as $loc) {
            echo "RBFID: {$loc['rbfid']} [CONFIGURADO] Carpeta: {$loc['base']}" . PHP_EOL;
            $res = $this->http->req('health', $loc['rbfid'], []);
            echo "Servidor: " . (($res['ok'] ?? false) ? "EN LINEA" : "DESCONECTADO") . PHP_EOL;
        }
        sleep(10);
    }
}

// --- MAIN ---
try {
    if (PHP_SAPI === 'cli') {
        $cfgFile = 'config.json';
        $client = new Client($cfgFile);
        $arg = $argv[1] ?? '';
        
        if ($arg === '--master' || $arg === '--main') $client->runOrchestrator();
        elseif ($arg === '-discover') $client->executeService('discover', '');
        elseif (str_starts_with($arg, '-') && !str_starts_with($arg, '--')) {
            $client->executeService(substr($arg, 1), $argv[2] ?? '');
        } elseif (empty($arg)) {
            $client->showStatusAndExit();
        } else {
            echo "Comando desconocido: $arg" . PHP_EOL;
        }
    }
} catch (\Throwable $e) {
    echo "Fatal Error: " . $e->getMessage() . PHP_EOL;
}