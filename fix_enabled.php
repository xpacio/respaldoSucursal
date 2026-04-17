<?php

declare(strict_types=1);

require_once __DIR__ . '/shared/autoload.php';

use App\Services\DatabaseService;

try {
    // Crear una conexión simple a la base de datos usando la configuración del proyecto
    $config = require __DIR__ . '/shared/Config/config.php';
    $dbConfig = $config['db'];
    
    $dsn = "pgsql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['dbname']}";
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== Arreglando campo 'enabled' para cliente 'roton' ===\n\n";
    
    // 1. Verificar estado actual
    $stmt = $pdo->prepare("SELECT rbfid, enabled FROM ar_clients WHERE rbfid = :rbfid");
    $stmt->execute([':rbfid' => 'roton']);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$current) {
        echo "Cliente 'roton' no encontrado en ar_clients\n";
        exit(1);
    }
    
    $currentEnabled = $current['enabled'] ? 'true' : 'false';
    echo "Estado actual: enabled = {$currentEnabled}\n";
    
    // 2. Actualizar a true
    $stmt = $pdo->prepare("UPDATE ar_clients SET enabled = true WHERE rbfid = :rbfid");
    $stmt->execute([':rbfid' => 'roton']);
    $rowsUpdated = $stmt->rowCount();
    
    echo "Filas actualizadas: {$rowsUpdated}\n";
    
    // 3. Verificar estado después de la actualización
    $stmt = $pdo->prepare("SELECT rbfid, enabled FROM ar_clients WHERE rbfid = :rbfid");
    $stmt->execute([':rbfid' => 'roton']);
    $updated = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $updatedEnabled = $updated['enabled'] ? 'true' : 'false';
    echo "Estado después: enabled = {$updatedEnabled}\n";
    
    // 4. También actualizar otros clientes que deberían estar enabled
    echo "\n=== Actualizando otros clientes ===\n";
    
    // Lista de clientes que deberían estar enabled (basado en clients.enabled = true)
    $stmt = $pdo->prepare("
        SELECT c.rbfid 
        FROM clients c 
        LEFT JOIN ar_clients ar ON ar.rbfid = c.rbfid 
        WHERE c.enabled = true AND (ar.enabled = false OR ar.enabled IS NULL)
    ");
    $stmt->execute();
    $clientsToEnable = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
    if (count($clientsToEnable) > 0) {
        echo "Clientes a habilitar: " . implode(', ', $clientsToEnable) . "\n";
        
        foreach ($clientsToEnable as $rbfid) {
            $stmt = $pdo->prepare("UPDATE ar_clients SET enabled = true WHERE rbfid = :rbfid");
            $stmt->execute([':rbfid' => $rbfid]);
            echo "  - {$rbfid}: actualizado\n";
        }
    } else {
        echo "No hay más clientes que necesiten actualización\n";
    }
    
    echo "\n=== Operación completada ===\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}