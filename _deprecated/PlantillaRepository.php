<?php
require_once __DIR__ . '/AbstractRepository.php';

/**
 * PlantillaRepository - Repository for overlay_plantillas table
 */
class PlantillaRepository extends AbstractRepository {
    protected string $tableName = 'overlay_plantillas';
    
    public function __construct(Database $db) {
        parent::__construct($db);
    }
    
    /**
     * Get all plantillas ordered by ID
     */
    public function getAll(): array {
        return $this->db->fetchAll("SELECT * FROM {$this->tableName} ORDER BY id");
    }
    
    /**
     * Find auto-mount plantilla by destination
     */
    public function findAutoMountByDst(string $dst): ?array {
        return $this->db->fetchOne(
            "SELECT id, overlay_dst FROM {$this->tableName} WHERE overlay_dst = :dst AND auto_mount = 't'",
            [':dst' => $dst]
        );
    }
    
    /**
     * Find auto-mount plantilla by destination excluding an ID
     */
    public function findAutoMountByDstExcluding(string $dst, int $excludeId): ?array {
        return $this->db->fetchOne(
            "SELECT id, overlay_dst FROM {$this->tableName} WHERE overlay_dst = :dst AND auto_mount = 't' AND id != :exclude_id",
            [':dst' => $dst, ':exclude_id' => $excludeId]
        );
    }
    
    /**
     * Create a new plantilla
     */
    public function createPlantilla(string $src, string $dst, string $mode, string $autoMount, string $dstPerms = 'exclusive'): int {
        return $this->db->insert(
            "INSERT INTO {$this->tableName} (overlay_src, overlay_dst, mode, auto_mount, dst_perms) VALUES (:src, :dst, :mode, :auto_mount, :dst_perms) RETURNING id",
            [':src' => $src, ':dst' => $dst, ':mode' => $mode, ':auto_mount' => $autoMount, ':dst_perms' => $dstPerms]
        );
    }
    
    /**
     * Update a plantilla
     */
    public function updatePlantilla(int $id, string $src, string $dst, string $mode, string $autoMount, string $dstPerms = 'exclusive'): bool {
        return $this->db->execute(
            "UPDATE {$this->tableName} SET overlay_src = :src, overlay_dst = :dst, mode = :mode, auto_mount = :auto_mount, dst_perms = :dst_perms WHERE id = :id",
            [':src' => $src, ':dst' => $dst, ':mode' => $mode, ':auto_mount' => $autoMount, ':dst_perms' => $dstPerms, ':id' => $id]
        ) > 0;
    }
    
    /**
     * Get plantilla with just dst and mode (for unmount operations)
     */
    public function getDstAndMode(int $id): ?array {
        return $this->db->fetchOne(
            "SELECT overlay_dst, mode FROM {$this->tableName} WHERE id = :id",
            [':id' => $id]
        );
    }
    
    /**
     * Get auto_mount, overlay_dst, mode for a plantilla
     */
    public function getAutoMountInfo(int $id): ?array {
        return $this->db->fetchOne(
            "SELECT auto_mount, overlay_dst, mode FROM {$this->tableName} WHERE id = :id",
            [':id' => $id]
        );
    }
}
