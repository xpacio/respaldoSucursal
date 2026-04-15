<?php

declare(strict_types=1);

require_once __DIR__ . '/../shared/Constants.php';
require_once __DIR__ . '/../shared/Logger.php';
require_once __DIR__ . '/../shared/Config.php';
require_once __DIR__ . '/Location.php';
require_once __DIR__ . '/../shared/Hash.php';
require_once __DIR__ . '/Chunk.php';
require_once __DIR__ . '/../shared/Services/ConfigService.php';
require_once __DIR__ . '/../shared/Services/RegistrationService.php';
require_once __DIR__ . '/../shared/Services/SyncService.php';

class Client
{
    public array $locations = [];
    private string $serverUrl;
    private HttpClient $http;
    private ConfigService $configService;
    private RegistrationService $regService;
    private SyncService $syncService;
    
    // Properties needed to fix deprecation warnings
    private string $configPath = '';
    private string $filesVersion = '';
    private array $filesToWatch = [];
    private array $watchers = [];
    private int $timestamp = 0;
    private string $totp = '';
    private int $lastFullSync = 0;
    private array $lastFileHashes = [];
    // private array $fileStateCache = []; // DEPRECATED: unused

    public function __construct() {
        $this->serverUrl = Constants::DEFAULT_SERVER_URL;
        $this->http = new HttpClient();
        $this->configService = new ConfigService();
        $this->regService = new RegistrationService($this->http, $this->serverUrl);
        $this->syncService = new SyncService($this->http, $this->regService);
    }
    
    public static function init(): Client
    {
        return new Client();
    }



    public function findRbfIni(string $exeDir): void
    {
        $this->configPath = $exeDir . DIRECTORY_SEPARATOR . 'config.json';
        $locations = $this->configService->loadLocations($this->configPath);

        if ($locations !== null) {
            $this->locations = $locations;
            Logger::info("Locations loaded from config: " . count($this->locations));
            return;
        }

        // Fallback: Scan
        $this->locations = $this->scanForLocations();
        
        if (empty($this->locations)) {
            throw new Exception('No se encontraron sucursales.');
        }

        foreach ($this->locations as $loc) {
            $this->createWorkDirectory($loc->work_path);
        }

        Logger::info('Sucursales configuradas: ' . count($this->locations));
    }


    public function deinit(): void
    {
        foreach ($this->watchers as $watcher) {
            $watcher->deinit();
        }
    }

    private function loadLocationsFromConfig(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if ($data === null || !isset($data['locations'])) {
            return null;
        }

        $locations = [];
        foreach ($data['locations'] as $locData) {
            $loc = Location::fromArray($locData);
            if ($loc !== null) {
                $locations[] = $loc;
            }
        }

        return $locations;
    }

    private function scanForLocations(): array
    {
        $allLocations = $this->_scanForLocations();
        if (empty($allLocations)) return [];

        // Agrupar por RBFID
        $grouped = [];
        foreach ($allLocations as $loc) {
            $grouped[$loc->rbfid][] = $loc;
        }

        $activeLocations = [];
        foreach ($grouped as $rbfid => $locations) {
            if (count($locations) === 1) {
                // Validar que existen los archivos testigo
                if ($this->hasWitnessFiles($locations[0])) {
                    $activeLocations[] = $locations[0];
                }
            } else {
                // Elegir el más reciente basado en los archivos testigo
                $winner = null;
                $maxMtime = -1;
                foreach ($locations as $loc) {
                    // Solo considerar ubicaciones con archivos testigo
                    if (!$this->hasWitnessFiles($loc)) {
                        continue;
                    }
                    $mtime = $this->getWitnessMtime($loc);
                    if ($mtime > $maxMtime) {
                        $maxMtime = $mtime;
                        $winner = $loc;
                    }
                }
                if ($winner) {
                    $activeLocations[] = $winner;
                }
            }
        }

        return $activeLocations;
    }

    private function hasWitnessFiles(Location $loc): bool {
        $files = ['XCORTE.DBF', 'CANOTA.DBF', 'CAT_PROD.DBF', 'MASTER.DBF'];
        foreach ($files as $file) {
            $path = $loc->base_path . DIRECTORY_SEPARATOR . $file;
            if (!file_exists($path)) {
                Logger::debug("Archivo testigo faltante: $path");
                return false;
            }
        }
        return true;
    }

    private function getWitnessMtime(Location $loc): int {
        $files = ['XCORTE.DBF', 'CANOTA.DBF', 'CAT_PROD.DBF', 'MASTER.DBF'];
        $maxMtime = 0;
        foreach ($files as $file) {
            $path = $loc->base_path . DIRECTORY_SEPARATOR . $file;
            if (file_exists($path)) {
                $maxMtime = max($maxMtime, filemtime($path));
            }
        }
        return $maxMtime;
    }

    private function _scanForLocations(): array
    {
        $locations = [];

        if (PHP_OS === 'WINNT') {
            $drives = ['C', 'D'];
            foreach ($drives as $drive) {
                $locs = $this->scanDrive($drive);
                foreach ($locs as $loc) {
                    $locations[] = $loc;
                }
                if (count($locations) >= 20) break;
            }
        } else {
            $roots = ['/srv'];
            foreach ($roots as $root) {
                if (!is_dir($root)) continue;
                $locs = $this->scanPath($root);
                foreach ($locs as $loc) {
                    $locations[] = $loc;
                }
                if (count($locations) >= 20) break;
            }
        }

        return $locations;
    }

    private function scanDrive(string $drive): array
    {
        $locations = [];
        $root = $drive . ':\\';

        if (!is_dir($root)) {
            return $locations;
        }

        Logger::debug("Escaneando unidad: $root");

        $entries = scandir($root);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;

            $entryLower = strtolower($entry);
            if (in_array($entryLower, Constants::EXCLUDED_DIRS_WINDOWS)) {
                continue;
            }

            $subdirPath = $root . $entry;
            if (!is_dir($subdirPath)) continue;

            $result = $this->findRbfIniInDirAndBase($subdirPath, 3);
            if ($result !== null) {
                [$rbfid, $basePath] = $result;
                // For Windows: basePath is the branch root (e.g., D:\pvsi\ROTON)
                // work_path should be at branch root: D:\pvsi\ROTON\quickbck\
                $workPath = $basePath . DIRECTORY_SEPARATOR . 'quickbck' . DIRECTORY_SEPARATOR;
                $locations[] = new Location($rbfid, $basePath, $workPath);
                Logger::info("Sucursal encontrada: $rbfid en $basePath");
            }
        }

        Logger::debug("Scan $root completo: " . count($locations) . " sucursales");
        return $locations;
    }

    private function scanPath(string $root): array
    {
        $locations = [];

        Logger::debug("Escaneando: $root");

        $excluded = Constants::EXCLUDED_DIRS_LINUX;
        
        $entries = @scandir($root);
        if ($entries === false) {
            return $locations;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;

            $entryLower = strtolower($entry);
            if (in_array($entryLower, $excluded)) continue;

            $subdirPath = $root . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($subdirPath)) continue;

            $result = $this->findRbfIniInDirAndBase($subdirPath, 4);
            if ($result !== null) {
                [$rbfid, $basePath] = $result;
                $workPath = $basePath . DIRECTORY_SEPARATOR . 'quickbck' . DIRECTORY_SEPARATOR;
                $locations[] = new Location($rbfid, $basePath, $workPath);
                Logger::info("Sucursal encontrada: $rbfid en $basePath");
            }
        }

        Logger::debug("Scan $root completo: " . count($locations) . " sucursales");
        return $locations;
    }

    private function findRbfIniInDirAndBase(string $dir, int $maxDepth): ?array
    {
        // First check: rbf/rbf.ini (one level inside)
        $rbfPath = $dir . DIRECTORY_SEPARATOR . 'rbf' . DIRECTORY_SEPARATOR . 'rbf.ini';
        if (file_exists($rbfPath)) {
            $rbfid = $this->parseRbfIni($rbfPath);
            if ($rbfid !== null) {
                // base_path is the parent of rbf folder (the branch root)
                $rbfDir = dirname($rbfPath);
                $basePath = dirname($rbfDir);
                return [$rbfid, $basePath];
            }
        }
        
        // Fallback: recursive search for rbf.ini
        $files = $this->globRecursive($dir, 'rbf.ini', $maxDepth);
        foreach ($files as $file) {
            $rbfid = $this->parseRbfIni($file);
            if ($rbfid !== null) {
                // For recursive, base_path is the directory containing rbf.ini
                $rbfDir = dirname($file);
                $basePath = dirname($rbfDir);
                return [$rbfid, $basePath];
            }
        }
        return null;
    }

    // DEPRECATED: unused method
    /*
    private function findRbfIniInDir(string $dir, int $maxDepth): ?string
    {
        // First check: rbf/rbf.ini (one level inside)
        $rbfPath = $dir . DIRECTORY_SEPARATOR . 'rbf' . DIRECTORY_SEPARATOR . 'rbf.ini';
        if (file_exists($rbfPath)) {
            $rbfid = $this->parseRbfIni($rbfPath);
            if ($rbfid !== null) {
                return $rbfid;
            }
        }
        
        // Fallback: recursive search for rbf.ini
        $files = $this->globRecursive($dir, 'rbf.ini', $maxDepth);
        foreach ($files as $file) {
            $rbfid = $this->parseRbfIni($file);
            if ($rbfid !== null) {
                return $rbfid;
            }
        }
        return null;
    }
    */

    private function globRecursive(string $dir, string $pattern, int $maxDepth): array
    {
        $files = [];
        $this->globRecursiveHelper($dir, $pattern, $maxDepth, 0, $files);
        return $files;
    }

    private function globRecursiveHelper(string $dir, string $pattern, int $maxDepth, int $depth, array &$files): void
    {
        if ($depth > $maxDepth) return;

        $entries = @scandir($dir);
        if ($entries === false) return;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            
            if (is_file($path) && strtolower($entry) === $pattern) {
                $files[] = $path;
            } elseif (is_dir($path)) {
                $this->globRecursiveHelper($path, $pattern, $maxDepth, $depth + 1, $files);
            }
        }
    }

    private function parseRbfIni(string $path): ?string
    {
        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (strpos($trimmed, '_SUC=') === 0) {
                $value = substr($trimmed, 5);
                return trim($value, ' "');
            }
        }

        return null;
    }

    private function createWorkDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    private function initWatchers(): void
    {
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

    public function register(): void
    {
        Logger::info('Registrando ' . count($this->locations) . ' ubicaciones...');
        
        foreach ($this->locations as $loc) {
            $this->http->registerClient($this->serverUrl, $loc->rbfid);
            Logger::info("[{$loc->rbfid}] Registrado OK");
        }
    }

    public function runLoop(): void
    {
        $pollInterval = Constants::POLL_INTERVAL_SECONDS;
        $fullCheckInterval = Constants::FULL_CHECK_INTERVAL_SECONDS;

        while (true) {
            $now = time();
            if ($now - $this->lastFullSync > $fullCheckInterval) {
                Logger::info('Full sync horario');
                $this->lastFullSync = $now;
                $this->fullHashCheck();
            }

            foreach ($this->locations as $loc) {
                $this->copyToWork($loc);

                foreach ($this->filesToWatch as $filename) {
                    $workFile = $loc->work_path . $filename;
                    if (!file_exists($workFile)) continue;

                    $stat = stat($workFile);
                    if (!$stat) continue;

                    $key = $this->hashPath($workFile);
                    
                    if (isset($this->fileStateCache[$key]) &&
                        $this->fileStateCache[$key]['mtime'] === $stat['mtime'] &&
                        $this->fileStateCache[$key]['size'] === $stat['size']) {
                        continue;
                    }

                    Logger::info("Cambio detectado: $filename");
                    
                    if ($this->syncService->syncFile($this->serverUrl, $loc, $filename, $workFile, false)) {
                        $this->fileStateCache[$key] = [
                            'mtime' => $stat['mtime'],
                            'size' => $stat['size']
                        ];
                        Logger::info("Sync $filename OK");
                    } else {
                        Logger::err("Sync $filename fallo");
                    }
                }
            }
            sleep($pollInterval);
        }
    }

    private function fetchTimestamp(string $rbfid): void
    {
        $url = rtrim($this->serverUrl, '/');
        
        $body = json_encode([
            'action' => 'init',
            'rbfid' => $rbfid,
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response === false) {
            return;
        }

        $data = json_decode($response, true);
        if ($data === null || !isset($data['timestamp'])) {
            return;
        }

        $ts = $data['timestamp'];
        if (empty($ts)) {
            Logger::warn("[$rbfid] Cliente deshabilitado — entrando en modo latente");
            $this->timestamp = 0;
            return;
        }

        $this->timestamp = (int)$ts;
    }

    private function generateTotp(string $rbfid): string
    {
        if ($this->timestamp === 0) {
            throw new Exception('No timestamp');
        }

        $tsStr = (string)$this->timestamp;
        if (strlen($tsStr) < 3) {
            throw new Exception('Invalid timestamp');
        }

        $seed = substr($tsStr, 0, -2);
        $input = $seed . $rbfid;
        
        $hash = Hash::compute($input);
        $this->totp = $hash->toBase64();

        return $this->totp;
    }


    // Nota: checkForChanges(), syncOnce() y las propiedades de $watchers ya no se necesitan,
    // pero puedes dejarlas por si acaso, solo asegúrate de no llamarlas desde runLoop.

    public function fullHashCheck(): void
    {
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
            if (!empty($this->configPath)) {
                $this->configService->saveLocations($this->configPath, $this->locations, $this->filesVersion, $this->filesToWatch);
            }
            Logger::info("Lista actualizada: " . count($this->filesToWatch) . " archivos (v{$this->filesVersion})");
        }

        foreach ($this->locations as $loc) {
            $this->copyToWork($loc);
            foreach ($this->filesToWatch as $filename) {
                $workFile = $loc->work_path . $filename;
                if (!file_exists($workFile)) continue;
                $this->syncService->syncFile($this->serverUrl, $loc, $filename, $workFile, true);
            }
        }
    }


    // DEPRECATED: unused method
    /*
    private function checkForChanges(): void
    {
        foreach ($this->watchers as $watcher) {
            if ($watcher->checkChanges()) {
                $this->lastChangeDetected = (int)(microtime(true) * 1000);
            }
        }
    }
    */

    // DEPRECATED: unused method
    /*
    public function syncOnce(): void
    {
        $hadChanges = false;
        foreach ($this->watchers as $watcher) {
            if ($watcher->hasChanges()) {
                $hadChanges = true;
            }
        }
        if (!$hadChanges) return;

        foreach ($this->locations as $loc) {
            $this->copyToWork($loc);

            foreach ($this->watchers as $watcher) {
                $changes = $watcher->getChanges();
                foreach ($changes as $change) {
                    $filename = $change['filename'];
                    $workFile = $loc->work_path . $filename;

                    $result = $this->syncFileWithRetry($loc, $filename, $workFile, $change['size']);
                    if ($result === true) {
                        Logger::info("Sync $filename OK");
                    } else {
                        Logger::err("Sync $filename fallo");
                    }
                }
            }
        }

        foreach ($this->watchers as $watcher) {
            $watcher->clearChanges();
        }
    }
    */

    private function copyToWork(Location $loc): void
    {
        $this->createWorkDirectory($loc->work_path);

        if (PHP_OS === 'WINNT') {
            $robocopy = Config::getInstance()->robocopy;
            
            $workPathTrimmed = rtrim($loc->work_path, DIRECTORY_SEPARATOR);
            $cmd = 'robocopy "' . $loc->base_path . '" "' . $workPathTrimmed . '" ';
            foreach ($this->filesToWatch as $f) {
                $cmd .= '"' . $f . '" ';
            }
            $cmd .= '/COPY:' . $robocopy->copy_flags;
            $cmd .= ' /R:' . $robocopy->retry;
            $cmd .= ' /W:' . $robocopy->wait;
            
            if ($robocopy->exclude_older) $cmd .= ' /XO';

            Logger::info("Ejecutando Robocopy: $cmd");
            passthru($cmd);
        } else {
            foreach ($this->filesToWatch as $filename) {
                $src = $loc->base_path . DIRECTORY_SEPARATOR . $filename;
                $dst = $loc->work_path . $filename;

                if (!file_exists($src)) continue;

                copy($src, $dst);
            }
        }
    }

    private function syncFileWithRetry(Location $loc, string $filename, string $workFile, int $size): ?bool
    {
        return $this->syncFileWithRetryForced($loc, $filename, $workFile, false);
    }

    private function syncFileWithRetryForced(Location $loc, string $filename, string $workFile, bool $forced): ?bool
    {
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
                    $totalChunks += count($t->chunks);
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

    private function hashFile(string $path): string
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new Exception("Cannot read file: $path");
        }
        return Hash::compute($content)->getHex();
    }

    private function hashChunks(string $path, int $fileSize, int $chunkSize): array
    {
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

    private function uploadChunks(Location $loc, string $filename, string $workFile, int $fileSize, SyncResponse $response, bool $forced): void
    {
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
            foreach ($target->chunks as $chunkIdx) {
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
            die("Sincronización finalizada después del primer archivo.");
                    }
                } catch (Exception $e) {
                    Logger::warn("Upload chunk $chunkIdx fallo: {$e->getMessage()}");
                    continue;
                }
            }
        }

        fclose($handle);
    }

    private function persistFileList(): void
    {
        if (empty($this->configPath)) {
            return;
        }

        $config = Config::getInstance();
        $config->files_version = $this->filesVersion;
        $config->files = $this->filesToWatch;
        $config->save($this->configPath, $this->locations);
    }

    private function hashPath(string $path): int
    {
        return (int)hexdec(hash('xxh64', $path));
    }

    public function setServerUrl(string $url): void
    {
        $this->serverUrl = $url;
        $this->regService = new RegistrationService($this->http, $url);
        $this->syncService = new SyncService($this->http, $this->regService);
    }

    public function saveConfig(): void
    {
        if (empty($this->configPath)) {
            Logger::warn("Config path not set, skipping save");
            return;
        }

        try {
            $config = Config::getInstance();
            $config->files_version = $this->filesVersion;
            $config->files = $this->filesToWatch;
            $config->save($this->configPath, $this->locations);
            Logger::info("Config guardado en: {$this->configPath}");
        } catch (Exception $e) {
            Logger::err("Error guardando config: " . $e->getMessage());
        }
    }

    public function generateApiConfig(string $searchRoot): ?array
    {
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

    private function findRbfIniFile(string $root): ?string
    {
        $directPath = $root . DIRECTORY_SEPARATOR . 'rbf' . DIRECTORY_SEPARATOR . 'rbf.ini';
        if (file_exists($directPath)) {
            return $directPath;
        }

        $files = $this->globRecursive($root, 'rbf.ini', 5);
        return $files[0] ?? null;
    }

    public function saveApiConfig(string $outputPath, string $rbfid, string $basePath): void
    {
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
