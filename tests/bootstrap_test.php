<?php
/**
 * Test básico de API3
 * Ejecutar: php -l tests/bootstrap_test.php && php tests/bootstrap_test.php
 */

require_once __DIR__ . '/../Config/config.php';
require_once __DIR__ . '/../Config/Database.php';
require_once __DIR__ . '/../Config/Logger.php';
require_once __DIR__ . '/../Services/SystemService.php';
require_once __DIR__ . '/../Services/ClientService.php';

echo "=== API3 Bootstrap Test ===\n\n";

$config = require __DIR__ . '/../Config/config.php';

echo "1. Config loaded: ";
echo isset($config['db']) && isset($config['paths']) ? "OK\n" : "FAIL\n";

echo "2. Database connection: ";
try {
    $db = new Database($config['db']);
    $result = $db->fetchOne("SELECT 1 as test");
    echo $result ? "OK\n" : "FAIL\n";
} catch (Exception $e) {
    echo "FAIL - " . $e->getMessage() . "\n";
}

echo "3. Logger: ";
try {
    $logger = new Logger($db);
    echo "OK\n";
} catch (Exception $e) {
    echo "FAIL - " . $e->getMessage() . "\n";
}

echo "4. System: ";
try {
    $system = new System($config);
    echo "OK\n";
} catch (Exception $e) {
    echo "FAIL - " . $e->getMessage() . "\n";
}

echo "5. ClientService: ";
try {
    $clientService = new ClientService($db, $logger, $system);
    echo "OK\n";
} catch (Exception $e) {
    echo "FAIL - " . $e->getMessage() . "\n";
}

echo "6. List clients: ";
try {
    $clients = $clientService->listClients();
    echo count($clients) . " clients found\n";
} catch (Exception $e) {
    echo "FAIL - " . $e->getMessage() . "\n";
}

echo "\n=== TestgetMessage() . Complete ===\n";
