<?php
declare(strict_types=1);

require_once __DIR__ . '/DatabaseService.php';

class ClientService {
    private DatabaseService $db;

    public function __construct(DatabaseService $db) {
        $this->db = $db;
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
