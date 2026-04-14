<?php

function route_distribucion(Router $r, string $resource) {
    $data = $r->getBody();
    $action = $data['action'] ?? 'list';
    $svc = new DistribucionService($r->db, $r->logger);

    $id = intval($data['id'] ?? 0);

    $result = match ($action) {
        'list'           => $svc->list($data),
        'options'        => $svc->options(),
        'get'            => $svc->get($id),
        'create'         => $svc->create($data),
        'update'         => $svc->update($id, $data),
        'delete'         => $svc->delete($id),
        'clientes'       => $svc->getClientes($id),
        'clientes_plaza' => $svc->getClientesPorPlaza($data['plaza'] ?? '', $id),
        'add_cliente'    => $svc->addCliente($id, $data['rbfid'] ?? ''),
        'remove_cliente' => $svc->removeCliente($id, $data['rbfid'] ?? ''),
        'evaluar'        => $svc->evaluarVersion($id),
        'copiar'         => $svc->copiar($id),
        'copiar_job'     => $svc->copiarComoJob($id),
        'copiar_vista'   => (function() use ($r, $data) {
            $ids = $data['ids'] ?? [];
            if (empty($ids)) return ['ok' => false, 'error' => 'Sin distribuciones', 'code' => 'EMPTY_IDS'];
            $worker = new BackendWorker($r->db, $r->logger, new System([]));
            $jobId = $worker->enqueue('distribuir_batch', ['distribucion_ids' => array_map('intval', $ids)], 'admin');
            return ['ok' => true, 'job_id' => $jobId, 'status' => 'queued', 'total' => count($ids)];
        })(),
        'copiar_todas'   => (function() use ($r) {
            $worker = new BackendWorker($r->db, $r->logger, new System([]));
            $jobId = $worker->enqueue('distribuir_all', [], 'admin');
            return ['ok' => true, 'job_id' => $jobId, 'status' => 'queued'];
        })(),
        'versiones'      => $svc->getVersiones($id),
        'errores'        => $svc->getErrores($id),
        'resolver'       => $svc->resolverError(intval($data['error_id'] ?? 0)),
        'ejecuciones'    => $svc->getEjecuciones($id),
        'logs'           => $svc->getLogs(),
        // Tipos (perfiles)
        'tipos'          => $svc->getTipos(),
        'create_tipo'    => $svc->createTipo($data),
        'update_tipo'    => $svc->updateTipo($data['tipo'] ?? '', $data),
        'delete_tipo'    => $svc->deleteTipo($data['tipo'] ?? ''),
        'importar'       => $svc->importar($data['texto'] ?? '', !empty($data['truncar'])),
        'exportar'       => $svc->exportar(),
        'scan_precios'   => $svc->scanPrecios(!empty($data['incluidas'])),
        default          => ['ok' => false, 'error' => "Acción '$action' no existe", 'code' => 'UNKNOWN_ACTION'],
    };

    $r->jsonResponse($result);
}
