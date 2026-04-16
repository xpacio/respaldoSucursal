<?php

declare(strict_types=1);

class ArgumentParser
{
    private array $options = [
        'help' => false,
        'version' => false,
        'quiet' => false,
        'run_once' => false,
        'server' => null,
    ];

    public function parse(array $argv): void
    {
        $i = 1;
        while ($i < count($argv)) {
            $arg = $argv[$i];
            
            if ($arg === '-h' || $arg === '--help') {
                $this->options['help'] = true;
            } elseif ($arg === '-v' || $arg === '--version') {
                $this->options['version'] = true;
            } elseif ($arg === '-q' || $arg === '--quiet') {
                $this->options['quiet'] = true;
            } elseif ($arg === '--run-once') {
                $this->options['run_once'] = true;
            } elseif ($arg === '--server') {
                $i++;
                if ($i < count($argv)) {
                    $this->options['server'] = $argv[$i];
                }
            }
            
            $i++;
        }
    }

    public function getOption(string $name)
    {
        return $this->options[$name] ?? null;
    }

    public function hasOption(string $name): bool
    {
        return isset($this->options[$name]) && $this->options[$name] !== false;
    }

    public function getAllOptions(): array
    {
        return $this->options;
    }
}