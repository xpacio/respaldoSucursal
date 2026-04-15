<?php
/**
 * Route: ar - Agente de Respaldo
 * 
 * Endpoints:
 *   register - Registrar cliente (rbfid)
 *   config   - Obtener lista de archivos a respaldar
 *   sync     - Sincronizar hashes, recibir lista de chunks faltantes
 *   upload   - Subir chunk
 *   status   - Estado del cliente
 */

require_once __DIR__ . '/../../shared/TotpValidator.php';
require_once __DIR__ . '/../../cli/Chunk.php';
require_once __DIR__ . '/../ar/src/ArCore.php';

function route_ar(Router $r, string $resource): void {
    Logger::debug("route_ar: resource=$resource");
    
    ArCore::getInstance($r);
    $body = $r->getBody();
    $action = $body['action'] ?? '';
    
    if ($resource === '' || $resource === null) {
        $action = $action ?: ($body['action'] ?? 'sync');
    }

    Logger::debug("route_ar: action=$action, body=" . json_encode($body));

    switch ($action) {
        case 'init':
            route_ar_init($r, $body);
            break;

        case 'register':
            route_ar_register($r, $body);
            break;

        case 'config':
            route_ar_config($r, $body);
            break;

        case 'sync':
            route_ar_sync($r, $body);
            break;

        case 'download':
            route_ar_download($r, $body);
            break;

        case 'upload':
            route_ar_upload($r, $body);
            break;

        case 'query_chunks':
            route_ar_query_chunks($r, $body);
            break;

        case 'status':
            route_ar_status($r, $body);
            break;

        case 'history':
            route_ar_history($r, $body);
            break;

        default:
            $r->jsonResponse(['ok' => false, 'error' => 'Accion no reconocida', 'code' => 'INVALID_ACTION'], 400);
    }
}

/**
 * Inicializar cliente AR - obtener timestamp
 * Body: { rbfid: "roton" }
 * Response: X-Timestamp header
 */
function route_ar_init(Router $r, array $body): void {
    $rbfid = $body['rbfid'] ?? '';
    Logger::debug("route_ar_init: rbfid=$rbfid");
    
    if (empty($rbfid)) {
        Logger::warn("route_ar_init: RBFID requerido");
        $r->jsonResponse(['ok' => false, 'error' => 'RBFID requerido', 'code' => 'MISSING_RBFID'], 400);
        return;
    }
    
    $client = $r->clientService->getClientStatus($rbfid);
    
    if (!$client) {
        Logger::info("route_ar_init: nuevo cliente $rbfid, insertando...");
        $r->db->execute(
            "INSERT INTO ar_clients (rbfid, enabled, registered_at) VALUES (:rbfid, false, NOW())",
            [':rbfid' => $rbfid]
        );
        $r->db->execute(
            "INSERT INTO clients (rbfid, enabled, created_at) VALUES (:rbfid, false, NOW())",
            [':rbfid' => $rbfid]
        );
        
        header('X-Timestamp: ');
        $r->jsonResponse([
            'ok' => true,
            'rbfid' => $rbfid,
            'timestamp' => '',
            'enabled' => false,
            'latent' => true
        ]);
        return;
    }
    
    $timestamp = time();
    $timestampStr = (string) $timestamp;

    if (!$client['enabled']) {
        header('X-Timestamp: ');
        $r->jsonResponse([
            'ok' => true,
            'rbfid' => $rbfid,
            'timestamp' => '',
            'enabled' => false,
            'latent' => true
        ]);
        return;
    }

    header('X-Timestamp: ' . $timestampStr);
    $r->jsonResponse([
        'ok' => true,
        'rbfid' => $rbfid,
        'timestamp' => $timestampStr,
        'enabled' => true
    ]);
}

function route_ar_register(Router $r, array $body): void {
    $rbfid = $body['rbfid'] ?? '';
    Logger::debug("route_ar_register: rbfid=$rbfid");

    if (empty($rbfid)) {
        Logger::warn("route_ar_register: RBFID requerido");
        $r->jsonResponse(['ok' => false, 'error' => 'RBFID requerido', 'code' => 'MISSING_RBFID'], 400);
        return;
    }

    try {
        $ar = ArCore::getInstance($r);
        $ar->client->registerClient($rbfid);
        Logger::info("route_ar_register: success rbfid=$rbfid");
        $r->jsonResponse(['ok' => true, 'rbfid' => $rbfid]);
    } catch (Exception $e) {
        Logger::err("route_ar_register: error " . $e->getMessage());
        $r->jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}


/**
 * Consultar chunks faltantes
 * Body: { rbfid, totp_token, filename }
 */
function route_ar_query_chunks(Router $r, array $body): void {
    $rbfid = $body['rbfid'] ?? '';
    $filename = $body['filename'] ?? '';
    $totpToken = $body['totp_token'] ?? '';

    $validation = validateTotp($r->db, $rbfid, $totpToken);
    if (!$validation['ok']) {
        $r->jsonResponse($validation, 401);
        return;
    }

    if (empty($filename)) {
        $r->jsonResponse(['ok' => false, 'error' => 'Filename requerido'], 400);
        return;
    }

    $clientData = getClientArPath($r->db, $rbfid);
    if (!$clientData) {
        $r->jsonResponse(['ok' => false, 'error' => 'Cliente no encontrado'], 404);
        return;
    }

    // Consultar chunks con status = 'pending' o 'failed'
    $faltantes = $r->db->fetchAll(
        "SELECT chunk_index, hash_xxh3, status, error_count 
         FROM ar_file_hashes 
         WHERE rbfid = :rbfid AND file_name = :file AND status != 'received'
         ORDER BY chunk_index",
        [':rbfid' => $rbfid, ':file' => $filename]
    );

    // Determinar siguiente chunk a pedir
    $nextChunk = null;
    $errors = [];
    foreach ($faltantes as $ch) {
        if ($ch['status'] === 'failed' && $ch['error_count'] >= 5) {
            $errors[] = ['index' => $ch['chunk_index'], 'error' => 'max_retries_exceeded'];
            continue;
        }
        if ($nextChunk === null) {
            $nextChunk = $ch['chunk_index'];
        }
    }

    // Si no hay más chunks, verificar archivo completo
    $fileComplete = false;
    $destPath = '';
    if ($nextChunk === null) {
        $workPath = $clientData['work_dir'] . '/' . $filename;
        $fileRecord = $r->db->fetchOne(
            "SELECT hash_esperado FROM ar_files WHERE rbfid = :rbfid AND file_name = :file",
            [':rbfid' => $rbfid, ':file' => $filename]
        );

        if (file_exists($workPath)) {
            $actualHash = hash('xxh3', file_get_contents($workPath));
            if ($fileRecord && $fileRecord['hash_esperado'] === $actualHash) {
                // Hash correcto: mover a destino
                $destDir = $clientData['base_dir'];
                if (!is_dir($destDir)) mkdir($destDir, 0755, true);
                $destPath = $destDir . '/' . $filename;
                
                if (file_exists($destPath)) unlink($destPath);
                rename($workPath, $destPath);
                
                // Actualizar hash_xxh3 y resetear error_count
                $r->db->execute(
                    "UPDATE ar_files SET hash_xxh3 = :hash, updated_at = NOW() WHERE rbfid = :rbfid AND file_name = :file",
                    [':hash' => $actualHash, ':rbfid' => $rbfid, ':file' => $filename]
                );
                $r->db->execute(
                    "UPDATE ar_file_hashes SET error_count = 0 WHERE rbfid = :rbfid AND file_name = :file",
                    [':rbfid' => $rbfid, ':file' => $filename]
                );
                
                $fileComplete = true;
            } else {
                // Hash incorrecto: rehashear y marcar failed
                $fileSize = (int)($fileRecord['file_size'] ?? 0);
                $chunksInfo = $r->db->fetchAll(
                    "SELECT chunk_index, hash_xxh3 FROM ar_file_hashes
                     WHERE rbfid = :rbfid AND file_name = :file ORDER BY chunk_index",
                    [':rbfid' => $rbfid, ':file' => $filename]
                );
                
                $fh = fopen($workPath, 'rb');
                foreach ($chunksInfo as $chunk) {
                    $range = Chunk::getChunkRange($fileSize, (int)$chunk['chunk_index']);
                    fseek($fh, $range['offset']);
                    $chunkData = fread($fh, $range['size']);
                    $chunkHash = hash('xxh3', $chunkData);
                    
                    if ($chunkHash !== $chunk['hash_xxh3']) {
                        $r->db->execute(
                            "UPDATE ar_file_hashes SET status = 'failed' WHERE rbfid = :rbfid AND file_name = :file AND chunk_index = :idx",
                            [':rbfid' => $rbfid, ':file' => $filename, ':idx' => $chunk['chunk_index']]
                        );
                        $errors[] = ['index' => $chunk['chunk_index'], 'error' => 'hash_mismatch'];
                    }
                }
                fclose($fh);
            }
        }
    }

    // Responder
    $response = [
        'ok' => true,
        'file' => $filename,
        'hash' => $fileRecord['hash_esperado'] ?? '',
    ];

    if ($fileComplete) {
        $response['sync'] = 'complete';
        $response['dest_path'] = $destPath;
    } elseif ($nextChunk !== null) {
        $response['chunk'] = $nextChunk;
    } else {
        $response['sync'] = 'complete';
        $response['dest_path'] = '';
    }

    if (!empty($errors)) {
        $response['errors'] = $errors;
    }

    $r->jsonResponse($response);
}

function route_ar_config(Router $r, array $body): void {
    $rbfid = $body['rbfid'] ?? '';
    $clientVersion = $body['files_version'] ?? '';

    $files = $r->db->fetchAll(
        "SELECT file_name FROM ar_global_files WHERE enabled = true ORDER BY file_name",
        []
    );

    // Versión basada en el hash de la lista — solo manda files si cambió
    $filesList = array_column($files, 'file_name');
    $serverVersion = substr(md5(implode(',', $filesList)), 0, 8);

    $response = ['ok' => true, 'rbfid' => $rbfid, 'files_version' => $serverVersion];
    if ($clientVersion !== $serverVersion) {
        $response['files'] = $filesList;
    }

    $r->jsonResponse($response);
}


/**
 * Sincronizar hashes
 * Body: { rbfid, totp_token, files: [ { filename, hash_completo, chunk_hashes: [...] }, ... ] }
 */
function route_ar_sync(Router $r, array $body): void {
    $rbfid = $body['rbfid'] ?? '';
    $timestamp = $body['timestamp'] ?? '';
    $files = $body['files'] ?? [];
    Logger::debug("route_ar_sync: rbfid=$rbfid, files_count=" . count($files));

    try {

    // Aceptar token del body (cliente Zig) o headers (cliente legacy)
    $tokenHeader = $body['totp_token'] ?? $_SERVER['HTTP_X_TOKEN'] ?? '';
    $clientRbfid = $rbfid ?: ($_SERVER['HTTP_X_RBFID'] ?? '');
    $clientTimestamp = $timestamp ?: ($_SERVER['HTTP_X_TIMESTAMP'] ?? '');

    if (empty($clientRbfid) || empty($tokenHeader)) {
        Logger::warn("route_ar_sync: faltan datos de autenticacion");
        $r->jsonResponse(['ok' => false, 'error' => 'Faltan datos de autenticacion'], 401);
        return;
    }

    // Validar token — si viene timestamp usarlo, si no usar validateTotp con ventana
    if (!empty($clientTimestamp)) {
        $seed = substr($clientTimestamp, 0, -2);
        $expectedHash = xxh3_token($seed . $clientRbfid);
        if ($tokenHeader !== $expectedHash) {
            Logger::warn("route_ar_sync: token invalido");
            $r->jsonResponse(['ok' => false, 'error' => 'Token invalido'], 401);
            return;
        }
    } else {
        $validation = validateTotp($r->db, $clientRbfid, $tokenHeader);
        if (!$validation['ok']) {
            Logger::warn("route_ar_sync: validateTotp failed");
            $r->jsonResponse($validation, 401);
            return;
        }
    }

    $slots = getArSlots($r->db);
    $rateDelay = $slots['available'] > 0 ? 3000 : 10000;
    Logger::debug("route_ar_sync: slots used={$slots['used']} available={$slots['available']}");

    $syncId = $r->db->insert(
        "INSERT INTO ar_sync_history (rbfid, status, started_at) VALUES (:rbfid, 'pending', NOW())",
        [':rbfid' => $clientRbfid]
    );
    Logger::debug("route_ar_sync: sync_id=$syncId");

    // Obtener datos del cliente para ruta de destino
    $clientData = getClientArPath($r->db, $clientRbfid);

    $needsUpload = [];
    
    foreach ($files as $file) {
        $filename = $file['filename'] ?? '';
        $hashCompleto = $file['hash_completo'] ?? '';
        $chunkHashes = $file['chunk_hashes'] ?? [];
        $incomingMtime = (int)($file['mtime'] ?? 0);
        $incomingSize = (int)($file['size'] ?? 0);

        if (empty($filename) || empty($hashCompleto)) continue;

        $serverFile = $r->db->fetchOne(
            "SELECT hash_xxh3, file_mtime FROM ar_files WHERE rbfid = :rbfid AND file_name = :file_name",
            [':rbfid' => $clientRbfid, ':file_name' => $filename]
        );

        // RECHAZAR SI ES MÁS VIEJO
        if ($serverFile && $incomingMtime > 0 && (int)$serverFile['file_mtime'] > 0 && $incomingMtime < (int)$serverFile['file_mtime']) {
            error_log("AR sync: RECHAZADO $filename - archivo más viejo que el servidor. Cliente: $incomingMtime, Servidor: " . $serverFile['file_mtime']);
            continue; // Ignorar este archivo
        }

        if (!$serverFile || $serverFile['hash_xxh3'] !== $hashCompleto) {
            // Guardar chunk_count esperado y hash PENDIENTE (null hasta que reassembly confirme)
            $chunkCnt = max(1, count($chunkHashes));
            error_log("AR sync: rbfid=$clientRbfid file=$filename chunk_count=$chunkCnt hash_esperado=$hashCompleto size=$incomingSize mtime=$incomingMtime");
            $r->db->execute(
                "INSERT INTO ar_files (rbfid, file_name, chunk_count, hash_xxh3, hash_esperado, updated_at, file_size, file_mtime)
                 VALUES (:rbfid, :file, :cnt, NULL, :esperado, NOW(), :size, :mtime)
                 ON CONFLICT (rbfid, file_name) DO UPDATE SET chunk_count = :cnt, hash_xxh3 = NULL, hash_esperado = :esperado, updated_at = NOW(), file_size = :size, file_mtime = :mtime",
                [':rbfid' => $clientRbfid, ':file' => $filename, ':cnt' => $chunkCnt, ':esperado' => $hashCompleto, ':size' => $incomingSize, ':mtime' => $incomingMtime]
            );

            // Guardar cada chunk con status = 'pending'
            foreach ($chunkHashes as $idx => $ch) {
                $r->db->execute(
                    "INSERT INTO ar_file_hashes (rbfid, file_name, chunk_index, hash_xxh3, status, updated_at)
                     VALUES (:rbfid, :file, :idx, :hash, 'pending', NOW())
                     ON CONFLICT (rbfid, file_name, chunk_index) DO UPDATE SET hash_xxh3 = :hash, status = 'pending', updated_at = NOW()",
                    [':rbfid' => $clientRbfid, ':file' => $filename, ':idx' => $idx, ':hash' => $ch]
                );
            }

            // Consultar siguiente chunk faltante
            $nextChunk = $r->db->fetchOne(
                "SELECT chunk_index FROM ar_file_hashes 
                 WHERE rbfid = :rbfid AND file_name = :file AND status != 'received'
                 ORDER BY chunk_index LIMIT 1",
                [':rbfid' => $clientRbfid, ':file' => $filename]
            );

            if ($nextChunk) {
                $workPath = ($clientData['work_dir'] ?? '') . '/' . $filename;
                $destPath = ($clientData['base_dir'] ?? '') . '/' . $filename;
                $needsUpload[] = [
                    'file' => $filename,
                    'chunk' => $nextChunk['chunk_index'],
                    'work_path' => $workPath,
                    'dest_path' => $destPath,
                    'md5' => $hashCompleto
                ];
                error_log("AR sync: $filename necesita upload chunk {$nextChunk['chunk_index']}");
            } else {
                error_log("AR sync: $filename no necesita upload (ya existe o completo)");
            }
        }
    }

    $response = [
        'ok' => true,
        'sync_id' => $syncId,
        'needs_upload' => $needsUpload,
        'rate_delay' => $rateDelay,
        'slots_used' => $slots['used'],
        'slots_available' => $slots['available']
    ];
    error_log("AR sync response: " . json_encode($response));
    $r->jsonResponse($response);
    } catch (Exception $e) {
        error_log("AR sync error: " . $e->getMessage());
        $r->jsonResponse(['ok' => false, 'error' => 'Error interno: ' . $e->getMessage()], 500);
    }
}

/**
 * Subir chunk
 * Body: { rbfid, totp_token, filename, chunk_index, hash_xxh3, data (base64) }
 */
function route_ar_upload(Router $r, array $body): void {
    $core = ArCore::getInstance($r);
    $rbfid      = $body['rbfid']       ?? '';
    $totpToken  = $body['totp_token']  ?? '';
    $filename   = $body['filename']    ?? '';
    $chunkIndex = (int)($body['chunk_index'] ?? 0);
    $hashXxh3   = $body['hash_xxh3']   ?? '';
    $data       = $body['data']        ?? '';

    Logger::debug("route_ar_upload: rbfid=$rbfid filename=$filename chunk=$chunkIndex");

    $validation = $core->auth->validate($rbfid, $totpToken);
    if (!$validation['ok']) {
        Logger::warn("route_ar_upload: auth failed for $rbfid");
        $r->jsonResponse($validation, 401);
        return;
    }

    if (empty($filename) || empty($hashXxh3) || empty($data)) {
        Logger::warn("route_ar_upload: campos requeridos faltantes");
        $r->jsonResponse(['ok' => false, 'error' => 'Faltan campos requeridos'], 400);
        return;
    }

    $clientData = getClientArPath($r->db, $rbfid);
    if (!$clientData) {
        Logger::warn("route_ar_upload: cliente no encontrado: $rbfid");
        $r->jsonResponse(['ok' => false, 'error' => 'Cliente no encontrado'], 404);
        return;
    }

    try {
        $result = $core->upload->handle($rbfid, $filename, $chunkIndex, $hashXxh3, $data, $clientData);
        Logger::info("route_ar_upload: success rbfid=$rbfid file=$filename chunk=$chunkIndex");
        $r->jsonResponse(['ok' => true, 'file' => $filename, 'chunk' => $chunkIndex, ...$result]);
    } catch (Exception $e) {
        Logger::err("route_ar_upload: error " . $e->getMessage());
        $r->jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}


function route_ar_status(Router $r, array $body): void {
    $rbfid = $body['rbfid'] ?? '';

    if (empty($rbfid)) {
        $r->jsonResponse(['ok' => false, 'error' => 'RBFID requerido'], 400);
        return;
    }

    $client = $r->clientService->getClientStatus($rbfid);

    if (!$client) {
        $r->jsonResponse(['ok' => false, 'error' => 'Cliente no encontrado'], 404);
        return;
    }

    $files = $r->clientService->getClientFiles($rbfid);

    $r->jsonResponse([
        'ok' => true,
        'client' => $client,
        'files' => $files
    ]);
}

function route_ar_history(Router $r, array $body): void {
    $rbfid = $body['rbfid'] ?? '';
    $limit = $body['limit'] ?? 50;

    if (empty($rbfid)) {
        $r->jsonResponse(['ok' => false, 'error' => 'RBFID requerido'], 400);
        return;
    }

    $history = $r->db->fetchAll(
        "SELECT id, file_name, chunk_count, updated_at FROM ar_files 
         WHERE rbfid = :rbfid ORDER BY updated_at DESC LIMIT :limit",
        [':rbfid' => $rbfid, ':limit' => $limit]
    );

    $r->jsonResponse(['ok' => true, 'history' => $history]);
}


function getArSlots(Database $db): array {
    $result = $db->fetchOne("SELECT COUNT(*) as used FROM ar_sessions WHERE status = 'active' AND updated_at > NOW() - INTERVAL '5 minutes'");
    $used = (int) ($result['used'] ?? 0);
    return [
        'used' => $used,
        'available' => max(0, 10 - $used)
    ];
}

function getMissingChunks(Database $db, string $rbfid, string $filename, string $targetHash): array {
    return [
        ['file' => $filename, 'index' => 0, 'hash' => $targetHash]
    ];
}

function getClientArPath(Database $db, string $rbfid): ?array {
    $client = $db->fetchOne(
        "SELECT emp, plaza FROM clients WHERE rbfid = :rbfid",
        [':rbfid' => $rbfid]
    );

    if (!$client) return null;

    $emp   = !empty($client['emp'])   ? $client['emp']   : '_';
    $plaza = !empty($client['plaza']) ? $client['plaza'] : '_';

    $basePath = '/srv/qbck/' . $emp . '/' . $plaza . '/' . $rbfid;
    $workPath = '/tmp/ar/' . $rbfid;

    return [
        'emp'      => $emp,
        'plaza'    => $plaza,
        'base_dir' => $basePath,
        'work_dir' => $workPath,
    ];
}

function getDefaultFilesList(): array {
    return [
        ['file_name' => 'AJTFLU.DBF'], ['file_name' => 'ASISTE.DBF'], ['file_name' => 'CAJAS.DBF'],
        ['file_name' => 'CANCFDI.DBF'], ['file_name' => 'CANCFDI.FPT'], ['file_name' => 'CANOTA.DBF'],
        ['file_name' => 'CANOTA.DBT'], ['file_name' => 'CANOTA.FPT'], ['file_name' => 'CANOTAEX.DBF'],
        ['file_name' => 'CARPORT.DBF'], ['file_name' => 'CARPORT.FPT'], ['file_name' => 'CASEMANA.DBF'],
        ['file_name' => 'CAT_NEG.DBF'], ['file_name' => 'CATPROD3.DBF'], ['file_name' => 'CAT_PROD.DBF'],
        ['file_name' => 'CCOTIZA.DBF'], ['file_name' => 'CENTER.DBF'], ['file_name' => 'CFDREL.DBF'],
        ['file_name' => 'CG3_VAEN.DBF'], ['file_name' => 'CG3_VAPA.DBF'], ['file_name' => 'CLIENTE.DBF'],
        ['file_name' => 'COBRANZA.DBF'], ['file_name' => 'COMPRAS.DBF'], ['file_name' => 'CONCXC.DBF'],
        ['file_name' => 'CONVTODO.DBF'], ['file_name' => 'CPEDIDO.DBF'], ['file_name' => 'CPENDIE.DBF'],
        ['file_name' => 'CPXCORTE.DBF'], ['file_name' => 'CRMC_OBS.DBF'], ['file_name' => 'CUENGAS.DBF'],
        ['file_name' => 'CUNOTA.DBF'], ['file_name' => 'DD_CONTROL.DBF'], ['file_name' => 'DD_DATOS.DBF'],
        ['file_name' => 'DESEMANA.DBF'], ['file_name' => 'ES_COBRO.DBF'], ['file_name' => 'EYSIENC.DBF'],
        ['file_name' => 'EYSIPAR.DBF'], ['file_name' => 'FACCFD.DBF'], ['file_name' => 'FACCFD.FPT'],
        ['file_name' => 'FLUJO01.DBF'], ['file_name' => 'FLUJORES.DBF'], ['file_name' => 'HISTORIA.DBF'],
        ['file_name' => 'INVFISIC.DBF'], ['file_name' => 'MASTER.DBF'], ['file_name' => 'M_CONF.DBF'],
        ['file_name' => 'MINTINV.DBF'], ['file_name' => 'MOVCXCD.DBF'], ['file_name' => 'MOVSINV.DBF'],
        ['file_name' => 'N_CONF.DBF'], ['file_name' => 'NEGADOS.DBF'], ['file_name' => 'NOESTA.DBF'],
        ['file_name' => 'NOHAY.DBF'], ['file_name' => 'N_RESP.DBF'], ['file_name' => 'N_RESP.DBT'],
        ['file_name' => 'N_RESP_M.DBF'], ['file_name' => 'N_RESP_M.DBT'], ['file_name' => 'OBSDOCS.DBF'],
        ['file_name' => 'PAGDOCS.DBF'], ['file_name' => 'PAGMULT.DBF'], ['file_name' => 'PAGSPEI.DBF'],
        ['file_name' => 'PARAMS.DBF'], ['file_name' => 'PARTCOMP.DBF'], ['file_name' => 'PARTCOT.DBF'],
        ['file_name' => 'PARXCAR.DBF'], ['file_name' => 'PARVALES.DBF'], ['file_name' => 'PAVACL.DBF'],
        ['file_name' => 'PCOTIZA.DBF'], ['file_name' => 'PEDIDO.DBF'], ['file_name' => 'PEDIDO1.DBF'],
        ['file_name' => 'PEDIDO2.DBF'], ['file_name' => 'PPEDIDO.DBF'], ['file_name' => 'PPENDIE.DBF'],
        ['file_name' => 'RESP_PIN.DBF'], ['file_name' => 'R_BBVA.DBF'], ['file_name' => 'R_KUSHKI.DBF'],
        ['file_name' => 'SERCFD2.DBF'], ['file_name' => 'STOCK.DBF'], ['file_name' => 'SUCURCTAI.DBF'],
        ['file_name' => 'TABLA004.DBF'], ['file_name' => 'TABLA005.DBF'], ['file_name' => 'TERCAJAS.DBF'],
        ['file_name' => 'TLSERVI.DBF'], ['file_name' => 'USUARIOS.DBF'], ['file_name' => 'VACLI.DBF'],
        ['file_name' => 'VALES.DBF'], ['file_name' => 'VALPEN.DBF'], ['file_name' => 'VCPENDI.DBF'],
        ['file_name' => 'VENDEDOR.DBF'], ['file_name' => 'VENTA.DBF'], ['file_name' => 'VENTA.DBT'],
        ['file_name' => 'VENTA.FPT'], ['file_name' => 'VENTAPP.DBF'], ['file_name' => 'VPPENDI.DBF'],
        ['file_name' => 'XCORTE.DBF'],
    ];
}

/**
 * Descargar ejecutable del cliente AR
 * GET /ar/download
 */
function route_ar_download(Router $r, array $data): void {
    $exe_path = '/srv/zigRespaldoSucursal/zig-out/bin/ar.exe';
    if (!file_exists($exe_path)) {
        $r->jsonResponse(['ok' => false, 'error' => 'Ejecutable no encontrado'], 404);
        return;
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="ar.exe"');
    header('Content-Length: ' . filesize($exe_path));
    readfile($exe_path);
    exit;
}

function xxh3_token(string $input): string {
    // xxh3-64 en little-endian → base64 → 11 chars (igual que Zig hash.zig toBase64)
    $hexHash = hash('xxh3', $input);           // 16 hex chars = 8 bytes
    $bytes   = hex2bin($hexHash);              // 8 bytes big-endian
    $le      = strrev($bytes);                 // little-endian (como Zig)
    $b64     = base64_encode($le);             // 12 chars con padding
    return substr($b64, 0, 11);               // 11 chars sin padding (igual que toBase64)
}

function xxh3(string $input): string {
    return hash('xxh3', $input);
}