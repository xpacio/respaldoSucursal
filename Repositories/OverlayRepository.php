<?php
require_once __DIR__ . '/AbstractRepository.php';

/**
 * OverlayRepository - Repository for overlays table
 */
class OverlayRepository extends AbstractRepository {
    protected string $tableName = 'overlays';
    
    public function __construct(Database $db) {
        parent::__construct($db);
    }
    
    /**
     * Find overlays by client rbfid
     */
    public function findByClient(string $rbfid): array {
        $sql = "SELECT * FROM {$this->tableName} WHERE rbfid = :rbfid";
        return $this->db->fetchAll($sql, [':rbfid' => $rbfid]);
    }
    
    /**
     * Find overlay destinations by client rbfid
     */
    public function findDestinationsByClient(string $rbfid): array {
        $sql = "SELECT overlay_dst FROM {$this->tableName} WHERE rbfid = :rbfid";
        return $this->db->fetchAll($sql, [':rbfid' => $rbfid]);
    }
    
    /**
     * Create overlay for client
     */
    public function createForClient(string $rbfid, string $src, string $dst, string $mode, string $dstPerms = 'exclusive'): int {
        $sql = "INSERT INTO {$this->tableName} (rbfid, overlay_src, overlay_dst, mode, dst_perms) 
                VALUES (:rbfid, :src, :dst, :mode, :dst_perms) RETURNING id";
        return $this->db->insert($sql, [
            ':rbfid' => $rbfid,
            ':src' => $src,
            ':dst' => $dst,
            ':mode' => $mode,
            ':dst_perms' => $dstPerms
        ]);
    }
    
}
