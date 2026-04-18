<?php declare(strict_types=1);
namespace App;

use PDO;
use Exception;

// --- Constants ---
class Constants
{
    public const CHUNK_MIN = 65536;
    public const CHUNK_MAX = 1048576;
    public const THRESHOLDS = [1048576 => 65536, 10485760 => 262144, 104857600 => 1048576];
    public const DEFAULT_URL = 'http://respaldosucursal.servicios.care';
    public const POLL_SEC = 300;
    public const FULL_CHECK_SEC = 10;
    public static array $WATCH_FILES = ['AJTFLU.DBF', 'ASISTE.DBF', 'CAJAS.DBF', 'CANCFDI.DBF', 'CANCFDI.FPT', 'CANOTA.DBF', 'CANOTA.DBT', 'CANOTA.FPT', 'CANOTAEX.DBF', 'CARPORT.DBF', 'CARPORT.FPT', 'CASEMANA.DBF', 'CAT_NEG.DBF', 'CATPROD3.DBF', 'CAT_PROD.DBF', 'CCOTIZA.DBF', 'CENTER.DBF', 'CFDREL.DBF', 'CG3_VAEN.DBF', 'CG3_VAPA.DBF', 'CLIENTE.DBF', 'COBRANZA.DBF', 'COMPRAS.DBF', 'CONCXC.DBF', 'CONVTODO.DBF', 'CPEDIDO.DBF', 'CPENDIE.DBF', 'CPXCORTE.DBF', 'CRMC_OBS.DBF', 'CUENGAS.DBF', 'CUNOTA.DBF', 'DD_CONTROL.DBF', 'DD_DATOS.DBF', 'DESEMANA.DBF', 'ES_COBRO.DBF', 'EYSIENC.DBF', 'EYSIPAR.DBF', 'FACCFD.DBF', 'FACCFD.FPT', 'FLUJO01.DBF', 'FLUJORES.DBF', 'HISTORIA.DBF', 'INVFSIC.DBF', 'MASTER.DBF', 'M_CONF.DBF', 'MINTINV.DBF', 'MOVCXCD.DBF', 'MOVSINV.DBF', 'N_CONF.DBF', 'NEGADOS.DBF', 'NOESTA.DBF', 'NOHAY.DBF', 'N_RESP.DBF', 'N_RESP.DBT', 'N_RESP_M.DBF', 'N_RESP_M.DBT', 'OBSDOCS.DBF', 'PAGDOCS.DBF', 'PAGMULT.DBF', 'PAGSPEI.DBF', 'PARAMS.DBF', 'PARTCOMP.DBF', 'PARTCOT.DBF', 'PARXCAR.DBF', 'PARVALES.DBF', 'PAVACL.DBF', 'PCOTIZA.DBF', 'PEDIDO.DBF', 'PEDIDO1.DBF', 'PEDIDO2.DBF', 'PPEDIDO.DBF', 'PPENDIE.DBF', 'RESP_PIN.DBF', 'R_BBVA.DBF', 'R_KUSHKI.DBF', 'SERCFD2.DBF', 'STOCK.DBF', 'SUCURCTAI.DBF', 'TABLA004.DBF', 'TABLA005.DBF', 'TERCAJAS.DBF', 'TLSERVI.DBF', 'USUARIOS.DBF', 'VACLI.DBF', 'VALES.DBF', 'VALPEN.DBF', 'VCPENDI.DBF', 'VENDEDOR.DBF', 'VENTA.DBF', 'VENTA.DBT', 'VENTA.FPT', 'VENTAPP.DBF', 'VPPENDI.DBF', 'XCORTE.DBF'];
    public const EXCLUDED_WIN = ['windows', 'program files', 'program files (x86)', 'recycler', '$recycle.bin', 'system volume information', 'documents and settings', 'perflogs', 'quickbck', '.ar_work'];
}

// --- Logger & Request Buffer ---
class Log
{
    private static string $dir = 'logs';
    private static string $prefix = 'ar';
    private static array $buffer = [];
    private static bool $verbose = true;

    public static function init(string $d, bool $verbose = true): void
    {
        self::$dir = $d;
        self::$verbose = $verbose;
        if (!is_dir(self::$dir))
            mkdir(self::$dir, 0755, true);
        register_shutdown_function([self::class, 'flush']);
    }

    public static function add(string $msg, string $level = 'INFO'): void
    {
        $line = sprintf("[%s] [%s] %s", date('Y-m-d H:i:s'), $level, $msg);
        self::$buffer[] = $line;
        if (self::$verbose && PHP_SAPI === 'cli')
            echo $line . PHP_EOL;
    }

    public static function debug(string $m): void { self::add($m, 'DEBUG'); }
    public static function info(string $m): void { self::add($m, 'INFO'); }
    public static function error(string $m): void { self::add($m, 'ERROR'); }

    public static function flush(): void
    {
        if (empty(self::$buffer))
            return;
        if (!is_dir(self::$dir))
            @mkdir(self::$dir, 0777, true);
        $toPersist = (PHP_SAPI === 'cli') ? self::$buffer : array_filter(self::$buffer, fn($l) => str_contains($l, '[ERROR]'));
        if (!empty($toPersist)) {
            $f = self::$dir . '/' . self::$prefix . '-' . date('Y-m-d') . '.log';
            @file_put_contents($f, implode(PHP_EOL, $toPersist) . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
        self::$buffer = [];
    }

    public static function getBuffer(): array { return self::$buffer; }
    public static function clear(): void { self::$buffer = []; }
}

// --- Core Utilities ---
class Hash
{
    public static function compute(string $d): string
    {
        return hash('xxh3', $d, false);
    }
    public static function computeFile(string $p): string
    {
        return hash_file('xxh3', $p);
    }
    public static function toBase64(string $hex): string
    {
        return substr(base64_encode(strrev(hex2bin($hex))), 0, 11);
    }
    public static function fromBase64(string $str): string
    {
        return bin2hex(strrev(base64_decode(str_pad($str, 12, '='))));
    }
}

class Chunk
{
    public static function size(int $sz): int
    {
        $sz = max(0, $sz);
        foreach (Constants::THRESHOLDS as $thr => $cs)
            if ($sz < $thr)
                return $cs;
        return Constants::CHUNK_MAX;
    }
}

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

// --- TOTP & Auth ---
class Totp
{
    public static function gen(string $rbfid, int $ts): string
    {
        return Hash::toBase64(Hash::compute(substr((string) $ts, 0, -2) . $rbfid));
    }
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
        $fh = fopen($p, 'c+b');
        if (!$fh)
            return false;
        if (flock($fh, LOCK_EX)) {
            fseek($fh, $off);
            fwrite($fh, $d);
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