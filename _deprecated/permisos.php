<?php

function route_permisos(Router $r, string $resource) {
    $data = $r->getBody();
    $action = $data['action'] ?? 'get';

    match ($action) {
        'get' => permisos_get($r, $data),
        default => $r->jsonResponse(['ok' => false, 'error' => "Acción '$action' no existe en permisos"], 404),
    };
}

function permisos_get(Router $r, array $data) {
    $clientId = $data['id'] ?? '';
    $r->logger->enterContext(2, 30, 20, "Obtener permisos cliente $clientId");

    if (!$clientId) { $r->jsonResponse(['ok' => false, 'error' => 'ID de cliente requerido'], 400); }

    $client = $r->db->fetchOne("SELECT rbfid FROM clients WHERE rbfid = :rbfid", [':rbfid' => $clientId]);
    if (!$client) { $r->jsonResponse(['ok' => false, 'error' => 'Cliente no encontrado'], 404); }

    $homePath = "/home/$clientId";
    $username = "_$clientId";

    $exitCode = null;
    $r->system->sudo("/usr/bin/test -d " . escapeshellarg($homePath), $exitCode);
    if ($exitCode !== 0) {
        $r->jsonResponse(['ok' => true, 'permisos' => ['carpetas' => [], 'archivos' => [], 'error' => 'Directorio home no existe']]);
    }

    $overlays = $r->db->fetchAll("SELECT id, overlay_src, overlay_dst, mode, mounted FROM overlays WHERE rbfid = :rbfid", [':rbfid' => $clientId]);
    $overlayByDst = [];
    foreach ($overlays as $o) {
        $overlayByDst[$o['overlay_dst']] = $o;
    }

    $carpetas = [];
    $archivos = [];

    $output = $r->system->sudo("ls -la " . escapeshellarg($homePath), $exitCode);

    $lines = explode("\n", $output);
    foreach ($lines as $line) {
        if (empty(trim($line))) continue;
        $parts = preg_split('/\s+/', $line);
        if (count($parts) < 9) continue;

        $perms = $parts[0];
        $owner = $parts[2] ?? '';
        $group = $parts[3] ?? '';
        $name = implode(' ', array_slice($parts, 8));

        if ($name === '.' || $name === '..' || $name[0] === '.') continue;
        if ($perms[0] !== 'd') continue;

        $overlay = $overlayByDst[$name] ?? null;

        if ($overlay) {
            $mode = $overlay['mode'];
            $ownerTarget = $mode === 'rw' ? "root:$username" : "root:users";
            $permsTarget = $mode === 'rw' ? "750" : "550";
        } else {
            $mode = null;
            $ownerTarget = "root:users";
            $permsTarget = "550";
        }

        $currentOwner = "$owner:$group";
        $currentPerms = substr($perms, -3);
        $status = ($currentOwner !== $ownerTarget || $currentPerms !== $permsTarget) ? 'warning' : 'ok';

        $carpetas[] = [
            'path' => $name, 'mode' => $mode, 'owner' => $currentOwner,
            'perms' => $perms, 'status' => $status, 'overlay_id' => $overlay['id'] ?? null,
            'owner_target' => $ownerTarget, 'perms_target' => $permsTarget
        ];

        $itemPath = "$homePath/$name";
        $folderFiles = [];
        $exitCodeFiles = null;
        $outputFiles = $r->system->sudo("ls -la " . escapeshellarg($itemPath), $exitCodeFiles);

        if ($exitCodeFiles === 0) {
            $fileLines = explode("\n", $outputFiles);
            foreach ($fileLines as $fileLine) {
                if (empty(trim($fileLine))) continue;
                $fileParts = preg_split('/\s+/', $fileLine);
                if (count($fileParts) < 9) continue;
                $filePerms = $fileParts[0];
                $fileOwner = $fileParts[2] ?? '';
                $fileGroup = $fileParts[3] ?? '';
                $fileSize = is_numeric($fileParts[4] ?? '') ? intval($fileParts[4]) : 0;
                $fileName = implode(' ', array_slice($fileParts, 8));
                if ($fileName === '.' || $fileName === '..') continue;

                $folderFiles[] = [
                    'name' => $fileName, 'type' => $filePerms[0] === 'd' ? 'directorio' : 'archivo',
                    'perms' => $filePerms, 'owner' => "$fileOwner:$fileGroup", 'size' => $fileSize
                ];
            }
        }
        $archivos[$name] = $folderFiles;
    }

    usort($carpetas, fn($a, $b) => $a['path'] <=> $b['path']);

    $r->jsonResponse(['ok' => true, 'permisos' => ['carpetas' => $carpetas, 'archivos' => $archivos, 'home_path' => $homePath, 'client_id' => $clientId]]);
}
