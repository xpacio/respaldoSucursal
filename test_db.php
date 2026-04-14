<?php
$config = require 'shared/Config/config.php';
require_once 'shared/Config/Database.php';

$dbConfig = $config['db'];
$db = new Database($dbConfig);

try {
    $result = $db->fetchOne("SELECT 1 as test");
    echo "Conexión exitosa: " . $result['test'] . "\n";
} catch (Exception $e) {
    echo "Error de conexión: " . $e->getMessage() . "\n";
}
?>