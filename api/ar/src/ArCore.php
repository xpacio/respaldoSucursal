<?php
declare(strict_types=1);

require_once __DIR__ . '/Services/StorageService.php';
require_once __DIR__ . '/Services/DatabaseService.php';
require_once __DIR__ . '/Services/AuthService.php';
require_once __DIR__ . '/Services/UploadService.php';
require_once __DIR__ . '/Services/ClientService.php';
require_once __DIR__ . '/Services/ChunkService.php';
require_once __DIR__ . '/Services/SyncService.php';
require_once __DIR__ . '/Traits/Traits.php';

class ArCore {
    use ResponseTrait, LoggingTrait;

    private static ?ArCore $instance = null;
    
    public StorageService $storage;
    public DatabaseService $db;
    public AuthService $auth;
    public UploadService $upload;
    public ClientService $client;
    public ChunkService $chunk;
    public SyncService $sync;
    public $router;

    private function __construct($router) {
        $this->router = $router;
        $this->storage = new StorageService();
        $this->db = new DatabaseService($router->db);
        $this->auth = new AuthService($router->db);
        $this->upload = new UploadService($this->db, $this->storage);
        $this->client = new ClientService($this->db);
        $this->chunk = new ChunkService($this->db);
        $this->sync = new SyncService($this->db);
    }

    public static function getInstance($router = null): ArCore {
        if (self::$instance === null && $router !== null) {
            self::$instance = new ArCore($router);
        }
        return self::$instance;
    }
}
