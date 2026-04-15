<?php

namespace Client\Pipeline;

require_once __DIR__ . '/../HttpClient.php';

final class TransferStage implements PipelineStageInterface {
    public function __construct(
        private \HttpClient $httpClient,
        private string $serverEndpoint,
        private string $rbfid,
        private string $totp,
        private int $maxRetries = 3
    ) {}

    public function process(mixed $payload): mixed {
        $sessionId = $this->initiateSession($payload);

        foreach ($payload['chunks'] as $chunk) {
            $this->uploadChunkWithRetry($sessionId, $chunk, $payload['file_hash']);
        }

        $this->finalizeSession($sessionId, $payload);
        return ['status' => 'completed', 'session_id' => $sessionId];
    }

    private function uploadChunkWithRetry(string $sessionId, array $chunk, string $fileHash): void {
        $attempts = 0;

        while ($attempts < $this->maxRetries) {
            try {
                $response = $this->sendChunk($sessionId, $chunk, $fileHash);

                if ($response['verified'] ?? false) {
                    return;
                }

                throw new \RuntimeException("Hash mismatch in chunk {$chunk['index']}");

            } catch (\Throwable $e) {
                $attempts++;
                if ($attempts >= $this->maxRetries) {
                    throw new \RuntimeException(
                        "Chunk {$chunk['index']} failed after {$this->maxRetries} attempts: " . $e->getMessage()
                    );
                }
                usleep(100000 * $attempts);
            }
        }
    }

    private function sendChunk(string $sessionId, array $chunk, string $fileHash): array {
        $url = $this->serverEndpoint . '/api/backup/chunk';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'session_id' => $sessionId,
                'file_hash' => $fileHash,
                'chunk_index' => $chunk['index'],
                'chunk_hash' => $chunk['hash'],
                'data' => $chunk['data'],
                'is_last' => ($chunk['index'] + 1) === count($chunk)
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException("HTTP $httpCode");
        }

        return json_decode($response, true) ?: ['verified' => false];
    }

    private function initiateSession(array $payload): string {
        $url = $this->serverEndpoint . '/api/backup/init';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'rbfid' => $this->rbfid,
                'totp_token' => $this->totp,
                'filename' => basename($payload['file_path']),
                'file_hash' => $payload['file_hash'],
                'file_size' => $payload['file_size'],
                'total_chunks' => $payload['total_chunks']
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true) ?: [];
        return $data['session_id'] ?? '';
    }

    private function finalizeSession(string $sessionId, array $payload): void {
        $url = $this->serverEndpoint . '/api/backup/finalize';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'session_id' => $sessionId,
                'file_hash' => $payload['file_hash']
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);

        curl_exec($ch);
        curl_close($ch);
    }
}