#!/usr/bin/env php
<?php declare(strict_types=1);
namespace App\Cli;

require_once __DIR__ . '/shared.php';
use App\DB; use App\Config; use App\Totp; use App\Log; use App\Storage; use App\Constants; use App\Hash; use App\Chunk;

class Client {
    private string $url;
    private array $locations = [];
    private int $lastFull = 0;
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
        $this->locations = array_map(fn($l) => ['rbfid' => $l['rbfid'], 'base' => $l['base'] ?? $l['base_path'] ?? null, 'work' => $l['work'] ?? $l['work_path'] ?? null], $data['locations'] ?? []);
        if (empty($this->locations)) {
            Log::info('Scanning disk...');
            $this->scanDisk();
            if (!empty($this->locations)) {
                $save = ['locations' => $this->locations];
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
        
        Log::debug("Syncing files from $base to $work for {$loc['rbfid']}");
        
        if (!is_dir($base)) {
            Log::error("Base directory not found: $base");
            return;
        }
        
        $filesUpdated = 0;
        
        foreach (Constants::$WATCH_FILES as $file) {
            $src = $base . DIRECTORY_SEPARATOR . $file;
            $dst = $work . DIRECTORY_SEPARATOR . $file;
            
            if (!file_exists($src)) {
                Log::debug("Source file $file not found in $base");
                continue;
            }
            
            $copyNeeded = false;
            
            if (!file_exists($dst)) {
                $copyNeeded = true;
                Log::debug("File $file doesn't exist in work directory, will copy");
            } else {
                $srcStat = stat($src);
                $dstStat = stat($dst);
                
                Log::debug("Comparing $file: src_mtime={$srcStat['mtime']}, dst_mtime={$dstStat['mtime']}, src_size={$srcStat['size']}, dst_size={$dstStat['size']}");
                
                if ($srcStat['size'] !== $dstStat['size']) {
                    $copyNeeded = true;
                    Log::debug("File $file changed (size: {$srcStat['size']} != {$dstStat['size']}), will update");
                }
                // Optional: also check mtime if source is newer
                // else if ($srcStat['mtime'] > $dstStat['mtime']) {
                //     $copyNeeded = true;
                //     Log::debug("File $file changed (mtime: {$srcStat['mtime']} > {$dstStat['mtime']}), will update");
                // }
            }
            
            if ($copyNeeded) {
                Log::info("Copying $file from $base to $work");
                if (copy($src, $dst)) {
                    // Preserve mtime
                    $srcStat = stat($src);
                    touch($dst, $srcStat['mtime']);
                    
                    if (file_exists($dst)) {
                        $filesUpdated++;
                        Log::info("Copied/updated $file to work directory");
                    } else {
                        Log::error("Failed to copy $file to work directory");
                    }
                } else {
                    Log::error("Copy failed for $file");
                }
            } else {
                Log::debug("File $file is up to date in work directory");
            }
        }
        
        if ($filesUpdated > 0) {
            Log::info("Synced $filesUpdated files to work directory for {$loc['rbfid']}");
        } else {
            Log::debug("No files needed syncing for {$loc['rbfid']}");
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
            if (time() - $this->lastFull >= Constants::FULL_CHECK_SEC) {
                $this->lastFull = time();
                $this->fullSync();
            }
            foreach ($this->locations as $l) {
                // Sync files from base to work directory first
                $this->syncWorkFiles($l);
                
                foreach (Constants::$WATCH_FILES as $f) {
                    $wp = $l['work'] . DIRECTORY_SEPARATOR . $f;
                    if (!file_exists($wp))
                        continue;
                    $st = stat($wp);
                    $k = hash('xxh64', $wp);
                    if (isset($this->cache[$k]) && $this->cache[$k]['mtime'] == $st['mtime'] && $this->cache[$k]['size'] == $st['size'])
                        continue;
                    Log::info("Syncing $f @ {$l['rbfid']}");
                    $this->uploadFile($l, $f, $wp, (int) $st['mtime'], (int) $st['size']);
                    $this->cache[$k] = ['mtime' => $st['mtime'], 'size' => $st['size']];
                    
                    // Note: Removed debug exit to allow continuous operation
                }
            }
            Log::flush();
            sleep(Constants::POLL_SEC);
        }
    }

    private function fullSync(): void {
        Log::info('Running full hash check...');
        $l = $this->locations[0] ?? null;
        if (!$l)
            return;
        $res = $this->req('config', ['rbfid' => $l['rbfid']]);
        if (!empty($res['files'])) {
            Log::info("Updated file list (" . count($res['files']) . ")");
            Constants::$WATCH_FILES = $res['files'];
        }
    }

    private function uploadFile(array $loc, string $file, string $wp, int $mtime, int $size): void {
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
        
        Log::debug("File $file: size=$size, chunk_size=$cs, total_chunks=" . count($chs));
        
        $req = $this->req('sync', ['rbfid' => $loc['rbfid'], 'files' => [['filename' => $file, 'hash_completo' => Hash::toBase64($h), 'chunk_hashes' => array_map(fn($c) => Hash::toBase64($c), $chs), 'mtime' => $mtime, 'size' => $size]]]);
        
        Log::debug("Sync response for $file: " . json_encode($req));
        
        $chunksUploaded = 0;
        $currentChunk = 0;
        
        // Upload initial chunks from needs_upload
        foreach ($req['needs_upload'] ?? [] as $t) {
            $off = $t['chunk'] * $cs;
            $d = file_get_contents($wp, false, null, $off, min($cs, $size - $off));
            $uploadResp = $this->req('upload', ['rbfid' => $loc['rbfid'], 'filename' => $t['file'], 'chunk_index' => $t['chunk'], 'hash_xxh3' => Hash::toBase64(hash('xxh3', $d)), 'data' => base64_encode($d), 'size' => $size]);
            Log::debug("Uploaded chunk {$t['chunk']} of $file, response: " . json_encode($uploadResp));
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
            $uploadResp = $this->req('upload', ['rbfid' => $loc['rbfid'], 'filename' => $file, 'chunk_index' => $nextChunk, 'hash_xxh3' => Hash::toBase64(hash('xxh3', $d)), 'data' => base64_encode($d), 'size' => $size]);
            Log::debug("Uploaded chunk $nextChunk of $file, response: " . json_encode($uploadResp));
            $chunksUploaded++;
            $currentChunk = $nextChunk;
            
            // Small delay between chunks
            usleep(100000); // 0.1 second
        }
        
        Log::info("Uploaded $chunksUploaded chunks for $file (expected: " . count($chs) . ")");
        Log::flush();
    }

    private function req(string $action, array $body): array {
        $l = $this->locations[0] ?? null;
        $ts = time();
        $tok = Totp::gen($l['rbfid'] ?? '', $ts);
        curl_setopt($this->ch, CURLOPT_URL, $this->url);
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, json_encode(['action' => $action, ...$body, 'totp_token' => $tok, 'timestamp' => $ts]));
        $raw = curl_exec($this->ch);
        if ($raw === false) {
            $err = curl_error($this->ch);
            Log::error("cURL Error ($action): $err");
            return ['ok' => false, 'error' => $err];
        }
        $res = json_decode($raw, true) ?: [];
        $ok = (bool) ($res['ok'] ?? false);
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