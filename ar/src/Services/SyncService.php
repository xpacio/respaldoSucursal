<?php
declare(strict_types=1);

require_once __DIR__ . '/DatabaseService.php';

class SyncService {
    private DatabaseService $db;

    public function __construct(DatabaseService $db) {
        $this->db = $db;
    }

    public function createSyncRecord(string $rbfid): int {
        return $this->db->insert(
            "INSERT INTO ar_sync_history (rbfid, status, started_at) VALUES (:rbfid, 'pending', NOW())",
            [':rbfid' => $rbfid]
        );
    }
}
