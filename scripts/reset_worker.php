<?php
/**
 * Worker para ejecutar reset asincrónico en background
 * Uso: php reset_worker.php <job_id>
 */

if (php_sapi_name() !== 'cli') {
    die('Este script solo puede ejecutarse desde la línea de comandos');
}

if ($argc < 2) {
    die("Uso: php reset_worker.php <job_id>\n");
}

$jobId = $argv[1];

// Cargar clases necesarias
require_once '/srv/app/www/sync/Config/Database.php';
require_once '/srv/app/www/sync/Config/Logger.php';
require_once '/srv/app/www/sync/Services/SystemService.php';
require_once '/srv/app/www/sync/Services/AdminService.php';

$jobId = $argv[1];

// Inicializar servicios (usar usuario postgres para el worker en background)
$db = new Database(['host' => 'localhost', 'port' => 5432, 'dbname' => 'sync', 'user' => 'postgres', 'password' => '']);
$logger = Logger::create($db);
$system = new System([]);
$adminService = new AdminService($db, $logger, $system);

try {
    error_log("[RESET WORKER] Iniciando job: $jobId");
    
    $result = $adminService->executeAsyncReset($jobId);
    
    error_log("[RESET WORKER] Job $jobId completado exitosamente");
    exit(0);
    
} catch (Exception $e) {
    error_log("[RESET WORKER] Error en job $jobId: " . $e->getMessage());
    exit(1);
}
