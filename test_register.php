<?php

declare(strict_types=1);

require_once __DIR__ . '/shared/autoload.php';

use App\Logger;
use App\Config;

// Configurar logging
Logger::init(__DIR__ . '/logs', true);

// URL del servidor
$serverUrl = 'http://respaldosucursal.servicios.care';

// Simular lo que hace el cliente
$rbfid = 'roton';
$timestamp = time();
$seed = substr((string)$timestamp, 0, -2);
$input = $seed . $rbfid;

// Calcular TOTP (simplificado)
$totp = hash('xxh3', $input);
$totp_base64 = base64_encode($totp);

// Preparar la solicitud
$body = json_encode([
    'action' => 'register',
    'rbfid' => $rbfid,
    'totp_token' => $totp_base64,
]);

echo "=== Probando registro de cliente ===\n";
echo "URL: $serverUrl\n";
echo "RBFID: $rbfid\n";
echo "Body: $body\n\n";

// Enviar solicitud
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $serverUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json',
]);

$response = curl_exec($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
if ($error) {
    echo "cURL Error: $error\n";
}
echo "Response: $response\n";

// Intentar también con un path específico
echo "\n=== Probando con path /api/register ===\n";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $serverUrl . '/api/register');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json',
]);

$response = curl_exec($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
if ($error) {
    echo "cURL Error: $error\n";
}
echo "Response: $response\n";