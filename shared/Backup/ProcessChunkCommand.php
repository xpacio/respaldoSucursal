<?php

namespace Shared\Backup;

use Shared\Backup\BackupCommandInterface;
use Shared\Backup\BackupSessionRepositoryInterface;
use Shared\Backup\ChunkDTO;

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
