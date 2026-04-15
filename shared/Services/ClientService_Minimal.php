<?php

/**
 * ClientService - Minimal version for AR registration only
 * Note: Does not implement full interface to avoid dependency issues
 */
class ClientService_Minimal {
    private $db;
    private $logger;

    public function __construct($db, $logger = null) {
        $this->db = $db;
        $this->logger = $logger;
    }

    public function getClientStatus(string $rbfid): ?array {
        return $this->db->fetchOne(
            "SELECT c.rbfid, c.emp, c.plaza, c.enabled, ar.registered_at, ar.last_sync_at
             FROM clients c
             LEFT JOIN ar_clients ar ON ar.rbfid = c.rbfid
             WHERE c.rbfid = :rbfid",
            [':rbfid' => $rbfid]
        );
    }

    public function getClientFiles(string $rbfid): array {
        return $this->db->fetchAll(
            "SELECT file_name, chunk_count, updated_at FROM ar_files WHERE rbfid = :rbfid",
            [':rbfid' => $rbfid]
        );
    }

    public function registerClient(string $rbfid): void {
        $existing = $this->db->fetchOne("SELECT rbfid FROM ar_clients WHERE rbfid = :rbfid", [':rbfid' => $rbfid]);
        if (!$existing) {
            $this->db->execute(
                "INSERT INTO ar_clients (rbfid, enabled, registered_at) VALUES (:rbfid, true, NOW())",
                [':rbfid' => $rbfid]
            );
        }
    }
}
