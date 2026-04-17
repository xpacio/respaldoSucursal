<?php

declare(strict_types=1);

namespace App\Cli;

class UploadTarget
{
    public string $file;
    public array $chunks;
    public string $work_path;
    public string $dest_path;
    public string $md5;

    public function __construct(string $file, array $chunks, string $work_path = '', string $dest_path = '', string $md5 = '')
    {
        $this->file = $file;
        $this->chunks = $chunks;
        $this->work_path = $work_path;
        $this->dest_path = $dest_path;
        $this->md5 = $md5;
    }

    public static function fromArray(array $data): ?UploadTarget
    {
        if (!isset($data['file']) || !isset($data['chunks'])) {
            return null;
        }
        return new UploadTarget(
            $data['file'],
            $data['chunks'],
            $data['work_path'] ?? '',
            $data['dest_path'] ?? '',
            $data['md5'] ?? ''
        );
    }
}