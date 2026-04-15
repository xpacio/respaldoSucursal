<?php

namespace Client\Pipeline;

require_once __DIR__ . '/PipelineStageInterface.php';
require_once __DIR__ . '/HashCalculationStage.php';
require_once __DIR__ . '/ChunkingStage.php';
require_once __DIR__ . '/TransferStage.php';

final class BackupPipeline {
    private array $stages = [];

    public function addStage(PipelineStageInterface $stage): self {
        $this->stages[] = $stage;
        return $this;
    }

    public function execute(array $payload): array {
        $result = $payload;

        foreach ($this->stages as $stage) {
            $result = $stage->process($result);
        }

        return $result;
    }
}