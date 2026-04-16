<?php
/**
 * Router - Convención por recurso
 * 
 * URL /{resource} → carga routes/{resource}.php → llama route_{resource}()
 * El handler extrae todo del JSON body.
 */
class Router {
    private static $instance = null;

    // Dependencias globales (accesibles desde handlers)
    public $db;
    public $logger;
    public $testResponse;

    // Mapeo: primer segmento de URL → archivo de handler
    private array $resourceMap = [
        'ar'          => 'ar',
        'backup'      => 'backup',
        'client'      => 'atomic',
        'job'         => 'atomic',
        'public'      => 'atomic',
        'heartbeat'   => 'heartbeat',
    ];

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

        // Si es raíz, procesar acción del body directamente con ar.php
        if (!$resource || $path === '/' || $path === '') {
            Logger::debug('Router: raíz - procesar acción con ar handler');
            $body = $this->getBody();
            $action = $body['action'] ?? '';
            
            if (empty($action)) {
                $this->jsonResponse(['ok' => true, 'status' => 'healthy']);
                return;
            }
            
            $file = __DIR__ . '/../api/routes/ar.php';
            if (!file_exists($file)) {
                Logger::err("Router: handler ar.php no encontrado");
                $this->jsonResponse(['ok' => false, 'error' => 'Handler no encontrado', 'code' => 'HANDLER_MISSING'], 500);
                return;
            }
            
            require_once $file;
            route_ar($this, '');
            return;
        }

        // Health check
        if ($resource === 'health') {
            Logger::debug('Router: health check');
            $this->jsonResponse(['ok' => true, 'status' => 'healthy']);
            return;
        }

        // Buscar handler por convención
        $handler = $this->resourceMap[$resource] ?? null;
        if (!$handler) {
            Logger::warn("Router: recurso no existe: $resource");
            $this->jsonResponse(['ok' => false, 'error' => 'Recurso no existe: ' . $resource, 'code' => 'NOT_FOUND'], 404);
            return;
        }

        $file = __DIR__ . "/../api/routes/{$handler}.php";
        if (!file_exists($file)) {
            Logger::err("Router: handler no encontrado: $file");
            $this->jsonResponse(['ok' => false, 'error' => 'Handler no encontrado', 'code' => 'HANDLER_MISSING'], 500);
            return;
        }

        require_once $file;

        $fn = "route_{$handler}";
        if (!function_exists($fn)) {
            Logger::err("Router: función no definida: $fn");
            $this->jsonResponse(['ok' => false, 'error' => "Función route_{$handler} no definida", 'code' => 'HANDLER_INVALID'], 500);
            return;
        }

        $body = $this->getBody();
        Logger::debug("Router: body=" . json_encode($body));

        $fn($this, $resource);
    }

    /**
     * Respuesta JSON estándar
     */
    public function jsonResponse(array $data, int $code = 200): void {
        Logger::debug("Router.jsonResponse: code=$code, data=" . json_encode($data));

        if (defined('TEST_MODE') && TEST_MODE) {
            $this->testResponse = ['data' => $data, 'code' => $code];
            return;
        }

        $timestamp = time();
        $timestampStr = (string) $timestamp;
        
        // Agregar timestamp a respuestas exitosas (2xx)
        if ($code >= 200 && $code < 300 && !isset($data['timestamp'])) {
            $data['timestamp'] = $timestampStr;
            header('X-Timestamp: ' . $timestampStr);
        }

        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
