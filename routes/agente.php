<?php
/**
 * Agente Routes - Handlers para gestión de archivos del agente
 */
require_once __DIR__ . '/../Services/AgenteService.php';

function route_agente(Router $r, string $resource) {
    $data = $r->getBody();
    $action = $data['action'] ?? 'files';

    $agente = new AgenteService();

    match ($action) {
        'files'       => agente_files($r, $agente, $data),
        'file'        => agente_file($r, $agente, $data),
        'file_save'   => agente_file_save($r, $agente, $data),
        'file_create' => agente_file_create($r, $agente, $data),
        'dir_create'  => agente_dir_create($r, $agente, $data),
        'delete'      => agente_delete($r, $agente, $data),
        'upload'      => agente_upload($r, $agente, $data),
        'download'    => agente_download($r, $agente, $data),
        default       => $r->jsonResponse(['ok' => false, 'error' => "Acción '$action' no existe en agente"], 404),
    };
}

function agente_files(Router $r, AgenteService $agente, array $data) {
    $result = $agente->listFiles($data['path'] ?? '');
    $r->jsonResponse($result);
}

function agente_file(Router $r, AgenteService $agente, array $data) {
    $path = $data['path'] ?? '';
    if (empty($path)) { $r->jsonResponse(['ok' => false, 'error' => 'Path requerido'], 400); }
    $result = $agente->readFile($path);
    $r->jsonResponse($result);
}

function agente_file_save(Router $r, AgenteService $agente, array $data) {
    $path = $data['path'] ?? '';
    if (empty($path)) { $r->jsonResponse(['ok' => false, 'error' => 'Path requerido'], 400); }
    $result = $agente->saveFile($path, $data['content'] ?? '');
    $r->jsonResponse($result);
}

function agente_file_create(Router $r, AgenteService $agente, array $data) {
    $path = $data['path'] ?? '';
    if (empty($path)) { $r->jsonResponse(['ok' => false, 'error' => 'Path requerido'], 400); }
    $result = $agente->createFile($path, $data['content'] ?? '');
    $r->jsonResponse($result);
}

function agente_dir_create(Router $r, AgenteService $agente, array $data) {
    $path = $data['path'] ?? '';
    if (empty($path)) { $r->jsonResponse(['ok' => false, 'error' => 'Path requerido'], 400); }
    $result = $agente->createDirectory($path);
    $r->jsonResponse($result);
}

function agente_delete(Router $r, AgenteService $agente, array $data) {
    $path = $data['path'] ?? '';
    if (empty($path)) { $r->jsonResponse(['ok' => false, 'error' => 'Path requerido'], 400); }
    $result = $agente->delete($path);
    $r->jsonResponse($result);
}

function agente_upload(Router $r, AgenteService $agente, array $data) {
    if (!isset($_FILES['file'])) { $r->jsonResponse(['ok' => false, 'error' => 'Archivo requerido'], 400); }
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) { $r->jsonResponse(['ok' => false, 'error' => 'Error en upload'], 400); }
    $path = $_POST['path'] ?? $file['name'];
    $content = file_get_contents($file['tmp_name']);
    $result = $agente->saveBinary($path, $content);
    $r->jsonResponse($result);
}

function agente_download(Router $r, AgenteService $agente, array $data) {
    $path = $data['path'] ?? '';
    if (empty($path)) { $r->jsonResponse(['ok' => false, 'error' => 'Path requerido'], 400); }
    $fullPath = '/srv/bat/' . ltrim($path, '/');
    if (!file_exists($fullPath)) { $r->jsonResponse(['ok' => false, 'error' => 'Archivo no encontrado'], 404); }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($fullPath));
    readfile($fullPath);
    exit;
}
