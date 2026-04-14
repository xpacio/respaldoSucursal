<?php

function route_sheditor(Router $r, string $resource) {
    $data = $r->getBody();
    $action = $data['action'] ?? 'list';
    $editor = new EditorService($r->db, $r->logger);

    $result = match ($action) {
        // Editor (BD como fuente de verdad)
        'list'   => $editor->listBdFiles($data['path'] ?? ''),
        'read'   => $editor->readFromDb($data['path'] ?? ''),
        'save'   => $editor->save($data['path'] ?? '', $data['content'] ?? ''),
        'create' => $editor->create($data['path'] ?? '', $data['content'] ?? ''),
        'delete' => $editor->delete($data['path'] ?? ''),
        'mkdir'  => $editor->mkdir($data['path'] ?? ''),

        // Verificación MD5
        'verify'       => $editor->verify($data['path'] ?? ''),
        'verify_all'   => $editor->verifyAll(),

        // Visor de disco
        'disk_list'    => $editor->listDisk($data['path'] ?? ''),
        'disk_orphans' => $editor->findOrphans(),

        // Importar/Reconstruir
        'import'       => $editor->import($data['path'] ?? ''),
        'rebuild_all'  => $editor->rebuildAll(),

        default => ['ok' => false, 'error' => "Acción '$action' no existe en sheditor", 'code' => 'UNKNOWN_ACTION'],
    };

    $r->jsonResponse($result);
}
