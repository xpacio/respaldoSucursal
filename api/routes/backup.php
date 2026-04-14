<?php

use Shared\Backup\BackupApiController;
use Shared\Backup\FileSystemBackupRepository;

function route_backup(Router $r, string $resource): void {
    $path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $segments = array_values(array_filter(explode('/', trim($path, '/'))));

    if (isset($segments[0]) && $segments[0] === 'api') {
        array_shift($segments);
    }

    $action = $segments[1] ?? null;
    $body = $r->getBody();
    $repository = new FileSystemBackupRepository();
    $controller = new BackupApiController($repository);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $r->jsonResponse(['ok' => false, 'error' => 'Method not allowed'], 405);
    }

    if ($action === 'init') {
        $r->jsonResponse($controller->init($body));
    }

    if ($action === 'chunk') {
        $r->jsonResponse($controller->receiveChunk($body));
    }

    $r->jsonResponse(['ok' => false, 'error' => 'Not found'], 404);
}
