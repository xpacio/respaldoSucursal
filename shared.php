<?php
declare(strict_types=1);

namespace App;

use Exception;
use PDO;

// --- Constants ---
class Constants
{
    public const CHUNK_MIN_SIZE = 65536;
    public const CHUNK_MAX_SIZE = 1048576;
    public const CHUNK_ALIGNMENT = 4096;
    public const CHUNK_1MB_THRESHOLD = 1048576;
    public const CHUNK_10MB_THRESHOLD = 10485760;
    public const CHUNK_100MB_THRESHOLD = 104857600;

    public const SYNC_DELAY_MS = 2000;
    public const RATE_DELAY_MIN_MS = 1000;
    public const RATE_DELAY_MAX_MS = 30000;
    public const MAX_SLOTS = 10;
    public const DEFAULT_RATE_DELAY_MS = 3000;
    public const FULL_SLOT_DELAY_MS = 10000;

    public const DEFAULT_SERVER_URL = 'http://respaldosucursal.servicios.care';

    public const POLL_INTERVAL_SECONDS = 300;
    public const STABILIZE_DELAY_MS = 2000;
    public const FULL_CHECK_INTERVAL_SECONDS = 3600;

    public const FILES_TO_WATCH = [
        'AJTFLU.DBF', 'ASISTE.DBF', 'CAJAS.DBF', 'CANCFDI.DBF', 'CANCFDI.FPT',
        'CANOTA.DBF', 'CANOTA.DBT', 'CANOTA.FPT', 'CANOTAEX.DBF', 'CARPORT.DBF',
        'CARPORT.FPT', 'CASEMANA.DBF', 'CAT_NEG.DBF', 'CATPROD3.DBF', 'CAT_PROD.DBF',
        'CCOTIZA.DBF', 'CENTER.DBF', 'CFDREL.DBF', 'CG3_VAEN.DBF', 'CG3_VAPA.DBF',
        'CLIENTE.DBF', 'COBRANZA.DBF', 'COMPRAS.DBF', 'CONCXC.DBF', 'CONVTODO.DBF',
        'CPEDIDO.DBF', 'CPENDIE.DBF', 'CPXCORTE.DBF', 'CRMC_OBS.DBF', 'CUENGAS.DBF',
        'CUNOTA.DBF', 'DD_CONTROL.DBF', 'DD_DATOS.DBF', 'DESEMANA.DBF', 'ES_COBRO.DBF',
        'EYSIENC.DBF', 'EYSIPAR.DBF', 'FACCFD.DBF', 'FACCFD.FPT', 'FLUJO01.DBF',
        'FLUJORES.DBF', 'HISTORIA.DBF', 'INVFSIC.DBF', 'MASTER.DBF', 'M_CONF.DBF',
        'MINTINV.DBF', 'MOVCXCD.DBF', 'MOVSINV.DBF', 'N_CONF.DBF', 'NEGADOS.DBF',
        'NOESTA.DBF', 'NOHAY.DBF', 'N_RESP.DBF', 'N_RESP.DBT', 'N_RESP_M.DBF',
        'N_RESP_M.DBT', 'OBSDOCS.DBF', 'PAGDOCS.DBF', 'PAGMULT.DBF', 'PAGSPEI.DBF',
        'PARAMS.DBF', 'PARTCOMP.DBF', 'PARTCOT.DBF', 'PARXCAR.DBF', 'PARVALES.DBF',
        'PAVACL.DBF', 'PCOTIZA.DBF', 'PEDIDO.DBF', 'PEDIDO1.DBF', 'PEDIDO2.DBF',
        'PPEDIDO.DBF', 'PPENDIE.DBF', 'RESP_PIN.DBF', 'R_BBVA.DBF', 'R_KUSHKI.DBF',
        'SERCFD2.DBF', 'STOCK.DBF', 'SUCURCTAI.DBF', 'TABLA004.DBF', 'TABLA005.DBF',
        'TERCAJAS.DBF', 'TLSERVI.DBF', 'USUARIOS.DBF', 'VACLI.DBF', 'VALES.DBF',
        'VALPEN.DBF', 'VCPENDI.DBF', 'VENDEDOR.DBF', 'VENTA.DBF', 'VENTA.DBT',
        'VENTA.FPT', 'VENTAPP.DBF', 'VPPENDI.DBF', 'XCORTE.DBF',
    ];

    public const EXCLUDED_DIRS_WINDOWS = [
        'windows', 'program files', 'program files (x86)', 'recycler', '$recycle.bin',
        'system volume information', 'documents and settings', 'perflogs', 'quickbck', '.ar_work',
    ];

    public const EXCLUDED_DIRS_LINUX = [
        'proc', 'sys', 'dev', 'run', 'snap', 'boot', 'lib', 'lib64', 'bin', 'sbin',
        'usr', 'etc', 'var', 'opt', 'quickbck', '.ar_work',
    ];
}

// --- Logger ---
class Logger
{
    private static string $logDir = 'logs';
    private static string $prefix = 'ar';
    private static ?string $currentDate = null;
    private static $handle = null;
    private static bool $verbose = true;
    private static bool $quiet = false;

    public static function init(string $logDir, bool $verbose = true): void
    {
        self::$logDir = $logDir;
        self::$verbose = $verbose;
        self::$currentDate = date('Y-m-d');
        if (!is_dir(self::$logDir)) mkdir(self::$logDir, 0755, true);
    }

    public static function setQuiet(bool $quiet): void { self::$quiet = $quiet; }

    private static function getHandle()
    {
        $today = date('Y-m-d');
        if (self::$handle === null || self::$currentDate !== $today) {
            if (self::$handle !== null) fclose(self::$handle);
            self::$currentDate = $today;
            $filepath = self::$logDir . DIRECTORY_SEPARATOR . self::$prefix . '-' . $today . '.log';
            self::$handle = fopen($filepath, 'a');
        }
        return self::$handle;
    }

    private static function write(string $level, string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $line = sprintf("[%s] [%s] %s\n", $timestamp, $level, $message);
        $handle = self::getHandle();
        if ($handle !== false) fwrite($handle, $line);
        if (!self::$quiet && (self::$verbose || $level === 'ERR' || $level === 'WARN')) {
            if (PHP_SAPI === 'cli' && defined('STDERR')) fwrite(STDERR, $line);
            else error_log($line);
        }
    }

    public static function debug(string $message): void { self::write('DBG', $message); }
    public static function info(string $message): void { self::write('INFO', $message); }
    public static function warn(string $message): void { self::write('WARN', $message); }
    public static function err(string $message): void { self::write('ERR', $message); }
    public static function setVerbose(bool $verbose): void { self::$verbose = $verbose; }
    public static function close(): void { if (self::$handle !== null) { fclose(self::$handle); self::$handle = null; } }
}

// --- Hash ---
class Hash
{
    private string $hex;
    public function __construct(string $hex) { $this->hex = $hex; }
    public static function compute(string $data): Hash { return new Hash(hash('xxh3', $data, false)); }
    public static function computeFile(string $path): Hash {
        return new Hash(\App\Utilities\StreamHasher::hashFileEfficient($path, 'xxh3', 5242880));
    }
    public function toBase64(): string {
        $bytes = hex2bin($this->hex);
        return substr(base64_encode(strrev($bytes)), 0, 11);
    }
    public static function fromBase64(string $str): Hash {
        $decoded = base64_decode(str_pad($str, 12, '='));
        if ($decoded === false || strlen($decoded) < 8) throw new Exception('Invalid hash');
        return new Hash(bin2hex(strrev($decoded)));
    }
    public function getHex(): string { return $this->hex; }
    public function equals(Hash $other): bool { return $this->hex === $other->hex; }
}

// --- Config ---
class Config
{
    public string $server_url = Constants::DEFAULT_SERVER_URL;
    public int $sync_interval_sec = 3600;
    public int $full_check_interval_ms = 3600000;
    public array $files = [];
    public string $files_version = '';
    public array $locations = [];
    private static Config $instance;

    public static function getInstance(): Config {
        if (!isset(self::$instance)) self::$instance = new Config();
        return self::$instance;
    }

    public static function getDbConfig(): array {
        return [
            'host' => 'localhost',
            'port' => 5432,
            'dbname' => 'sync',
            'user' => 'postgres',
            'password' => ''
        ];
    }

    public static function load(string $path): Config {
        $config = self::getInstance();
        if (!file_exists($path)) return $config;
        $data = json_decode(file_get_contents($path) ?: '{}', true);
        if (!$data) return $config;
        $config->server_url = $data['server_url'] ?? $config->server_url;
        $config->sync_interval_sec = (int)($data['sync_interval_sec'] ?? $config->sync_interval_sec);
        $config->full_check_interval_ms = (int)($data['full_check_interval_ms'] ?? $config->full_check_interval_ms);
        $config->files_version = $data['files_version'] ?? $config->files_version;
        $config->files = $data['files'] ?? $config->files;
        return $config;
    }

    public function save(string $path, array $locations = []): void {
        $data = [
            'server_url' => $this->server_url,
            'sync_interval_sec' => $this->sync_interval_sec,
            'full_check_interval_ms' => $this->full_check_interval_ms,
            'locations' => array_map(fn($l) => method_exists($l, 'toArray') ? $l->toArray() : (array)$l, $locations),
            'files_version' => $this->files_version,
            'files' => $this->files,
        ];
        if (file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT)) === false) throw new Exception("Cannot write config");
    }
}

// --- Database ---
namespace App\Config;
use PDO;
class Database {
    private static ?PDO $conn = null;
    private array $config;
    public function __construct(array $config) { $this->config = $config; }
    private function connect(): PDO {
        if (self::$conn === null) {
            $dsn = sprintf("pgsql:host=%s;port=%d;dbname=%s", $this->config['host'], $this->config['port'], $this->config['dbname']);
            self::$conn = new PDO($dsn, $this->config['user'], $this->config['password']);
            self::$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        return self::$conn;
    }
    public function fetchOne(string $sql, array $params = []): ?array {
        $stmt = $this->connect()->prepare($sql); $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    public function fetchAll(string $sql, array $params = []): array {
        $stmt = $this->connect()->prepare($sql); $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function execute(string $sql, array $params = []): int {
        $stmt = $this->connect()->prepare($sql); $stmt->execute($params);
        return $stmt->rowCount();
    }
    public function insert(string $sql, array $params = []): string {
        $stmt = $this->connect()->prepare($sql); $stmt->execute($params);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ? (string)reset($res) : $this->connect()->lastInsertId();
    }
}

// --- Traits ---
namespace App\Traits;
trait LoggingTrait {
    public function log(string $message): void { error_log("AR: " . $message); }
}
trait ResponseTrait {
    public function jsonResponse(array $data, int $code = 200): void {
        $ts = (string)time();
        if (!isset($data['timestamp'])) $data['timestamp'] = $ts;
        header('X-Timestamp: ' . $ts);
        header('Content-Type: application/json');
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// --- Utilities ---
namespace App\Utilities;
class ArgumentParser {
    private array $options = ['help' => false, 'version' => false, 'quiet' => false, 'run_once' => false, 'server' => null];
    public function parse(array $argv): void {
        for ($i = 1; $i < count($argv); $i++) {
            if ($argv[$i] === '-h' || $argv[$i] === '--help') $this->options['help'] = true;
            elseif ($argv[$i] === '-v' || $argv[$i] === '--version') $this->options['version'] = true;
            elseif ($argv[$i] === '-q' || $argv[$i] === '--quiet') $this->options['quiet'] = true;
            elseif ($argv[$i] === '--run-once') $this->options['run_once'] = true;
            elseif ($argv[$i] === '--server' && isset($argv[++$i])) $this->options['server'] = $argv[$i];
        }
    }
    public function hasOption(string $n): bool { return !empty($this->options[$n]); }
    public function getOption(string $n) { return $this->options[$n] ?? null; }
}
class FileUtil {
    public static function fileExists($p) { return file_exists($p); }
    public static function getContents($p) { return @file_get_contents($p) ?: null; }
}
class JsonUtil {
    public static function decode($j, $a = false) { return json_decode($j, $a); }
}
class StreamHasher {
    public static function hashFileEfficient($p, $a = 'xxh3', $t = 5242880) {
        $sz = @filesize($p) ?: 0;
        if ($sz < $t && function_exists('hash_file')) return hash_file($a, $p);
        $ctx = hash_init($a); $fh = fopen($p, 'rb');
        while (!feof($fh)) hash_update($ctx, fread($fh, 8192));
        fclose($fh); return hash_final($ctx);
    }
}

// --- Shared Services ---
namespace App\Services;
class TimestampManager {
    private array $ts = [];
    public function update($id, $t) { $this->ts[$id] = $t; }
    public function get($id) { return $this->ts[$id] ?? 0; }
}
class DatabaseService {
    private $db;
    public function __construct($db) { $this->db = $db; }
    public function fetchOne($s, $p=[]) { return $this->db->fetchOne($s, $p); }
    public function fetchAll($s, $p=[]) { return $this->db->fetchAll($s, $p); }
    public function execute($s, $p=[]) { return $this->db->execute($s, $p); }
    public function insert($s, $p=[]) { return $this->db->insert($s, $p); }
    public function getDb() { return $this->db; }
}

// --- TOTP Validator ---
namespace App;
use App\Config\Database;
class TotpValidator {
    public static function validate(Database $db, string $rbfid, string $token): array {
        $c = $db->fetchOne("SELECT enabled FROM clients WHERE rbfid = :rbfid", [':rbfid' => $rbfid]);
        if (!$c) return ['ok' => false, 'error' => 'No encontrado'];
        if ($c['enabled'] !== true && $c['enabled'] !== 't') return ['ok' => false, 'error' => 'Deshabilitado'];
        $now = time();
        for ($d = -30; $d <= 30; $d++) {
            $expected = Hash::compute(substr((string)($now + $d), 0, -2) . $rbfid)->toBase64();
            if (hash_equals($expected, $token)) return ['ok' => true];
        }
        return ['ok' => false, 'error' => 'Token invalido'];
    }
}
