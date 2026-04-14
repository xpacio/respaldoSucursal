<?php
/**
 * AbstractRepository - Base implementation for repositories
 */
abstract class AbstractRepository implements RepositoryInterface {
    protected Database $db;
    protected string $tableName;
    protected string $primaryKey = 'id';
    
    public function __construct(Database $db) {
        $this->db = $db;
    }
    
    public function findOneBy(array $criteria): ?array {
        $where = [];
        $params = [];
        $i = 1;
        
        foreach ($criteria as $field => $value) {
            $where[] = "{$field} = :f_{$i}";
            $params[":f_{$i}"] = $value;
            $i++;
        }
        
        $sql = "SELECT * FROM {$this->tableName}";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        $sql .= " LIMIT 1";
        
        return $this->db->fetchOne($sql, $params);
    }
    
    public function save(array $data): string {
        $fields = [];
        $placeholders = [];
        $params = [];
        $i = 1;
        
        foreach ($data as $field => $value) {
            $fields[] = $field;
            $placeholders[] = ":v_{$i}";
            $params[":v_{$i}"] = $value;
            $i++;
        }
        
        $sql = "INSERT INTO {$this->tableName} (" . implode(', ', $fields) . ") 
                VALUES (" . implode(', ', $placeholders) . ") 
                RETURNING {$this->primaryKey}";
        
        return $this->db->insert($sql, $params);
    }
    
    public function update(int $id, array $data): bool {
        $set = [];
        $params = [];
        $i = 1;
        
        foreach ($data as $field => $value) {
            $set[] = "{$field} = :v_{$i}";
            $params[":v_{$i}"] = $value;
            $i++;
        }
        
        $params[':pk_id'] = $id;
        $sql = "UPDATE {$this->tableName} SET " . implode(', ', $set) . " 
                WHERE {$this->primaryKey} = :pk_id";
        
        return $this->db->execute($sql, $params) > 0;
    }
    
    public function delete(int $id): bool {
        $sql = "DELETE FROM {$this->tableName} WHERE {$this->primaryKey} = :pk_id";
        return $this->db->execute($sql, [':pk_id' => $id]) > 0;
    }
}
