<?php
declare(strict_types=1);

require_once __DIR__ . '/StorageService.php';
require_once __DIR__ . '/DatabaseService.php';
require_once __DIR__ . '/../../../../cli/Chunk.php';

class UploadService {
    private DatabaseService $db;
    private StorageService $storage;

    public function __construct(DatabaseService $db, StorageService $storage) {
        $this->db = $db;
        $this->storage = $storage;
    }

    public function handle(string $rbfid, string $filename, int $chunkIndex, string $expectedHash, string $data, array $clientData): array {
        $decoded = base64_decode($data, true);
        if ($decoded === false) throw new Exception('Data base64 invalida');

        $fileInfo = $this->db->fetchOne(
            "SELECT file_size FROM ar_files WHERE rbfid = :rbfid AND file_name = :file",
            [':rbfid' => $rbfid, ':file' => $filename]
        );
        $fileSize = (int)($fileInfo['file_size'] ?? 0);

        if ($fileSize === 0 && $chunkIndex === 0) {
            $fileSize = strlen($decoded);
        }

        $range = Chunk::getChunkRange($fileSize, $chunkIndex);
        
        // 1. Guardar chunk
        if (!$this->storage->saveChunk($clientData['work_dir'], $filename, $chunkIndex, $range['offset'], $decoded)) {
            throw new Exception('No se pudo guardar el chunk');
        }

        // 2. Validar hash del chunk
        $receivedHash = hash('xxh3', $decoded);
        $dbExpectedHash = $this->db->fetchOne(
            "SELECT hash_xxh3 FROM ar_file_hashes WHERE rbfid = :rbfid AND file_name = :file AND chunk_index = :idx",
            [':rbfid' => $rbfid, ':file' => $filename, ':idx' => $chunkIndex]
        );

        if ($receivedHash !== $dbExpectedHash['hash_xxh3']) {
            $this->db->execute(
                "UPDATE ar_file_hashes SET status = 'failed', error_count = error_count + 1 WHERE rbfid = :rbfid AND file_name = :file AND chunk_index = :idx",
                [':rbfid' => $rbfid, ':file' => $filename, ':idx' => $chunkIndex]
            );
            return ['status' => 'failed', 'chunk' => $chunkIndex, 'error' => 'hash_mismatch'];
        }

        // 3. Marcar como received
        $this->db->execute(
            "UPDATE ar_file_hashes SET status = 'received', updated_at = NOW() WHERE rbfid = :rbfid AND file_name = :file AND chunk_index = :idx",
            [':rbfid' => $rbfid, ':file' => $filename, ':idx' => $chunkIndex]
        );

        // 4. Consultar siguiente chunk
        $nextChunk = $this->db->fetchOne(
            "SELECT chunk_index, status, error_count FROM ar_file_hashes 
             WHERE rbfid = :rbfid AND file_name = :file AND status != 'received'
             ORDER BY chunk_index LIMIT 1",
            [':rbfid' => $rbfid, ':file' => $filename]
        );

        if ($nextChunk) {
            return ['status' => 'received', 'next_chunk' => $nextChunk['chunk_index']];
        }

        // 5. Verificar y Mover archivo completo
        return $this->finalizeFile($rbfid, $filename, $clientData['work_dir'], $clientData['base_dir']);
    }

    private function finalizeFile(string $rbfid, string $filename, string $workDir, string $baseDir): array {
        $workPath = $workDir . '/' . $filename;
        $fileRecord = $this->db->fetchOne(
            "SELECT hash_esperado FROM ar_files WHERE rbfid = :rbfid AND file_name = :file",
            [':rbfid' => $rbfid, ':file' => $filename]
        );
        
        $actualHash = $this->storage->getHashStreaming($workPath);
        
        if ($fileRecord && $fileRecord['hash_esperado'] === $actualHash) {
            if (!is_dir($baseDir)) mkdir($baseDir, 0755, true);
            $destPath = $baseDir . '/' . $filename;
            if (file_exists($destPath)) unlink($destPath);
            rename($workPath, $destPath);
            
            $this->db->execute(
                "UPDATE ar_files SET hash_xxh3 = :hash, updated_at = NOW() WHERE rbfid = :rbfid AND file_name = :file",
                [':hash' => $actualHash, ':rbfid' => $rbfid, ':file' => $filename]
            );
            $this->db->execute(
                "UPDATE ar_file_hashes SET error_count = 0 WHERE rbfid = :rbfid AND file_name = :file",
                [':rbfid' => $rbfid, ':file' => $filename]
            );
            return ['status' => 'complete', 'dest_path' => $destPath];
        }
        
        return ['status' => 'error', 'message' => 'hash_mismatch_final'];
    }
}
