<?php

namespace Shared\Backup;

// REPOSITORY: Abstracción del almacenamiento
interface BackupSessionRepositoryInterface {
    public function create(array $metadata): string;
    public function find(string $sessionId): ?array;
    public function appendChunk(string $sessionId, ChunkDTO $chunk): bool;
    public function verifyAndFinalize(string $sessionId): bool;
    public function delete(string $sessionId): void;
}
