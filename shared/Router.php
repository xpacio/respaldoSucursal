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
    public $system;
    public $clientService;
    public $userService;
    public $sshService;
    public $mountService;
    public $overlaySyncService;
    public $adminService;
    public $clientManager;

    // Mapeo: primer segmento de URL → archivo de handler
    private array $resourceMap = [
        'ar'          => 'ar',
        'clientes'   => 'clientes',
        'cliente'    => 'clientes',
        'plantillas' => 'plantillas',
        'plantilla'  => 'plantillas',
        'logs'       => 'logs',
        'log'        => 'logs',
        'admin'      => 'admin',
        'permisos'   => 'permisos',
        'agente'     => 'agente',
        'sheditor'   => 'sheditor',
        'sh'         => 'sheditor',
        'distribucion' => 'distribucion',
        'root'       => 'root',
        'client'     => 'atomic',
        'job'        => 'atomic',
        'public'     => 'atomic',
        'heartbeat'  => 'heartbeat',
    ];

    private function __construct() {}

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Router();
        }
        return self::$instance;
    }

    public function setDependencies($db, $logger, $system, $clientService, $userService, $sshService, $mountService, $overlaySyncService, $adminService = null) {
        $this->db = $db;
        $this->logger = $logger;
        $this->system = $system;
        $this->clientService = $clientService;
        $this->userService = $userService;
        $this->sshService = $sshService;
        $this->mountService = $mountService;
        $this->overlaySyncService = $overlaySyncService;
        $this->adminService = $adminService;
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
     */
    public function dispatch(string $path): void {
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $resource = $segments[0] ?? null;

        // Health check
        if (!$resource) {
            $this->jsonResponse(['ok' => true, 'status' => 'healthy']);
            return;
        }

        // Buscar handler por convención
        $handler = $this->resourceMap[$resource] ?? null;
        if (!$handler) {
            $this->jsonResponse(['ok' => false, 'error' => 'Recurso no existe: ' . $resource, 'code' => 'NOT_FOUND'], 404);
            return;
        }

        $file = __DIR__ . "/routes/{$handler}.php";
        if (!file_exists($file)) {
            $this->jsonResponse(['ok' => false, 'error' => 'Handler no encontrado', 'code' => 'HANDLER_MISSING'], 500);
            return;
        }

        require_once $file;

        $fn = "route_{$handler}";
        if (!function_exists($fn)) {
            $this->jsonResponse(['ok' => false, 'error' => "Función route_{$handler} no definida", 'code' => 'HANDLER_INVALID'], 500);
            return;
        }

        $fn($this, $resource);
    }

    /**
     * Respuesta JSON estándar
     */
    public function jsonResponse(array $data, int $code = 200): void {
        if ($this->logger) {
            if ($this->logger->hasContext()) {
                $this->logger->exitContext();
            }
            $this->logger->finish('success');
        }
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
