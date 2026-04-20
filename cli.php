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
            $results = $this->transferUpload($service, $loc, $cfg);

            $this->http->req('service_result', $rbfid, [
                'service' => $service, 'status' => 'success', 'results' => $results,
                'execution_time_ms' => (int)((microtime(true) - $start) * 1000)
            ]);
        } catch (\Throwable $e) { Log::error("Service Error ($service): " . $e->getMessage()); }
    }

    private function transferUpload(string $service, array $loc, array $cfg): array {
        $source = $loc['base'];
        $work = $loc['work'];
        if (!is_dir($work)) mkdir($work, 0755, true);

        $updated = 0;
        $files = $cfg['files'] ?? Constants::$WATCH_FILES;

        foreach ($files as $f) {
            $f = strtoupper($f);
            $src = $source . DIRECTORY_SEPARATOR . $f;
            $dst = $work . DIRECTORY_SEPARATOR . $f;
            if (!file_exists($src)) continue;

            // Usar Robocopy en Windows para manejar archivos DBF bloqueados
            if (Platform::isWindows()) {
                $cmd = sprintf('robocopy %s %s %s /R:1 /W:1 /NJH /NJS /NDL /NC /NS', 
                    escapeshellarg($source), escapeshellarg($work), escapeshellarg($f));
                exec($cmd);
            } else {
                copy($src, $dst);
            }
            
            if (file_exists($dst)) {
                $this->uploadFile($service, $loc, $f, $dst);
                $updated++;
            }
        }
        return ['files_processed' => $updated];
    }

    private function uploadFile(string $service, array $loc, string $file, string $wp): void {
        $size = filesize($wp);
        $h = Hash::computeFile($wp);
        $cs = Chunk::size($size);
        $chs = []; $fh = fopen($wp, 'rb');
        while ($chunk = fread($fh, $cs)) { $chs[] = Hash::toBase64(hash('xxh3', $chunk)); }
        fclose($fh);

        $req = $this->http->req('sync', $loc['rbfid'], [
            'files' => [['filename' => $file, 'hash_completo' => Hash::toBase64($h), 'chunk_hashes' => $chs, 'mtime' => filemtime($wp), 'size' => $size]]
        ]);

        foreach ($req['needs_upload'] ?? [] as $t) {
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
        }
    }
}

$client = new Client('config.json');
$client->runOrchestrator();