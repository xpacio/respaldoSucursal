<?php
/**
 * Autoloader - Carga clases bajo demanda
 * 
 * Mapea nombres de clase a archivos automáticamente.
 */
spl_autoload_register(function (string $class) {
    $className = ltrim($class, '\\');
    $shortName = basename(str_replace('\\', '/', $className));

    // Mapeo explícito: clase → archivo (cuando el nombre no coincide)
    $fileMap = [
        'System' => 'Services/System.php',
        'BackupCommandInterface' => 'Backup/BackupCommandInterface.php',
        'ChunkDTO' => 'Backup/ChunkDTO.php',
        'BackupSessionRepositoryInterface' => 'Backup/BackupSessionRepositoryInterface.php',
        'ProcessChunkCommand' => 'Backup/ProcessChunkCommand.php',
        'BackupApiController' => 'Backup/BackupApiController.php',
        'FileSystemBackupRepository' => 'Backup/FileSystemBackupRepository.php',
    ];
    
    if (isset($fileMap[$className])) {
        $file = __DIR__ . '/' . $fileMap[$className];
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }

    if (isset($fileMap[$shortName])) {
        $file = __DIR__ . '/' . $fileMap[$shortName];
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
    
    // Búsqueda en directorios
    $dirs = ['', 'Config', 'Services', 'Repositories', '../cli'];
    foreach ($dirs as $dir) {
        $filePath = $dir ? __DIR__ . "/{$dir}/{$shortName}.php" : __DIR__ . "/{$shortName}.php";
        if (file_exists($filePath)) {
            require_once $filePath;
            return;
        }
    }
});
