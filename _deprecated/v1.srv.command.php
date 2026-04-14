<?php

namespace Server\Command;

// Command Interface
interface BackupCommandInterface {
    public function execute(): array;
    public function undo(): void; // Para rollback si falla
}

// DTO para chunks
readonly class ChunkDTO {
    public function __construct(
        public string $sessionId,
        public string $fileHash,
        public int $chunkIndex,
        public string $chunkHash,
        public string $data,
        public bool $isLast
    ) {}
}

// REPOSITORY: Abstracción del almacenamiento
interface BackupSessionRepositoryInterface {
    public function create(array $metadata): string;
    public function find(string $sessionId): ?array;
    public function appendChunk(string $sessionId, ChunkDTO $chunk): bool;
    public function verifyAndFinalize(string $sessionId): bool;
    public function delete(string $sessionId): void;
}

// Implementación con archivo temporal
class FileSystemBackupRepository implements BackupSessionRepositoryInterface {
    private string $tempDir;
    private string $finalDir;
    
    public function __construct() {
        $this->tempDir = sys_get_temp_dir() . '/backup_temp/';
        $this->finalDir = '/var/backups/received/';
        @mkdir($this->tempDir, 0777, true);
        @mkdir($this->finalDir, 0777, true);
    }
    
    public function create(array $metadata): string {
        $sessionId = bin2hex(random_bytes(16));
        $sessionFile = $this->tempDir . $sessionId . '.json';
        
        file_put_contents($sessionFile, json_encode([
            'metadata' => $metadata,
            'chunks_received' => [],
            'temp_file' => $this->tempDir . $sessionId . '.tmp',
            'status' => 'initialized',
            'created_at' => time()
        ]));
        
        // Crear archivo temporal vacío
        touch($this->tempDir . $sessionId . '.tmp');
        
        return $sessionId;
    }
    
    public function find(string $sessionId): ?array {
        $file = $this->tempDir . $sessionId . '.json';
        return file_exists($file) ? json_decode(file_get_contents($file), true) : null;
    }
    
    public function appendChunk(string $sessionId, ChunkDTO $chunk): bool {
        $session = $this->find($sessionId);
        if (!$session) return false;
        
        // Verificar chunk hash
        $decodedData = base64_decode($chunk->data);
        if (hash('xxh3', $decodedData) !== $chunk->chunkHash) {
            return false; // Corrupción detectada
        }
        
        // Escribir en posición correcta (random access)
        $tempFile = fopen($session['temp_file'], 'r+b');
        fseek($tempFile, $chunk->chunkIndex * strlen($decodedData));
        fwrite($tempFile, $decodedData);
        fclose($tempFile);
        
        // Actualizar metadatos
        $session['chunks_received'][] = $chunk->chunkIndex;
        $session['last_activity'] = time();
        $this->saveSession($sessionId, $session);
        
        return true;
    }
    
    public function verifyAndFinalize(string $sessionId): bool {
        $session = $this->find($sessionId);
        if (!$session) return false;
        
        // Verificar hash completo del archivo
        $finalHash = hash_file('xxh3', $session['temp_file']);
        if ($finalHash !== $session['metadata']['file_hash']) {
            $this->delete($sessionId);
            throw new \RuntimeException("Hash final no coincide - archivo corrupto");
        }
        
        // Mover a destino final
        $finalPath = $this->finalDir . $session['metadata']['filename'];
        rename($session['temp_file'], $finalPath);
        
        // Limpiar metadata
        unlink($this->tempDir . $sessionId . '.json');
        
        return true;
    }
    
    public function delete(string $sessionId): void {
        @unlink($this->tempDir . $sessionId . '.json');
        @unlink($this->tempDir . $sessionId . '.tmp');
    }
    
    private function saveSession(string $sessionId, array $data): void {
        file_put_contents(
            $this->tempDir . $sessionId . '.json', 
            json_encode($data)
        );
    }
}

// COMMAND: Procesar chunk entrante
class ProcessChunkCommand implements BackupCommandInterface {
    public function __construct(
        private BackupSessionRepositoryInterface $repository,
        private ChunkDTO $chunk
    ) {}
    
    public function execute(): array {
        $success = $this->repository->appendChunk(
            $this->chunk->sessionId, 
            $this->chunk
        );
        
        if (!$success) {
            return ['verified' => false, 'error' => 'Hash verification failed'];
        }
        
        // Si es el último chunk, finalizar
        if ($this->chunk->isLast) {
            $finalized = $this->repository->verifyAndFinalize($this->chunk->sessionId);
            return [
                'verified' => true, 
                'finalized' => $finalized,
                'message' => 'Backup completed'
            ];
        }
        
        return ['verified' => true, 'chunk_index' => $this->chunk->chunkIndex];
    }
    
    public function undo(): void {
        // En caso de error en pipeline, eliminar sesión
        $this->repository->delete($this->chunk->sessionId);
    }
}

// HANDLER HTTP (Controlador del servidor)
class BackupApiController {
    public function __construct(
        private BackupSessionRepositoryInterface $repository
    ) {}
    
    public function init(array $request): array {
        $sessionId = $this->repository->create($request);
        return ['session_id' => $sessionId, 'status' => 'ready'];
    }
    
    public function receiveChunk(array $request): array {
        $chunk = new ChunkDTO(
            $request['session_id'],
            $request['file_hash'],
            $request['chunk_index'],
            $request['chunk_hash'],
            $request['data'],
            $request['is_last']
        );
        
        $command = new ProcessChunkCommand($this->repository, $chunk);
        
        try {
            return $command->execute();
        } catch (\Throwable $e) {
            $command->undo(); // Rollback
            return ['error' => $e->getMessage()];
        }
    }
}