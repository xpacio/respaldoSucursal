<?php

declare(strict_types=1);

require_once __DIR__ . '/shared/autoload.php';

try {
    // Crear una conexión simple a la base de datos usando la configuración del proyecto
    $config = require __DIR__ . '/shared/Config/config.php';
    $dbConfig = $config['db'];
    
    $dsn = "pgsql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['dbname']}";
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== Eliminando columna 'enabled' de tabla 'ar_clients' ===\n\n";
    
    // 1. Verificar si la columna existe
    $stmt = $pdo->prepare("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'ar_clients' AND column_name = 'enabled'
    ");
    $stmt->execute();
    $columnExists = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$columnExists) {
        echo "La columna 'enabled' ya no existe en 'ar_clients'\n";
        exit(0);
    }
    
    echo "Columna 'enabled' encontrada en 'ar_clients'\n";
    
    // 2. Eliminar la columna
    echo "Eliminando columna 'enabled'...\n";
    $pdo->exec("ALTER TABLE ar_clients DROP COLUMN enabled");
    
    echo "¡Columna eliminada exitosamente!\n";
    
    // 3. Verificar estructura actualizada
    echo "\n=== Estructura actualizada de tabla ar_clients ===\n";
    $stmt = $pdo->prepare("
        SELECT column_name, data_type, is_nullable, column_default
        FROM information_schema.columns 
        WHERE table_name = 'ar_clients' 
        ORDER BY ordinal_position
    ");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($columns as $col) {
        echo "  {$col['column_name']}: {$col['data_type']}";
        echo " (nullable: {$col['is_nullable']})";
        if ($col['column_default']) {
            echo " (default: {$col['column_default']})";
        }
        echo "\n";
    }
    
    echo "\n=== Operación completada ===\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    
    // Si el error es porque hay dependencias, mostrar más información
    if (strpos($e->getMessage(), 'DROP COLUMN') !== false) {
        echo "\nNOTA: Puede que haya dependencias (índices, constraints, etc.) en la columna.\n";
        echo "Para eliminar forzadamente: ALTER TABLE ar_clients DROP COLUMN enabled CASCADE;\n";
    }
    
    exit(1);
}