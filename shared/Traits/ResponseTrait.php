<?php

namespace App\Traits;

trait ResponseTrait
{
    public function jsonResponse(array $data, int $code = 200): void
    {
        $timestamp = time();
        $timestampStr = (string) $timestamp;
        
        // Agregar timestamp a respuestas exitosas (2xx)
        if ($code >= 200 && $code < 300 && !isset($data['timestamp'])) {
            $data['timestamp'] = $timestampStr;
            header('X-Timestamp: ' . $timestampStr);
        }
        
        header('Content-Type: application/json');
        http_response_code($code);
        echo json_encode($data);
        exit;
    }
}