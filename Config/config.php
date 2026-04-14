<?php
/**
 * Configuración centralizada v3
 */

return [
    'db' => [
        'host' => 'localhost',
        'port' => 5432,
        'dbname' => 'sync',
        'user' => 'postgres',
        'password' => ''
    ],
    'paths' => [
        'keys' => '/srv/app/keys',
        'gis_bin' => '/srv/app/bin',
        'home' => '/home'
    ],
    'ssh' => [
        'key_type' => 'ed25519',
        'restrict_command' => 'rsync-wrapper'
    ],
    'logging' => [
        'strategy' => 'database', // 'database', 'file', 'composite'
        'file_log_dir' => '/var/log/gis',
        'composite_strategies' => ['database', 'file'] // Solo si strategy es 'composite'
    ]
];
