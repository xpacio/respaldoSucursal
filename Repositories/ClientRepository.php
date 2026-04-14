<?php
require_once __DIR__ . '/AbstractRepository.php';

/**
 * ClientRepository - Repository for clients table
 */
class ClientRepository extends AbstractRepository {
    protected string $tableName = 'clients';
    protected string $primaryKey = 'rbfid';
    
    public function __construct(Database $db) {
        parent::__construct($db);
    }
    
    /**
     * Find client by rbfid (string instead of int)
     */
    public function findByRbfid(string $rbfid): ?array {
        return $this->findOneBy(['rbfid' => $rbfid]);
    }
    
    /**
     * Get all clients with basic info and latest sync date
     */
    public function getAllBasic(?string $searchTerm = null): array {
        $sql = "SELECT rbfid, enabled, ssh_enabled, emp, plaza, key_download_enabled, key_downloaded_at, last_sync
                FROM {$this->tableName}";
        
        $params = [];
        
        if ($searchTerm) {
            $search = "%$searchTerm%";
            $sql .= " WHERE rbfid ILIKE :search";
            $params = [':search' => $search];
            // Si hay búsqueda, ordenar por rbfid ASC
            $sql .= " ORDER BY rbfid ASC";
        } else {
            // Si no hay búsqueda, ordenar por ultima sincronización (nulos al final)
            $sql .= " ORDER BY last_sync DESC NULLS LAST, rbfid ASC";
        }
        
        return $this->db->fetchAll($sql, $params);
    }
    
    public function toggleSsh(string $rbfid, bool $enabled): bool {
        $sql = "UPDATE {$this->tableName} SET ssh_enabled = :enabled WHERE rbfid = :rbfid";
        return $this->db->execute($sql, [':enabled' => $enabled ? 'true' : 'false', ':rbfid' => $rbfid]) > 0;
    }
    
    /**
     * Toggle client enabled status
     */
    public function toggleEnabled(string $rbfid, bool $enabled): bool {
        $sql = "UPDATE {$this->tableName} SET enabled = :enabled WHERE rbfid = :rbfid";
        return $this->db->execute($sql, [':enabled' => $enabled ? 'true' : 'false', ':rbfid' => $rbfid]) > 0;
    }
}
