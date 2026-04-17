<?php
/**
 * Autoloader - Carga clases bajo demanda (PSR-4)
 * 
 * Mapea el namespace App\ a los directorios del proyecto.
 */
spl_autoload_register(function (string $class) {
    // Prefijo del namespace del proyecto
    $prefix = 'App\\';
    
    // Si la clase no usa el prefijo, no hacemos nada
    if (strpos($class, $prefix) !== 0) {
        // Fallback para nombres cortos si es necesario (compatibilidad temporal)
        $shortName = basename(str_replace(['\\', '/'], '/', $class));
        $fallbackDirs = [
            __DIR__,
            __DIR__ . '/Services',
            __DIR__ . '/Backup',
            __DIR__ . '/Config',
            __DIR__ . '/Traits',
            __DIR__ . '/../cli',
            __DIR__ . '/../api',
        ];

        foreach ($fallbackDirs as $dir) {
            $file = $dir . '/' . $shortName . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
        return;
    }

    // Obtener el nombre relativo de la clase
    $relativeClass = substr($class, strlen($prefix));

    // Mapeo de sub-namespaces a directorios específicos
    $mappings = [
        'Api\\'     => __DIR__ . '/../api/',
        'Cli\\'     => __DIR__ . '/../cli/',
        ''          => __DIR__ . '/', // Default mapping for App\ -> shared/
    ];

    foreach ($mappings as $nsPrefix => $baseDir) {
        if ($nsPrefix === '' || strpos($relativeClass, $nsPrefix) === 0) {
            $subRelativeClass = ($nsPrefix === '') ? $relativeClass : substr($relativeClass, strlen($nsPrefix));
            $file = $baseDir . str_replace('\\', '/', $subRelativeClass) . '.php';
            
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    }
});

