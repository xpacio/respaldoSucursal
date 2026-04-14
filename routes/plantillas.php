<?php

function route_plantillas(Router $r, string $resource) {
    $data = $r->getBody();
    $action = $data['action'] ?? 'list';

    match ($action) {
        'list'   => plantillas_list($r, $data),
        'create' => plantillas_create($r, $data),
        'edit'   => plantillas_edit($r, $data),
        'delete' => plantillas_delete($r, $data),
        default  => $r->jsonResponse(['ok' => false, 'error' => "Acción '$action' no existe en plantillas"], 404),
    };
}

function plantillas_list(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 10, "Obtener plantillas");
    $repo = RepositoryFactory::getPlantillaRepository($r->db);
    $plantillas = $repo->getAll();
    $r->jsonResponse(['ok' => true, 'plantillas' => $plantillas]);
}

function plantillas_create(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 20, "Crear plantilla");
    $src = trim($data['src'] ?? '');
    $dst = trim($data['dst'] ?? '');
    $mode = $data['mode'] ?? 'ro';
    $dstPerms = $data['dst_perms'] ?? 'exclusive';
    $auto = $data['auto_mount'] ?? 'false';

    if (!$src || !$dst) { $r->jsonResponse(['ok' => false, 'error' => 'Faltan campos requeridos'], 400); }
    if (!in_array($mode, ['ro', 'rw'])) { $r->jsonResponse(['ok' => false, 'error' => 'Modo inválido, debe ser "ro" o "rw"'], 400); }
    if (!in_array($dstPerms, ['exclusive', 'group'])) { $r->jsonResponse(['ok' => false, 'error' => 'Permisos inválidos'], 400); }

    $autoBool = ($auto === 'true' || $auto === true) ? 't' : 'f';
    $repo = RepositoryFactory::getPlantillaRepository($r->db);

    if ($autoBool === 't' && $repo->findAutoMountByDst($dst)) {
        $autoBool = 'f';
    }

    $id = $repo->createPlantilla($src, $dst, $mode, $autoBool, $dstPerms);

    if ($id) {
        $msg = $autoBool === 'f' ? 'Plantilla creada (auto deshabilitado)' : 'Plantilla creada';
        if ($autoBool === 't') {
            (new ClientService($r->db, $r->logger, $r->system))->applyTemplatesToAllActiveClients();
        }
        $r->jsonResponse(['ok' => true, 'message' => $msg, 'id' => $id]);
    } else {
        $r->jsonResponse(['ok' => false, 'error' => 'Error al crear plantilla'], 500);
    }
}

function plantillas_edit(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 30, "Editar plantilla");
    $id = intval($data['id'] ?? 0);
    $src = trim($data['src'] ?? '');
    $dst = trim($data['dst'] ?? '');
    $mode = $data['mode'] ?? 'ro';
    $dstPerms = $data['dst_perms'] ?? 'exclusive';
    $auto = $data['auto_mount'] ?? 'false';

    if (!$id) { $r->jsonResponse(['ok' => false, 'error' => 'ID requerido'], 400); }
    if (!$src || !$dst) { $r->jsonResponse(['ok' => false, 'error' => 'Faltan campos requeridos'], 400); }
    if (!in_array($mode, ['ro', 'rw'])) { $r->jsonResponse(['ok' => false, 'error' => 'Modo inválido'], 400); }
    if (!in_array($dstPerms, ['exclusive', 'group'])) { $r->jsonResponse(['ok' => false, 'error' => 'Permisos inválidos'], 400); }

    $autoBool = ($auto === 'true' || $auto === true) ? 't' : 'f';
    $repo = RepositoryFactory::getPlantillaRepository($r->db);

    if ($autoBool === 't' && $repo->findAutoMountByDstExcluding($dst, $id)) {
        $r->jsonResponse(['ok' => false, 'error' => 'Ya existe otra plantilla con este destino como auto'], 400);
    }

    $oldTemplate = $repo->getAutoMountInfo($id);
    $result = $repo->updatePlantilla($id, $src, $dst, $mode, $autoBool, $dstPerms);

    if ($result) {
        if ($autoBool === 't') {
            (new ClientService($r->db, $r->logger, $r->system))->applyTemplatesToAllActiveClients();
        } elseif ($oldTemplate && ($oldTemplate['auto_mount'] === 't' || $oldTemplate['auto_mount'] === true)) {
            (new MountService($r->db, $r->logger, $r->system))->unmountTemplateFromAllClients($oldTemplate['overlay_dst'], $oldTemplate['mode']);
        }
        $r->jsonResponse(['ok' => true, 'message' => 'Plantilla actualizada']);
    } else {
        $r->jsonResponse(['ok' => false, 'error' => 'Error al actualizar plantilla'], 500);
    }
}

function plantillas_delete(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 40, "Eliminar plantilla");
    $id = intval($data['id'] ?? 0);
    if (!$id) { $r->jsonResponse(['ok' => false, 'error' => 'ID requerido'], 400); }

    $repo = RepositoryFactory::getPlantillaRepository($r->db);
    $plantilla = $repo->getDstAndMode($id);

    if ($plantilla) {
        $mountService = new MountService($r->db, $r->logger, $r->system);
        $unmountResult = $mountService->unmountTemplateFromAllClients($plantilla['overlay_dst'], $plantilla['mode']);

        if (!$unmountResult['ok'] || $unmountResult['successful'] < $unmountResult['total']) {
            $r->jsonResponse([
                'ok' => false,
                'error' => "Desmontado {$unmountResult['successful']}/{$unmountResult['total']} clientes. Plantilla NO eliminada."
            ], 400);
            return;
        }
    }

    $result = $repo->delete($id);
    $r->jsonResponse($result ? ['ok' => true, 'message' => 'Plantilla eliminada'] : ['ok' => false, 'error' => 'Error al eliminar plantilla'], $result ? 200 : 500);
}
