<?php

declare(strict_types=1);

require_once __DIR__ . '/Constants.php';

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

        $fileSizeF = (float)$fileSize;
        $targetBlocks = 100.0 + (log($fileSizeF / 1048576.0, 2) * 500.0);
        $clamped = max(self::MIN_CHUNK, $fileSizeF / $targetBlocks);
        $finalSize = min(self::MAX_CHUNK, $clamped);
        $aligned = ceil($finalSize / self::ALIGNMENT) * self::ALIGNMENT;

        return (int)$aligned;
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