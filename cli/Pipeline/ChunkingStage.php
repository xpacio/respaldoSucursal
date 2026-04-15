<?php

namespace Client\Pipeline;

final class ChunkingStage implements PipelineStageInterface {
    public function __construct(
        private int $chunkSize = 1024 * 1024
    ) {}

    public function process(mixed $payload): mixed {
        $filePath = $payload['file_path'];
        $chunks = [];
        $handle = fopen($filePath, 'rb');
        $index = 0;

        while (!feof($handle)) {
            $chunkData = fread($handle, $this->chunkSize);
            if ($chunkData === false || $chunkData === '') {
                break;
            }
            $chunkHash = hash('xxh3', $chunkData);

            $chunks[] = [
                'index' => $index,
                'data' => base64_encode($chunkData),
                'hash' => $chunkHash,
                'size' => strlen($chunkData)
            ];
            $index++;
        }

        fclose($handle);

        return [
            ...$payload,
            'chunks' => $chunks,
            'total_chunks' => $index
        ];
    }
}