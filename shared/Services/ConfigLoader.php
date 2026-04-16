<?php

declare(strict_types=1);

require_once __DIR__ . '/../Logger.php';
require_once __DIR__ . '/../Utilities/FileUtil.php';
require_once __DIR__ . '/../Utilities/JsonUtil.php';
require_once __DIR__ . '/../../cli/Location.php';

class ConfigLoader
{
    public function loadConfig(string $configPath, Client $client): bool
    {
        if (!FileUtil::fileExists($configPath)) {
            return false;
        }

        $content = FileUtil::getContents($configPath);
        if ($content === null) {
            Logger::warn("No se pudo leer el archivo de configuración: $configPath");
            return false;
        }

        $data = JsonUtil::decode($content, true);
        if ($data === null) {
            Logger::warn("Configuración JSON inválida en: $configPath");
            return false;
        }

        // Configurar URL del servidor si está especificada
        if (isset($data['server_url'])) {
            $client->setServerUrl($data['server_url']);
        }

        // Cargar ubicaciones si están especificadas
        if (isset($data['locations']) && is_array($data['locations'])) {
            $client->setCfgPath($configPath);
            foreach ($data['locations'] as $locData) {
                if (isset($locData['rbfid']) && isset($locData['base'])) {
                    $base = $locData['base'];
                    $work = $locData['work'] ?? ($base . DIRECTORY_SEPARATOR . 'quickbck' . DIRECTORY_SEPARATOR);
                    $client->locations[] = new Location($locData['rbfid'], $base, $work);
                }
            }
            
            if (isset($data['files_version'])) {
                $client->setFilesVersion($data['files_version']);
            }
            
            if (isset($data['files']) && is_array($data['files'])) {
                $client->setFilesToWatch($data['files']);
            }
            
            Logger::info("Ubicaciones cargadas desde configuración: " . count($client->locations));
            return true;
        }

        Logger::warn("config.json sin ubicaciones definidas");
        return false;
    }

    public function createDefaultConfig(string $configPath, string $rbfid, string $basePath): bool
    {
        $data = [
            'rbfid' => $rbfid,
            'base_path' => $basePath,
            'generated_at' => date('c'),
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT);
        if (file_put_contents($configPath, $json) === false) {
            Logger::err("No se pudo crear configuración en: $configPath");
            return false;
        }

        Logger::info("Configuración API guardada en: $configPath");
        return true;
    }
}