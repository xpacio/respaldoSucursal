<?php
/**
 * routes/zig.php - Gateway hacia la API REST de Zig
 * 
 * Forward de requests desde frontend hacia el servidor Zig en :8081
 */

define('ZIG_HOST', 'http://127.0.0.1:8081');
define('ZIG_TIMEOUT', 30);

function route_zig(Router $r, string $resource) {
    $data = $r->getBody();
    $action = $data['action'] ?? '';
    
    $result = match ($action) {
        // === CLIENTES ===
        'clients'        => zig_proxy('GET', '/api/clients'),
        'client_detail'  => zig_proxy('GET', "/api/clients/{$data['id']}"),
        'client_stats'   => zig_proxy('GET', "/api/clients/{$data['id']}/stats"),
        
        // === ESTADÍSTICAS ===
        'stats'          => zig_proxy('GET', '/api/stats'),
        'activity'       => zig_proxy('GET', '/api/activity?limit=' . ($data['limit'] ?? 100)),
        
        // === PRECIOS ===
        'price_catalog'  => zig_proxy('GET', '/api/price/catalog' . (isset($data['plaza']) ? '?plaza=' . urlencode($data['plaza']) : '')),
        'price_scan'     => zig_proxy_post('/api/price/scan', ['root_path' => $data['root_path'] ?? '/srv/precios']),
        'price_sync'     => zig_proxy_post('/api/price/sync/' . ($data['plaza'] ?? ''), []),
        
        // === DISTRIBUCIÓN ===
        'distribucion_status' => zig_proxy('GET', '/api/distribucion/status'),
        
        // === RESPALDOS ===
        'backup_status'  => zig_proxy('GET', "/api/backup/status/{$data['rbfid']}"),
        'backup_history' => zig_proxy('GET', "/api/backup/history/{$data['rbfid']}?limit=" . ($data['limit'] ?? 50)),
        'backup_trigger' => zig_proxy_post('/api/backup/trigger', ['rbfid' => $data['rbfid']]),
        
        // === HEALTH ===
        'health'         => zig_proxy('GET', '/api/health'),
        
        default          => ['ok' => false, 'error' => "Acción '$action' no existe en zig", 'code' => 'UNKNOWN_ACTION'],
    };
    
    $r->jsonResponse($result);
}

/**
 * Proxy GET request to Zig server
 */
function zig_proxy(string $method, string $path): array {
    $url = ZIG_HOST . $path;
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => ZIG_TIMEOUT,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_CUSTOMREQUEST  => $method,
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if (!empty($error)) {
        return ['ok' => false, 'error' => "Conexión a Zig falló: $error", 'code' => 'ZIG_CONNECTION'];
    }
    
    if ($http_code >= 400) {
        return ['ok' => false, 'error' => "Zig API error: HTTP $http_code", 'code' => 'ZIG_HTTP_ERROR'];
    }
    
    $json = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['ok' => false, 'error' => 'Respuesta inválida de Zig', 'code' => 'ZIG_INVALID_JSON'];
    }
    
    return $json;
}

/**
 * Proxy POST request to Zig server
 */
function zig_proxy_post(string $path, array $body): array {
    $url = ZIG_HOST . $path;
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => ZIG_TIMEOUT,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if (!empty($error)) {
        return ['ok' => false, 'error' => "Conexión a Zig falló: $error", 'code' => 'ZIG_CONNECTION'];
    }
    
    if ($http_code >= 400) {
        return ['ok' => false, 'error' => "Zig API error: HTTP $http_code", 'code' => 'ZIG_HTTP_ERROR'];
    }
    
    $json = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['ok' => false, 'error' => 'Respuesta inválida de Zig', 'code' => 'ZIG_INVALID_JSON'];
    }
    
    return $json;
}