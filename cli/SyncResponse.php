<?php

declare(strict_types=1);

namespace App\Cli;

require_once __DIR__ . '/UploadTarget.php';

class SyncResponse
{
    public bool $ok;
    public string $sync_id;
    public array $needs_upload;
    public int $rate_delay;
    public int $slots_used;
    public int $slots_available;
    public string $files_version;
    public array $files;

    public function __construct(
        bool $ok = false,
        string $sync_id = '',
        array $needs_upload = [],
        int $rate_delay = 3000,
        int $slots_used = 0,
        int $slots_available = 10,
        string $files_version = '',
        array $files = []
    ) {
        $this->ok = $ok;
        $this->sync_id = $sync_id;
        $this->needs_upload = $needs_upload;
        $this->rate_delay = $rate_delay;
        $this->slots_used = $slots_used;
        $this->slots_available = $slots_available;
        $this->files_version = $files_version;
        $this->files = $files;
    }

    public static function fromArray(array $data): SyncResponse
    {
        $needsUpload = [];
        if (isset($data['needs_upload']) && is_array($data['needs_upload'])) {
            foreach ($data['needs_upload'] as $item) {
                $target = UploadTarget::fromArray($item);
                if ($target !== null) {
                    $needsUpload[] = $target;
                }
            }
        }

        return new SyncResponse(
            $data['ok'] ?? false,
            (string)($data['sync_id'] ?? ''),
            $needsUpload,
            $data['rate_delay'] ?? 3000,
            $data['slots_used'] ?? 0,
            $data['slots_available'] ?? 10,
            $data['files_version'] ?? '',
            $data['files'] ?? []
        );
    }
}