<?php

declare(strict_types=1);

class RobocopyConfig
{
    public string $copy_flags = 'D';
    public int $retry = 1;
    public int $wait = 1;
    public bool $exclude_older = true;
    public bool $subdirs = false;
    public bool $backup_mode = false;
    public int $multithread = 0;
    public bool $quiet = true;

    public function __construct(array $config = [])
    {
        if (isset($config['copy_flags'])) $this->copy_flags = $config['copy_flags'];
        if (isset($config['retry'])) $this->retry = (int)$config['retry'];
        if (isset($config['wait'])) $this->wait = (int)$config['wait'];
        if (isset($config['exclude_older'])) $this->exclude_older = (bool)$config['exclude_older'];
        if (isset($config['subdirs'])) $this->subdirs = (bool)$config['subdirs'];
        if (isset($config['backup_mode'])) $this->backup_mode = (bool)$config['backup_mode'];
        if (isset($config['multithread'])) $this->multithread = (int)$config['multithread'];
        if (isset($config['quiet'])) $this->quiet = (bool)$config['quiet'];
    }

    public function toArray(): array
    {
        return [
            'copy_flags' => $this->copy_flags,
            'retry' => $this->retry,
            'wait' => $this->wait,
            'exclude_older' => $this->exclude_older,
            'subdirs' => $this->subdirs,
            'backup_mode' => $this->backup_mode,
            'multithread' => $this->multithread,
            'quiet' => $this->quiet,
        ];
    }
}