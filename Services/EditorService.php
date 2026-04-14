<?php
require_once __DIR__ . '/../Config/PathUtils.php';

class EditorService {
    private Database $db;
    private Logger $logger;
    private EditorRepository $repo;
    private string $basePath = '/srv/sh';

    public function __construct(Database $db, Logger $logger) {
        $this->db = $db;
        $this->logger = $logger;
        $this->repo = new EditorRepository($db);
    }

    private function fullPath(string $path): string {
        $clean = '/' . ltrim($path, '/');
        $full = realpath($this->basePath . $clean);
        if ($full && str_starts_with($full, $this->basePath)) {
            return $full;
        }
        return $this->basePath . $clean;
    }

    // ─── Operaciones BD (fuente de verdad) ───

    public function listBdFiles(string $path = ''): array {
        $rows = $this->repo->listFiles($path);
        return ['ok' => true, 'files' => $rows];
    }

    public function readFromDb(string $path): array {
        $row = $this->repo->readFile($path);
        if (!$row) {
            return ['ok' => false, 'error' => 'Archivo no encontrado en BD', 'code' => 'NOT_FOUND'];
        }
        return ['ok' => true, 'path' => $row['path'], 'content' => $row['content'], 'md5' => $row['md5'], 'size' => (int)$row['size']];
    }

    public function saveToDb(string $path, string $content): array {
        $md5 = md5($content);

        if ($this->repo->exists($path)) {
            $this->repo->saveFile($path, $content);
        } else {
            $this->repo->createFile($path, $content);
        }

        return ['ok' => true, 'path' => $path, 'md5' => $md5, 'size' => strlen($content)];
    }

    // ─── Operaciones disco (espejo de BD) ───

    public function writeToDisk(string $path, string $content): array {
        $full = $this->fullPath($path);
        $dir = dirname($full);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $bytes = file_put_contents($full, $content);
        if ($bytes === false) {
            return ['ok' => false, 'error' => 'No se pudo escribir al disco'];
        }
        return ['ok' => true, 'bytes_written' => $bytes, 'disk_md5' => md5_file($full)];
    }

    // ─── Flujo completo: BD → disco → verify ───

    public function save(string $path, string $content): array {
        $md5 = md5($content);

        // 1. Guardar en BD (fuente de verdad)
        $dbResult = $this->saveToDb($path, $content);
        if (!$dbResult['ok']) return $dbResult;

        // 2. Escribir a disco
        $diskResult = $this->writeToDisk($path, $content);
        if (!$diskResult['ok']) return $diskResult;

        // 3. Verificar MD5
        $verified = ($diskResult['disk_md5'] === $md5);

        return [
            'ok' => true,
            'path' => $path,
            'md5' => $md5,
            'size' => strlen($content),
            'bytes_written' => $diskResult['bytes_written'],
            'verified' => $verified
        ];
    }

    public function create(string $path, string $content): array {
        if ($this->repo->exists($path)) {
            return ['ok' => false, 'error' => 'El archivo ya existe en BD', 'code' => 'ALREADY_EXISTS'];
        }
        return $this->save($path, $content);
    }

    public function delete(string $path): array {
        // Eliminar de BD
        $this->repo->deleteFile($path);

        // Eliminar de disco
        $full = $this->fullPath($path);
        if (file_exists($full)) {
            unlink($full);
        }

        return ['ok' => true, 'path' => $path];
    }

    public function mkdir(string $path): array {
        $full = $this->fullPath($path);
        if (is_dir($full)) {
            return ['ok' => true, 'path' => $path, 'existed' => true];
        }
        if (mkdir($full, 0755, true)) {
            return ['ok' => true, 'path' => $path];
        }
        return ['ok' => false, 'error' => 'No se pudo crear directorio'];
    }

    // ─── Verificación MD5 ───

    public function verify(string $path): array {
        $dbMd5 = $this->repo->getMd5($path);
        if (!$dbMd5) {
            return ['ok' => false, 'error' => 'Archivo no existe en BD', 'code' => 'NOT_FOUND'];
        }

        $full = $this->fullPath($path);
        if (!file_exists($full)) {
            return ['ok' => true, 'path' => $path, 'db_md5' => $dbMd5, 'disk_md5' => null, 'match' => false, 'status' => 'missing_from_disk'];
        }

        $diskMd5 = md5_file($full);
        $match = ($diskMd5 === $dbMd5);

        return [
            'ok' => true,
            'path' => $path,
            'db_md5' => $dbMd5,
            'disk_md5' => $diskMd5,
            'match' => $match,
            'status' => $match ? 'ok' : 'modified_on_disk'
        ];
    }

    public function verifyAll(): array {
        $files = $this->repo->listFiles();
        $results = [];
        foreach ($files as $file) {
            $results[] = $this->verify($file['path']);
        }
        return ['ok' => true, 'results' => $results];
    }

    // ─── Visor de disco ───

    public function listDisk(string $path = ''): array {
        $dir = $this->fullPath($path);
        if (!is_dir($dir)) {
            return ['ok' => false, 'error' => 'Directorio no existe'];
        }

        $items = [];
        $entries = scandir($dir);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $fullPath = $dir . '/' . $entry;
            $relPath = ($path ? $path . '/' : '') . $entry;
            $inDb = $this->repo->exists($relPath);

            $items[] = [
                'name' => $entry,
                'path' => $relPath,
                'is_dir' => is_dir($fullPath),
                'size' => is_file($fullPath) ? filesize($fullPath) : 0,
                'in_db' => $inDb,
                'modified' => is_file($fullPath) ? date('Y-m-d H:i:s', filemtime($fullPath)) : null
            ];
        }

        usort($items, fn($a, $b) => ($a['is_dir'] ? 0 : 1) <=> ($b['is_dir'] ? 0 : 1) ?: $a['name'] <=> $b['name']);

        return ['ok' => true, 'items' => $items, 'path' => $path];
    }

    public function findOrphans(): array {
        $orphans = [];
        $this->scanDirForOrphans($this->basePath, '', $orphans);
        return ['ok' => true, 'orphans' => $orphans];
    }

    private function scanDirForOrphans(string $dir, string $prefix, array &$orphans): void {
        $entries = @scandir($dir);
        if (!$entries) return;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $full = $dir . '/' . $entry;
            $rel = ($prefix ? $prefix . '/' : '') . $entry;

            if (is_dir($full)) {
                $this->scanDirForOrphans($full, $rel, $orphans);
            } elseif (is_file($full) && !$this->repo->exists($rel)) {
                $orphans[] = [
                    'path' => $rel,
                    'size' => filesize($full),
                    'modified' => date('Y-m-d H:i:s', filemtime($full))
                ];
            }
        }
    }

    // ─── Importar disco → BD ───

    public function import(string $path): array {
        $full = $this->fullPath($path);
        if (!is_file($full)) {
            return ['ok' => false, 'error' => 'Archivo no existe en disco'];
        }

        $content = file_get_contents($full);
        if ($content === false) {
            return ['ok' => false, 'error' => 'No se pudo leer el archivo'];
        }

        if ($this->repo->exists($path)) {
            $this->repo->saveFile($path, $content);
        } else {
            $this->repo->createFile($path, $content);
        }

        $md5 = md5($content);
        return ['ok' => true, 'path' => $path, 'md5' => $md5, 'size' => strlen($content)];
    }

    // ─── Reconstruir disco desde BD ───

    public function rebuildAll(): array {
        $files = $this->repo->listFiles();
        $results = ['written' => 0, 'failed' => 0, 'details' => []];

        foreach ($files as $file) {
            $full = $this->fullPath($file['path']);
            $dir = dirname($full);
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            $row = $this->repo->readFile($file['path']);
            $bytes = file_put_contents($full, $row['content']);

            if ($bytes !== false && md5_file($full) === $file['md5']) {
                $results['written']++;
                $results['details'][] = ['path' => $file['path'], 'status' => 'ok'];
            } else {
                $results['failed']++;
                $results['details'][] = ['path' => $file['path'], 'status' => 'failed'];
            }
        }

        $results['ok'] = true;
        return $results;
    }
}
