<?php

declare(strict_types=1);

namespace App\Services;

use App\Constants;
use App\Logger;
use App\Cli\Location;
use Exception;

require_once __DIR__ . '/../Constants.php';
require_once __DIR__ . '/../Logger.php';
require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../../cli/Location.php';

class LocationDiscoveryService
{
    private ConfigService $configService;

    public function __construct(ConfigService $configService)
    {
        $this->configService = $configService;
    }

    public function findLocations(string $cfgPath, string $exeDir): array
    {
        $locations = $this->configService->loadLocations($cfgPath);

        if ($locations !== null) {
            Logger::info("Locations loaded from config: " . count($locations));
            return $locations;
        }

        Logger::warn("config.json sin locations, escaneando disco...");
        $locations = $this->scanForLocations();
        
        if (empty($locations)) {
            throw new Exception('No se encontraron sucursales. Verifique que PVSI esté instalado o cree config.json manualmente.');
        }

        foreach ($locations as $loc) {
            $this->createWorkDirectory($loc->work_path);
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

    private function hasWitnessFiles(Location $loc): bool
    {
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

    private function getWitnessMtime(Location $loc): int
    {
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
            $drives = ['C', 'D']; // TODO: make configurable
            foreach ($drives as $drive) {
                $locs = $this->scanDrive($drive);
                foreach ($locs as $loc) {
                    $locations[] = $loc;
                }
                if (count($locations) >= 20) break; // Limit for performance
            }
        } else {
            $roots = ['/srv']; // TODO: make configurable
            foreach ($roots as $root) {
                if (!is_dir($root)) continue;
                $locs = $this->scanPath($root);
                foreach ($locs as $loc) {
                    $locations[] = $loc;
                }
                if (count($locations) >= 20) break; // Limit for performance
            }
        }

        return $locations;
    }

    private function scanDrive(string $drive): array
    {
        $locations = [];
        $root = $drive . ':\\\\';

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
                // For Windows: basePath is the branch root (e.g., D:\\pvsi\\ROTON)
                // work_path should be at branch root: D:\\pvsi\\ROTON\\quickbck\\\
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

        $lines = explode("\\n", $content);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (stripos($trimmed, '_suc=') === 0) {
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
}
