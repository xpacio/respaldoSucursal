<?php

declare(strict_types=1);

define('TEST_MODE', true);

require_once __DIR__ . '/shared/autoload.php';
require_once __DIR__ . '/shared/Config/config.php';
require_once __DIR__ . '/shared/Config/Database.php';
require_once __DIR__ . '/shared/Router.php';
require_once __DIR__ . '/api/routes/ar.php';

$config = require __DIR__ . '/shared/Config/config.php';
$db = new Database($config['db']);

function assertEquals($expected, $actual, string $message = ''): void {
    if ($expected !== $actual) {
        echo "ASSERT FAILED: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n";
        exit(1);
    }
}

function assertTrue($value, string $message = ''): void {
    if ($value !== true) {
        echo "ASSERT FAILED: {$message}\nExpected true, got: " . var_export($value, true) . "\n";
        exit(1);
    }
}

$router = Router::getInstance();
$router->db = $db;
$router->logger = null;

$rbfid = 'test_rbfid_' . bin2hex(random_bytes(4));

try {
    $db->execute('DELETE FROM ar_clients WHERE rbfid = :rbfid', [':rbfid' => $rbfid]);
} catch (Exception $e) {
    echo "ERROR: No se pudo limpiar registro previo, comprueba que la tabla ar_clients exista y la DB esté accesible.\n";
    echo $e->getMessage() . "\n";
    exit(1);
}

route_ar_register($router, ['action' => 'register', 'rbfid' => $rbfid]);
assertEquals(200, $router->json['code'], 'La respuesta debe ser HTTP 200');
assertTrue(isset($router->json['data']['ok']) && $router->json['data']['ok'] === true, 'La respuesta debe contener ok=true');
assertEquals($rbfid, $router->json['data']['rbfid'] ?? null, 'El rbfid devuelto debe coincidir');

$inserted = $db->fetchOne('SELECT rbfid FROM ar_clients WHERE rbfid = :rbfid', [':rbfid' => $rbfid]);
assertEquals($rbfid, $inserted['rbfid'] ?? null, 'El cliente debe haberse registrado en la base de datos');

route_ar_register($router, ['action' => 'register', 'rbfid' => $rbfid]);
assertEquals(200, $router->json['code'], 'La segunda llamada debe devolver HTTP 200');
assertTrue(isset($router->json['data']['ok']) && $router->json['data']['ok'] === true, 'La segunda llamada debe devolver ok=true');

$count = $db->fetchOne('SELECT COUNT(*) AS total FROM ar_clients WHERE rbfid = :rbfid', [':rbfid' => $rbfid]);
assertEquals('1', $count['total'] ?? null, 'Debe existir un solo registro para el rbfid');

$db->execute('DELETE FROM ar_clients WHERE rbfid = :rbfid', [':rbfid' => $rbfid]);

echo "AR registration flow test passed\n";
