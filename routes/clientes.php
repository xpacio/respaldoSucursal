<?php
/**
 * routes/clientes.php - Handler para /clientes y /cliente
 * 
 * Acciones (en body JSON):
 * search, create, update, enable, disable, delete,
 * ssh_enable, ssh_disable, ssh_regen,
 * enable_key_download, disable_key_download, reset_key_download, get_key_download_status,
 * get_ssh_key, add_overlay, get_mounts,
 * overlay_enable, overlay_disable, overlay_delete,
 * sync_overlays, options
 */

function route_clientes(Router $r, string $resource) {
    $data = $r->getBody();
    $action = $data['action'] ?? 'search';
    $logger = $r->logger;

    match ($action) {
        'search'                    => clientes_search($r, $data),
        'options'                   => clientes_options($r, $data),
        'create'                    => clientes_create($r, $data),
        'update'                    => clientes_update($r, $data),
        'enable'                    => clientes_enable($r, $data),
        'disable'                   => clientes_disable($r, $data),
        'delete'                    => clientes_delete($r, $data),
        'ssh_enable'                => clientes_ssh_enable($r, $data),
        'ssh_disable'               => clientes_ssh_disable($r, $data),
        'ssh_regen'                 => clientes_ssh_regen($r, $data),
        'enable_key_download'       => clientes_enable_key_download($r, $data),
        'disable_key_download'      => clientes_disable_key_download($r, $data),
        'reset_key_download'        => clientes_reset_key_download($r, $data),
        'get_key_download_status'   => clientes_get_key_download_status($r, $data),
        'get_ssh_key'               => clientes_get_ssh_key($r, $data),
        'add_overlay'               => clientes_add_overlay($r, $data),
        'get_mounts'                => clientes_get_mounts($r, $data),
        'overlay_enable'            => clientes_overlay_enable($r, $data),
        'overlay_disable'           => clientes_overlay_disable($r, $data),
        'overlay_delete'            => clientes_overlay_delete($r, $data),
        'sync_overlays'             => clientes_sync_overlays($r, $data),
        'importar'                  => clientes_importar($r, $data),
        'exportar'                  => clientes_exportar($r, $data),
        'regenerar_cautivos'        => clientes_regenerar_cautivos($r, $data),
        'limpiar_cautivos'          => clientes_limpiar_cautivos($r, $data),
        'permisos'                  => route_permisos($r, $resource),
        default                     => $r->jsonResponse(['ok' => false, 'error' => "Acción '$action' no existe en clientes", 'code' => 'UNKNOWN_ACTION'], 404),
    };
}

function clientes_search(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 10, "Buscar clientes");

    $rbfid = $data['rbfid'] ?? null;
    $emp = $data['emp'] ?? null;
    $plaza = $data['plaza'] ?? null;
    $enabled = $data['enabled'] ?? null;
    $limit = $data['limit'] ?? null;

    $conditions = [];
    $params = [];
    $pi = 1;

    if ($rbfid) { $conditions[] = "rbfid ILIKE :rbfid"; $params[':rbfid'] = "%$rbfid%"; }
    if ($emp) { $conditions[] = "emp ILIKE :emp"; $params[':emp'] = "%$emp%"; }
    if ($plaza) { $conditions[] = "plaza ILIKE :plaza"; $params[':plaza'] = "%$plaza%"; }
    if ($enabled === 'enabled') { $conditions[] = "(enabled = true OR ssh_enabled = true)"; }
    elseif ($enabled === 'disabled') { $conditions[] = "(enabled = false OR ssh_enabled = false)"; }

    $where = count($conditions) > 0 ? "WHERE " . implode(" AND ", $conditions) : "";

    $order = "ORDER BY rbfid ASC";
    if ($plaza) $order = "ORDER BY plaza ASC, rbfid ASC";
    if ($emp) $order = "ORDER BY emp ASC, rbfid ASC";

    $limitClause = "";
    if ($limit && is_numeric($limit) && $limit > 0) {
        $limitClause = "LIMIT " . intval($limit);
    }

    $sql = "SELECT rbfid, enabled, ssh_enabled, emp, plaza, key_download_enabled, key_downloaded_at, last_sync
            FROM clients $where $order $limitClause";

    $clientes = $r->db->fetchAll($sql, $params);
    $r->jsonResponse(['ok' => true, 'clientes' => $clientes]);
}

function clientes_options(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 10, "Obtener opciones clientes");

    $emps = $r->db->fetchAll("SELECT DISTINCT LOWER(emp) as emp FROM clients WHERE emp IS NOT NULL AND emp != '' ORDER BY LOWER(emp)");
    $plazas = $r->db->fetchAll("SELECT DISTINCT LOWER(plaza) as plaza FROM clients WHERE plaza IS NOT NULL AND plaza != '' ORDER BY LOWER(plaza)");

    $r->jsonResponse(['ok' => true, 'emp' => array_column($emps, 'emp'), 'plaza' => array_column($plazas, 'plaza')]);
}

function clientes_create(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 2, "Crear cliente");

    $id = $data['id'] ?? '';
    $emp = $data['emp'] ?? '';
    $plaza = $data['plaza'] ?? '';
    $enabled = ($data['enabled'] ?? true) === true || $data['enabled'] === 'true';

    $result = $r->clientService->create($id, $enabled, $emp, $plaza);
    $r->jsonResponse($result);
}

function clientes_update(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 2, "Actualizar cliente");

    $id = $data['id'] ?? '';
    $emp = $data['emp'] ?? '';
    $plaza = $data['plaza'] ?? '';
    $enabled = ($data['enabled'] ?? true) === true || $data['enabled'] === 'true';

    $result = $r->clientService->update($id, $emp, $plaza, $enabled);
    $r->jsonResponse($result);
}

function clientes_enable(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 2, "Habilitar cliente");
    $result = $r->clientService->enable($data['id'] ?? '');
    $r->jsonResponse($result);
}

function clientes_disable(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 2, "Deshabilitar cliente");
    $result = $r->clientService->disable($data['id'] ?? '');
    $r->jsonResponse($result);
}

function clientes_delete(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 2, "Eliminar cliente");
    $id = $data['id'] ?? '';
    $hard = ($data['hard'] ?? true) === true || $data['hard'] === 'true';
    $result = $r->clientService->delete($id, $hard);
    $r->jsonResponse($result);
}

function clientes_ssh_enable(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 2, "Habilitar SSH");
    $result = $r->clientService->enableSsh($data['id'] ?? '');
    $r->jsonResponse($result);
}

function clientes_ssh_disable(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 2, "Deshabilitar SSH");
    $result = $r->clientService->disableSsh($data['id'] ?? '');
    $r->jsonResponse($result);
}

function clientes_ssh_regen(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 2, "Renovar clave SSH");
    $result = $r->clientService->renewKey($data['id'] ?? '');
    $r->jsonResponse($result);
}

function clientes_enable_key_download(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 2, "Habilitar descarga de clave");
    $result = $r->clientService->enableKeyDownload($data['id'] ?? '');
    $r->jsonResponse($result);
}

function clientes_disable_key_download(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 2, "Deshabilitar descarga de clave");
    $result = $r->clientService->disableKeyDownload($data['id'] ?? '');
    $r->jsonResponse($result);
}

function clientes_reset_key_download(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 2, "Resetear descarga de clave");
    $result = $r->clientService->resetKeyDownload($data['id'] ?? '');
    $r->jsonResponse($result);
}

function clientes_get_key_download_status(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 2, "Obtener estado descarga clave");
    $result = $r->clientService->getKeyDownloadStatus($data['id'] ?? '');
    $r->jsonResponse($result);
}

function clientes_get_ssh_key(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 2, "Obtener clave SSH (UI)");
    $clientId = $data['id'] ?? '';

    $client = $r->db->fetchOne("SELECT rbfid, private_key, enabled FROM clients WHERE rbfid = :rbfid", [':rbfid' => $clientId]);

    if (!$client) { $r->jsonResponse(['ok' => false, 'error' => 'Cliente no encontrado'], 404); }
    if ($client['enabled'] !== true && $client['enabled'] !== 't') { $r->jsonResponse(['ok' => false, 'error' => 'Cliente deshabilitado'], 403); }
    if (empty($client['private_key'])) { $r->jsonResponse(['ok' => false, 'error' => 'Clave no disponible'], 404); }

    $base64Key = base64_encode($client['private_key']);
    $md4Suffix = strtoupper(substr(md5($base64Key), -4));

    header('MD4: ' . $md4Suffix);
    header('Content-Type: text/plain');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $base64Key;
    exit;
}

function clientes_add_overlay(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 2, "Agregar overlay");

    $id = $data['id'] ?? '';
    $src = $data['src'] ?? '';
    $dst = $data['dst'] ?? '';
    $mode = $data['mode'] ?? 'rw';
    $dstPerms = $data['dst_perms'] ?? 'exclusive';

    $result = $r->clientService->addOverlay($id, $src, $dst, $mode, $dstPerms);
    $r->jsonResponse($result);
}

function clientes_get_mounts(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 2, "Obtener mounts");
    $result = $r->clientService->getMounts($data['id'] ?? '');
    $r->jsonResponse($result);
}

function clientes_overlay_enable(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 2, "Habilitar overlay");
    $result = $r->mountService->enableOverlay(intval($data['overlay_id'] ?? 0));
    $r->jsonResponse($result);
}

function clientes_overlay_disable(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 2, "Deshabilitar overlay");
    $result = $r->mountService->disableOverlay(intval($data['overlay_id'] ?? 0));
    $r->jsonResponse($result);
}

function clientes_overlay_delete(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 2, "Eliminar overlay");
    $result = $r->mountService->deleteOverlay(intval($data['overlay_id'] ?? 0));
    $r->jsonResponse($result);
}

function clientes_sync_overlays(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 16, "Sincronizar overlays");
    $result = $r->overlaySyncService->reconcileClientOverlays($data['id'] ?? '');
    $r->jsonResponse($result);
}

function clientes_importar(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 10, "Importar clientes");
    
    $text = trim($data['clientes'] ?? '');
    if (empty($text)) {
        $r->jsonResponse(['ok' => false, 'error' => 'No se proporcionaron datos'], 400);
    }
    
    $lines = array_map('trim', explode("\n", $text));
    $imported = [];
    $errors = [];
    $lineNum = 0;
    
    foreach ($lines as $line) {
        $lineNum++;
        if (empty($line)) continue;
        
        $parts = preg_split('/\s+/', $line, 3);
        if (count($parts) !== 3) {
            $errors[] = "Línea $lineNum: formato incorrecto (debe ser: rbfid emp plaza)";
            continue;
        }
        
        $rbfid = strtolower(trim($parts[0]));
        $emp = strtolower(trim($parts[1]));
        $plaza = strtolower(trim($parts[2]));
        
        // Validar rbfid: 5 caracteres alfanuméricos
        if (!preg_match('/^[a-z0-9]{5}$/', $rbfid)) {
            $errors[] = "Línea $lineNum: rbfid '$rbfid' debe ser 5 caracteres alfanuméricos";
            continue;
        }
        
        // Validar emp: 3 caracteres alfabéticos
        if (!preg_match('/^[a-z]{3}$/', $emp)) {
            $errors[] = "Línea $lineNum: emp '$emp' debe ser 3 caracteres alfabéticos";
            continue;
        }
        
        // Validar plaza: 5 caracteres alfanuméricos
        if (!preg_match('/^[a-z0-9]{5}$/', $plaza)) {
            $errors[] = "Línea $lineNum: plaza '$plaza' debe ser 5 caracteres";
            continue;
        }
        
        $imported[] = ['rbfid' => $rbfid, 'emp' => $emp, 'plaza' => $plaza];
    }
    
    if (!empty($errors)) {
        $r->jsonResponse(['ok' => false, 'errors' => $errors, 'valid_count' => count($imported)]);
        return;
    }
    
    // Insertar en clientes_cautivos (upsert)
    $inserted = 0;
    $skipped = 0;
    foreach ($imported as $c) {
        try {
            $r->db->execute(
                "INSERT INTO clientes_cautivos (rbfid, emp, plaza) VALUES (:rbfid, :emp, :plaza) ON CONFLICT (rbfid) DO UPDATE SET emp = EXCLUDED.emp, plaza = EXCLUDED.plaza",
                [':rbfid' => $c['rbfid'], ':emp' => $c['emp'], ':plaza' => $c['plaza']]
            );
            
            // Crear cliente en BD si no existe
            $existing = $r->db->fetchOne("SELECT rbfid FROM clients WHERE rbfid = :rbfid", [':rbfid' => $c['rbfid']]);
            if (!$existing) {
                $r->clientService->create($c['rbfid'], true, $c['emp'], $c['plaza']);
            }
            $inserted++;
        } catch (Exception $e) {
            $errors[] = "Error importando {$c['rbfid']}: " . $e->getMessage();
            $skipped++;
        }
    }
    
    $r->jsonResponse([
        'ok' => true,
        'imported' => $inserted,
        'skipped' => $skipped,
        'errors' => $errors
    ]);
}

function clientes_exportar(Router $r, array $data) {
    $rows = $r->db->fetchAll("SELECT rbfid, emp, plaza FROM clientes_cautivos ORDER BY rbfid");

    $lines = [];
    foreach ($rows as $row) {
        $lines[] = "{$row['rbfid']} {$row['emp']} {$row['plaza']}";
    }

    $r->jsonResponse([
        'ok' => true,
        'texto' => implode("\n", $lines),
        'total' => count($lines)
    ]);
}

function clientes_regenerar_cautivos(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 10, "Regenerar clientes desde cautivos (async)");
    
    $currentUser = $_COOKIE['username'] ?? 'admin';
    $result = $r->adminService->createRegenerateClientsJob($currentUser);
    
    if (!$result['ok']) {
        $r->jsonResponse(['ok' => false, 'error' => 'No se pudo crear el job']);
        return;
    }
    
    $jobId = $result['job_id'];
    $scriptPath = '/srv/app/www/sync/scripts/worker.php';
    $cmd = "nohup php " . escapeshellarg($scriptPath) . " " . escapeshellarg($jobId) . " > /tmp/regenerar_$jobId.log 2>&1 &";
    exec($cmd);
    
    $r->jsonResponse([
        'ok' => true,
        'job_id' => $jobId,
        'message' => 'Job de regeneración iniciado'
    ]);
}

function clientes_limpiar_cautivos(Router $r, array $data) {
    $r->logger->enterContext(2, 30, 2, "Limpiar clientes cautivos");
    
    try {
        $r->db->execute("TRUNCATE TABLE clientes_cautivos RESTART IDENTITY CASCADE");
        
        $r->jsonResponse([
            'ok' => true,
            'message' => 'Tabla clientes_cautivos vaciada correctamente'
        ]);
    } catch (Exception $e) {
        $r->jsonResponse([
            'ok' => false,
            'error' => 'Error al limpiar cautivos: ' . $e->getMessage()
        ]);
    }
}
