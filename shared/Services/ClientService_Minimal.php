<?php
require_once __DIR__ . '/ServiceInterfaces.php';

/**
 * ClientService - Minimal version for AR registration only
 */
class ClientService implements ClientServiceInterface {
    private Database $db;
    private Logger $logger;
    private SystemInterface $system;

    public function __construct(
        Database $db,
        Logger $logger,
        SystemInterface $system,
        ...$args // Accept additional args for backward compatibility
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->system = $system;
    }

    /**
     * Register a client in ar_clients table
     */
    public function registerClient(string $rbfid): void {
        $existing = $this->db->fetchOne("SELECT rbfid FROM ar_clients WHERE rbfid = :rbfid", [':rbfid' => $rbfid]);
        if (!$existing) {
            $this->db->execute(
                "INSERT INTO ar_clients (rbfid, enabled, registered_at) VALUES (:rbfid, true, NOW())",
                [':rbfid' => $rbfid]
            );
        }
    }

    // Stub methods for interface compliance
    public function create(string $clientId, bool $enabled, string $emp = '', string $plaza = ''): array {
        return ['ok' => false, 'error' => 'Not implemented in minimal ClientService'];
    }

    public function applyTemplatesToClient(string $clientId): array {
        return ['ok' => false, 'error' => 'Not implemented'];
    }

    public function applyTemplatesToAllActiveClients(): array {
        return ['ok' => false, 'error' => 'Not implemented'];
    }

    public function enable(string $clientId): array {
        return ['ok' => false, 'error' => 'Not implemented'];
    }

    public function disable(string $clientId): array {
        return ['ok' => false, 'error' => 'Not implemented'];
    }

    public function delete(string $clientId, bool $hard = false): array {
        return ['ok' => false, 'error' => 'Not implemented'];
    }

    public function getUserService() {
        return null;
    }

    public function getSshService() {
        return null;
    }
}
