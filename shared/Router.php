<?php

namespace App;

use App\Traits\ResponseTrait;

/**
 * Router simplificado
 * 
 * Solo maneja health check y rechaza otros recursos.
 * La funcionalidad principal está en ArCore con acciones en body JSON.
 */

if (!defined('TEST_MODE')) {
    define('TEST_MODE', false);
}

class Router {
    use ResponseTrait;
    
    private static $instance = null;

    // Dependencias globales (accesibles desde handlers)
    public $db;
    public $logger;
    public $testResponse;

    // Mapeo: primer segmento de URL → archivo de handler
    private array $resourceMap = [];

    private function __construct() {}

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Router();
        }
        return self::$instance;
    }

    public function setDependencies($db, $logger = null) {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Leer body JSON de la petición
     */
    public function getBody(): array {
        $raw = file_get_contents('php://input');
        if (empty($raw)) return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Dispatch por convención: /{resource} → route_{resource}()
     * Si el path es raíz (/), usa la acción del body
     */
    public function dispatch(string $path): void {
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $resource = $segments[0] ?? null;

        Logger::debug("Router.dispatch: path=$path, resource=$resource");

        Logger::debug("Router.dispatch: path=$path, resource=$resource");

        // Si es raíz, retornar health check
        if (!$resource || $path === '/' || $path === '') {
            Logger::debug('Router: raíz - health check');
            $this->jsonResponse(['ok' => true, 'status' => 'healthy']);
            return;
        }

        // Health check - primer punto de contacto para timestamp
        if ($resource === 'health') {
            Logger::debug('Router: health check with timestamp');
            $timestamp = time();
            $timestampStr = (string) $timestamp;
            $this->jsonResponse([
                'ok' => true, 
                'status' => 'healthy',
                'timestamp' => $timestampStr,
                'message' => 'Use este timestamp para generar TOTP'
            ]);
            return;
        }

        // Recurso no encontrado
        Logger::warn("Router: recurso no existe: $resource");
        $this->jsonResponse(['ok' => false, 'error' => 'Recurso no existe: ' . $resource, 'code' => 'NOT_FOUND'], 404);
        return;
    }

    /**
     * Respuesta JSON estándar (extiende el trait para logging)
     */
    public function jsonResponse(array $data, int $code = 200): void {
        Logger::debug("Router.jsonResponse: code=$code, data=" . json_encode($data));

        if (defined('TEST_MODE') && TEST_MODE) {
            $this->testResponse = ['data' => $data, 'code' => $code];
            return;
        }

        // Usar el trait directamente
        $timestamp = time();
        $timestampStr = (string) $timestamp;
        
        // Agregar timestamp a respuestas exitosas (2xx)
        if ($code >= 200 && $code < 300 && !isset($data['timestamp'])) {
            $data['timestamp'] = $timestampStr;
            header('X-Timestamp: ' . $timestampStr);
        }
        
        header('Content-Type: application/json');
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
