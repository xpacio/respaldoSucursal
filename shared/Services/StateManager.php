<?php

declare(strict_types=1);

require_once __DIR__ . '/../Logger.php';

class StateManager
{
    private string $stateDir;
    private array $state = [
        'lastFullSync' => 0,
        'lastFileHashes' => [],
        'fileStateCache' => [],
        'isFirstSync' => true,
    ];

    public function __construct(string $stateDir)
    {
        $this->stateDir = $stateDir;
    }

    public function getStateFilePath(string $rbfid, string $workPath): string
    {
        return $workPath . DIRECTORY_SEPARATOR . 'XCORTE.json';
    }

    public function saveState(string $rbfid, string $workPath, array $state): void
    {
        $stateFile = $this->getStateFilePath($rbfid, $workPath);
        $json = json_encode($state, JSON_PRETTY_PRINT);
        
        if (file_put_contents($stateFile, $json) === false) {
            Logger::warn("No se pudo guardar estado para $rbfid");
        } else {
            Logger::debug("Estado guardado para $rbfid");
        }
    }

    public function loadState(string $rbfid, string $workPath): array
    {
        $stateFile = $this->getStateFilePath($rbfid, $workPath);
        
        if (file_exists($stateFile)) {
            $content = file_get_contents($stateFile);
            if ($content !== false) {
                $loadedState = json_decode($content, true);
                if ($loadedState !== null) {
                    Logger::debug("Estado cargado para $rbfid");
                    return array_merge($this->state, $loadedState);
                }
            }
        }
        
        return $this->state;
    }

    public function getDefaultState(): array
    {
        return $this->state;
    }

    public function updateStateValue(array &$state, string $key, $value): void
    {
        $state[$key] = $value;
    }

    public function getStateValue(array $state, string $key, $default = null)
    {
        return $state[$key] ?? $default;
    }
}