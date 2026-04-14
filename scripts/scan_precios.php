<?php
/**
 * Scanner de /srv/precios/ → genera texto de importación para distribuciones
 * Uso: php scripts/scan_precios.php [perfil1] [perfil2] ...
 * 
 * Sin argumentos: muestra resumen de todos los archivos encontrados
 * Con argumentos: genera texto de import para los perfiles indicados
 * 
 * Ejemplos:
 *   php scripts/scan_precios.php                    → resumen
 *   php scripts/scan_precios.php pcomb              → import de pcomb
 *   php scripts/scan_precios.php pcomb pdcomb pcombx1 → import de los 3
 */

$config = require __DIR__ . '/../Config/config.php';
require_once __DIR__ . '/../Config/Database.php';
require_once __DIR__ . '/../Repositories/RepositoryInterface.php';
require_once __DIR__ . '/../Repositories/AbstractRepository.php';
require_once __DIR__ . '/../Repositories/DistribucionRepository.php';

$db = new Database($config['db']);
$repo = new DistribucionRepository($db);

// 1. Obtener perfiles existentes de la BD
$perfiles = [];
foreach ($repo->getTipos() as $t) {
    $perfiles[$t['tipo']] = [
        'files' => array_map('trim', explode(',', $t['files'])),
        'dst' => $t['dst_template']
    ];
}

// 2. Obtener mapeo directorio → plaza de las distribuciones existentes
$distPlazas = [];
foreach ($repo->listAll() as $d) {
    // Extraer nombre de directorio de la ruta
    $dir = rtrim($d['src_path'], '/');
    // Quitar /ENVIAR, /LEALTAD, /MASTER/ENVIAR, /MASTER/LEALTAD del final
    $dir = preg_replace('#/(MASTER/)?(ENVIAR|LEALTAD)$#', '', $dir);
    $basename = basename($dir);
    if (!isset($distPlazas[$basename])) {
        $distPlazas[$basename] = $d['plaza'];
    }
}

// 3. Escanear /srv/precios/
$preciosDir = '/srv/precios';
$dirs = array_filter(scandir($preciosDir), fn($d) => $d[0] !== '.' && is_dir("$preciosDir/$d"));
sort($dirs);

// Para cada directorio, encontrar archivos en root (no en subdirectorios)
$scan = [];
foreach ($dirs as $dir) {
    $path = "$preciosDir/$dir";
    $plaza = $distPlazas[$dir] ?? null;
    
    // Root files (directo en el directorio, no en subdirectorios)
    $rootFiles = [];
    foreach (scandir($path) as $f) {
        if ($f[0] === '.') continue;
        if (is_file("$path/$f") && preg_match('/\.(DBF|CDX|dbf|cdx)$/i', $f)) {
            $rootFiles[] = strtoupper($f);
        }
    }
    
    // Subdirectorios con archivos
    $subdirs = [];
    foreach (scandir($path) as $f) {
        if ($f[0] === '.') continue;
        if (is_dir("$path/$f") && $f !== 'ENVIAR') {
            $subFiles = [];
            $subPath = "$path/$f";
            foreach (scandir($subPath) as $sf) {
                if ($sf[0] === '.') continue;
                if (is_file("$subPath/$sf") && preg_match('/\.(DBF|CDX|dbf|cdx)$/i', $sf)) {
                    $subFiles[] = strtoupper($sf);
                }
            }
            if (!empty($subFiles)) {
                $subdirs[$f] = $subFiles;
            }
        }
    }
    
    // Caso especial TAPACHULA: archivos están en MASTER/
    if ($dir === 'TAPACHULA') {
        $masterPath = "$path/MASTER";
        if (is_dir($masterPath)) {
            $masterFiles = [];
            foreach (scandir($masterPath) as $f) {
                if ($f[0] === '.') continue;
                if (is_file("$masterPath/$f") && preg_match('/\.(DBF|CDX|dbf|cdx)$/i', $f)) {
                    $masterFiles[] = strtoupper($f);
                }
            }
            // Agregar como si fuera root
            foreach ($masterFiles as $mf) {
                if (!in_array($mf, $rootFiles)) $rootFiles[] = $mf;
            }
            // Subdirectorios dentro de MASTER
            foreach (scandir($masterPath) as $f) {
                if ($f[0] === '.') continue;
                if (is_dir("$masterPath/$f") && $f !== 'ENVIAR') {
                    $subFiles = [];
                    $subSubPath = "$masterPath/$f";
                    foreach (scandir($subSubPath) as $sf) {
                        if ($sf[0] === '.') continue;
                        if (is_file("$subSubPath/$sf") && preg_match('/\.(DBF|CDX|dbf|cdx)$/i', $sf)) {
                            $subFiles[] = strtoupper($sf);
                        }
                    }
                    if (!empty($subFiles)) {
                        $subdirs[$f] = $subFiles;
                    }
                }
            }
        }
    }
    
    $scan[$dir] = [
        'plaza' => $plaza,
        'root' => $rootFiles,
        'subdirs' => $subdirs
    ];
}

// 4. Modo resumen (sin argumentos)
if ($argc < 2) {
    echo "═══════════════════════════════════════════════════\n";
    echo " Escáner de /srv/precios/\n";
    echo "═══════════════════════════════════════════════════\n\n";
    
    // Agrupar por archivo
    $fileMap = []; // filename → [dirs]
    foreach ($scan as $dir => $info) {
        foreach ($info['root'] as $f) {
            if (!isset($fileMap[$f])) $fileMap[$f] = [];
            $fileMap[$f][] = $dir;
        }
        foreach ($info['subdirs'] as $subName => $subFiles) {
            foreach ($subFiles as $f) {
                $key = "$f ({$subName}/)";
                if (!isset($fileMap[$key])) $fileMap[$key] = [];
                $fileMap[$key][] = $dir;
            }
        }
    }
    
    ksort($fileMap);
    
    // Mostrar por perfil existente
    echo "Perfiles existentes en BD:\n";
    foreach ($perfiles as $name => $p) {
        echo "  $name → " . implode(',', $p['files']) . "\n";
    }
    echo "\n";
    
    // Mostrar archivos sin perfil
    $allProfileFiles = [];
    foreach ($perfiles as $p) {
        foreach ($p['files'] as $f) $allProfileFiles[] = strtoupper($f);
    }
    
    echo "Archivos encontrados en /srv/precios/:\n";
    foreach ($fileMap as $file => $fileDirs) {
        $cleanFile = preg_replace('# \(.*\)$#', '', $file);
        $hasProfile = in_array($cleanFile, $allProfileFiles);
        $marker = $hasProfile ? '✓' : '○';
        echo "  $marker $file → " . count($fileDirs) . " dirs\n";
    }
    
    echo "\nPerfiles sin crear (○):\n";
    foreach ($fileMap as $file => $fileDirs) {
        $cleanFile = preg_replace('# \(.*\)$#', '', $file);
        if (!in_array($cleanFile, $allProfileFiles)) {
            echo "  $cleanFile (" . count($fileDirs) . " plazas)\n";
        }
    }
    
    echo "\nUso: php scripts/scan_precios.php <perfil1> [perfil2] ...\n";
    echo "Ejemplo: php scripts/scan_precios.php pcomb pdcomb\n";
    exit(0);
}

// 5. Modo generación (con argumentos)
$requestedProfiles = array_slice($argv, 1);

foreach ($requestedProfiles as $profileName) {
    $profileName = strtolower($profileName);
    
    // Buscar el perfil en BD
    if (!isset($perfiles[$profileName])) {
        echo "⚠ Perfil '$profileName' no existe en BD. Crear primero.\n\n";
        continue;
    }
    
    $profileFiles = $perfiles[$profileName]['files'];
    
    echo "═══ $profileName (" . implode(',', $profileFiles) . ") ═══\n";
    
    $lines = [];
    foreach ($scan as $dir => $info) {
        if (!$info['plaza']) continue;
        
        // ¿Tiene TODOS los archivos del perfil en root?
        $hasAll = true;
        foreach ($profileFiles as $pf) {
            if (!in_array(strtoupper($pf), $info['root'])) {
                $hasAll = false;
                break;
            }
        }
        
        if ($hasAll) {
            $ruta = ($dir === 'TAPACHULA') 
                ? "$preciosDir/TAPACHULA/MASTER/" 
                : "$preciosDir/$dir/";
            $lines[] = "$profileName,$dir,{$info['plaza']},$ruta";
        }
    }
    
    echo implode("\n", $lines) . "\n";
    echo "(" . count($lines) . " distribuciones)\n\n";
}
