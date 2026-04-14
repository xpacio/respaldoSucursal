<?php
/**
 * Route: heartbeat - Reporte de estado de clientes BAT (sin auth, con TOTP)
 * 
 * Actions:
 *   heartbeat - Reportar estado de una sucursal
 *   register  - Registrar nueva sucursal (desde setup.bat)
 */

require_once __DIR__ . '/../TotpValidator.php';

function route_heartbeat(Router $r, string $resource): void {
    $body = $r->getBody();
    $action = $body['action'] ?? '';

    switch ($action) {
        case 'heartbeat':
            route_heartbeat_report($r, $body);
            break;

        case 'register':
            route_heartbeat_register($r, $body);
            break;

        default:
            $r->jsonResponse(['ok' => false, 'error' => 'Accion no reconocida', 'code' => 'INVALID_ACTION'], 400);
    }
}

/**
 * Reportar heartbeat de una sucursal
 * Body: { action: 'heartbeat', rbfid, totp_token, tasks: [{name, status, md5}] }
 */
function route_heartbeat_report(Router $r, array $body): void {
    $rbfid = $body['rbfid'] ?? '';
    $totpToken = $body['totp_token'] ?? '';

    $validation = validateTotp($r->db, $rbfid, $totpToken);
    if (!$validation['ok']) {
        $r->jsonResponse($validation, 401);
        return;
    }

    $tasks = $body['tasks'] ?? [];
    $now = date('Y-m-d H:i:s');

    $r->db->execute(
        "UPDATE clients SET last_heartbeat_at = :now WHERE rbfid = :rbfid",
        [':now' => $now, ':rbfid' => $rbfid]
    );

    $taskResults = [];
    foreach ($tasks as $task) {
        $taskName = $task['name'] ?? '';
        $taskStatus = $task['status'] ?? 'unknown';

        $taskResults[] = [
            'name' => $taskName,
            'status' => $taskStatus,
            'recorded_at' => $now,
        ];
    }

    $r->jsonResponse([
        'ok' => true,
        'rbfid' => $rbfid,
        'server_time' => $now,
        'tasks' => $taskResults,
    ]);
}

/**
 * Registrar nueva sucursal desde setup.bat
 * Body: { action: 'register', rbfid, totp_token }
 */
function route_heartbeat_register(Router $r, array $body): void {
    $rbfid = $body['rbfid'] ?? '';
    $totpToken = $body['totp_token'] ?? '';

    $validation = validateTotp($r->db, $rbfid, $totpToken);
    if (!$validation['ok']) {
        $r->jsonResponse($validation, 401);
        return;
    }

    $existing = $r->db->fetchOne(
        "SELECT rbfid FROM clients WHERE rbfid = :rbfid",
        [':rbfid' => $rbfid]
    );

    if ($existing) {
        $r->jsonResponse(['ok' => false, 'error' => 'Sucursal ya registrada', 'code' => 'ALREADY_REGISTERED']);
        return;
    }

    $r->db->execute(
        "INSERT INTO clients (rbfid, enabled, ssh_enabled, key_download_enabled) VALUES (:rbfid, true, true, true)",
        [':rbfid' => $rbfid]
    );

    $r->jsonResponse([
        'ok' => true,
        'rbfid' => $rbfid,
        'message' => 'Sucursal registrada',
    ]);
}
