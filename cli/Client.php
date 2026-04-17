<?php

declare(strict_types=1);

namespace App\Cli;

use App\Constants;
use App\Logger;
use App\Config;
use App\Services\TimestampManager;
use App\Services\ConfigService;
use App\Services\RegistrationService;
use App\Services\SyncService;
use App\Services\LocationDiscoveryService;
use Exception;

require_once __DIR__ . '/Location.php';
require_once __DIR__ . '/../shared/Services/TimestampManager.php';

class Client
{
    public array $locations = [];
    private string $serverUrl;
    private HttpClient $http;
    private ConfigService $configService;
    private RegistrationService $regService;
    private SyncService $syncService;
    private LocationDiscoveryService $locationDiscoveryService;
    
    // Properties needed to fix deprecation warnings
    private string $cfgPath = '';
    private string $filesVersion = '';
    private array $filesToWatch = [];
    private int $timestamp = 0;
    private int $lastFullSync = 0;
    private array $fileStateCache = [];

    private bool $isFirstSync = true;

    public function __construct() {
        $this->serverUrl = Constants::DEFAULT_SERVER_URL;
        
        // Crear TimestampManager primero
        $timestampManager = new TimestampManager();
        
        // Crear HttpClient y configurar TimestampManager
        $this->http = new HttpClient();
        $this->http->setTimestampManager($timestampManager);
        
        $this->configService = new ConfigService();
        
        // Crear RegistrationService y configurar TimestampManager
        $this->regService = new RegistrationService($this->http, $this->serverUrl);
        $this->regService->setTimestampManager($timestampManager);
        
        $this->syncService = new SyncService($this->http, $this->regService);
        $this->locationDiscoveryService = new LocationDiscoveryService($this->configService);
        
        // Sincronizar tiempo inmediatamente al iniciar
        $this->syncServerTime();
    }

    private function syncServerTime(): void {
        if (empty($this->locations)) return;
        $loc = $this->locations[0] ?? null;
        if ($loc) {
            Logger::info("Sincronizando tiempo con servidor...");
            $this->regService->fetchTimestamp($loc->rbfid, true);
        }
    }
    
    public static function init(): Client {
        return new Client();
    }

    public function setLastFullSync(int $timestamp): void {
        $this->lastFullSync = $timestamp;
    }

    public function register(): void {
        Logger::info('Registrando ' . count($this->locations) . ' ubicaciones...');
        
        foreach ($this->locations as $loc) {
            try {
                $timestamp = $this->regService->fetchTimestamp($loc->rbfid);
                if ($timestamp === 0) {
                    Logger::warn("[{$loc->rbfid}] No se pudo obtener timestamp, saltando registro");
                    continue;
                }
                $totp = $this->regService->generateTotp($loc->rbfid);
                $response = $this->http->registerClient($this->serverUrl, $loc->rbfid, $totp);
                
                if (isset($response['ok']) && $response['ok']) {
                    $enabled = $response['enabled'] ?? false;
                    Logger::info("[{$loc->rbfid}] Registrado OK - Enabled: " . ($enabled ? 'Sí' : 'No'));
                    if (!$enabled) {
                        Logger::warn("[{$loc->rbfid}] Servidor no acepta sincronizaciones para este cliente");
                    }
                } else {
                    Logger::warn("[{$loc->rbfid}] Registro falló: " . ($response['error'] ?? 'Desconocido'));
                }
            } catch (Exception $e) {
                Logger::warn("[{$loc->rbfid}] Error en registro: " . $e->getMessage());
            }
        }
    }

    public function runLoop(): void {
        $pollInterval = Constants::POLL_INTERVAL_SECONDS;
        $fullCheckInterval = Constants::FULL_CHECK_INTERVAL_SECONDS;

        Logger::info("Iniciando modo espera (poll={$pollInterval}s, fullCheck={$fullCheckInterval}s)");

        while (true) {
            $now = time();
            if ($now - $this->lastFullSync >= $fullCheckInterval) {
                Logger::info("Full sync — actualizando lista del servidor");
                $this->lastFullSync = $now;
                $this->fullHashCheck();
                $this->saveState();
            }
            
            foreach ($this->locations as $loc) {
                $this->copyToWork($loc);

                foreach ($this->filesToWatch as $filename) {
                    $workFile = $loc->work_path . $filename;
                    if (!file_exists($workFile)) continue;

                    $stat = stat($workFile);
                    if (!$stat) continue;

                    $key = $this->hashPath($workFile);
                    $cached = $this->fileStateCache[$key] ?? null;

                    if ($cached !== null &&
                        $cached['mtime'] === $stat['mtime'] &&
                        $cached['size'] === $stat['size']) {
                        continue;
                    }

                    Logger::info("[{$loc->rbfid}] Cambio detectado: $filename");
                    
                    // Obtener timestamp fresco para este cambio
                    $timestamp = $this->regService->fetchTimestamp($loc->rbfid);
                    
                    if ($this->syncService->syncFile($this->serverUrl, $loc, $filename, $workFile, false, $timestamp)) {
                        $this->fileStateCache[$key] = [
                            'mtime' => $stat['mtime'],
                            'size' => $stat['size']
                        ];
                        Logger::info("[{$loc->rbfid}] $filename — sync OK");
                    } else {
                        Logger::err("[{$loc->rbfid}] $filename — sync fallo");
                    }
                }
            }

            Logger::info("Esperando {$pollInterval}s...");
            sleep($pollInterval);
        }
    }

    private function getStateFilePath(Location $loc): string {
        return $loc->work_path . DIRECTORY_SEPARATOR . 'XCORTE.json';
    }

    public function saveState(): void {
        foreach ($this->locations as $loc) {
            $stateFile = $this->getStateFilePath($loc);
            $state = [
                'lastFullSync' => $this->lastFullSync,
                'fileStateCache' => $this->fileStateCache,
                'isFirstSync' => $this->isFirstSync,
            ];
            $json = json_encode($state, JSON_PRETTY_PRINT);
            if (file_put_contents($stateFile, $json) === false) {
                Logger::warn("No se pudo guardar estado para {$loc->rbfid}");
            } else {
                Logger::debug("Estado guardado para {$loc->rbfid}");
            }
        }
    }

    public function loadState(): void {
        foreach ($this->locations as $loc) {
            $stateFile = $this->getStateFilePath($loc);
            if (file_exists($stateFile)) {
                $content = file_get_contents($stateFile);
                if ($content !== false) {
                    $state = json_decode($content, true);
                    if ($state !== null) {
                        $this->lastFullSync = $state['lastFullSync'] ?? $this->lastFullSync;
                        $this->fileStateCache = $state['fileStateCache'] ?? $this->fileStateCache;
                        $this->isFirstSync = $state['isFirstSync'] ?? $this->isFirstSync;
                        Logger::debug("Estado cargado para {$loc->rbfid}");
                    }
                }
            }
        }
    }

    public function fullHashCheck(): void {
        if (empty($this->locations)) return;

        $loc = $this->locations[0];
        $timestamp = $this->regService->fetchTimestamp($loc->rbfid);
        
        try {
            $totp = $this->regService->generateTotp($loc->rbfid);
        } catch (Exception $e) {
            Logger::err("generateTotp fallo: {$e->getMessage()}. Saltando.");
            return;
        }

        $result = $this->http->fetchFileListVersioned($this->serverUrl, $loc->rbfid, $totp, $this->filesVersion);
        if (!empty($result['files'])) {
            $this->filesToWatch = $result['files'];
            $this->filesVersion = $result['version'];
            if (!empty($this->cfgPath)) {
                $this->configService->saveLocations($this->cfgPath, $this->locations, $this->filesVersion, $this->filesToWatch);
            }
            Logger::info("Lista del servidor: " . count($this->filesToWatch) . " archivos (v{$this->filesVersion})");
        }

        foreach ($this->locations as $loc) {
            $this->copyToWork($loc);
            
            foreach ($this->filesToWatch as $filename) {
                $workFile = $loc->work_path . $filename;
                if (!file_exists($workFile)) continue;
                
                $stat = stat($workFile);
                if (!$stat) continue;

                Logger::debug("[{$loc->rbfid}] Evaluando $filename (mtime={$stat['mtime']}, size={$stat['size']})");
                
                if ($this->syncService->syncFile($this->serverUrl, $loc, $filename, $workFile, true, $timestamp)) {
                    $key = $this->hashPath($workFile);
                    $this->fileStateCache[$key] = [
                        'mtime' => $stat['mtime'],
                        'size' => $stat['size']
                    ];
                }
            }
            
            Logger::info("[{$loc->rbfid}] Evaluados " . count($this->filesToWatch) . " archivos");
        }
        
        $this->isFirstSync = false;
    }

    private function copyToWork(Location $loc): void
    {
        // TODO: Implement actual file copying logic here
        Logger::debug("[{$loc->rbfid}] Copying files to work directory: {$loc->work_path}");
    }







    private function persistFileList(): void {
        if (empty($this->cfgPath)) {
            return;
        }

        $config = Config::getInstance();
        $config->files_version = $this->filesVersion;
        $config->files = $this->filesToWatch;
        $config->save($this->cfgPath, $this->locations);
    }

    private function hashPath(string $path): int {
        return (int)hexdec(hash('xxh64', $path));
    }

    public function setServerUrl(string $url): void {
        $this->serverUrl = $url;
        $this->regService = new RegistrationService($this->http, $url);
        $this->syncService = new SyncService($this->http, $this->regService);
    }

    public function setCfgPath(string $path): void {
        $this->cfgPath = $path;
    }

    public function setFilesVersion(string $version): void {
        $this->filesVersion = $version;
    }

    public function setFilesToWatch(array $files): void {
        $this->filesToWatch = $files;
    }

    public function saveConfig(): void {
        if (empty($this->cfgPath)) {
            Logger::warn("Config path not set, skipping save");
            return;
        }

        try {
            $config = Config::getInstance();
            $config->files_version = $this->filesVersion;
            $config->files = $this->filesToWatch;
            $config->save($this->cfgPath, $this->locations);
            Logger::info("Config guardado en: {$this->cfgPath}");
        } catch (Exception $e) {
            Logger::err("Error guardando config: " . $e->getMessage());
        }
    }

    public function findRbfIni(string $cfgPath): void
    {
        $this->cfgPath = $cfgPath;
        $this->locations = $this->locationDiscoveryService->findLocations($cfgPath, dirname($_SERVER['argv'][0]));
    }
}
