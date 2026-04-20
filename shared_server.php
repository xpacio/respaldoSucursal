<?php declare(strict_types=1);
namespace App;

use PDO;

require_once __DIR__ . '/shared_core.php';

// --- DB & Config ---
class DB
{
    private static ?PDO $conn = null;
    public function __construct(array $cfg)
    {
        if (self::$conn === null) {
            self::$conn = new PDO("pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']}", $cfg['user'], $cfg['password']);
            self::$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
    }
    public function begin(): void { self::$conn->beginTransaction(); }
    public function commit(): void { self::$conn->commit(); }
    public function rollBack(): void { self::$conn->rollBack(); }
    public function q(string $sql, array $p = []): ?array
    {
        $s = self::$conn->prepare($sql);
        $s->execute($p);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    public function qa(string $sql, array $p = []): array
    {
        $s = self::$conn->prepare($sql);
        $s->execute($p);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public function exec(string $sql, array $p = []): int
    {
        $s = self::$conn->prepare($sql);
        $s->execute($p);
        return $s->rowCount();
    }
    public function insert(string $sql, array $p = []): string
    {
        $s = self::$conn->prepare($sql);
        $s->execute($p);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ? (string) reset($r) : self::$conn->lastInsertId();
    }
}

class Config
{
    public static function getDb(): array
    {
        return ['host' => 'localhost', 'port' => 5432, 'dbname' => 'sync', 'user' => 'postgres', 'password' => ''];
    }
}

// --- TOTP Verification ---
class TotpServer extends Totp
{
    public static function verify(DB $db, string $rbfid, string $token): bool
    {
        $c = $db->q("SELECT enabled FROM clients WHERE rbfid = :r", [':r' => $rbfid]);
        if (!$c || $c['enabled'] !== true && $c['enabled'] !== 't')
            return false;
        $now = time();
        for ($d = -30; $d <= 30; $d++)
            if (hash_equals(self::gen($rbfid, $now + $d), $token))
                return true;
        return false;
    }
}

// --- Services ---
class Storage
{
    public static function saveChunk(string $dir, string $f, int $idx, int $off, string $d): bool
    {
        if (!is_dir($dir))
            mkdir($dir, 0755, true);
        $p = $dir . '/' . $f;
        $fh = @fopen($p, 'c+b');
        if (!$fh)
            return false;
        if (flock($fh, LOCK_EX)) {
            fseek($fh, $off);
            $written = fwrite($fh, $d);
            if ($written !== strlen($d)) {
                Log::error("Storage write error: $p at offset $off. Expected " . strlen($d) . " bytes, wrote $written.");
                flock($fh, LOCK_UN);
                fclose($fh);
                return false;
            }
            fflush($fh);
            flock($fh, LOCK_UN);
            fclose($fh);
            return true;
        }
        fclose($fh);
        return false;
    }
}

// --- JSON Response Helper ---
trait JsonRes
{
    public static function json(array $data, int $code = 200, bool $attachLogs = true): void
    {
        header("Access-Control-Allow-Origin: *");
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, X-User-Id, X-TOTP-Token, Content-Type');
        if ($attachLogs)
            $data['_log'] = Log::getBuffer();
        $data['timestamp'] = time();
        header('Content-Type: application/json');
        http_response_code($code);
        header_remove('X-Powered-By');
        exit(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    public static function err(string $msg, int $code = 400): void
    {
        Log::error($msg);
        self::json(['ok' => false, 'error' => $msg], $code);
    }
}
