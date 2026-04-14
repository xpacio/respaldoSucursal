<?php

function route_logs(Router $r, string $resource) {
    $data = $r->getBody();
    $action = $data['action'] ?? 'list';

    match ($action) {
        'list'  => logs_list($r, $data),
        'get'   => logs_get($r, $data),
        'purge' => logs_purge($r, $data),
        default => $r->jsonResponse(['ok' => false, 'error' => "Acción '$action' no existe en logs"], 404),
    };
}

function logs_list(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 10, "Obtener logs");
    $limit = min(intval($data['limit'] ?? 100), 1000);
    $logs = $r->db->fetchAll(
        "SELECT id, rbfid, action, status, started_at, finished_at, source_type FROM executions ORDER BY started_at DESC LIMIT :lim",
        [':lim' => $limit]
    );
    $r->jsonResponse(['ok' => true, 'logs' => $logs]);
}

function logs_get(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 10, "Obtener pasos de ejecución");
    $executionId = $data['id'] ?? '';

    if (!$executionId || !preg_match('/^[a-f0-9-]{36}$/', $executionId)) {
        $r->jsonResponse(['ok' => false, 'error' => 'ID de ejecución inválido'], 400);
    }

    $execution = $r->db->fetchOne("SELECT id FROM executions WHERE id = :id", [':id' => $executionId]);
    if (!$execution) {
        $r->jsonResponse(['ok' => false, 'error' => 'Ejecución no encontrada'], 404);
    }

    $pasos = $r->db->fetchAll(
        "SELECT step_code, step_message, created_at FROM execution_steps WHERE execution_id = :exec_id ORDER BY created_at ASC",
        [':exec_id' => $executionId]
    );
    $r->jsonResponse(['ok' => true, 'pasos' => $pasos]);
}

function logs_purge(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 10, "Purgar logs");
    $olderThan = $data['olderThan'] ?? null;

    if (!$olderThan) {
        $r->jsonResponse(['ok' => false, 'error' => 'Parámetro olderThan es requerido'], 400);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2})?)?$/', $olderThan)) {
        $r->jsonResponse(['ok' => false, 'error' => 'Formato de fecha inválido. Usar YYYY-MM-DD o YYYY-MM-DDTHH:MM'], 400);
    }

    $deleted = $r->db->execute("DELETE FROM executions WHERE started_at < :older", [':older' => $olderThan]);
    $r->jsonResponse(['ok' => true, 'message' => 'Logs purgados', 'deleted' => $deleted]);
}
