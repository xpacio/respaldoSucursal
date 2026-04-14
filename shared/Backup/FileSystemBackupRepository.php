<?php

namespace Shared\Backup;

use Shared\Backup\BackupSessionRepositoryInterface;
use Shared\Backup\ChunkDTO;

class FileSystemBackupRepository implements BackupSessionRepositoryInterface {
    private string $tempDir;
    private string $finalDir;

    public function __construct() {
        $baseDir = sys_get_temp_dir() . '/respaldo_sucursal_backup/';
        $this->tempDir = $baseDir . 'temp/';
        $this->finalDir = $baseDir . 'received/';

        @mkdir($this->tempDir, 0777, true);
        @mkdir($this->finalDir, 0777, true);
    }

    public function create(array $metadata): string {
        $sessionId = bin2hex(random_bytes(16));
        $sessionFile = $this->tempDir . $sessionId . '.json';
        $tempFile = $this->tempDir . $sessionId . '.tmp';

        file_put_contents($sessionFile, json_encode([
            'metadata' => $metadata,
            'chunks_received' => [],
            'temp_file' => $tempFile,
            'status' => 'initialized',
            'created_at' => time()
        ]));

        touch($tempFile);
        return $sessionId;
    }

    public function find(string $sessionId): ?array {
        $file = $this->tempDir . $sessionId . '.json';
        if (!file_exists($file)) {
            return null;
        }
        return json_decode(file_get_contents($file), true);
    }

    public function appendChunk(string $sessionId, ChunkDTO $chunk): bool {
        $session = $this->find($sessionId);
        if (!$session) {
            return false;
        }

        $decodedData = base64_decode($chunk->data, true);
        if ($decodedData === false) {
            return false;
        }

        if (hash('xxh3', $decodedData) !== $chunk->chunkHash) {
            return false;
        }

        $chunkSize = $session['metadata']['chunk_size'] ?? strlen($decodedData);
        if (!isset($session['metadata']['chunk_size']) && $chunk->chunkIndex === 0) {
            $session['metadata']['chunk_size'] = strlen($decodedData);
        }

        $tempFile = fopen($session['temp_file'], 'c+b');
        if ($tempFile === false) {
            return false;
        }

        fseek($tempFile, $chunk->chunkIndex * $chunkSize);
        fwrite($tempFile, $decodedData);
        fclose($tempFile);

        $session['chunks_received'][] = $chunk->chunkIndex;
        $session['last_activity'] = time();
        $this->saveSession($sessionId, $session);

        return true;
    }

    public function verifyAndFinalize(string $sessionId): bool {
        $session = $this->find($sessionId);
        if (!$session) {
            return false;
        }

        $tempFile = $session['temp_file'];
        if (!file_exists($tempFile)) {
            return false;
        }

        $finalHash = hash_file('xxh3', $tempFile);
        if ($finalHash !== $session['metadata']['file_hash']) {
            $this->delete($sessionId);
            throw new \RuntimeException('Hash final no coincide - archivo corrupto');
        }

        $filename = $session['metadata']['filename'] ?? ($sessionId . '.bin');
        $finalPath = $this->finalDir . basename($filename);

        if (!rename($tempFile, $finalPath)) {
            throw new \RuntimeException('No se pudo mover el backup al destino final');
        }

        @unlink($this->tempDir . $sessionId . '.json');
        return true;
    }

    public function delete(string $sessionId): void {
        @unlink($this->tempDir . $sessionId . '.json');
        @unlink($this->tempDir . $sessionId . '.tmp');
    }

    private function saveSession(string $sessionId, array $data): void {
        file_put_contents($this->tempDir . $sessionId . '.json', json_encode($data));
    }
}
