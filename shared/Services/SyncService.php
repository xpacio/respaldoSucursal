<?php
declare(strict_types=1);

require_once __DIR__ . '/../Logger.php';
require_once __DIR__ . '/../Hash.php';
require_once __DIR__ . '/../../cli/Chunk.php';
require_once __DIR__ . '/../../cli/HttpClient.php';
require_once __DIR__ . '/../../cli/FileHashData.php';
require_once __DIR__ . '/RegistrationService.php';

class SyncService {
    private HttpClient $http;
    private RegistrationService $regService;

    public function __construct(HttpClient $http, RegistrationService $regService) {
        $this->http = $http;
        $this->regService = $regService;
    }

    public function hashFile(string $path): string {
        $content = file_get_contents($path);
        if ($content === false) throw new Exception("Cannot read: $path");
        return Hash::compute($content)->getHex();
    }

    public function syncFile(string $serverUrl, Location $loc, string $filename, string $workFile, bool $forced): bool {
        $stat = stat($workFile);
        if ($stat === false) return false;

        $timestamp = $this->regService->fetchTimestamp($loc->rbfid);
        $totp = $this->regService->generateTotp($loc->rbfid, $timestamp);

        $hash = $this->hashFile($workFile);
        $chunkSize = $forced ? Chunk::MAX_CHUNK : Chunk::calculateChunkSize((int)$stat['size']);
        
        $chunkHashes = $this->hashChunks($workFile, (int)$stat['size'], $chunkSize);
        $fileData = new FileHashData($filename, $hash, $chunkHashes, (int)$stat['mtime'], (int)$stat['size']);

        $response = $this->http->sync($serverUrl, $loc->rbfid, $totp, [$fileData]);

        if (empty($response->needs_upload)) {
            Logger::info("$filename — sin cambios");
            return true;
        }

        return $this->uploadChunks($serverUrl, $loc, $filename, $workFile, (int)$stat['size'], $response, $forced);
    }

    private function hashChunks(string $path, int $fileSize, int $chunkSize): array {
        $hashes = [];
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new Exception("Cannot open: $path");

        $offset = 0;
        while ($offset < $fileSize) {
            $size = min($chunkSize, $fileSize - $offset);
            fseek($handle, $offset);
            $chunk = fread($handle, $size);
            $hashes[] = Hash::compute($chunk)->getHex();
            $offset += $chunkSize;
        }
        fclose($handle);
        return $hashes;
    }

    private function uploadChunks(string $serverUrl, Location $loc, string $filename, string $workFile, int $fileSize, $response, bool $forced): bool {
        $chunkSize = Chunk::calculateChunkSize($fileSize);
        $handle = fopen($workFile, 'rb');
        
        $timestamp = $this->regService->fetchTimestamp($loc->rbfid);
        $totp = $this->regService->generateTotp($loc->rbfid, $timestamp);

        foreach ($response->needs_upload as $target) {
            foreach ($target->chunks as $chunkIdx) {
                $offset = $chunkIdx * $chunkSize;
                $size = min($chunkSize, $fileSize - $offset);

                fseek($handle, $offset);
                $data = fread($handle, $size);
                $chunkHash = Hash::compute($data)->getHex();

                $this->http->upload($serverUrl, $loc->rbfid, $totp, $filename, $chunkIdx, $chunkHash, $data);
            }
        }
        fclose($handle);
        return true;
    }
}
