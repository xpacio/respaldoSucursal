<?php declare(strict_types=1);
namespace App;

require_once __DIR__ . '/shared_core.php';

class ClientConfig
{
    public static function load(string $path): array
    {
        if (!file_exists($path)) return [];
        return json_decode(file_get_contents($path) ?: '{}', true) ?: [];
    }

    public static function save(string $path, array $data): bool
    {
        return (bool) file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

class Platform
{
    public static function isWindows(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    public static function scanDisk(): array {
        $locs = [];
        $drives = self::isWindows() ? ['C','D','E','F','G'] : ['/mnt','/media'];
        foreach ($drives as $d) {
            $base = self::isWindows() ? "$d:\\pvsi" : "$d/pvsi";
            if (!is_dir($base)) continue;

            $rbfid = null;
            $rFile = $base . DIRECTORY_SEPARATOR . '.rbfid';
            $iniFile = $base . DIRECTORY_SEPARATOR . 'rbf' . DIRECTORY_SEPARATOR . 'rbf.ini';

            if (file_exists($rFile)) {
                $rbfid = trim(file_get_contents($rFile));
            } elseif (file_exists($iniFile)) {
                if (preg_match('/_suc=([^\n\r]+)/i', file_get_contents($iniFile), $m)) {
                    $rbfid = trim($m[1], ' "');
                }
            }

            if ($rbfid) {
                $locs[] = [
                    'rbfid' => $rbfid, 
                    'base' => $base, 
                    'work' => $base . DIRECTORY_SEPARATOR . 'quickbck'
                ];
            }
        }
        return $locs;
    }

    public static function getDrives(): array
    {
        if (!self::isWindows()) return [];
        $drives = [];
        foreach (range('C', 'Z') as $drive) {
            if (is_dir($drive . ':\\')) $drives[] = $drive;
        }
        return $drives;
    }
}

class HttpClient
{
    private $ch;
    private string $baseUrl;

    public function __construct(string $url)
    {
        $this->baseUrl = rtrim($url, '/');
        $this->ch = curl_init();
        curl_setopt_array($this->ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);
    }

    public function req(string $action, string $rbfid, array $body): array
    {
        $ts = time();
        $tok = Totp::gen($rbfid, $ts);
        $url = $this->baseUrl . '/api/' . $action . '/' . $rbfid;

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
            Log::error("cURL Error ($action): " . curl_error($this->ch));
            return ['ok' => false, 'error' => curl_error($this->ch)];
        }
        
        $res = json_decode($raw, true) ?: [];
        $ok = (bool) ($res['ok'] ?? false);
        Log::add("Server responded to '$action': " . ($ok ? 'OK' : 'FAIL'), $ok ? 'DEBUG' : 'ERROR');
        return $res;
    }

    public function __destruct()
    {
        if ($this->ch) curl_close($this->ch);
    }
}

class ServiceRunner
{
    public static function run(string $service, string $rbfid): void
    {
        $cmd = sprintf("php cli.php -%s -rbfid %s", escapeshellarg($service), escapeshellarg($rbfid));
        if (Platform::isWindows()) {
            pclose(popen("start /B " . $cmd, "r"));
        } else {
            exec($cmd . " > /dev/null 2>&1 &");
        }
    }
}
