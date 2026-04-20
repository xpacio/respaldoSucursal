<?php declare(strict_types=1);
namespace App;

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
        
        if (PHP_SAPI === 'cli') {
            //if (self::$verbose) echo $line . PHP_EOL;
            echo $line . PHP_EOL;
        } else {
            // Enviar a error_log de PHP solo en modo servidor (Lighttpd/FastCGI)
            error_log($line);
        }
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
        return hash_file('xxh3', $p) ?: '';
    }
    public static function toBase64(string $hex): string
    {
        $bin = hex2bin($hex);
        return $bin ? substr(base64_encode(strrev($bin)), 0, 11) : '';
    }
    public static function fromBase64(string $str): string
    {
        $decoded = base64_decode(str_pad($str, 12, '='));
        return $decoded ? bin2hex(strrev($decoded)) : '';
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

// --- TOTP Generation ---
class Totp
{
    public static function gen(string $rbfid, int $ts): string
    {
        return Hash::toBase64(Hash::compute(substr((string) $ts, 0, -2) . $rbfid));
    }
}
