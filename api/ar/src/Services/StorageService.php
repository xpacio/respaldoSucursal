<?php
declare(strict_types=1);

class StorageService {
    public function saveChunk(string $workPath, string $filename, int $index, int $offset, string $data): bool {
        $filePath = $workPath . DIRECTORY_SEPARATOR . $filename;
        if (!is_dir($workPath)) mkdir($workPath, 0755, true);

        $fh = fopen($filePath, 'c+b');
        if (!$fh) return false;

        // Atomic writing with flock
        if (flock($fh, LOCK_EX)) {
            fseek($fh, $offset);
            fwrite($fh, $data);
            fflush($fh);
            flock($fh, LOCK_UN);
            fclose($fh);
            return true;
        }

        fclose($fh);
        return false;
    }

    public function getHashStreaming(string $filePath): string {
        $ctx = hash_init('xxh3');
        $fh = fopen($filePath, 'rb');
        if (!$fh) throw new Exception("Cannot open file: $filePath");
        
        while (!feof($fh)) {
            hash_update($ctx, fread($fh, 8192));
        }
        fclose($fh);
        return hash_final($ctx);
    }
}
