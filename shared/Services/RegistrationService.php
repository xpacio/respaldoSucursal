<?php
declare(strict_types=1);

require_once __DIR__ . '/../Logger.php';
require_once __DIR__ . '/../Hash.php';
require_once __DIR__ . '/../../cli/HttpClient.php';
require_once __DIR__ . '/TimestampManager.php';

class RegistrationService {
    private HttpClient $http;
    private string $serverUrl;
    private ?TimestampManager $timestampManager = null;

    public function __construct(HttpClient $http, string $serverUrl) {
        $this->http = $http;
        $this->serverUrl = $serverUrl;
    }
    
    public function setTimestampManager(TimestampManager $manager): void {
        $this->timestampManager = $manager;
    }
    
    public function getCachedTimestamp(string $rbfid): int {
        if ($this->timestampManager !== null) {
            $timestamp = $this->timestampManager->get($rbfid);
            Logger::debug("getCachedTimestamp for $rbfid: $timestamp");
            return $timestamp;
        }
        Logger::debug("getCachedTimestamp: timestampManager is null");
        return 0;
    }

    public function fetchTimestamp(string $rbfid, bool $forceRefresh = false): int {
        // Primero intentar usar timestamp cacheado
        if (!$forceRefresh) {
            $cached = $this->getCachedTimestamp($rbfid);
            if ($cached > 0) {
                return $cached;
            }
        }
        
        $url = rtrim($this->serverUrl, '/');
        $body = json_encode(['action' => 'init', 'rbfid' => $rbfid]);
        
        Logger::debug("Fetching timestamp from: $url");
        Logger::debug("Request body: $body");
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($response === false) {
            Logger::debug("CURL error: $error");
            return 0;
        }
        
        Logger::debug("HTTP Code: $httpCode");
        Logger::debug("Response: " . substr($response, 0, 200));
        
        // Extraer JSON de la respuesta (puede contener logs antes del JSON)
        $jsonStart = strrpos($response, '{');
        if ($jsonStart !== false) {
            $jsonStr = substr($response, $jsonStart);
            $data = json_decode($jsonStr, true);
        } else {
            $data = json_decode($response, true);
        }
        
        $timestamp = (isset($data['timestamp'])) ? (int)$data['timestamp'] : 0;
        
        // Cachear el timestamp obtenido
        if ($timestamp > 0 && $this->timestampManager !== null) {
            $this->timestampManager->update($rbfid, $timestamp);
        }
        
        return $timestamp;
    }

    public function generateTotp(string $rbfid, int $timestamp = 0): string {
        if ($timestamp === 0) {
            $timestamp = $this->getCachedTimestamp($rbfid);
            if ($timestamp === 0) {
                throw new Exception('No timestamp available');
            }
        }
        
        $tsStr = (string)$timestamp;
        if (strlen($tsStr) < 3) throw new Exception('Invalid timestamp');
        
        $seed = substr($tsStr, 0, -2);
        $input = $seed . $rbfid;
        
        return Hash::compute($input)->toBase64();
    }
}
