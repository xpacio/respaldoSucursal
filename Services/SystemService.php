<?php
/**
 * System - Wrapper para comandos del sistema
 */
require_once __DIR__ . '/ServiceInterfaces.php';

class System implements SystemInterface {
    private array $config;

    public function __construct(array $config) {
        $this->config = $config;
    }

    public function cmd(string $command, ?int &$exitCode = null): string {
        $output = [];
        exec($command, $output, $exitCode);
        error_log("[SYSTEM cmd] Command: {$command}, Exit: {$exitCode}, Output: " . implode(", ", $output));
        return implode("\n", $output);
    }

    public function sudo(string $command, ?int &$exitCode = null): string {
        $cmd = "sudo -n " . $command;
        error_log("[SYSTEM sudo] Command: {$cmd}");
        return $this->cmd($cmd, $exitCode);
    }
}
