<?php

declare(strict_types=1);

require_once __DIR__ . '/Hash.php';

class FileHashData
{
    public string $filename;
    public string $hash_completo;
    public array $chunk_hashes;
    public int $mtime;
    public int $size;

    public function __construct(string $filename, string $hash_completo, array $chunk_hashes, int $mtime, int $size)
    {
        $this->filename = $filename;
        $this->hash_completo = $hash_completo;
        $this->chunk_hashes = $chunk_hashes;
        $this->mtime = $mtime;
        $this->size = $size;
    }

    public function toArray(): array
    {
        return [
            'filename' => $this->filename,
            'hash_completo' => (new Hash($this->hash_completo))->toBase64(),
            'chunk_hashes' => array_map(
                fn($h) => (new Hash($h))->toBase64(),
                $this->chunk_hashes
            ),
            'mtime' => $this->mtime,
            'size' => $this->size,
        ];
    }
}