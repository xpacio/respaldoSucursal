<?php

declare(strict_types=1);

define('TEST_MODE', true);

require_once __DIR__ . '/shared/autoload.php';
require_once __DIR__ . '/shared/Router.php';
require_once __DIR__ . '/api/routes/ar.php';

class MockDatabase {
    private array $arClients = [];

    public function fetchOne(string $sql, array $params = []): ?array {
        if (stripos($sql, 'SELECT rbfid FROM ar_clients') !== false) {
            $rbfid = $params[':rbfid'] ?? null;
            foreach ($this->arClients as $client) {
                if ($client['rbfid'] === $rbfid) {
                    return ['rbfid' => $rbfid];
                }
            }
            return null;
        }

        if (stripos($sql, 'SELECT COUNT(*)') !== false) {
            $rbfid = $params[':rbfid'] ?? null;
            $count = 0;
            foreach ($this->arClients as $client) {
                if ($client['rbfid'] === $rbfid) {
                    $count++;
                }
            }
            return ['total' => (string) $count];
        }

        return null;
    }

    public function fetchAll(string $sql, array $params = []): array {
        return [];
    }

    public function execute(string $sql, array $params = []): int {
        if (stripos($sql, 'INSERT INTO ar_clients') !== false) {
            $rbfid = $params[':rbfid'] ?? null;
            if ($rbfid === null) {
                return 0;
            }
            $exists = $this->fetchOne('SELECT rbfid FROM ar_clients WHERE rbfid = :rbfid', [':rbfid' => $rbfid]);
            if (!$exists) {
                $this->arClients[] = ['rbfid' => $rbfid, 'enabled' => true, 'registered_at' => date('Y-m-d H:i:s')];
                return 1;
            }
            return 0;
        }

        if (stripos($sql, 'DELETE FROM ar_clients') !== false) {
            $rbfid = $params[':rbfid'] ?? null;
            $before = count($this->arClients);
            $this->arClients = array_filter($this->arClients, static fn($client) => $client['rbfid'] !== $rbfid);
            return $before - count($this->arClients);
        }

        return 0;
    }
}

$db = new MockDatabase();

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
assertEquals(200, $router->testResponse['code'], 'La respuesta debe ser HTTP 200');
assertTrue(isset($router->testResponse['data']['ok']) && $router->testResponse['data']['ok'] === true, 'La respuesta debe contener ok=true');
assertEquals($rbfid, $router->testResponse['data']['rbfid'] ?? null, 'El rbfid devuelto debe coincidir');

$inserted = $db->fetchOne('SELECT rbfid FROM ar_clients WHERE rbfid = :rbfid', [':rbfid' => $rbfid]);
assertEquals($rbfid, $inserted['rbfid'] ?? null, 'El cliente debe haberse registrado en la base de datos');

route_ar_register($router, ['action' => 'register', 'rbfid' => $rbfid]);
assertEquals(200, $router->testResponse['code'], 'La segunda llamada debe devolver HTTP 200');
assertTrue(isset($router->testResponse['data']['ok']) && $router->testResponse['data']['ok'] === true, 'La segunda llamada debe devolver ok=true');

$count = $db->fetchOne('SELECT COUNT(*) AS total FROM ar_clients WHERE rbfid = :rbfid', [':rbfid' => $rbfid]);
assertEquals('1', $count['total'] ?? null, 'Debe existir un solo registro para el rbfid');

$db->execute('DELETE FROM ar_clients WHERE rbfid = :rbfid', [':rbfid' => $rbfid]);

echo "AR registration flow test passed\n";
