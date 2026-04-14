<?php
declare(strict_types=1);

trait ResponseTrait {
    public function jsonResponse(array $data, int $code = 200): void {
        header('Content-Type: application/json');
        http_response_code($code);
        echo json_encode($data);
        exit;
    }
}

trait LoggingTrait {
    public function log(string $message): void {
        error_log("AR: " . $message);
    }
}
