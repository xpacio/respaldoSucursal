<?php
declare(strict_types=1);

namespace App\Api;

use App\Services\DatabaseService;

class ChunkService {
    private DatabaseService $db;

    public function __construct(DatabaseService $db) {
        $this->db = $db;
    }

    public function getMissingChunks(string $rbfid, string $filename): array {
        return $this->db->fetchAll(
            "SELECT chunk_index, hash_xxh3, status, error_count 
             FROM ar_file_hashes 
             WHERE rbfid = :rbfid AND file_name = :file AND status != 'received'
             ORDER BY chunk_index",
            [':rbfid' => $rbfid, ':file' => $filename]
        );
    }
}
