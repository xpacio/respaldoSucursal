#!/usr/bin/env php
<?php

declare(strict_types=1);

define('VERSION', '0.1.0');

require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Constants.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Client.php';

function printHelp(): void
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

function printVersion(): void
{
    echo 'ar ' . VERSION . "\n";
}

function main(array $argv): void
{
    $showHelp = false;
    $showVersion = false;
    $runOnce = false;
    $customServer = null;
    $verbose = true;

    $i = 1;
    while ($i < count($argv)) {
        $arg = $argv[$i];
        
        if ($arg === '-h' || $arg === '--help') {
            $showHelp = true;
        } elseif ($arg === '-v' || $arg === '--version') {
            $showVersion = true;
        } elseif ($arg === '-q' || $arg === '--quiet') {
            $verbose = false;
        } elseif ($arg === '--run-once') {
            $runOnce = true;
        } elseif ($arg === '--server') {
            $i++;
            if ($i < count($argv)) {
                $customServer = $argv[$i];
            }
        }
        
        $i++;
    }

    if ($showHelp) {
        printHelp();
        return;
    }

    if ($showVersion) {
        printVersion();
        return;
    }

    $exeDir = Config::getExeDir();
    $logDir = $exeDir . DIRECTORY_SEPARATOR . 'logs';
    
    Logger::init($logDir, $verbose);
    Logger::info("AR - Agente de Respaldo v" . VERSION);
    Logger::info("Servidor: " . Constants::DEFAULT_SERVER_URL);
    Logger::debug("Directorio executable: $exeDir");

    if ($customServer !== null) {
        Config::getInstance()->server_url = $customServer;
        Logger::info("Servidor alternativo: $customServer");
    }

    try {
        $client = Client::init();
        
        $client->findRbfIni($exeDir);
        
        if (count($client->locations) === 0) {
            Logger::err('No se encontraron sucursales. Verifique que PVSI esté instalado o cree config.json manualmente.');
            return;
        }

        Logger::info('Sucursales configuradas: ' . count($client->locations));
        foreach ($client->locations as $loc) {
            Logger::info("  [{$loc->rbfid}] {$loc->base_path}");
        }

        try {
            $client->register();
        } catch (Exception $e) {
            Logger::warn('Register fallo: ' . $e->getMessage() . ' — continuando');
        }

        if ($runOnce) {
            $client->fullHashCheck();
        } else {
            $client->runLoop();
        }
        
    } catch (Exception $e) {
        Logger::err('Error: ' . $e->getMessage());
    }
}

main($argv);