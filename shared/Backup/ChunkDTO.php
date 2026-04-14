<?php

namespace Shared\Backup;

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
