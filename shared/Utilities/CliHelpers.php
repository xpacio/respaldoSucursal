<?php

declare(strict_types=1);

namespace App\Utilities;

class CliHelpers
{
    private const VERSION = '0.1.0';

    public static function printHelp(): void
    {
        echo <<<'HELP'
AR - Agente de Respaldo

Uso: php cli.php [opciones]

Opciones:
  -h, --help        Mostrar esta ayuda
  -v, --version     Mostrar versión
  -q, --quiet       Solo mostrar info y errores
  --run-once        Ejecutar sincronización una vez y salir
  --server URL      Usar servidor alternativo

HELP;
    }

    public static function printVersion(): void
    {
        echo 'ar ' . self::VERSION . "\n";
    }
}