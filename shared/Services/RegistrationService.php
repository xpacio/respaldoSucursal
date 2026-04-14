<?php
declare(strict_types=1);

require_once __DIR__ . '/../Logger.php';
require_once __DIR__ . '/../Hash.php';
require_once __DIR__ . '/../../cli/HttpClient.php';

class RegistrationService {
    private HttpClient $http;
    private string $serverUrl;

    public function __construct(HttpClient $http, string $serverUrl) {
        $this->http = $http;
        $this->serverUrl = $serverUrl;
    }

    public function fetchTimestamp(string $rbfid): int {
        $url = $this->serverUrl . '/api/ar';
        $body = json_encode(['action' => 'init', 'rbfid' => $rbfid]);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        if ($response === false) return 0;
        
        $data = json_decode($response, true);
        return (isset($data['timestamp'])) ? (int)$data['timestamp'] : 0;
    }

    public function generateTotp(string $rbfid, int $timestamp): string {
        if ($timestamp === 0) throw new Exception('No timestamp');
        
        $tsStr = (string)$timestamp;
        if (strlen($tsStr) < 3) throw new Exception('Invalid timestamp');
        
        $seed = substr($tsStr, 0, -2);
        $input = $seed . $rbfid;
        
        return Hash::compute($input)->toBase64();
    }
}
