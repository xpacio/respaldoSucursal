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
        }
        Log::info('Found ' . count($this->locations) . ' locations');
    }

    private function scanDisk(): void {
        foreach (['C', 'D'] as $drv) {
            $root = "$drv:\\\\";
            if (!is_dir($root))
                continue;
            foreach (scandir($root) as $dir) {
                if (in_array(strtolower($dir), Constants::EXCLUDED_WIN))
                    continue;
                $ini = "$root$dir\\rbf\\rbf.ini";
                if (!file_exists($ini))
                    continue;
                if (preg_match('/_suc=([^\n\r]+)/i', file_get_contents($ini), $m)) {
                    $id = trim($m[1], ' "');
                    $base = "$root$dir";
                    $this->locations[] = ['rbfid' => $id, 'base' => $base, 'work' => $base . '\\quickbck\\'];
                    Log::info("Auto-discovered: $id");
                }
            }
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
        while (true) {
            if (time() - $this->lastFull >= Constants::FULL_CHECK_SEC) {
                $this->lastFull = time();
                $this->fullSync();
            }
            foreach ($this->locations as $l) {
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
        $req = $this->req('sync', ['rbfid' => $loc['rbfid'], 'files' => [['filename' => $file, 'hash_completo' => Hash::toBase64($h), 'chunk_hashes' => array_map(fn($c) => Hash::toBase64($c), $chs), 'mtime' => $mtime, 'size' => $size]]]);
        foreach ($req['needs_upload'] ?? [] as $t) {
            $off = $t['chunk'] * $cs;
            $d = file_get_contents($wp, false, null, $off, min($cs, $size - $off));
            $this->req('upload', ['rbfid' => $loc['rbfid'], 'filename' => $t['file'], 'chunk_index' => $t['chunk'], 'hash_xxh3' => Hash::toBase64(hash('xxh3', $d)), 'data' => base64_encode($d), 'size' => $size]);
            Log::debug("Uploaded chunk {$t['chunk']} of $file");
        }
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
    $cfg = $_SERVER['argv'][1] ?? __DIR__.'/config.json';
    $c->discover($cfg);
    if (empty($c->getLocations())) { Log::add('No locations found. Exiting.'); exit; }
    $c->register();
    if (in_array('--run-once', $_SERVER['argv'])) { $c->fullSync(); Log::add('Run once complete.'); exit; }
    $c->syncLoop();
} catch (\Throwable $e) { Log::add('CLI Fatal: '.$e->getMessage()); exit(1); }