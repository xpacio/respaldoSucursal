<?php
/**
 * audit_overlays.php - Auditoría y corrección de overlays
 *
 * Compara estado deseado (BD) con estado real (filesystem)
 * y corrige desviaciones: permisos, montajes, estado DB.
 *
 * Ejecución: boot + cada hora via systemd timer
 * Uso: php audit_overlays.php
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
require_once __DIR__ . '/../Services/OverlayAuditService.php';
require_once __DIR__ . '/../Repositories/RepositoryInterface.php';
require_once __DIR__ . '/../Repositories/AbstractRepository.php';
require_once __DIR__ . '/../Repositories/ClientRepository.php';
require_once __DIR__ . '/../Repositories/OverlayRepository.php';
require_once __DIR__ . '/../Repositories/PlantillaRepository.php';
require_once __DIR__ . '/../Repositories/RepositoryFactory.php';

$config = require __DIR__ . '/../Config/config.php';
$db = new Database($config['db']);
$logger = LoggerFactory::createFromConfig($config, $db);
$system = new System([]);
$mountService = new MountService($db, $logger, $system);
$auditService = new OverlayAuditService($db, $logger, $mountService);

try {
    $result = $auditService->auditAll();

    if ($result['ok']) {
        $summary = $result['summary'];
        error_log("[AUDIT] Resumen: {$summary['clients']} clientes, {$summary['mounted']} remontados, {$summary['unmounted']} desmontados, {$summary['permissions_fixed']} permisos corregidos, {$summary['db_corrected']} DB corregidas, {$summary['errors']} errores");
        exit(0);
    } else {
        error_log("[AUDIT] Falló la auditoría");
        exit(1);
    }

} catch (\Exception $e) {
    error_log("[AUDIT] Error fatal: " . $e->getMessage());
    exit(1);
}
