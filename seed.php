<?php

require __DIR__ . '/shared_server.php';
use App\DB;
use App\Config;

$db = new DB(Config::getDb());

$existing = $db->q("SELECT id FROM users LIMIT 1");
if ($existing) {
    echo "Ya existe al menos un usuario en la DB. No se creará otro.\n";
    exit(0);
}

$username = $argv[1] ?? 'admin';
$password = $argv[2] ?? 'admin123';
$nombre   = $argv[3] ?? 'Administrador';

$hash = password_hash($password, PASSWORD_BCRYPT);
$db->exec("INSERT INTO users (username, password, nombre, role) VALUES (:u, :p, :n, 'admin')", [
    ':u' => $username,
    ':p' => $hash,
    ':n' => $nombre,
]);

echo "Usuario creado: $username / $password (role: admin)\n";
