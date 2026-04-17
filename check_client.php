<?php

declare(strict_types=1);

require_once __DIR__ . '/shared/autoload.php';

use App\Services\DatabaseService;

try {
    // Crear una conexión simple a la base de datos usando la configuración del proyecto
    $config = require __DIR__ . '/shared/Config/config.php';
    $dbConfig = $config['db'];
    
    // Mapear nombres de configuración
    $dbConfig = [
        'host' => $dbConfig['host'],
        'port' => $dbConfig['port'],
        'database' => $dbConfig['dbname'],
        'username' => $dbConfig['user'],
        'password' => $dbConfig['password'],
    ];
    
    $dsn = "pgsql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['database']}";
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== Verificando estado del cliente 'roton' ===\n\n";
    
    // 1. Verificar en tabla clients
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE rbfid = :rbfid");
    $stmt->execute([':rbfid' => 'roton']);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($client) {
        echo "Tabla 'clients':\n";
        foreach ($client as $key => $value) {
            echo "  $key: " . ($value === null ? 'NULL' : $value) . "\n";
        }
        echo "\n";
    } else {
        echo "Cliente 'roton' NO encontrado en tabla 'clients'\n\n";
    }
    
    // 2. Verificar en tabla ar_clients (sin columna enabled)
    $stmt = $pdo->prepare("SELECT rbfid, registered_at, last_sync_at FROM ar_clients WHERE rbfid = :rbfid");
    $stmt->execute([':rbfid' => 'roton']);
    $arClient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($arClient) {
        echo "Tabla 'ar_clients':\n";
        foreach ($arClient as $key => $value) {
            echo "  $key: " . ($value === null ? 'NULL' : $value) . "\n";
        }
        echo "\n";
    } else {
        echo "Cliente 'roton' NO encontrado en tabla 'ar_clients'\n\n";
    }
    
    // 3. Verificar la consulta que usa getClientStatus (actualizada)
    $stmt = $pdo->prepare("
        SELECT c.rbfid, c.emp, c.plaza, c.enabled, ar.registered_at, ar.last_sync_at
        FROM clients c
        LEFT JOIN ar_clients ar ON ar.rbfid = c.rbfid
        WHERE c.rbfid = :rbfid
    ");
    $stmt->execute([':rbfid' => 'roton']);
    $getClientStatusResult = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($getClientStatusResult) {
        echo "Consulta getClientStatus (actualizada):\n";
        foreach ($getClientStatusResult as $key => $value) {
            echo "  $key: " . ($value === null ? 'NULL' : $value) . "\n";
        }
        
        echo "\nAnálisis:\n";
        echo "  - clients.enabled: " . ($getClientStatusResult['enabled'] ? 'true' : 'false') . "\n";
        echo "  - NOTA: Ahora solo hay una fuente de verdad (clients.enabled)\n";
    }
    
    // 4. Verificar estructura de tabla ar_clients (actualizada)
    echo "\n=== Estructura de tabla ar_clients (sin columna enabled) ===\n";
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
    
    // 5. Verificar que la columna enabled ya no existe
    echo "\n=== Verificando que columna 'enabled' fue eliminada ===\n";
    $stmt = $pdo->prepare("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'ar_clients' AND column_name = 'enabled'
    ");
    $stmt->execute();
    $enabledColumn = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$enabledColumn) {
        echo "✓ Columna 'enabled' ya no existe en 'ar_clients'\n";
    } else {
        echo "✗ Columna 'enabled' todavía existe en 'ar_clients'\n";
    }
    
    // 6. Verificar todos los clientes AR (sin columna enabled)
    echo "\n=== Todos los clientes en ar_clients ===\n";
    $stmt = $pdo->prepare("
        SELECT rbfid, registered_at, last_sync_at
        FROM ar_clients
        ORDER BY rbfid
    ");
    $stmt->execute();
    $allArClients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Total: " . count($allArClients) . " clientes\n";
    foreach ($allArClients as $client) {
        echo "  {$client['rbfid']}: registered={$client['registered_at']}\n";
    }
    
    // 7. Verificar estado de clientes según clients.enabled
    echo "\n=== Estado de clientes según clients.enabled ===\n";
    $stmt = $pdo->prepare("
        SELECT c.rbfid, c.enabled, ar.registered_at
        FROM clients c
        LEFT JOIN ar_clients ar ON ar.rbfid = c.rbfid
        WHERE ar.rbfid IS NOT NULL
        ORDER BY c.rbfid
    ");
    $stmt->execute();
    $allClientsWithStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($allClientsWithStatus as $client) {
        $enabled = $client['enabled'] ? 'HABILITADO' : 'DESHABILITADO';
        echo "  {$client['rbfid']}: {$enabled} (registrado: {$client['registered_at']})\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}