<?php
declare(strict_types=1);

namespace App\Cli;

use App\Logger;
use App\Services\TimestampManager;
use Exception;

class HttpClient
{
    private const CONTENT_TYPE = 'application/json';
    private int $timeout = 30;
    private ?TimestampManager $timestampManager = null;
    
    public function setTimestampManager(TimestampManager $manager): void {
        $this->timestampManager = $manager;
    }
    
    private function updateTimestampFromResponse(string $response, string $rbfid): void {
        if ($this->timestampManager === null) return;
        
        $data = $this->extractJsonFromResponse($response);
        if ($data !== null && isset($data['timestamp']) && $data['timestamp'] !== '') {
            $timestamp = (int)$data['timestamp'];
            $this->timestampManager->update($rbfid, $timestamp);
        }
    }

    public function setTimeout(int $seconds): void {
        $this->timeout = $seconds;
    }

    public function increaseTimeout(): void {
        $this->timeout = min($this->timeout * 2, 300); // Max 5 min
    }

    public function registerClient(string $serverUrl, string $rbfid, string $totp): array {
        $url = rtrim($serverUrl, '/');
        
        $body = json_encode([
            'action' => 'register',
            'rbfid' => $rbfid,
            'totp_token' => $totp,
        ]);

        $response = $this->post($url, $body);
        
        if ($response === null) {
            throw new Exception('Register failed: no response');
        }

        $data = $this->extractJsonFromResponse($response);
        if ($data === null) {
            throw new Exception('Register failed: invalid response');
        }

        $this->updateTimestampFromResponse($response, $rbfid);
        Logger::debug("Register response: " . substr($response, 0, 200));
        return $data;
    }

    public function fetchFileListVersioned(
        string $serverUrl,
        string $rbfid,
        string $totp,
        string $currentVersion
    ): array {
        $url = rtrim($serverUrl, '/');
        
        $body = json_encode([
            'action' => 'config',
            'rbfid' => $rbfid,
            'totp_token' => $totp,
            'files_version' => $currentVersion,
        ]);

        $response = $this->post($url, $body);
        
        if ($response === null || $response === '') {
            Logger::debug("fetchFileListVersioned: empty response");
            return ['version' => '', 'files' => []];
        }

        Logger::debug("fetchFileListVersioned response: " . substr($response, 0, 300));
        $data = $this->extractJsonFromResponse($response);
        if ($data === null) {
            Logger::debug("fetchFileListVersioned: failed to extract JSON");
            return ['version' => '', 'files' => []];
        }
        
        $this->updateTimestampFromResponse($response, $rbfid);

        $version = $data['files_version'] ?? '';
        $files = [];

        if (isset($data['files']) && is_array($data['files'])) {
            $files = $data['files'];
        }

        return ['version' => $version, 'files' => $files];
    }

    public function sync(
        string $serverUrl,
        string $rbfid,
        string $totp,
        array $files
    ): SyncResponse {
        $url = rtrim($serverUrl, '/');

        $filesJson = [];
        foreach ($files as $fileData) {
            $filesJson[] = $fileData->toArray();
        }

        $body = json_encode([
            'action' => 'sync',
            'rbfid' => $rbfid,
            'totp_token' => $totp,
            'files' => $filesJson,
        ]);

        $response = $this->post($url, $body);
        
        if ($response === null || $response === '') {
            throw new Exception("Sync failed: empty response");
        }

        $data = $this->extractJsonFromResponse($response);
        if ($data === null || !isset($data['ok']) || $data['ok'] !== true) {
            throw new Exception("Sync failed: " . ($data['message'] ?? 'invalid response'));
        }
        
        $this->updateTimestampFromResponse($response, $rbfid);

        return SyncResponse::fromArray($data);
    }

    public function upload(
        string $serverUrl,
        string $rbfid,
        string $totp,
        string $filename,
        int $chunkIndex,
        string $chunkHash,
        string $data
    ): UploadResponse {
        $url = rtrim($serverUrl, '/');

        $encodedData = base64_encode($data);
        
        // $chunkHash ya viene como base64 del cliente, pasar directo
        $hashBase64 = $chunkHash;

        $body = json_encode([
            'action' => 'upload',
            'rbfid' => $rbfid,
            'totp_token' => $totp,
            'filename' => $filename,
            'chunk_index' => $chunkIndex,
            'hash_xxh3' => $hashBase64,
            'data' => $encodedData,
        ]);

        $response = $this->post($url, $body);

        if ($response === null || $response === '') {
            throw new Exception("Upload failed: empty response");
        }

        $data = $this->extractJsonFromResponse($response);
        if ($data === null || !isset($data['ok']) || $data['ok'] !== true) {
            throw new Exception("Upload failed: " . ($data['message'] ?? 'invalid response'));
        }
        
        $this->updateTimestampFromResponse($response, $rbfid);

        return UploadResponse::fromArray($data);
    }

    private function post(string $url, string $body): ?string
    {
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: ' . self::CONTENT_TYPE,
            'Accept: ' . self::CONTENT_TYPE,
        ]);

        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            Logger::debug('cURL error: ' . curl_error($ch));
            curl_close($ch);
            return null;
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            Logger::debug("HTTP error: $httpCode");
            throw new Exception("HTTP error: $httpCode");
        }

        return $response;
    }
    
    private function extractJsonFromResponse(string $response): ?array
    {
        // Extraer JSON de la respuesta (puede contener logs antes del JSON)
        $jsonStart = strrpos($response, '{');
        if ($jsonStart !== false) {
            $jsonStr = substr($response, $jsonStart);
            $data = json_decode($jsonStr, true);
            if ($data !== null) {
                return $data;
            }
        }
        
        // Intentar decodificar la respuesta completa
        return json_decode($response, true);
    }
    
    private function extractTimestampFromResponse(string $response): int
    {
        $data = $this->extractJsonFromResponse($response);
        if ($data !== null && isset($data['timestamp']) && $data['timestamp'] !== '') {
            return (int)$data['timestamp'];
        }
        return 0;
    }
}