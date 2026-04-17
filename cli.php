#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/shared/autoload.php';

use App\Logger;
use App\Constants;
use App\Config;
use App\Cli\Client;
use App\Cli\Location;
use App\Services\ConfigLoader;
use App\Utilities\ArgumentParser;
use App\Utilities\CliHelpers;
use Exception;

function main(array $argv): void
{
    $parser = new ArgumentParser();
    $parser->parse($argv);

    if ($parser->hasOption('help')) {
        CliHelpers::printHelp();
        return;
    }

    if ($parser->hasOption('version')) {
        CliHelpers::printVersion();
        return;
    }

    $verbose = !$parser->hasOption('quiet');
    $runOnce = $parser->hasOption('run_once');
    $customServer = $parser->getOption('server');

    $exeDir = dirname($_SERVER['argv'][0]);
    $logDir = $exeDir . DIRECTORY_SEPARATOR . 'logs';
    $cfgPath = $exeDir . DIRECTORY_SEPARATOR . 'config.json';
    
    Logger::init($logDir, $verbose);
    Logger::info("AR - Agente de Respaldo");
    Logger::info("Servidor: " . Constants::DEFAULT_SERVER_URL);

    if ($customServer !== null) {
        Config::getInstance()->server_url = $customServer;
        Logger::info("Servidor alternativo: $customServer");
    }

    try {
        $client = Client::init();
        $client->loadState();
        
        $configLoader = new ConfigLoader();
        
        // Intentar cargar configuración existente
        $configLoaded = $configLoader->loadConfig($cfgPath, $client);
        
        if (!$configLoaded) {
            Logger::warn("config.json sin ubicaciones, escaneando disco...");
            $client->findRbfIni($exeDir);
        }
        
        if (count($client->locations) === 0) {
            Logger::err('No se encontraron sucursales. Verifique que PVSI esté instalado o cree config.json manualmente.');
            return;
        }

        Logger::info('Sucursales configuradas: ' . count($client->locations));
        foreach ($client->locations as $loc) {
            Logger::info("  [{$loc->rbfid}] {$loc->base_path}");
        }

        if (!file_exists($cfgPath)) {
            $firstLoc = $client->locations[0];
            $configLoader->createDefaultConfig($cfgPath, $firstLoc->rbfid, $firstLoc->base_path);
        }

        try {
            $client->register();
        } catch (Exception $e) {
            Logger::warn('Register fallo: ' . $e->getMessage() . ' — continuando');
        }

        if ($runOnce) {
            $client->fullHashCheck();
            $client->saveState();
        } else {
            $client->fullHashCheck();
            $client->setLastFullSync(time());
            $client->runLoop();
        }
        
    } catch (Exception $e) {
        Logger::err('Error: ' . $e->getMessage());
    }
}

main($argv);