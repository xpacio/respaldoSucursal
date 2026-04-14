<?php
declare(strict_types=1);

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../Location.php';
require_once __DIR__ . '/../Constants.php';

class ConfigService {
    public function loadLocations(string $configPath): ?array {
        if (!file_exists($configPath)) return null;
        $data = json_decode(file_get_contents($configPath), true);
        if ($data === null || !isset($data['locations'])) return null;
        
        $locations = [];
        foreach ($data['locations'] as $locData) {
            $loc = Location::fromArray($locData);
            if ($loc !== null) $locations[] = $loc;
        }
        return $locations;
    }

    public function saveLocations(string $configPath, array $locations, string $filesVersion, array $filesToWatch): void {
        $config = Config::load($configPath);
        $config->files_version = $filesVersion;
        $config->files = $filesToWatch;
        $config->save($configPath, $locations);
    }
}
