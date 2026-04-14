<?php

declare(strict_types=1);

require_once __DIR__ . '/../shared/Constants.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/ChangeQueue.php';

class FileWatcher
{
    private string $basePath;
    private array $filesToWatch;
    private array $lastMtimes = [];
    private array $changes = [];
    private ChangeQueue $queue;

    public function __construct(string $basePath, array $filesToWatch, string $workDir)
    {
        $this->basePath = $basePath;
        $this->filesToWatch = $filesToWatch;
        $this->queue = new ChangeQueue($workDir);
    }

    public function checkChanges(): bool
    {
        $changed = false;
        
        foreach ($this->filesToWatch as $file) {
            $path = $this->basePath . DIRECTORY_SEPARATOR . $file;
            
            if (!file_exists($path)) {
                continue;
            }

            $currentMtime = filemtime($path);
            $currentSize = filesize($path);

            if (!isset($this->lastMtimes[$file]) || 
                $this->lastMtimes[$file] !== $currentMtime) {
                $this->lastMtimes[$file] = $currentMtime;
                $this->changes[] = [
                    'filename' => $file,
                    'size' => $currentSize,
                ];
                $this->queue->add($file, $currentSize);
                Logger::debug("Cambio detectado: $file ({$currentSize} bytes)");
                $changed = true;
            }
        }

        return $changed;
    }

    public function getChanges(): array
    {
        return $this->queue->process();
    }

    public function clearChanges(): void
    {
        $this->changes = [];
        $this->queue->clear();
    }

    public function hasChanges(): bool
    {
        return count($this->changes) > 0;
    }

    public function deinit(): void
    {
    }
}