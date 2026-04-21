<?php declare(strict_types=1);

// Inicializar el sistema
require_once __DIR__ . '/shared_core.php';
\App\Log::init(__DIR__ . '/logs');

// Obtener la ruta solicitada
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = trim($uri, '/');
$parts = explode('/', $path);

/**
 * ENRUTADOR BÁSICO
 * /srv o /api -> Delega a srv.php (API para cli.php)
 * Cualquier otra ruta -> Delega a ctl.php (Administración Web)
 */
if ($parts[0] === 'srv' || $parts[0] === 'api') {
    require_once __DIR__ . '/srv.php';
} else {
    require_once __DIR__ . '/web.php';
}