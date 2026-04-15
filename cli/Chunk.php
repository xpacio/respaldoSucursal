<?php

declare(strict_types=1);

require_once __DIR__ . '/../shared/Constants.php';

class Chunk
{
    public const MIN_CHUNK = Constants::CHUNK_MIN_SIZE;
    public const MAX_CHUNK = Constants::CHUNK_MAX_SIZE;
    public const ALIGNMENT = Constants::CHUNK_ALIGNMENT;

    public static function calculateChunkSize(int $fileSize): int
    {
        if ($fileSize === 0) {
            return self::MIN_CHUNK;
        }

        if ($fileSize < Constants::CHUNK_1MB_THRESHOLD) {
            return self::MIN_CHUNK;
        }

        if ($fileSize < Constants::CHUNK_10MB_THRESHOLD) {
            return 65536;
        }

        if ($fileSize < Constants::CHUNK_100MB_THRESHOLD) {
            return 262144;
        }

        return self::MAX_CHUNK;
    }

    public static function calculateChunkSizeDynamic(int $fileSize): int
    {
        if ($fileSize <= self::MIN_CHUNK) {
            return $fileSize;
        }
        $fileSizeMB = $fileSize / (1024 * 1024);
        $targetBlocks = 50.0 + (log(max(1.0, $fileSizeMB), 2) * 50.0);
        $chunkSize = $fileSize / $targetBlocks;
        $chunkSize = max(self::MIN_CHUNK, min(self::MAX_CHUNK, $chunkSize));
        return (int)(ceil($chunkSize / self::ALIGNMENT) * self::ALIGNMENT);
    }

    public static function calculateChunkCount(int $fileSize): int
    {
        $chunkSize = self::calculateChunkSize($fileSize);
        return self::calculateChunkCount2($fileSize, $chunkSize);
    }

    public static function calculateChunkCount2(int $fileSize, int $chunkSize): int
    {
        if ($chunkSize === 0 || $fileSize === 0) {
            return 0;
        }
        return (int)(($fileSize + $chunkSize - 1) / $chunkSize);
    }

    public static function getChunkRange(int $fileSize, int $startChunk): array
    {
        $chunkSize = self::calculateChunkSize($fileSize);
        $offset = $startChunk * $chunkSize;
        $size = (int)min($chunkSize, $fileSize - $offset);
        
        return [
            'offset' => $offset,
            'size' => $size,
        ];
    }
}