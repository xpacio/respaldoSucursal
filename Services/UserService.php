<?php
/**
 * UserService - Gestión de usuarios Linux
 */
require_once __DIR__ . '/ServiceInterfaces.php';

class UserService implements UserServiceInterface {
    private Database $db;
    private Logger $logger;
    private SystemInterface $system;

    public function __construct(Database $db, Logger $logger, System $system) {
        $this->db = $db;
        $this->logger = $logger;
        $this->system = $system;
    }

    public function create(string $username, string $homeDir): array {
        // Limpiar si el usuario ya existe
        if ($this->exists($username)) {
            $this->delete($username);
        }
        
        // Limpiar grupo del usuario si existe (grupos secundarios)
        $this->system->sudo("groupdel " . escapeshellarg($username) . " 2>/dev/null || true", $exitCode);
        
        // Limpiar home si existe (puede quedar de un usuario anterior)
        if (is_dir($homeDir)) {
            $this->system->sudo("rm -rf " . escapeshellarg($homeDir) . " 2>&1", $exitCode);
        }
        
        $exitCode = null;
        $this->system->sudo("useradd --create-home --home-dir " . escapeshellarg($homeDir) . " --shell /bin/bash --groups users " . escapeshellarg($username) . " 2>&1", $exitCode);
        
        if ($exitCode !== 0) {
            return ['ok' => false, 'error' => 'useradd failed'];
        }
        
        return ['ok' => true];
    }

    public function enable(string $username): array {
        $exitCode = null;
        $this->system->sudo("usermod -U " . escapeshellarg($username) . " 2>/dev/null", $exitCode);
        $this->system->sudo("usermod -s /bin/bash " . escapeshellarg($username) . " 2>/dev/null", $exitCode);
        return ['ok' => $exitCode === 0, 'error' => $exitCode !== 0 ? 'usermod failed' : null];
    }

    public function disable(string $username): array {
        $exitCode = null;
        $this->system->sudo("usermod -L " . escapeshellarg($username) . " 2>/dev/null", $exitCode);
        $this->system->sudo("usermod -s /sbin/nologin " . escapeshellarg($username) . " 2>/dev/null", $exitCode);
        return ['ok' => $exitCode === 0, 'error' => $exitCode !== 0 ? 'usermod failed' : null];
    }

    public function delete(string $username): array {
        $exitCode = null;
        
        // userdel -r elimina: usuario, home, mailbox
        $this->system->sudo("userdel -r " . escapeshellarg($username) . " 2>&1", $exitCode);
        
        // Limpiar grupo principal del usuario (grupo con mismo nombre)
        $this->system->sudo("groupdel " . escapeshellarg($username) . " 2>/dev/null || true", $exitCode);
        
        return ['ok' => true];
    }

    public function exists(string $username): bool {
        $result = $this->system->cmd("id $username 2>/dev/null", $exitCode);
        return $exitCode === 0;
    }

    public function ensure(string $username, string $homeDir): array {
        if ($this->exists($username)) {
            return ['ok' => true, 'action' => 'skipped', 'reason' => 'Usuario ya existe'];
        }
        
        $result = $this->create($username, $homeDir);
        if (!$result['ok']) {
            return ['ok' => false, 'action' => 'error', 'error' => $result['error'] ?? 'create failed'];
        }
        
        return ['ok' => true, 'action' => 'created'];
    }
}
