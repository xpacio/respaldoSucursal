<?php

function route_atomic(Router $r, string $resource) {
    $data = $r->getBody();
    $action = $data['action'] ?? '';

    match ($resource) {
        'client' => route_atomic_client($r, $data, $action),
        'job'    => route_atomic_job($r, $data, $action),
        'public' => route_public($r, $data, $action),
        default  => $r->jsonResponse(['ok' => false, 'error' => 'Recurso no soportado'], 404),
    };
}

function route_atomic_client(Router $r, array $data, string $action) {
    $cm = $r->clientManager;
    $id = $data['id'] ?? '';

    $result = match ($action) {
        'validate'          => $cm->validate($id, $data['emp'] ?? '', $data['plaza'] ?? ''),
        'user_create'       => $cm->createUser($id),
        'user_delete'       => $cm->deleteUser($id),
        'user_enable'       => $cm->enableUser($id),
        'user_disable'      => $cm->disableUser($id),
        'ssh_generate'      => $cm->generateSsh($id),
        'ssh_save_keys'     => $cm->saveKeys($id, $data['private_key'] ?? '', $data['public_key'] ?? ''),
        'ssh_enable'        => $cm->enableSsh($id),
        'ssh_disable'       => $cm->disableSsh($id),
        'db_insert'         => $cm->saveToDb($id, ($data['enabled'] ?? true) === true || $data['enabled'] === 'true', $data['emp'] ?? '', $data['plaza'] ?? '', $data['private_key'] ?? '', $data['public_key'] ?? ''),
        'db_delete'         => $cm->deleteFromDb($id),
        'db_state'          => $cm->updateDbState($id, ($data['enabled'] ?? true) === true, ($data['ssh_enabled'] ?? true) === true),
        'mount_all'         => $cm->mountAllOverlays($id),
        'unmount_all'       => $cm->unmountAllOverlays($id),
        'templates_apply'   => $cm->applyTemplates($id),
        default             => ['ok' => false, 'error' => "Acción '$action' no existe en client", 'code' => 'UNKNOWN_ACTION'],
    };

    $r->jsonResponse($result);
}

function route_atomic_job(Router $r, array $data, string $action) {
    $cm = $r->clientManager;

    $result = match ($action) {
        'run'    => match ($data['params_action'] ?? '') {
            'disable_clients'      => $cm->disableClients($data['client_ids'] ?? [], $data['created_by'] ?? 'admin'),
            'enable_clients'       => $cm->enableClients($data['client_ids'] ?? [], $data['created_by'] ?? 'admin'),
            'delete_clients'       => $cm->deleteClients($data['client_ids'] ?? [], $data['created_by'] ?? 'admin'),
            'sync_overlays'        => $cm->syncAllOverlays($data['client_ids'] ?? [], $data['created_by'] ?? 'admin'),
            'regen_ssh_keys'       => $cm->regenAllSshKeys($data['client_ids'] ?? [], $data['created_by'] ?? 'admin'),
            'mount_all_overlays'   => $cm->mountAllClientsOverlays($data['client_ids'] ?? [], $data['created_by'] ?? 'admin'),
            'unmount_all_overlays' => $cm->unmountAllClientsOverlays($data['client_ids'] ?? [], $data['created_by'] ?? 'admin'),
            default => ['ok' => false, 'error' => 'params_action desconocida', 'code' => 'UNKNOWN_ACTION'],
        },
        'status' => $cm->getJobStatus($data['job_id'] ?? ''),
        'cancel' => $cm->cancelJob($data['job_id'] ?? ''),
        'list'   => $cm->listJobs($data['status'] ?? 'running', min(intval($data['limit'] ?? 50), 200)),
        default  => ['ok' => false, 'error' => "Acción '$action' no existe en job", 'code' => 'UNKNOWN_ACTION'],
    };

    $r->jsonResponse($result);
}

function route_public(Router $r, array $data, string $action) {
    // Public endpoints handled in index.php (before auth)
    $r->jsonResponse(['ok' => false, 'error' => 'Endpoint público no disponible'], 404);
}
