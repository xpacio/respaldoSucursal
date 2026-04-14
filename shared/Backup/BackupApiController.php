<?php

namespace Shared\Backup;

use Shared\Backup\BackupSessionRepositoryInterface;
use Shared\Backup\ChunkDTO;

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
