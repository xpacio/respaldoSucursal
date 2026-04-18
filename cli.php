#!/usr/bin/env php
<?php declare(strict_types=1);
namespace App\Cli;

require_once __DIR__ . '/shared_client.php';
use App\Constants; use App\Log; use App\Hash; use App\Chunk; use App\Totp;
use App\HttpClient; use App\Platform; use App\ClientConfig; use App\ServiceRunner;

class Client {
    private HttpClient $http;
    private array $locations = [];
    private string $cfgPath;
    private array $cache = [];

    public function __construct(string $cfgPath) {
        $this->cfgPath = $cfgPath;
        Log::init(dirname(__FILE__) . '/logs', true);
        $this->http = new HttpClient(Constants::DEFAULT_URL);
        $this->loadConfig();
    }

    private function loadConfig(): void {
        $data = ClientConfig::load($this->cfgPath);
        $this->locations = array_map(function($l) {
            return [
                'rbfid' => $l['rbfid'] ?? '',
                'base' => $l['base'] ?? ($l['base_path'] ?? ''),
                'work' => $l['work'] ?? ($l['work_path'] ?? '')
            ];
        }, $data['locations'] ?? []);
        
        if (!empty($data['watch_files'])) {
            Constants::$WATCH_FILES = array_map('strtoupper', $data['watch_files']);
        }
    }

    // --- ORCHESTRATOR MODE ---
    public function runOrchestrator(): void {
        Log::info('--- ORCHESTRATOR STARTED ---');
        while (true) {
            foreach ($this->locations as $loc) {
                $rbfid = $loc['rbfid'];
                Log::debug("Checking schedule for $rbfid");
                
                $res = $this->http->req('schedule', $rbfid, []);
                if (!empty($res['services'])) {
                    foreach ($res['services'] as $svc) {
                        Log::info("Launching service: {$svc['name']} for $rbfid");
                        ServiceRunner::run($svc['name'], $rbfid);
                    }
                }
                
                // Heartbeat
                $this->http->req('heartbeat', $rbfid, [
                    'status' => 'running',
                    'system_info' => [
                        'platform' => Platform::isWindows() ? 'windows' : 'linux',
                        'php_version' => PHP_VERSION,
                        'hostname' => gethostname()
                    ]
                ]);
            }
            Log::flush();
            sleep(60);
        }
    }

    // --- SERVICE MODE ---
    public function executeService(string $service, string $rbfid): void {
        $start = microtime(true);
        Log::info("Service Start: $service ($rbfid)");
        
        $loc = null;
        foreach ($this->locations as $l) {
            if ($l['rbfid'] === $rbfid) { $loc = $l; break; }
        }
        
        if (!$loc && $service !== 'discover') {
            Log::error("RBFID $rbfid not found in config");
            return;
        }

        $results = [];
        $status = 'success';

        try {
            switch ($service) {
                case 'respaldo':
                    $results = $this->serviceRespaldo($loc);
                    break;
                case 'discover':
                    $this->discover($this->cfgPath);
                    $results = ['locations' => count($this->locations)];
                    break;
                default:
                    Log::error("Service '$service' not implemented");
                    $status = 'failed';
                    $results = ['error' => "Service '$service' not implemented"];
            }
        } catch (\Throwable $e) {
            Log::error("Service Execution Error: " . $e->getMessage());
            $status = 'failed';
            $results = ['error' => $e->getMessage()];
        }

        $timeMs = (int)((microtime(true) - $start) * 1000);
        if ($service !== 'discover') {
            $this->http->req('service_result', $rbfid, [
                'service_name' => $service,
                'status' => $status,
                'results' => $results,
                'execution_time_ms' => $timeMs
            ]);
        }
        Log::info("Service End: $service. Status: $status. Time: {$timeMs}ms");
        Log::flush();
    }

    // --- LOGIC: RESPALDO ---
    private function serviceRespaldo(array $loc): array {
        $updated = 0;
        $missing = [];
        
        // 1. Sync Base -> Work
        $resSync = $this->syncWorkFiles($loc);
        $updated += $resSync['updated'];
        $missing = $resSync['missing'];
        
        // 2. Upload from Work -> Server
        foreach (Constants::$WATCH_FILES as $f) {
            $fUpper = strtoupper($f);
            $wp = $loc['work'] . DIRECTORY_SEPARATOR . $fUpper;
            if (!file_exists($wp)) continue;
            
            $st = stat($wp);
            $this->uploadFile($loc, $fUpper, $wp, (int)$st['mtime'], (int)$st['size']);
            $updated++;
        }
        
        return ['files_updated' => $updated, 'missing_count' => count($missing)];
    }

    private function syncWorkFiles(array $loc): array {
        $base = $loc['base'];
        $work = $loc['work'];
        $rbfid = $loc['rbfid'];
        $updated = 0;
        $missing = [];

        if (!is_dir($work)) mkdir($work, 0755, true);

        foreach (Constants::$WATCH_FILES as $file) {
            $fileUpper = strtoupper($file);
            $src = null;
            
            // Buscar archivo (insensible a mayúsculas)
            if (file_exists($base . DIRECTORY_SEPARATOR . $fileUpper)) {
                $src = $base . DIRECTORY_SEPARATOR . $fileUpper;
            } else if (is_dir($base)) {
                foreach (scandir($base) as $entry) {
                    if (strtoupper($entry) === $fileUpper) {
                        $src = $base . DIRECTORY_SEPARATOR . $entry;
                        break;
                    }
                }
            }

            if (!$src) {
                $missing[] = $fileUpper;
                continue;
            }

            $dst = $work . DIRECTORY_SEPARATOR . $fileUpper;
            $copyNeeded = !file_exists($dst) || stat($src)['size'] !== stat($dst)['size'];

            if ($copyNeeded) {
                if (copy($src, $dst)) {
                    touch($dst, stat($src)['mtime']);
                    $updated++;
                }
            }
        }

        if (!empty($missing)) {
            $this->http->req('missing', $rbfid, ['missing_files' => $missing]);
        }

        return ['updated' => $updated, 'missing' => $missing];
    }

    private function uploadFile(array $loc, string $file, string $wp, int $mtime, int $size): void {
        $h = Hash::computeFile($wp);
        $cs = Chunk::size($size);
        $chs = [];
        $fh = fopen($wp, 'rb');
        while (!feof($fh)) {
            $chunk = fread($fh, $cs);
            if ($chunk === false || $chunk === '') break;
            $chs[] = Hash::toBase64(hash('xxh3', $chunk));
        }
        fclose($fh);

        $req = $this->http->req('sync', $loc['rbfid'], [
            'files' => [[
                'filename' => $file,
                'hash_completo' => Hash::toBase64($h),
                'chunk_hashes' => $chs,
                'mtime' => $mtime,
                'size' => $size
            ]]
        ]);

        $uploadResp = [];
        foreach ($req['needs_upload'] ?? [] as $t) {
            $off = $t['chunk'] * $cs;
            $d = file_get_contents($wp, false, null, $off, min($cs, $size - $off));
            $uploadResp = $this->http->req('upload', $loc['rbfid'], [
                'filename' => $file,
                'chunk_index' => $t['chunk'],
                'hash_xxh3' => Hash::toBase64(hash('xxh3', $d)),
                'data' => base64_encode($d),
                'size' => $size
            ]);
        }

        while (isset($uploadResp['next_chunk'])) {
            $idx = $uploadResp['next_chunk'];
            $off = $idx * $cs;
            $d = file_get_contents($wp, false, null, $off, min($cs, $size - $off));
            $uploadResp = $this->http->req('upload', $loc['rbfid'], [
                'filename' => $file,
                'chunk_index' => $idx,
                'hash_xxh3' => Hash::toBase64(hash('xxh3', $d)),
                'data' => base64_encode($d),
                'size' => $size
            ]);
            usleep(100000);
        }
    }

    // --- LOGIC: DISCOVER ---
    public function discover(string $cfgPath): void {
        Log::info('Discovering locations...');
        $this->scanDisk();
        if (!empty($this->locations)) {
            ClientConfig::save($cfgPath, ['locations' => $this->locations, 'files_version' => '', 'watch_files' => []]);
            Log::info("Saved " . count($this->locations) . " locations to $cfgPath");
        }
    }

    private function scanDisk(): void {
        $paths = Platform::isWindows() ? array_map(fn($d) => $d.':\\', range('C','D')) : ['/srv'];
        foreach ($paths as $root) {
            if (!is_dir($root)) continue;
            foreach (scandir($root) as $dir) {
                if ($dir === '.' || $dir === '..' || in_array(strtolower($dir), Constants::EXCLUDED_WIN)) continue;
                $path = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $dir;
                $ini = $path . DIRECTORY_SEPARATOR . 'rbf' . DIRECTORY_SEPARATOR . 'rbf.ini';
                if (file_exists($ini) && preg_match('/_suc=([^\n\r]+)/i', file_get_contents($ini), $m)) {
                    $this->locations[] = [
                        'rbfid' => trim($m[1], ' "'),
                        'base' => $path,
                        'work' => $path . DIRECTORY_SEPARATOR . 'quickbck' . DIRECTORY_SEPARATOR
                    ];
                }
            }
        }
    }
}

// --- MAIN EXECUTION ---
try {
    $args = $_SERVER['argv'];
    array_shift($args); // script name
    
    $service = null;
    $rbfid = null;
    $cfg = __DIR__ . '/config.json';

    foreach ($args as $idx => $arg) {
        if (str_starts_with($arg, '-')) {
            $name = ltrim($arg, '-');
            if ($name === 'rbfid') {
                $rbfid = $args[$idx + 1] ?? null;
            } else {
                $service = $name;
            }
        }
    }

    $client = new Client($cfg);

    if ($service) {
        $client->executeService($service, (string)$rbfid);
    } else {
        $client->runOrchestrator();
    }
} catch (\Throwable $e) {
    echo "Fatal Error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}