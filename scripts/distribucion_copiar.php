<?php
/**
 * distribucion_copiar.php - Ejecuta todas las distribuciones activas
 *
 * Copia archivos a todos los clientes de cada distribución activa.
 * Uso: php distribucion_copiar.php
 * Ejecución: cada hora via systemd timer
 */

if (php_sapi_name() !== 'cli') {
    die('Este script solo puede ejecutarse desde la línea de comandos');
}

// ─── Bootstrap ───
require_once __DIR__ . '/../Config/config.php';
require_once __DIR__ . '/../Config/Database.php';
require_once __DIR__ . '/../Config/Logger.php';
require_once __DIR__ . '/../Config/LoggerFactory.php';
require_once __DIR__ . '/../Config/PathUtils.php';
require_once __DIR__ . '/../Services/ServiceInterfaces.php';
require_once __DIR__ . '/../Services/SystemService.php';
require_once __DIR__ . '/../Services/MountService.php';
require_once __DIR__ . '/../Services/UserService.php';
require_once __DIR__ . '/../Services/SshService.php';
require_once __DIR__ . '/../Services/ClientService.php';
require_once __DIR__ . '/../Services/BackendWorker.php';
require_once __DIR__ . '/../Services/DistribucionService.php';
require_once __DIR__ . '/../Repositories/RepositoryInterface.php';
require_once __DIR__ . '/../Repositories/AbstractRepository.php';
require_once __DIR__ . '/../Repositories/ClientRepository.php';
require_once __DIR__ . '/../Repositories/OverlayRepository.php';
require_once __DIR__ . '/../Repositories/PlantillaRepository.php';
require_once __DIR__ . '/../Repositories/DistribucionRepository.php';
require_once __DIR__ . '/../Repositories/JobRepository.php';
require_once __DIR__ . '/../Repositories/RepositoryFactory.php';

$config = require __DIR__ . '/../Config/config.php';
$db = new Database($config['db']);
$logger = LoggerFactory::createFromConfig($config, $db);

$dists = $db->fetchAll("SELECT id, nombre, tipo FROM distribucion WHERE activa = true ORDER BY nombre, tipo");

if (empty($dists)) {
    error_log("[DISTRIBUCION] No hay distribuciones activas");
    exit(0);
}

$svc = new DistribucionService($db, $logger);
$exitosos = 0;
$fallidos = 0;
$errores = [];

$logger->startExecution('cron', "[DISTRIBUCION] todas (" . count($dists) . ")", 'cron');

foreach ($dists as $dist) {
    $label = "{$dist['nombre']}/{$dist['tipo']} (ID {$dist['id']})";

    try {
        $result = $svc->copiar(intval($dist['id']));
        if ($result['ok']) {
            $exitosos++;
            error_log("[DISTRIBUCION] $label: {$result['exitosos']}/{$result['total']} exitosos");
        } else {
            $fallidos++;
            $errores[] = $label . ': ' . ($result['error'] ?? 'unknown');
            error_log("[DISTRIBUCION] $label: ERROR - {$result['error']}");
        }
    } catch (\Exception $e) {
        $fallidos++;
        $errores[] = $label . ': ' . $e->getMessage();
        error_log("[DISTRIBUCION] $label: EXCEPTION - {$e->getMessage()}");
    }
}

error_log("[DISTRIBUCION] Resumen: {$exitosos} exitosas, {$fallidos} fallidas de " . count($dists) . " totales");

$logger->finish($fallidos > 0 ? 'error' : 'success');

if (!empty($errores)) {
    error_log("[DISTRIBUCION] Errores:\n" . implode("\n", $errores));
}

exit($fallidos > 0 ? 1 : 0);
