<?php
/**
 * ServiceFactory - Factory Method Pattern para crear servicios
 * Permite mejor testabilidad y flexibilidad en la creación de servicios
 */
class ServiceFactory {
    private Database $db;
    private Logger $logger;
    private SystemInterface $system;
    
    public function __construct(Database $db, Logger $logger, SystemInterface $system) {
        $this->db = $db;
        $this->logger = $logger;
        $this->system = $system;
    }
    
    /**
     * Crear ClientService
     */
    public function createClientService(): ClientServiceInterface {
        // Use minimal version to avoid dependency hell
        require_once __DIR__ . '/ClientService_Minimal.php';
        return new ClientService($this->db, $this->logger, $this->system);
    }
    
    /**
     * Crear UserService
     */
    public function createUserService(): UserServiceInterface {
        return new UserService($this->db, $this->logger, $this->system);
    }
    
    /**
     * Crear SshService
     */
    public function createSshService(): SshServiceInterface {
        return new SshService($this->db, $this->logger, $this->system);
    }
    
    /**
     * Crear MountService
     */
    public function createMountService(): MountServiceInterface {
        return new MountService($this->db, $this->logger, $this->system);
    }

    /**
     * Crear OverlaySyncService
     */
    public function createOverlaySyncService(): OverlaySyncServiceInterface {
        return new OverlaySyncService(
            $this->db,
            $this->logger,
            $this->createMountService()
        );
    }
    
    /**
     * Crear System (wrapper de comandos)
     */
    public static function createSystem(array $config): SystemInterface {
        return new System($config);
    }
    
    /**
     * Factory method estático para crear todos los servicios
     */
    public static function createServices(array $config, Database $db, Logger $logger): array {
        $system = self::createSystem($config);
        $factory = new self($db, $logger, $system);
        
        return [
            'clientService' => $factory->createClientService(),
            'userService' => $factory->createUserService(),
            'sshService' => $factory->createSshService(),
            'mountService' => $factory->createMountService(),
            'overlaySyncService' => $factory->createOverlaySyncService(),
            'system' => $system,
            'factory' => $factory
        ];
    }
}