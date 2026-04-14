<?php
/**
 * Logger - Sistema unificado de logging para UI y API
 * 
 * Versión simplificada que funciona para ambos casos de uso.
 */

interface LoggingStrategy {
    public function startExecution(string $clientId, string $action, string $sourceType = 'api'): string;
    public function finish(string $status): void;
    public function getCurrentExecutionId(): ?string;
    public function enterContext(int $module, int $api, int $function, string $description = ''): void;
    public function stepWithContext(int $step, int $result, string $message = ''): void;
    public function exitContext(): void;
    public function hasContext(): bool;
}

class DatabaseLoggingStrategy implements LoggingStrategy {
    private Database $db;
    private ?string $currentExecutionId = null;
    private array $contextStack = [];
    
    public function __construct(Database $db) {
        $this->db = $db;
    }
    
    public function startExecution(string $clientId, string $action, string $sourceType = 'api'): string {
        try {
            $this->currentExecutionId = $this->db->insert(
                "INSERT INTO executions (rbfid, action, source_type) VALUES (:rbfid, :action, :source_type) RETURNING id",
                [':rbfid' => $clientId, ':action' => $action, ':source_type' => $sourceType]
            );
            return $this->currentExecutionId;
        } catch (\Exception $e) {
            error_log("Logger error: " . $e->getMessage());
            return '';
        }
    }
    
    public function finish(string $status): void {
        if (!$this->currentExecutionId) return;
        try {
            $this->db->execute(
                "UPDATE executions SET status = :status, finished_at = NOW() WHERE id = :id",
                [':status' => $status, ':id' => $this->currentExecutionId]
            );
        } catch (\Exception $e) {
            error_log("Logger finish error: " . $e->getMessage());
        }
        $this->currentExecutionId = null;
    }
    
    public function enterContext(int $module, int $api, int $function, string $description = ''): void {
        $this->contextStack[] = ['module' => $module, 'api' => $api, 'function' => $function, 'description' => $description];
    }
    
    public function stepWithContext(int $step, int $result, string $message = ''): void {
        if (empty($this->contextStack) || $step === 0 || $step === 99) return;
        $ctx = end($this->contextStack);
        $code = ($ctx['module'] * 10000000) + ($ctx['api'] * 100000) + ($ctx['function'] * 1000) + ($step * 10) + $result;
        $fullMsg = $ctx['description'] ? "{$ctx['description']}: $message" : $message;
        if (!$this->currentExecutionId) return;
        try {
            $this->db->execute(
                "INSERT INTO execution_steps (execution_id, step_code, step_message) VALUES (:exec_id, :step_code, :message)",
                [':exec_id' => $this->currentExecutionId, ':step_code' => $code, ':message' => $fullMsg]
            );
        } catch (\Exception $e) {
            error_log("Logger step error: " . $e->getMessage());
        }
    }
    
    public function exitContext(): void {
        array_pop($this->contextStack);
    }
    
    public function hasContext(): bool {
        return !empty($this->contextStack);
    }
    
    public function getCurrentExecutionId(): ?string {
        return $this->currentExecutionId;
    }
}

class Logger {
    private LoggingStrategy $strategy;
    
    public function __construct(LoggingStrategy $strategy) {
        $this->strategy = $strategy;
    }
    
    public static function create(Database $db): self {
        return new self(new DatabaseLoggingStrategy($db));
    }
    
    public function startExecution(string $clientId, string $action, string $sourceType = 'api'): void {
        $this->strategy->startExecution($clientId, $action, $sourceType);
    }
    
    public function finish(string $status): void {
        $this->strategy->finish($status);
    }
    
    public function getCurrentExecutionId(): ?string {
        return $this->strategy->getCurrentExecutionId();
    }
    
    public function enterContext(int $module, int $api, int $function, string $description = ''): void {
        $this->strategy->enterContext($module, $api, $function, $description);
    }
    
    public function stepWithContext(int $step, int $result, string $message = ''): void {
        $this->strategy->stepWithContext($step, $result, $message);
    }
    
    public function exitContext(): void {
        $this->strategy->exitContext();
    }
    
    public function hasContext(): bool {
        return $this->strategy->hasContext();
    }
}
