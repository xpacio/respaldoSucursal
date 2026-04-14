<?php
/**
 * API Authentication Module - Token based
 * Simplified: Lee token de cookie (donde login lo guarda)
 */

function apiAuthRequire() {
    // Get token from cookie (set by login)
    $token = $_COOKIE['auth_token'] ?? null;
    
    // Fallback: try Authorization header
    if (empty($token)) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
            $token = $m[1];
        }
    }
    
    if (empty($token)) {
        http_response_code(401);
        echo json_encode(['error' => 'Falta Authorization', 'code' => 'MISSING_TOKEN', 'debug' => 'Cookie vacía']);
        exit;
    }
    
    try {
        $host = 'localhost';
        $port = 5432;
        $dbname = 'sync';
        $user = 'postgres';
        $password = '';
        
        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
        $db = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        
        $stmt = $db->prepare("SELECT id, username, nombre, token_expires_at FROM users WHERE login_token = ?");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Token no encontrado', 'code' => 'TOKEN_NOT_FOUND']);
            exit;
        }
        
        if (!empty($user['token_expires_at']) && strtotime($user['token_expires_at']) <= time()) {
            http_response_code(401);
            echo json_encode(['error' => 'Token expirado', 'code' => 'TOKEN_EXPIRED']);
            exit;
        }
        
        $newExpiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $updateStmt = $db->prepare("UPDATE users SET token_expires_at = ? WHERE login_token = ?");
        $updateStmt->execute([$newExpiresAt, $token]);
        
        return [
            'id' => $user['id'],
            'username' => $user['username'],
            'nombre' => $user['nombre'],
            'token' => $token,
            'token_expires_at' => $newExpiresAt
        ];
        
    } catch (PDOException $e) {
        error_log('API Auth error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Error de autenticación', 'code' => 'AUTH_ERROR']);
        exit;
    }
}
