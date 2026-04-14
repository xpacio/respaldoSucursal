<?php
/**
 * AgenteService - Gestión de archivos del agente
 * Lee, escribe y lista archivos en /srv/bat/
 */
class AgenteService {
    private const BASE_PATH = '/srv/bat';
    
    private array $editableExtensions = ['bat', 'vbs', 'txt', 'cmd', 'ps1'];
    private array $uploadableExtensions = ['bat', 'vbs', 'txt', 'cmd', 'ps1', 'exe'];
    
    /**
     * Listar archivos y carpetas en una ruta
     */
    public function listFiles(string $path = ''): array {
        $fullPath = $this->getFullPath($path);
        
        if (!is_dir($fullPath)) {
            return ['ok' => false, 'error' => 'Directorio no encontrado'];
        }
        
        $items = [];
        
        $entries = scandir($fullPath);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            
            $entryPath = $fullPath . '/' . $entry;
            $relativePath = ltrim($path . '/' . $entry, '/');
            $isDir = is_dir($entryPath);
            
            $item = [
                'name' => $entry,
                'path' => $relativePath,
                'type' => $isDir ? 'directory' : 'file',
                'size' => $isDir ? null : filesize($entryPath),
                'modified' => date('Y-m-d H:i:s', filemtime($entryPath))
            ];
            
            if (!$isDir) {
                $ext = pathinfo($entry, PATHINFO_EXTENSION);
                $item['extension'] = $ext;
                $item['editable'] = in_array($ext, $this->editableExtensions);
                $item['downloadable'] = true;
            }
            
            $items[] = $item;
        }
        
        usort($items, function($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'directory' ? -1 : 1;
            }
            return strcmp($a['name'], $b['name']);
        });
        
        return [
            'ok' => true,
            'path' => $path,
            'base_path' => self::BASE_PATH,
            'items' => $items
        ];
    }
    
    /**
     * Leer contenido de un archivo
     */
    public function readFile(string $path): array {
        $fullPath = $this->getFullPath($path);
        
        if (!is_file($fullPath)) {
            return ['ok' => false, 'error' => 'Archivo no encontrado'];
        }
        
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        
        if (!in_array($ext, $this->editableExtensions)) {
            return [
                'ok' => false,
                'error' => 'Archivo no editable',
                'is_binary' => true,
                'size' => filesize($fullPath)
            ];
        }
        
        $content = file_get_contents($fullPath);
        
        if ($content === false) {
            return ['ok' => false, 'error' => 'Error al leer archivo'];
        }
        
        return [
            'ok' => true,
            'path' => $path,
            'name' => basename($path),
            'content' => $content,
            'extension' => $ext,
            'editable' => true,
            'size' => strlen($content)
        ];
    }
    
    /**
     * Guardar contenido de un archivo
     */
    public function saveFile(string $path, string $content): array {
        $fullPath = $this->getFullPath($path);
        
        if (!is_file($fullPath)) {
            return ['ok' => false, 'error' => 'Archivo no encontrado'];
        }
        
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        
        if (!in_array($ext, $this->editableExtensions)) {
            return ['ok' => false, 'error' => 'Tipo de archivo no editable'];
        }
        
        $result = file_put_contents($fullPath, $content);
        
        if ($result === false) {
            return ['ok' => false, 'error' => 'Error al guardar archivo'];
        }
        
        return [
            'ok' => true,
            'path' => $path,
            'bytes_written' => $result
        ];
    }
    
    /**
     * Guardar archivo binario (upload)
     */
    public function saveBinary(string $path, string $data): array {
        $fullPath = $this->getFullPath($path);
        
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        
        if (!in_array($ext, $this->uploadableExtensions)) {
            return ['ok' => false, 'error' => 'Tipo de archivo no permitido para upload'];
        }
        
        $result = file_put_contents($fullPath, $data);
        
        if ($result === false) {
            return ['ok' => false, 'error' => 'Error al guardar archivo'];
        }
        
        return [
            'ok' => true,
            'path' => $path,
            'bytes_written' => $result
        ];
    }
    
    /**
     * Obtener ruta completa desde path relativa
     */
    private function getFullPath(string $path): string {
        $fullPath = self::BASE_PATH . '/' . ltrim($path, '/');
        $realPath = realpath($fullPath);
        
        if ($realPath === false) {
            return $fullPath;
        }
        
        if (strpos($realPath, self::BASE_PATH) !== 0) {
            throw new Exception('Ruta fuera del directorio permitido');
        }
        
        return $realPath;
    }
    
    /**
     * Crear nueva carpeta
     */
    public function createDirectory(string $path): array {
        $fullPath = $this->getFullPath($path);
        
        if (is_dir($fullPath)) {
            return ['ok' => false, 'error' => 'La carpeta ya existe'];
        }
        
        if (!mkdir($fullPath, 0755, true)) {
            return ['ok' => false, 'error' => 'Error al crear carpeta'];
        }
        
        return [
            'ok' => true,
            'path' => $path
        ];
    }
    
    /**
     * Crear nuevo archivo
     */
    public function createFile(string $path, string $content = ''): array {
        $fullPath = $this->getFullPath($path);
        
        if (is_file($fullPath)) {
            return ['ok' => false, 'error' => 'El archivo ya existe'];
        }
        
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        
        if (!in_array($ext, $this->editableExtensions)) {
            return ['ok' => false, 'error' => 'Tipo de archivo no permitido'];
        }
        
        $result = file_put_contents($fullPath, $content);
        
        if ($result === false) {
            return ['ok' => false, 'error' => 'Error al crear archivo'];
        }
        
        return [
            'ok' => true,
            'path' => $path
        ];
    }
    
    /**
     * Eliminar archivo o carpeta
     */
    public function delete(string $path): array {
        $fullPath = $this->getFullPath($path);
        
        if (!file_exists($fullPath)) {
            return ['ok' => false, 'error' => 'No encontrado'];
        }
        
        if (is_dir($fullPath)) {
            if (!rmdir($fullPath)) {
                return ['ok' => false, 'error' => 'Error al eliminar carpeta'];
            }
        } else {
            if (!unlink($fullPath)) {
                return ['ok' => false, 'error' => 'Error al eliminar archivo'];
            }
        }
        
        return [
            'ok' => true,
            'path' => $path
        ];
    }
}
