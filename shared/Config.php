<?php
declare(strict_types=1);

namespace App;

use App\Cli\RobocopyConfig;
use App\Cli\Location;
use Exception;

class Config
{
    public string $server_url = Constants::DEFAULT_SERVER_URL;
    public RobocopyConfig $robocopy;
    public int $sync_interval_sec = 3600;
    public int $full_check_interval_ms = 3600000;
    public array $files = [];
    public string $files_version = '';
    public array $locations = [];

    private static Config $instance;

    public function __construct()
    {
        $this->robocopy = new RobocopyConfig();
    }

    public static function getInstance(): Config
    {
        if (!isset(self::$instance)) {
            self::$instance = new Config();
        }
        return self::$instance;
    }

    public static function load(string $path): Config
    {
        $config = self::getInstance();
        
        if (!file_exists($path)) {
            Logger::debug("Config file not found: $path");
            return $config;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            Logger::debug("Cannot read config file: $path");
            return $config;
        }

        $data = json_decode($content, true);
        if ($data === null) {
            Logger::debug("Invalid JSON in config file: $path");
            return $config;
        }

        if (isset($data['server_url'])) {
            $config->server_url = $data['server_url'];
        }

        if (isset($data['robocopy'])) {
            $config->robocopy = new RobocopyConfig($data['robocopy']);
        }

        if (isset($data['sync_interval_sec'])) {
            $config->sync_interval_sec = (int)$data['sync_interval_sec'];
        }

        if (isset($data['full_check_interval_ms'])) {
            $config->full_check_interval_ms = (int)$data['full_check_interval_ms'];
        }

        if (isset($data['files_version'])) {
            $config->files_version = $data['files_version'];
        }

        if (isset($data['files']) && is_array($data['files'])) {
            $config->files = $data['files'];
        }

        if (isset($data['locations']) && is_array($data['locations'])) {
            $config->locations = [];
            foreach ($data['locations'] as $locData) {
                $loc = Location::fromArray($locData);
                if ($loc !== null) {
                    $config->locations[] = $loc;
                }
            }
        }

        Logger::debug("Config loaded from: $path");
        return $config;
    }

    public function save(string $path, array $locations = []): void
    {
        $data = [
            'server_url' => $this->server_url,
            'robocopy' => $this->robocopy->toArray(),
            'sync_interval_sec' => $this->sync_interval_sec,
            'full_check_interval_ms' => $this->full_check_interval_ms,
            'locations' => [],
            'files_version' => $this->files_version,
            'files' => $this->files,
        ];

        foreach ($locations as $loc) {
            $data['locations'][] = $loc->toArray();
        }

        $json = json_encode($data, JSON_PRETTY_PRINT);
        if (file_put_contents($path, $json) === false) {
            throw new Exception("Cannot write config to: $path");
        }

        Logger::debug("Config saved to: $path");
    }

}