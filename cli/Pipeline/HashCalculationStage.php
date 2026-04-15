<?php

namespace Client\Pipeline;

final readonly class HashCalculationStage implements PipelineStageInterface {
    public function process(mixed $payload): mixed {
        $filePath = $payload['file_path'];
        
        $hash = hash_file('xxh3', $filePath);
        
        return [
            ...$payload,
            'file_hash' => $hash,
            'file_size' => filesize($filePath)
        ];
    }
}