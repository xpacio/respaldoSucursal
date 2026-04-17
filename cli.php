#!/usr/bin/env php
<?php
declare(strict_types=1);

namespace {
    require_once __DIR__ . '/shared.php';
}

namespace App\Cli {
    use App\Constants;
    use App\Logger;
    use App\Services\TimestampManager;
    use App\Services\ConfigService;
    use App\Services\RegistrationService;
    use App\Services\SyncService;
    use App\Services\LocationDiscoveryService;
    use App\Utilities\Chunk;
    use App\TotpValidator;
    use Exception;

    class Client {
        public array $locations = [];
        private string $url;
        private HttpClient $http;
        private ConfigService $cfgSvc;
        private RegistrationService $regSvc;
        private SyncService $syncSvc;
        private LocationDiscoveryService $disco;
        private string $cfgPath = '';
        private string $v = '';
        private array $files = [];
        private int $lastFull = 0;
        private array $cache = [];

        public function __construct() {
            $this->url = Constants::DEFAULT_SERVER_URL;
            $tsM = new TimestampManager();
            $this->http = new HttpClient(); $this->http->setTimestampManager($tsM);
            $this->cfgSvc = new ConfigService();
            $this->regSvc = new RegistrationService($this->http, $this->url); $this->regSvc->setTimestampManager($tsM);
            $this->syncSvc = new SyncService($this->http, $this->regSvc);
            $this->disco = new LocationDiscoveryService($this->cfgSvc);
        }

        public function findRbfIni($p) { $this->cfgPath = $p; $this->locations = $this->disco->findLocations($p, dirname($_SERVER['argv'][0])); }
        public function setLastFull($t) { $this->lastFull = $t; }
        public function saveState() {
            foreach ($this->locations as $l) {
                file_put_contents($l->work_path.'/XCORTE.json', json_encode(['lastFull' => $this->lastFull, 'cache' => $this->cache], JSON_PRETTY_PRINT));
            }
        }
        public function loadState() {
            foreach ($this->locations as $l) {
                $f = $l->work_path.'/XCORTE.json';
                if (file_exists($f)) {
                    $d = json_decode(file_get_contents($f), true);
                    if ($d) { $this->lastFull = $d['lastFull'] ?? 0; $this->cache = $d['cache'] ?? []; }
                }
            }
        }

        public function register() {
            foreach ($this->locations as $l) {
                try {
                    $ts = $this->regSvc->fetchTimestamp($l->rbfid);
                    if ($ts) $this->http->registerClient($this->url, $l->rbfid, TotpValidator::generate($l->rbfid, $ts));
                } catch (Exception $e) { Logger::warn("Reg Error: ".$e->getMessage()); }
            }
        }

        public function fullHashCheck() {
            if (!$this->locations) return; $l = $this->locations[0];
            $res = $this->http->fetchFileListVersioned($this->url, $l->rbfid, TotpValidator::generate($l->rbfid, $this->regSvc->fetchTimestamp($l->rbfid) ?: (int)time()), $this->v);
            if (!empty($res['files'])) { $this->files = $res['files']; $this->v = $res['version']; }
            foreach ($this->locations as $loc) {
                $this->copyToWork($loc);
                foreach ($this->files as $f) {
                    $w = $loc->work_path.$f; if (!file_exists($w)) continue;
                    $st = stat($w);
                    if ($this->syncSvc->syncFile($this->url, $loc, $f, $w, true)) {
                        $this->cache[hash('xxh64', $w)] = ['mtime' => $st['mtime'], 'size' => $st['size']];
                    }
                }
            }
        }

        public function runLoop() {
            while (true) {
                if (time() - $this->lastFull >= 3600) { $this->lastFull = time(); $this->fullHashCheck(); $this->saveState(); }
                foreach ($this->locations as $l) {
                    $this->copyToWork($l);
                    foreach ($this->files as $f) {
                        $w = $l->work_path.$f; if (!file_exists($w)) continue;
                        $st = stat($w); $k = hash('xxh64', $w);
                        if (isset($this->cache[$k]) && $this->cache[$k]['mtime'] === $st['mtime'] && $this->cache[$k]['size'] === $st['size']) continue;
                        if ($this->syncSvc->syncFile($this->url, $l, $f, $w, false)) {
                            $this->cache[$k] = ['mtime' => $st['mtime'], 'size' => $st['size']];
                        }
                    }
                }
                sleep(Constants::POLL_INTERVAL_SECONDS);
            }
        }

        private function copyToWork($loc) {
            if (!is_dir($loc->work_path)) mkdir($loc->work_path, 0755, true);
            foreach ($this->files as $f) {
                $src = $loc->base_path.DIRECTORY_SEPARATOR.$f;
                if (file_exists($src)) copy($src, $loc->work_path.$f);
            }
        }
    }

    class HttpClient {
        private $tm; public function setTimestampManager($m) { $this->tm = $m; }
        public function registerClient($u, $id, $t) { return $this->req($u, ['action'=>'register','rbfid'=>$id,'totp_token'=>$t]); }
        public function fetchFileListVersioned($u, $id, $t, $v) { return $this->req($u, ['action'=>'config','rbfid'=>$id,'totp_token'=>$t,'files_version'=>$v]); }
        public function sync($u, $id, $t, $fs) { return SyncResponse::fromArray($this->req($u, ['action'=>'sync','rbfid'=>$id,'totp_token'=>$t,'files'=>array_map(fn($f)=>$f->toArray(), $fs)])); }
        public function upload($u, $id, $t, $fn, $idx, $h, $d) { return $this->req($u, ['action'=>'upload','rbfid'=>$id,'totp_token'=>$t,'filename'=>$fn,'chunk_index'=>$idx,'hash_xxh3'=>$h,'data'=>base64_encode($d)]); }
        private function req($u, $b) {
            $ch = curl_init($u); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($b)); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $res = curl_exec($ch); $data = json_decode($res, true);
            if ($data && isset($data['timestamp'])) $this->tm->update($b['rbfid'], (int)$data['timestamp']);
            curl_close($ch); return $data;
        }
    }

    class Location {
        public $rbfid, $base_path, $work_path;
        public function __construct($id, $b, $w) { $this->rbfid = $id; $this->base_path = $b; $this->work_path = $w; }
        public function toArray() { return ['rbfid'=>$this->rbfid, 'base'=>$this->base_path, 'work'=>$this->work_path]; }
        public static function fromArray($d) { return new Location($d['rbfid'], $d['base'], $d['work']); }
    }

    class SyncResponse {
        public $ok, $sync_id, $needs_upload;
        public static function fromArray($d) {
            $r = new self(); $r->ok = $d['ok']??false; $r->sync_id = (string)($d['sync_id']??'');
            $r->needs_upload = array_map(fn($i)=>UploadTarget::fromArray($i), $d['needs_upload']??[]);
            return $r;
        }
    }

    class UploadTarget {
        public $file, $chunk, $md5;
        public static function fromArray($d) { $t = new self(); $t->file = $d['file']??''; $t->chunk = $d['chunk']??0; $t->md5 = $d['md5']??''; return $t; }
    }

    class FileHashData {
        public $filename, $hash, $chunks, $mtime, $size;
        public function __construct($f, $h, $c, $m, $s) { $this->filename = $f; $this->hash = $h; $this->chunks = $c; $this->mtime = $m; $this->size = $s; }
        public function toArray() { return ['filename'=>$this->filename, 'hash_completo'=>(new \App\Hash($this->hash))->toBase64(), 'chunk_hashes'=>array_map(fn($h)=>(new \App\Hash($h))->toBase64(), $this->chunks), 'mtime'=>$this->mtime, 'size'=>$this->size]; }
    }
}

namespace App\Services {
    use App\Logger;
    use App\Cli\Location;
    use App\Cli\Chunk;
    use App\Cli\FileHashData;

    class ConfigService {
        public function loadLocations($p) { if(!file_exists($p)) return null; $d=json_decode(file_get_contents($p),true); return array_map(fn($l)=>Location::fromArray($l), $d['locations']??[]); }
        public function saveLocations($p, $ls, $v, $fw) { $c = \App\Config::load($p); $c->files_version = $v; $c->files = $fw; $c->save($p, $ls); }
    }

    class RegistrationService {
        private $http, $url, $tm;
        public function __construct($h, $u) { $this->http = $h; $this->url = $u; }
        public function setTimestampManager($m) { $this->tm = $m; }
        public function fetchTimestamp($id) { 
            $res = @file_get_contents(rtrim($this->url,'/').'/health');
            if ($res === false) return 0;
            $data = json_decode($res, true); $ts = (int)($data['timestamp']??0);
            if ($ts) $this->tm->update($id, $ts); return $ts;
        }
        public function generateTotp($id) {
            $ts = $this->tm->get($id); if (!$ts) throw new \Exception('No TS');
            return TotpValidator::generate($id, $ts);
        }
    }

    class SyncService {
        private $http, $reg; public function __construct($h, $r) { $this->http=$h; $this->reg=$r; }
        public function syncFile($u, $l, $f, $w, $forced) {
            $st = stat($w); $h = \App\Utilities\StreamHasher::hashFileEfficient($w);
            $cs = Chunk::calculateChunkSize((int)$st['size']); $chs = []; $off = 0; $fh = fopen($w,'rb');
            while ($off < $st['size']) { fseek($fh, $off); $chs[] = \App\Hash::compute(fread($fh, $cs))->getHex(); $off += $cs; }
            fclose($fh); $totp = $this->reg->generateTotp($l->rbfid);
            $res = $this->http->sync($u, $l->rbfid, $totp, [new FileHashData($f, $h, $chs, (int)$st['mtime'], (int)$st['size'])]);
            if (!$res->needs_upload) return true;
            $fh = fopen($w, 'rb');
            foreach ($res->needs_upload as $t) {
                $off = $t->chunk * $cs; fseek($fh, $off); $data = fread($fh, min($cs, $st['size']-$off));
                $this->http->upload($u, $l->rbfid, $totp, $f, $t->chunk, \App\Hash::compute($data)->getHex(), $data);
            }
            fclose($fh); return true;
        }
    }

    class LocationDiscoveryService {
        private $cfg; public function __construct($c) { $this->cfg = $c; }
        
        public function findLocations(string $cfgPath, string $exeDir): array {
            $locations = $this->cfg->loadLocations($cfgPath);
            if ($locations !== null) return $locations;
            Logger::warn("config.json sin locations, escaneando disco...");
            $locations = $this->scanForLocations();
            if (empty($locations)) throw new Exception('No se encontraron sucursales.');
            foreach ($locations as $loc) { if (!is_dir($loc->work_path)) mkdir($loc->work_path, 0755, true); }
            return $locations;
        }

        private function scanForLocations(): array {
            $locations = [];
            if (PHP_OS === 'WINNT') {
                foreach (['C', 'D'] as $drive) {
                    $root = $drive . ':\\\\'; if (!is_dir($root)) continue;
                    $entries = scandir($root);
                    foreach ($entries as $entry) {
                        if (in_array(strtolower($entry), Constants::EXCLUDED_DIRS_WINDOWS)) continue;
                        $res = $this->findRbfIniInDir($root . $entry, 3);
                        if ($res) {
                            [$id, $base] = $res;
                            $locations[] = new Location($id, $base, $base . DIRECTORY_SEPARATOR . 'quickbck' . DIRECTORY_SEPARATOR);
                        }
                    }
                }
            }
            return $locations;
        }

        private function findRbfIniInDir(string $dir, int $depth): ?array {
            $ini = $dir . DIRECTORY_SEPARATOR . 'rbf' . DIRECTORY_SEPARATOR . 'rbf.ini';
            if (file_exists($ini)) {
                $content = file_get_contents($ini);
                if (preg_match('/_suc=([^\\n\r]+)/i', $content, $m)) {
                    return [trim($m[1], ' "'), dirname(dirname($ini))];
                }
            }
            return null;
        }
    }
}

namespace {
    use App\Cli\Client;
    use App\Utilities\ArgumentParser;
    use App\Utilities\CliHelpers;
    use App\Logger;

    $p = new ArgumentParser(); $p->parse($argv); 
    if ($p->hasOption('help')) { CliHelpers::printHelp(); exit; }
    $exeDir = dirname($_SERVER['argv'][0]); Logger::init($exeDir.'/logs', !$p->hasOption('quiet'));
    try {
        $c = new Client(); $c->loadState(); $c->findRbfIni($exeDir.'/config.json');
        if (!$c->locations) { Logger::err('No sucursales'); exit; }
        $c->register();
        if ($p->hasOption('run_once')) { $c->fullHashCheck(); $c->saveState(); }
        else { $c->fullHashCheck(); $c->setLastFull(time()); $c->runLoop(); }
    } catch (\Exception $e) { Logger::err($e->getMessage()); }
}