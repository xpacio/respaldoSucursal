<?php
/**
 * Autoloader - Carga clases bajo demanda
 * 
 * Mapea nombres de clase a archivos automáticamente.
 */
spl_autoload_register(function (string $class) {
    // Mapeo explícito: clase → archivo (cuando el nombre no coincide)
    $fileMap = [
        'System' => 'Services/System.php',
    ];
    
    if (isset($fileMap[$class])) {
        $file = __DIR__ . '/' . $fileMap[$class];
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
    
    // Búsqueda en directorios
    $dirs = ['Config', 'Services', 'Repositories'];
    foreach ($dirs as $dir) {
        $file = __DIR__ . "/{$dir}/{$class}.php";
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});
