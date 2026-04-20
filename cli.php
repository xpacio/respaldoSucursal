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
        $source = $loc['base'];
        $work = $loc['work'];
        if (!is_dir($work)) mkdir($work, 0755, true);

        $files = $cfg['files'] ?? Constants::$WATCH_FILES;
        $results = [
            'files_count' => count($files),
            'sync_ok' => [],
            'sync_missing' => [],
            'files_sync' => 0
        ];

        foreach ($files as $f) {
            $f = strtoupper($f);
            $src = $source . DIRECTORY_SEPARATOR . $f;
            $dst = $work . DIRECTORY_SEPARATOR . $f;
            
            if (!file_exists($src)) {
                $results['sync_missing'][] = $f;
                continue;
            }

            Log::info("--- Processing: $f ---");
            if (Platform::isWindows()) {
                $cmd = sprintf('robocopy %s %s %s /R:1 /W:1 /NJH /NJS /NDL /NC /NS', 
                    escapeshellarg($source), escapeshellarg($work), escapeshellarg($f));
                exec($cmd);
            } else {
                copy($src, $dst);
            }
            
            if (file_exists($dst)) {
                $this->uploadFile($service, $loc, $f, $dst);
                $results['sync_ok'][] = $f;
                $results['files_sync']++;
            }
        }

        if (!empty($results['sync_missing'])) {
            $this->http->req('missing', $loc['rbfid'], ['missing_files' => $results['sync_missing']]);
        }

        return $results;
    }

    private function uploadFile(string $service, array $loc, string $file, string $wp): void {
        $size = filesize($wp);
        $h = Hash::computeFile($wp);
        $cs = Chunk::size($size);
        $chs = []; $fh = fopen($wp, 'rb');
        while ($chunk = fread($fh, $cs)) { $chs[] = Hash::toBase64(hash('xxh3', $chunk)); }
        fclose($fh);

        // Bucle de sincronización: repetir hasta que no haya más chunks pendientes para este archivo
        while (true) {
            Log::debug("  Checking sync status for $file...");
            $req = $this->http->req('sync', $loc['rbfid'], [
                'files' => [['filename' => $file, 'hash_completo' => Hash::toBase64($h), 'chunk_hashes' => $chs, 'mtime' => filemtime($wp), 'size' => $size]]
            ]);

            if (empty($req['needs_upload'])) {
                Log::info("  File $file is synchronized.");
                break; 
            }

            foreach ($req['needs_upload'] as $t) {
                $perc = round(($t['chunk'] + 1) / (max(1, count($chs))) * 100);
                Log::info(sprintf("  [%3d%%] Uploading %s chunk %d...", $perc, $file, $t['chunk']));
                $off = $t['chunk'] * $cs;
                $data = file_get_contents($wp, false, null, $off, min($cs, $size - $off));
                $attempts = 0; $success = false;
                while ($attempts < 3 && !$success) {
                    $res = $this->http->req('upload', $loc['rbfid'], [
                        'filename' => $file, 'chunk_index' => $t['chunk'], 
                        'chunk_hash' => Hash::toBase64(hash('xxh3', $data)), 
                        'data' => base64_encode($data), 'size' => $size
                    ]);
                    if ($res['ok'] ?? false) $success = true; else $attempts++;
                }
                if (!$success) throw new \Exception("Failed to upload chunk {$t['chunk']} of $file after 3 attempts");
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