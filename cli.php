#!/usr/bin/env php
<?php declare(strict_types=1);
namespace App\Cli;

require_once __DIR__ . '/shared.php';
use App\DB; use App\Config; use App\Totp; use App\Log; use App\Storage; use App\Constants; use App\Hash; use App\Chunk;

class Client {
    private string $url;
    private array $locations = [];
    private int $lastFull = 0;
    private int $lastConfigCheck = 0;
    private array $cache = [];
    private $ch;

    public function __construct() {
        $this->url = Constants::DEFAULT_URL;
        Log::init(dirname(__FILE__) . '/logs', true);
        $this->ch = curl_init();
        curl_setopt_array($this->ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);
    }

    public function discover(string $cfgPath): void {
        Log::info('Discovering locations...');
        $data = file_exists($cfgPath) ? json_decode(file_get_contents($cfgPath) ?: '{}', true) : [];
        $this->locations = array_map(function($l) {
            $base = isset($l['base']) ? $l['base'] : (isset($l['base_path']) ? $l['base_path'] : null);
            $work = isset($l['work']) ? $l['work'] : (isset($l['work_path']) ? $l['work_path'] : null);
            return ['rbfid' => $l['rbfid'], 'base' => $base, 'work' => $work];
        }, isset($data['locations']) ? $data['locations'] : []);
        
        // Cargar lista de archivos de config.json si existe
        if (!empty($data['watch_files'])) {
            Constants::$WATCH_FILES = array_map('strtoupper', $data['watch_files']);
            $version = isset($data['files_version']) ? $data['files_version'] : 'unknown';
            Log::info("Loaded " . count(Constants::$WATCH_FILES) . " files from config.json (version: $version)");
        }
        
        if (empty($this->locations)) {
            Log::info('Scanning disk...');
            $this->scanDisk();
            if (!empty($this->locations)) {
                $save = ['locations' => $this->locations, 'files_version' => '', 'watch_files' => []];
                file_put_contents($cfgPath, json_encode($save, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                Log::info("Auto-discovered locations saved to $cfgPath");
            }
        }
        foreach ($this->locations as &$l) {
            if (!is_dir($l['work']))
                mkdir($l['work'], 0755, true);
            
            // Copy/update files from base to work directory
            $this->syncWorkFiles($l);
        }
        Log::info('Found ' . count($this->locations) . ' locations');
    }

    private function scanDisk(): void {
        // Windows drives (original compatibility)
        foreach (['C', 'D'] as $drv) {
            $root = "$drv:\\\\";
            if (!is_dir($root))
                continue;
            foreach (scandir($root) as $dir) {
                if (in_array(strtolower($dir), Constants::EXCLUDED_WIN))
                    continue;
                $this->checkClientLocation("$root$dir");
            }
        }
        
        // Linux directory - only /srv
        $root = '/srv';
        if (is_dir($root)) {
            foreach (scandir($root) as $dir) {
                if ($dir === '.' || $dir === '..')
                    continue;
                $this->checkClientLocation("$root/$dir");
            }
        }
    }
    
    private function checkClientLocation(string $path): void {
        // Only detect via rbf/rbf.ini with _suc= pattern
        $ini = $path . DIRECTORY_SEPARATOR . 'rbf' . DIRECTORY_SEPARATOR . 'rbf.ini';
        if (file_exists($ini)) {
            $content = file_get_contents($ini);
            if (preg_match('/_suc=([^\n\r]+)/i', $content, $m)) {
                $id = trim($m[1], ' "');
                $work = $path . DIRECTORY_SEPARATOR . 'quickbck' . DIRECTORY_SEPARATOR;
                $this->locations[] = ['rbfid' => $id, 'base' => $path, 'work' => $work];
                Log::info("Auto-discovered: $id at $path");
            }
        }
    }
    
    private function syncWorkFiles(array $loc): void {
        $base = $loc['base'];
        $work = $loc['work'];
        $rbfid = $loc['rbfid'];
        
        Log::debug("Syncing files from $base to $work for $rbfid");
        
        if (!is_dir($base)) {
            Log::error("Base directory not found: $base");
            return;
        }
        
        $filesUpdated = 0;
        $missingFiles = [];
        
        foreach (Constants::$WATCH_FILES as $file) {
            // Asegurar nombre en mayúsculas
            $fileUpper = strtoupper($file);
            
            // Buscar archivo en base directory (puede estar en minúsculas o mixto)
            $found = false;
            $actualName = $fileUpper;
            
            // Primero intentar con el nombre exacto (mayúsculas)
            $src = $base . DIRECTORY_SEPARATOR . $fileUpper;
            if (file_exists($src)) {
                $found = true;
            } else {
                // Buscar archivo con cualquier casing
                if (is_dir($base)) {
                    foreach (scandir($base) as $entry) {
                        if (strtoupper($entry) === $fileUpper) {
                            $src = $base . DIRECTORY_SEPARATOR . $entry;
                            $actualName = $entry;
                            $found = true;
                            Log::debug("Found $fileUpper as $actualName in base directory");
                            break;
                        }
                    }
                }
            }
            
            if (!$found) {
                Log::debug("File $fileUpper not found in $base");
                $missingFiles[] = $fileUpper;
                continue;
            }
            
            $dst = $work . DIRECTORY_SEPARATOR . $fileUpper;
            
            $copyNeeded = false;
            
            if (!file_exists($dst)) {
                $copyNeeded = true;
                Log::debug("File $fileUpper doesn't exist in work directory, will copy");
            } else {
                $srcStat = stat($src);
                $dstStat = stat($dst);
                
                Log::debug("Comparing $fileUpper: src_mtime={$srcStat['mtime']}, dst_mtime={$dstStat['mtime']}, src_size={$srcStat['size']}, dst_size={$dstStat['size']}");
                
                if ($srcStat['size'] !== $dstStat['size']) {
                    $copyNeeded = true;
                    Log::debug("File $fileUpper changed (size: {$srcStat['size']} != {$dstStat['size']}), will update");
                }
            }
            
            if ($copyNeeded) {
                Log::info("Copying $fileUpper from $base to $work");
                if (copy($src, $dst)) {
                    // Preserve mtime
                    $srcStat = stat($src);
                    touch($dst, $srcStat['mtime']);
                    
                    if (file_exists($dst)) {
                        $filesUpdated++;
                        Log::info("Copied/updated $fileUpper to work directory");
                    } else {
                        Log::error("Failed to copy $fileUpper to work directory");
                    }
                } else {
                    Log::error("Copy failed for $fileUpper");
                }
            } else {
                Log::debug("File $fileUpper is up to date in work directory");
            }
        }
        
        // Notificar archivos faltantes al servidor
        if (!empty($missingFiles)) {
            $this->reportMissingFiles($rbfid, $missingFiles);
        }
        
        if ($filesUpdated > 0) {
            Log::info("Synced $filesUpdated files to work directory for $rbfid");
        } else {
            Log::debug("No files needed syncing for $rbfid");
        }
    }
    
    private function reportMissingFiles(string $rbfid, array $missingFiles): void {
        Log::info("Reporting " . count($missingFiles) . " missing files to server: " . implode(', ', $missingFiles));
        
        try {
            $response = $this->req('missing', [
                'rbfid' => $rbfid,
                'missing_files' => $missingFiles,
                'timestamp' => time()
            ]);
            
            if (isset($response['ok']) ? $response['ok'] : false) {
                Log::info("Missing files reported successfully");
            } else {
                Log::error("Failed to report missing files: " . (isset($response['error']) ? $response['error'] : 'Unknown error'));
            }
        } catch (\Throwable $e) {
            Log::error("Error reporting missing files: " . $e->getMessage());
        }
    }

    public function register(): void {
        foreach ($this->locations as $l) {
            $this->req('register', ['rbfid' => $l['rbfid']]);
            Log::info("Registered: {$l['rbfid']}");
        }
    }

    public function syncLoop(): void {
        Log::info('Starting sync loop...');
        $fileCount = 0;
        while (true) {
            $currentTime = time();
            
            // Verificar configuración cada 3600 segundos (1 hora)
            if ($currentTime - $this->lastConfigCheck >= 3600) {
                $this->lastConfigCheck = $currentTime;
                $this->checkConfig();
            }
            
            if ($currentTime - $this->lastFull >= Constants::FULL_CHECK_SEC) {
                $this->lastFull = $currentTime;
                $this->fullSync();
            }
            foreach ($this->locations as $l) {
                // Sync files from base to work directory first
                $this->syncWorkFiles($l);
                
                foreach (Constants::$WATCH_FILES as $f) {
                    // Asegurar nombre en mayúsculas
                    $fUpper = strtoupper($f);
                    $wp = $l['work'] . DIRECTORY_SEPARATOR . $fUpper;
                    
                    if (!file_exists($wp))
                        continue;
                    
                    $st = stat($wp);
                    $k = hash('xxh64', $wp);
                    if (isset($this->cache[$k]) && $this->cache[$k]['mtime'] == $st['mtime'] && $this->cache[$k]['size'] == $st['size'])
                        continue;
                    
                    Log::info("Syncing $fUpper @ {$l['rbfid']}");
                    $this->uploadFile($l, $fUpper, $wp, (int) $st['mtime'], (int) $st['size']);
                    $this->cache[$k] = ['mtime' => $st['mtime'], 'size' => $st['size']];
                    
                    // Note: Removed debug exit to allow continuous operation
                }
            }
            Log::flush();
            sleep(Constants::POLL_SEC);
        }
    }

    private function checkConfig(): void {
        Log::info('Checking for updated file list (every 3600s)...');
        $l = isset($this->locations[0]) ? $this->locations[0] : null;
        if (!$l)
            return;
        
        // Cargar configuración actual
        $cfgPath = __DIR__ . '/config.json';
        $config = file_exists($cfgPath) ? json_decode(file_get_contents($cfgPath) ?: '{}', true) : [];
        $currentVersion = isset($config['files_version']) ? $config['files_version'] : '';
        
        $res = $this->req('config', ['rbfid' => $l['rbfid'], 'files_version' => $currentVersion]);
        
        if (!empty($res['files'])) {
            // Normalizar nombres a mayúsculas
            $files = array_map('strtoupper', $res['files']);
            $newVersion = isset($res['files_version']) ? $res['files_version'] : substr(md5(implode(',', $files)), 0, 8);
            
            Log::info("Updated file list (" . count($files) . "), version: $newVersion");
            
            // Actualizar Constants y config.json
            Constants::$WATCH_FILES = $files;
            $config['files_version'] = $newVersion;
            $config['watch_files'] = $files;
            
            file_put_contents($cfgPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            Log::info("Saved file list to config.json");
        } else {
            $version = isset($res['files_version']) ? $res['files_version'] : 'unknown';
            Log::debug("File list unchanged (version: $version)");
        }
    }
    
    private function fullSync(): void {
        Log::info('Running full hash check...');
        $l = isset($this->locations[0]) ? $this->locations[0] : null;
        if (!$l)
            return;
        
        // Cargar configuración actual
        $cfgPath = __DIR__ . '/config.json';
        $config = file_exists($cfgPath) ? json_decode(file_get_contents($cfgPath) ?: '{}', true) : [];
        $currentVersion = isset($config['files_version']) ? $config['files_version'] : '';
        
        $res = $this->req('config', ['rbfid' => $l['rbfid'], 'files_version' => $currentVersion]);
        
        if (!empty($res['files'])) {
            // Normalizar nombres a mayúsculas
            $files = array_map('strtoupper', $res['files']);
            $newVersion = isset($res['files_version']) ? $res['files_version'] : substr(md5(implode(',', $files)), 0, 8);
            
            Log::info("Updated file list (" . count($files) . "), version: $newVersion");
            
            // Actualizar Constants y config.json
            Constants::$WATCH_FILES = $files;
            $config['files_version'] = $newVersion;
            $config['watch_files'] = $files;
            
            file_put_contents($cfgPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            Log::info("Saved file list to config.json");
        } else {
            $version = isset($res['files_version']) ? $res['files_version'] : 'unknown';
            Log::debug("File list unchanged (version: $version)");
        }
    }

    private function uploadFile(array $loc, string $file, string $wp, int $mtime, int $size): void {
        // Asegurar nombre en mayúsculas
        $fileUpper = strtoupper($file);
        
        $h = Hash::computeFile($wp);
        $cs = Chunk::size($size);
        $chs = [];
        $off = 0;
        $fh = fopen($wp, 'rb');
        while ($off < $size) {
            $chs[] = hash('xxh3', fread($fh, $cs));
            $off += $cs;
        }
        fclose($fh);
        
        Log::debug("File $fileUpper: size=$size, chunk_size=$cs, total_chunks=" . count($chs));
        
        $req = $this->req('sync', ['rbfid' => $loc['rbfid'], 'files' => [['filename' => $fileUpper, 'hash_completo' => Hash::toBase64($h), 'chunk_hashes' => array_map(fn($c) => Hash::toBase64($c), $chs), 'mtime' => $mtime, 'size' => $size]]]);
        
        Log::debug("Sync response for $fileUpper: " . json_encode($req));
        
        $chunksUploaded = 0;
        $currentChunk = 0;
        
        // Upload initial chunks from needs_upload
        foreach (isset($req['needs_upload']) ? $req['needs_upload'] : [] as $t) {
            $off = $t['chunk'] * $cs;
            $d = file_get_contents($wp, false, null, $off, min($cs, $size - $off));
            $uploadResp = $this->req('upload', ['rbfid' => $loc['rbfid'], 'filename' => $fileUpper, 'chunk_index' => $t['chunk'], 'hash_xxh3' => Hash::toBase64(hash('xxh3', $d)), 'data' => base64_encode($d), 'size' => $size]);
            Log::debug("Uploaded chunk {$t['chunk']} of $fileUpper, response: " . json_encode($uploadResp));
            $chunksUploaded++;
            $currentChunk = $t['chunk'];
        }
        
        // Continue uploading if server returns next_chunk
        while (true) {
            $nextChunk = null;
            
            // Check if we should get next chunk from server response
            if (isset($uploadResp['next_chunk'])) {
                $nextChunk = $uploadResp['next_chunk'];
            }
            
            if ($nextChunk === null || $nextChunk >= count($chs)) {
                break;
            }
            
            $off = $nextChunk * $cs;
            $d = file_get_contents($wp, false, null, $off, min($cs, $size - $off));
            $uploadResp = $this->req('upload', ['rbfid' => $loc['rbfid'], 'filename' => $fileUpper, 'chunk_index' => $nextChunk, 'hash_xxh3' => Hash::toBase64(hash('xxh3', $d)), 'data' => base64_encode($d), 'size' => $size]);
            Log::debug("Uploaded chunk $nextChunk of $fileUpper, response: " . json_encode($uploadResp));
            $chunksUploaded++;
            $currentChunk = $nextChunk;
            
            // Small delay between chunks
            usleep(100000); // 0.1 second
        }
        
        Log::info("Uploaded $chunksUploaded chunks for $fileUpper (expected: " . count($chs) . ")");
        Log::flush();
    }

    private function req(string $action, array $body): array {
        $l = isset($this->locations[0]) ? $this->locations[0] : null;
        $ts = time();
        $rbfid = isset($l['rbfid']) ? $l['rbfid'] : '';
        $tok = Totp::gen($rbfid, $ts);
        
        // Construir URL con action y rbfid
        $url = $this->url . '/api/' . $action . '/' . $rbfid;
        
        curl_setopt($this->ch, CURLOPT_URL, $url);
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-RBFID: ' . $rbfid,
            'X-TOTP-Token: ' . $tok,
            'X-Timestamp: ' . $ts
        ]);
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, json_encode($body));
        $raw = curl_exec($this->ch);
        if ($raw === false) {
            $err = curl_error($this->ch);
            Log::error("cURL Error ($action): $err");
            return ['ok' => false, 'error' => $err];
        }
        $res = json_decode($raw, true) ?: [];
        $ok = (bool) (isset($res['ok']) ? $res['ok'] : false);
        Log::add("Server responded to '$action': " . ($ok ? 'OK' : 'FAIL'), $ok ? 'INFO' : 'ERROR');
        return $res;
    }

    public function __destruct() {
        if ($this->ch)
            curl_close($this->ch);
    }

    public function getLocations(): array {
        return $this->locations;
    }
}

// --- CLI Execution ---
try {
    $c = new Client();
    
    // Parse command line arguments
    $args = $_SERVER['argv'];
    array_shift($args); // Remove script name
    
    $cfg = __DIR__.'/config.json';
    $runOnce = false;
    
    foreach ($args as $arg) {
        if ($arg === '--run-once') {
            $runOnce = true;
        } elseif (strpos($arg, '--') !== 0) {
            // First non-option argument is config path
            $cfg = $arg;
        }
    }
    
    $c->discover($cfg);
    if (empty($c->getLocations())) { Log::add('No locations found. Exiting.'); exit; }
    $c->register();
    if ($runOnce) { $c->fullSync(); Log::add('Run once complete.'); exit; }
    $c->syncLoop();
} catch (\Throwable $e) { Log::add('CLI Fatal: '.$e->getMessage()); exit(1); }