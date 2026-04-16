<?php

declare(strict_types=1);

require_once __DIR__ . '/Location.php';

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
    private array $watchers = [];
    private int $timestamp = 0;
    private string $totp = '';
    private int $lastFullSync = 0;
    private array $lastFileHashes = [];
    private array $fileStateCache = [];

    private bool $isFirstSync = true;

    public function __construct() {
        $this->serverUrl = Constants::DEFAULT_SERVER_URL;
        $this->http = new HttpClient();
        $this->configService = new ConfigService();
        $this->regService = new RegistrationService($this->http, $this->serverUrl);
        $this->syncService = new SyncService($this->http, $this->regService);
        $this->locationDiscoveryService = new LocationDiscoveryService($this->configService);
    }
    
    public static function init(): Client {
        return new Client();
    }

    public function setLastFullSync(int $timestamp): void {
        $this->lastFullSync = $timestamp;
    }

    public function deinit(): void
    {
        foreach ($this->watchers as $watcher) {
            $watcher->deinit();
        }
    }
    private function initWatchers(): void {
        $this->watchers = [];
        
        foreach ($this->locations as $loc) {
            $watcher = new FileWatcher(
                $loc->base_path,
                $this->filesToWatch,
                $loc->work_path
            );
            $this->watchers[] = $watcher;
        }
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
                $totp = $this->regService->generateTotp($loc->rbfid, $timestamp);
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
                    
                    if ($this->syncService->syncFile($this->serverUrl, $loc, $filename, $workFile, false)) {
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



    // Nota: checkForChanges(), syncOnce() y las propiedades de $watchers ya no se necesitan,
    // pero puedes dejarlas por si acaso, solo asegúrate de no llamarlas desde runLoop.

    private function getStateFilePath(Location $loc): string {
        return $loc->work_path . DIRECTORY_SEPARATOR . 'XCORTE.json';
    }

    public function saveState(): void {
        foreach ($this->locations as $loc) {
            $stateFile = $this->getStateFilePath($loc);
            $state = [
                'lastFullSync' => $this->lastFullSync,
                'lastFileHashes' => $this->lastFileHashes,
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
                        $this->lastFileHashes = $state['lastFileHashes'] ?? $this->lastFileHashes;
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
            $totp = $this->regService->generateTotp($loc->rbfid, $timestamp);
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
                
                if ($this->syncService->syncFile($this->serverUrl, $loc, $filename, $workFile, true)) {
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

    private function syncFileWithRetry(Location $loc, string $filename, string $workFile, int $size): ?bool {
        return $this->syncFileWithRetryForced($loc, $filename, $workFile, false);
    }

    private function syncFileWithRetryForced(Location $loc, string $filename, string $workFile, bool $forced): ?bool {
        try {
            $this->fetchTimestamp($loc->rbfid);
        } catch (Exception $e) {
            Logger::debug("fetchTimestamp fallo: {$e->getMessage()}");
        }

        try {
            $this->totp = $this->generateTotp($loc->rbfid);
        } catch (Exception $e) {
            Logger::err("generateTotp fallo: {$e->getMessage()}. No se puede continuar sin timestamp.");
            return null;
        }

        $retries = 0;
        $maxRetries = 3;

        while ($retries < $maxRetries) {
            if (!file_exists($workFile)) {
                return null;
            }

            $stat = stat($workFile);
            if ($stat === false) {
                return null;
            }

            try {
                $hash = $this->hashFile($workFile);
            } catch (Exception $e) {
                $retries++;
                continue;
            }

            $chunkSize = $forced 
                ? Chunk::MAX_CHUNK 
                : Chunk::calculateChunkSize($stat['size']);
            $chunkCount = Chunk::calculateChunkCount2($stat['size'], $chunkSize);

            $chunkHashes = [];
            try {
                $chunkHashes = $this->hashChunks($workFile, $stat['size'], $chunkSize);
            } catch (Exception $e) {
                $retries++;
                continue;
            }

            $fileData = new FileHashData($filename, $hash, $chunkHashes, (int)$stat['mtime'], (int)$stat['size']);

            try {
                $response = $this->http->sync($this->serverUrl, $loc->rbfid, $this->totp, [$fileData]);
            } catch (Exception $e) {
                $retries++;
                Logger::debug("Sync $filename retry " . ($retries + 1) . "/3: {$e->getMessage()}");
                continue;
            }

            if (empty($response->needs_upload)) {
                Logger::info("$filename — sin cambios, servidor ya tiene la version actual");
            } else {
                $totalChunks = 0;
                $workPath = '';
                $destPath = '';
                $md5Hash = '';
                foreach ($response->needs_upload as $t) {
                    $chunkIdx = $t->chunk ?? $t->chunks[0] ?? 0;
                    $totalChunks = is_array($t->chunks ?? null) ? count($t->chunks) : 1;
                    $workPath = $t->work_path ?? '';
                    $destPath = $t->dest_path ?? '';
                    $md5Hash = $t->md5 ?? '';
                }
                Logger::info("$filename → $destPath [ MD5: $md5Hash ] — work: $workPath — enviando $totalChunks/$chunkCount chunks");

                try {
                    $this->uploadChunks($loc, $filename, $workFile, $stat['size'], $response, $forced);
                    Logger::info("$filename — sync completo ($totalChunks chunks enviados)");
                } catch (Exception $e) {
                    $retries++;
                    continue;
                }
            }

            return true;
        }

        Logger::err("Sync $filename fallo después de $maxRetries intentos");
        return false;
    }

    private function hashFile(string $path): string {
        require_once __DIR__ . '/../shared/Utilities/StreamHasher.php';
        return StreamHasher::hashFileEfficient($path, 'xxh3', 5242880);
    }

    private function hashChunks(string $path, int $fileSize, int $chunkSize): array {
        $hashes = [];
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new Exception("Cannot open file: $path");
        }

        $offset = 0;
        while ($offset < $fileSize) {
            $size = min($chunkSize, $fileSize - $offset);
            fseek($handle, $offset);
            $chunk = fread($handle, $size);
            if ($chunk === false) {
                fclose($handle);
                throw new Exception("Cannot read chunk at offset $offset");
            }
            $hashes[] = Hash::compute($chunk)->getHex();
            $offset += $chunkSize;
        }

        fclose($handle);
        return $hashes;
    }

    private function uploadChunks(Location $loc, string $filename, string $workFile, int $fileSize, SyncResponse $response, bool $forced): void {
        $chunkSize = Chunk::calculateChunkSize($fileSize);

        try {
            $this->totp = $this->generateTotp($loc->rbfid);
        } catch (Exception $e) {
            Logger::err("generateTotp fallo: {$e->getMessage()}. Abortando upload.");
            return;
        }

        $handle = fopen($workFile, 'rb');
        if ($handle === false) {
            return;
        }

        foreach ($response->needs_upload as $target) {
            $chunkIndices = $target->chunks ?? [$target->chunk ?? 0];
            foreach ($chunkIndices as $chunkIdx) {
                $offset = $chunkIdx * $chunkSize;
                $size = min($chunkSize, $fileSize - $offset);

                fseek($handle, $offset);
                $data = fread($handle, $size);
                if ($data === false) {
                    continue;
                }

                $chunkHash = Hash::compute($data)->getHex();

                try {
                    $uploadResult = $this->http->upload(
                        $this->serverUrl,
                        $loc->rbfid,
                        $this->totp,
                        $filename,
                        $chunkIdx,
                        $chunkHash,
                        $data
                    );
                    
                    Logger::debug("Upload chunk $chunkIdx OK");

                    if (!$forced && ($uploadResult->next_delay > 0 || $response->rate_delay > 0)) {
                        $delayMs = max($uploadResult->next_delay, $response->rate_delay);
                        if ($delayMs > 0) {
                            usleep($delayMs * 1000);
                        }
                    }
                } catch (Exception $e) {
                    Logger::warn("Upload chunk $chunkIdx fallo: {$e->getMessage()}");
                    continue;
                }
            }
        }

        fclose($handle);
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

    public function setLastFullSync(int $timestamp): void {
        $this->lastFullSync = $timestamp;
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

    public function generateApiConfig(string $searchRoot): ?array {
        $rbfIniPath = $this->findRbfIniFile($searchRoot);
        
        if ($rbfIniPath === null) {
            Logger::err("No se encontró rbf.ini en: $searchRoot");
            return null;
        }

        $rbfid = $this->parseRbfIni($rbfIniPath);
        if ($rbfid === null) {
            Logger::err("No se pudo leer rbfid de: $rbfIniPath");
            return null;
        }

        $parentPath = dirname(dirname($rbfIniPath));
        
        Logger::info("rbf.ini encontrado: $rbfIniPath");
        Logger::info("rbfid: $rbfid");
        Logger::info("Ruta padre: $parentPath");

        return [
            'rbfid' => $rbfid,
            'base_path' => $parentPath,
        ];
    }

    private function findRbfIni(string $cfgPath): void
    {
        $this->cfgPath = $cfgPath;
        $this->locations = $this->locationDiscoveryService->findLocations($cfgPath, dirname($_SERVER['argv'][0]));
    }

    private function findRbfIniFile(string $root): ?string {
        $directPath = $root . DIRECTORY_SEPARATOR . 'rbf' . DIRECTORY_SEPARATOR . 'rbf.ini';
        if (file_exists($directPath)) {
            return $directPath;
        }

        $files = $this->globRecursive($root, 'rbf.ini', 5);
        return $files[0] ?? null;
    }

    public function saveApiConfig(string $outputPath, string $rbfid, string $basePath): void {
        $data = [
            'rbfid' => $rbfid,
            'base_path' => $basePath,
            'generated_at' => date('c'),
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT);
        if (file_put_contents($outputPath, $json) === false) {
            throw new Exception("Cannot write config to: $outputPath");
        }

        Logger::info("API config guardado en: $outputPath");
    }
}
