<?php
require_once __DIR__ . '/AbstractRepository.php';

class EditorRepository extends AbstractRepository {
    protected string $tableName = 'editor_files';
    
    public function __construct(Database $db) {
        parent::__construct($db);
    }
    
    public function listFiles(string $path = ''): array {
        if ($path) {
            return $this->db->fetchAll(
                "SELECT id, path, md5, size, created_at, modified_at FROM {$this->tableName} WHERE path LIKE :path_prefix ORDER BY path",
                [':path_prefix' => $path . '%']
            );
        }
        return $this->db->fetchAll("SELECT id, path, md5, size, created_at, modified_at FROM {$this->tableName} ORDER BY path");
    }
    
    public function readFile(string $path): ?array {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->tableName} WHERE path = :path",
            [':path' => $path]
        );
    }
    
    public function saveFile(string $path, string $content): bool {
        $md5 = md5($content);
        $size = strlen($content);
        return $this->db->execute(
            "UPDATE {$this->tableName} SET content = :content, md5 = :md5, size = :size, modified_at = NOW() WHERE path = :path",
            [':content' => $content, ':md5' => $md5, ':size' => $size, ':path' => $path]
        ) > 0;
    }
    
    public function createFile(string $path, string $content): int {
        $md5 = md5($content);
        $size = strlen($content);
        return $this->db->insert(
            "INSERT INTO {$this->tableName} (path, content, md5, size) VALUES (:path, :content, :md5, :size) RETURNING id",
            [':path' => $path, ':content' => $content, ':md5' => $md5, ':size' => $size]
        );
    }
    
    public function deleteFile(string $path): bool {
        return $this->db->execute("DELETE FROM {$this->tableName} WHERE path = :path", [':path' => $path]) > 0;
    }
    
    public function exists(string $path): bool {
        return $this->db->fetchOne("SELECT id FROM {$this->tableName} WHERE path = :path", [':path' => $path]) !== null;
    }
    
    public function getMd5(string $path): ?string {
        $row = $this->db->fetchOne("SELECT md5 FROM {$this->tableName} WHERE path = :path", [':path' => $path]);
        return $row ? $row['md5'] : null;
    }
    
    public function renameFile(string $oldPath, string $newPath, string $content): bool {
        $md5 = md5($content);
        $size = strlen($content);
        $this->db->execute("DELETE FROM {$this->tableName} WHERE path = :old_path", [':old_path' => $oldPath]);
        return $this->db->execute(
            "INSERT INTO {$this->tableName} (path, content, md5, size) VALUES (:path, :content, :md5, :size) ON CONFLICT (path) DO UPDATE SET content = :upd_content, md5 = :upd_md5, size = :upd_size, modified_at = NOW()",
            [':path' => $newPath, ':content' => $content, ':md5' => $md5, ':size' => $size, ':upd_content' => $content, ':upd_md5' => $md5, ':upd_size' => $size]
        ) > 0;
    }
}
