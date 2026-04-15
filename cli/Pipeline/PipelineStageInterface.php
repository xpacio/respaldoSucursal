<?php

namespace Client\Pipeline;

interface PipelineStageInterface {
    public function process(mixed $payload): mixed;
}