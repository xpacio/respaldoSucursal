<?php

declare(strict_types=1);

namespace App\Cli;

class UploadResponse
{
    public bool $ok;
    public int $next_delay;
    public int $slots_used;

    public function __construct(bool $ok = false, int $next_delay = 0, int $slots_used = 0)
    {
        $this->ok = $ok;
        $this->next_delay = $next_delay;
        $this->slots_used = $slots_used;
    }

    public static function fromArray(array $data): UploadResponse
    {
        return new UploadResponse(
            $data['ok'] ?? false,
            $data['next_delay'] ?? 0,
            $data['slots_used'] ?? 0
        );
    }
}