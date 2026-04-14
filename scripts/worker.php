<?php
/**
 * Worker script - ejecuta un job en background
 * Uso: php worker.php <job_id>
 */

if (php_sapi_name() !== 'cli') {
    die('Este script solo puede ejecutarse desde la línea de comandos');
}

if ($argc < 2) {
    die("Uso: php worker.php <job_id>\n");
}

$jobId = $argv[1];

require_once __DIR__ . '/../Config/config.php';
require_once __DIR__ . '/../Config/Database.php';
require_once __DIR__ . '/../Config/Logger.php';
require_once __DIR__ . '/../Config/LoggerFactory.php';
require_once __DIR__ . '/../Services/ServiceInterfaces.php';
require_once __DIR__ . '/../Services/SystemService.php';
require_once __DIR__ . '/../Services/AdminService.php';
require_once __DIR__ . '/../Services/BackendWorker.php';

$config = require __DIR__ . '/../Config/config.php';
$db = new Database($config['db']);
$logger = LoggerFactory::createFromConfig($config, $db);
$system = new System([]);

try {
    // Buscar el job en la tabla de procesos_desacoplados
    $job = $db->fetchOne("SELECT job_id, job_type FROM procesos_desacoplados WHERE job_id = :job_id", [':job_id' => $jobId]);
    
    if ($job) {
        // Es un job de AdminService
        $adminService = new AdminService($db, $logger, $system);
        
        match ($job['job_type']) {
            'limpiar_cautivos' => $adminService->executeLimpiarCautivosJob($jobId),
            'regenerar_cautivos' => $adminService->executeRegenerateClientsJob($jobId),
            'system_reset' => $adminService->executeAsyncReset($jobId),
            default => error_log("Worker: tipo de job desconocido: {$job['job_type']}")
        };
    } else {
        // Es un job de BackendWorker
        $worker = BackendWorker::create();
        $worker->execute($jobId);
    }
    
    exit(0);
    
} catch (Exception $e) {
    error_log("[WORKER] Error en job $jobId: " . $e->getMessage());
    exit(1);
}
