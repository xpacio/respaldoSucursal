<?php

declare(strict_types=1);

require_once __DIR__ . '/FileHashData.php';
require_once __DIR__ . '/SyncResponse.php';
require_once __DIR__ . '/UploadResponse.php';
require_once __DIR__ . '/Hash.php';
require_once __DIR__ . '/Logger.php';

class HttpClient
{
    private const TIMEOUT = 30;
    private const CONTENT_TYPE = 'application/json';

    public function registerClient(string $serverUrl, string $rbfid): void
    {
        $url = $serverUrl . '/api/ar';
        
        $body = json_encode([
            'action' => 'register',
            'rbfid' => $rbfid,
        ]);

        $response = $this->post($url, $body);
        
        if ($response === null) {
            throw new Exception('Register failed: no response');
        }

        Logger::debug("Register response: " . substr($response, 0, 200));
    }

    public function fetchFileListVersioned(
        string $serverUrl,
        string $rbfid,
        string $totp,
        string $currentVersion
    ): array {
        $url = $serverUrl . '/api/ar';
        
        $body = json_encode([
            'action' => 'config',
            'rbfid' => $rbfid,
            'totp_token' => $totp,
            'files_version' => $currentVersion,
        ]);

        $response = $this->post($url, $body);
        
        if ($response === null || $response === '') {
            return ['version' => '', 'files' => []];
        }

        $data = json_decode($response, true);
        if ($data === null) {
            return ['version' => '', 'files' => []];
        }

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
        $url = $serverUrl . '/api/ar';

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

        $data = json_decode($response, true);
        if ($data === null || !isset($data['ok']) || $data['ok'] !== true) {
            throw new Exception("Sync failed: " . ($data['message'] ?? 'invalid response'));
        }

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
        $url = $serverUrl . '/api/ar';

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

        $data = json_decode($response, true);
        if ($data === null || !isset($data['ok']) || $data['ok'] !== true) {
            throw new Exception("Upload failed: " . ($data['message'] ?? 'invalid response'));
        }

        return UploadResponse::fromArray($data);
    }

    private function post(string $url, string $body): ?string
    {
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
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
}