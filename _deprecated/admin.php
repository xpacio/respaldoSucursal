<?php

function route_admin(Router $r, string $resource) {
    $data = $r->getBody();
    $action = $data['action'] ?? '';

    match ($action) {
        'reset_start'    => admin_reset_start($r, $data),
        'reset_status'   => admin_reset_status($r, $data),
        'jobs_active'    => admin_jobs_active($r, $data),
        'job_status'     => admin_job_status($r, $data),
        'execute_job'    => admin_execute_job($r, $data),
        default          => $r->jsonResponse(['ok' => false, 'error' => "Acción '$action' no existe en admin"], 404),
    };
}

function admin_reset_start(Router $r, array $data) {
    $r->logger->enterContext(1, 30, 99, "Iniciar reset del sistema");

    $currentUser = $_COOKIE['username'] ?? 'admin';
    $result = $r->adminService->createResetJob($currentUser);

    $jobId = $result['job_id'];
    $scriptPath = '/srv/app/www/sync/scripts/reset_worker.php';
    $cmd = "nohup php " . escapeshellarg($scriptPath) . " " . escapeshellarg($jobId) . " > /tmp/reset_$jobId.log 2>&1 &";
    exec($cmd);

    $r->jsonResponse($result);
}

function admin_reset_status(Router $r, array $data) {
    $r->logger->enterContext(1, 30, 99, "Consultar estado del reset");
    $jobId = $data['job_id'] ?? null;

    if (!$jobId) {
        $r->jsonResponse(['ok' => false, 'error' => 'job_id requerido'], 400);
    }

    $result = $r->adminService->getJobStatus($jobId);
    $r->jsonResponse($result);
}

function admin_jobs_active(Router $r, array $data) {
    $result = $r->adminService->getActiveJobs();
    $r->jsonResponse($result);
}

function admin_job_status(Router $r, array $data) {
    $jobId = $data['job_id'] ?? null;
    if (!$jobId) {
        $r->jsonResponse(['ok' => false, 'error' => 'job_id requerido'], 400);
    }
    $result = $r->adminService->getJobStatus($jobId);
    $r->jsonResponse($result);
}

function admin_execute_job(Router $r, array $data) {
    $jobId = $data['job_id'] ?? null;
    if (!$jobId) {
        $r->jsonResponse(['ok' => false, 'error' => 'job_id requerido'], 400);
    }
    
    $job = $r->db->fetchOne("SELECT job_type FROM procesos_desacoplados WHERE job_id = :job_id", [':job_id' => $jobId]);
    if (!$job) {
        $r->jsonResponse(['ok' => false, 'error' => 'Job no encontrado'], 404);
    }
    
    match ($job['job_type']) {
        'limpiar_cautivos' => $r->adminService->executeLimpiarCautivosJob($jobId),
        default => $r->jsonResponse(['ok' => false, 'error' => 'Tipo de job no soportado'], 400),
    };
}
