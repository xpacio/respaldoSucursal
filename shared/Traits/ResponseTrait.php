<?php

namespace App\Traits;

trait ResponseTrait
{
    public function jsonResponse(array $data, int $code = 200): void
    {
        $timestamp = time();
        $timestampStr = (string) $timestamp;
        
        // Agregar siempre el timestamp a todas las respuestas (éxito y error)
        if (!isset($data['timestamp'])) {
            $data['timestamp'] = $timestampStr;
        }
        header('X-Timestamp: ' . $timestampStr);
        
        header('Content-Type: application/json');
        http_response_code($code);
        echo json_encode($data);
        exit;
    }
}