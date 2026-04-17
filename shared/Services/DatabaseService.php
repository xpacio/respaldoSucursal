<?php
declare(strict_types=1);

namespace App\Services;

class DatabaseService {
    private $db;
    public function __construct($db) { $this->db = $db; }
    
    public function fetchOne(string $sql, array $params = []) { return $this->db->fetchOne($sql, $params); }
    public function fetchAll(string $sql, array $params = []) { return $this->db->fetchAll($sql, $params); }
    public function execute(string $sql, array $params = []) { return $this->db->execute($sql, $params); }
    public function insert(string $sql, array $params = []) { return $this->db->insert($sql, $params); }
}
