<?php
/**
 * BackendWorker - Ejecuta jobs pesados en background
 * 
 * Singleton que procesa jobs encolados por ClientManager.
 * Cada job se ejecuta en un proceso PHP separado (exec).
 */

require_once __DIR__ . '/../Config/config.php';
require_once __DIR__ . '/../Config/Database.php';
require_once __DIR__ . '/../Config/Logger.php';
require_once __DIR__ . '/../Config/LoggerFactory.php';
require_once __DIR__ . '/../Config/PathUtils.php';
require_once __DIR__ . '/../Services/ServiceInterfaces.php';
require_once __DIR__ . '/../Services/SystemService.php';
require_once __DIR__ . '/../Services/UserService.php';
require_once __DIR__ . '/../Services/SshService.php';
require_once __DIR__ . '/../Services/MountService.php';
require_once __DIR__ . '/../Services/ClientService.php';
require_once __DIR__ . '/../Repositories/RepositoryInterface.php';
require_once __DIR__ . '/../Repositories/AbstractRepository.php';
require_once __DIR__ . '/../Repositories/ClientRepository.php';
require_once __DIR__ . '/../Repositories/OverlayRepository.php';
require_once __DIR__ . '/../Repositories/PlantillaRepository.php';
require_once __DIR__ . '/../Repositories/JobRepository.php';
require_once __DIR__ . '/../Repositories/DistribucionRepository.php';
require_once __DIR__ . '/../Repositories/RepositoryFactory.php';
require_once __DIR__ . '/../Services/DistribucionService.php';

class BackendWorker {
    private Database $db;
    private Logger $logger;
    private JobRepository $jobRepo;
    private ClientService $clientService;
    private MountService $mountService;
    private System $system;

    public function __construct(Database $db, Logger $logger, System $system) {
        $this->db = $db;
        $this->logger = $logger;
        $this->system = $system;
        $this->jobRepo = RepositoryFactory::getJobRepository($db);
        $this->clientService = new ClientService($db, $logger, $system);
        $this->mountService = new MountService($db, $logger, $system);
    }

    public static function create(): self {
        $config = require __DIR__ . '/../Config/config.php';
        $db = new Database($config['db']);
        $logger = LoggerFactory::createFromConfig($config, $db);
        $system = new System([]);
        return new self($db, $logger, $system);
    }

    /**
     * Encolar un job y lanzar worker en background
     */
    public function enqueue(string $action, array $params, string $createdBy = 'agent'): string {
        $jobId = $this->jobRepo->createJob($action, $params, $createdBy);
        
        $scriptPath = __DIR__ . '/../scripts/worker.php';
        $logFile = "/tmp/worker_{$jobId}.log";
        exec("php " . escapeshellarg($scriptPath) . " " . escapeshellarg($jobId) . " > " . escapeshellarg($logFile) . " 2>&1 &");
        
        return $jobId;
    }

    /**
     * Ejecutar un job (llamado por scripts/worker.php)
     */
    public function execute(string $jobId): void {
        $job = $this->jobRepo->getJob($jobId);
        if (!$job) {
            error_log("Worker: job $jobId not found");
            return;
        }

        if ($job['status'] !== 'queued') {
            error_log("Worker: job $jobId already {$job['status']}");
            return;
        }

        try {
            $params = json_decode($job['params'], true) ?? [];
            
            match ($job['action']) {
                'disable_clients' => $this->runDisableClients($jobId, $params),
                'enable_clients' => $this->runEnableClients($jobId, $params),
                'delete_clients' => $this->runDeleteClients($jobId, $params),
                'sync_overlays' => $this->runSyncOverlays($jobId, $params),
                'regen_ssh_keys' => $this->runRegenSshKeys($jobId, $params),
                'mount_all_overlays' => $this->runMountAllOverlays($jobId, $params),
                'unmount_all_overlays' => $this->runUnmountAllOverlays($jobId, $params),
                'distribuir_batch' => $this->runDistribuirBatch($jobId, $params),
                'distribuir_all' => $this->runDistribuirAll($jobId, $params),
                default => $this->jobRepo->markFailed($jobId, "Acción desconocida: {$job['action']}"),
            };
        } catch (\Exception $e) {
            $this->jobRepo->markFailed($jobId, $e->getMessage());
        }
    }

    // ─── Implementaciones de acciones ───

    private function runDisableClients(string $jobId, array $params): void {
        $clientIds = $params['client_ids'] ?? [];
        $total = count($clientIds);
        $results = ['success' => [], 'failed' => []];
        
        $this->jobRepo->markRunning($jobId, $total);
        
        foreach ($clientIds as $i => $clientId) {
            // Verificar si fue cancelado
            $job = $this->jobRepo->getJob($jobId);
            if ($job['status'] === 'cancelled') break;
            
            $result = $this->clientService->disable($clientId);
            if ($result['ok']) {
                $results['success'][] = $clientId;
            } else {
                $results['failed'][] = ['id' => $clientId, 'error' => $result['error'] ?? 'unknown'];
            }
            
            $this->jobRepo->updateProgress($jobId, $i + 1);
        }
        
        $this->jobRepo->markDone($jobId, $results);
    }

    private function runEnableClients(string $jobId, array $params): void {
        $clientIds = $params['client_ids'] ?? [];
        $total = count($clientIds);
        $results = ['success' => [], 'failed' => []];
        
        $this->jobRepo->markRunning($jobId, $total);
        
        foreach ($clientIds as $i => $clientId) {
            $job = $this->jobRepo->getJob($jobId);
            if ($job['status'] === 'cancelled') break;
            
            $result = $this->clientService->enable($clientId);
            if ($result['ok']) {
                $results['success'][] = $clientId;
            } else {
                $results['failed'][] = ['id' => $clientId, 'error' => $result['error'] ?? 'unknown'];
            }
            
            $this->jobRepo->updateProgress($jobId, $i + 1);
        }
        
        $this->jobRepo->markDone($jobId, $results);
    }

    private function runDeleteClients(string $jobId, array $params): void {
        $clientIds = $params['client_ids'] ?? [];
        $total = count($clientIds);
        $results = ['success' => [], 'failed' => []];
        
        $this->jobRepo->markRunning($jobId, $total);
        
        foreach ($clientIds as $i => $clientId) {
            $job = $this->jobRepo->getJob($jobId);
            if ($job['status'] === 'cancelled') break;
            
            $result = $this->clientService->delete($clientId, true);
            if ($result['ok']) {
                $results['success'][] = $clientId;
            } else {
                $results['failed'][] = ['id' => $clientId, 'error' => $result['error'] ?? 'unknown'];
            }
            
            $this->jobRepo->updateProgress($jobId, $i + 1);
        }
        
        $this->jobRepo->markDone($jobId, $results);
    }

    private function runSyncOverlays(string $jobId, array $params): void {
        $clientIds = $params['client_ids'] ?? [];
        if (empty($clientIds)) {
            $clients = $this->db->fetchAll("SELECT rbfid FROM clients WHERE enabled = 't'");
            $clientIds = array_column($clients, 'rbfid');
        }
        
        $total = count($clientIds);
        $results = ['success' => [], 'failed' => []];
        
        $this->jobRepo->markRunning($jobId, $total);
        
        foreach ($clientIds as $i => $clientId) {
            $job = $this->jobRepo->getJob($jobId);
            if ($job['status'] === 'cancelled') break;
            
            try {
                $syncService = new OverlaySyncService($this->db, $this->logger, $this->mountService);
                $result = $syncService->reconcileClientOverlays($clientId);
                if ($result['ok']) {
                    $results['success'][] = $clientId;
                } else {
                    $results['failed'][] = ['id' => $clientId, 'error' => $result['error'] ?? 'unknown'];
                }
            } catch (\Exception $e) {
                $results['failed'][] = ['id' => $clientId, 'error' => $e->getMessage()];
            }
            
            $this->jobRepo->updateProgress($jobId, $i + 1);
        }
        
        $this->jobRepo->markDone($jobId, $results);
    }

    private function runRegenSshKeys(string $jobId, array $params): void {
        $clientIds = $params['client_ids'] ?? [];
        if (empty($clientIds)) {
            $clients = $this->db->fetchAll("SELECT rbfid FROM clients");
            $clientIds = array_column($clients, 'rbfid');
        }
        
        $total = count($clientIds);
        $results = ['success' => [], 'failed' => []];
        
        $this->jobRepo->markRunning($jobId, $total);
        
        foreach ($clientIds as $i => $clientId) {
            $job = $this->jobRepo->getJob($jobId);
            if ($job['status'] === 'cancelled') break;
            
            $result = $this->clientService->renewKey($clientId);
            if ($result['ok']) {
                $results['success'][] = $clientId;
            } else {
                $results['failed'][] = ['id' => $clientId, 'error' => $result['error'] ?? 'unknown'];
            }
            
            $this->jobRepo->updateProgress($jobId, $i + 1);
        }
        
        $this->jobRepo->markDone($jobId, $results);
    }

    private function runMountAllOverlays(string $jobId, array $params): void {
        $clientIds = $params['client_ids'] ?? [];
        $total = count($clientIds);
        $results = ['success' => [], 'failed' => []];
        
        $this->jobRepo->markRunning($jobId, $total);
        
        foreach ($clientIds as $i => $clientId) {
            $job = $this->jobRepo->getJob($jobId);
            if ($job['status'] === 'cancelled') break;
            
            $overlays = $this->db->fetchAll(
                "SELECT id FROM overlays WHERE rbfid = :rbfid AND mounted = 'false'",
                [':rbfid' => $clientId]
            );
            
            $clientOk = true;
            foreach ($overlays as $overlay) {
                $result = $this->mountService->enableOverlay($overlay['id']);
                if (!$result['ok']) $clientOk = false;
            }
            
            if ($clientOk) {
                $results['success'][] = $clientId;
            } else {
                $results['failed'][] = ['id' => $clientId, 'error' => 'Algunos overlays fallaron'];
            }
            
            $this->jobRepo->updateProgress($jobId, $i + 1);
        }
        
        $this->jobRepo->markDone($jobId, $results);
    }

    private function runUnmountAllOverlays(string $jobId, array $params): void {
        $clientIds = $params['client_ids'] ?? [];
        $total = count($clientIds);
        $results = ['success' => [], 'failed' => []];
        
        $this->jobRepo->markRunning($jobId, $total);
        
        foreach ($clientIds as $i => $clientId) {
            $job = $this->jobRepo->getJob($jobId);
            if ($job['status'] === 'cancelled') break;
            
            $overlays = $this->db->fetchAll(
                "SELECT id FROM overlays WHERE rbfid = :rbfid AND mounted = 'true'",
                [':rbfid' => $clientId]
            );
            
            $clientOk = true;
            foreach ($overlays as $overlay) {
                $result = $this->mountService->disableOverlay($overlay['id']);
                if (!$result['ok']) $clientOk = false;
            }
            
            if ($clientOk) {
                $results['success'][] = $clientId;
            } else {
                $results['failed'][] = ['id' => $clientId, 'error' => 'Algunos overlays no se desmontaron'];
            }
            
            $this->jobRepo->updateProgress($jobId, $i + 1);
        }
        
        $this->jobRepo->markDone($jobId, $results);
    }

    // ─── Distribución ───

    private function runDistribuirBatch(string $jobId, array $params): void {
        $distIds = $params['distribucion_ids'] ?? [];
        $total = count($distIds);
        $results = ['success' => [], 'failed' => []];

        $this->jobRepo->markRunning($jobId, $total);
        $this->logger->startExecution('batch', "[DISTRIBUCION] batch ($total)");

        foreach ($distIds as $i => $distId) {
            $job = $this->jobRepo->getJob($jobId);
            if ($job['status'] === 'cancelled') break;

            $distId = intval($distId);
            $dist = $this->db->fetchOne("SELECT nombre, tipo FROM distribucion WHERE id = :id", [':id' => $distId]);
            $label = $dist ? "{$dist['nombre']}/{$dist['tipo']}" : "ID $distId";

            try {
                $svc = new DistribucionService($this->db, $this->logger);
                $result = $svc->copiar($distId);
                if ($result['ok']) {
                    $results['success'][] = ['id' => $distId, 'label' => $label, 'exitosos' => $result['exitosos'], 'total' => $result['total']];
                } else {
                    $results['failed'][] = ['id' => $distId, 'label' => $label, 'error' => $result['error'] ?? 'unknown'];
                }
            } catch (\Exception $e) {
                $results['failed'][] = ['id' => $distId, 'label' => $label, 'error' => $e->getMessage()];
            }

            $this->jobRepo->updateProgress($jobId, $i + 1);
        }

        $this->logger->finish(!empty($results['failed']) ? 'error' : 'success');
        $this->jobRepo->markDone($jobId, $results);
    }

    private function runDistribuirAll(string $jobId, array $params): void {
        $rows = $this->db->fetchAll("SELECT id, nombre, tipo FROM distribucion WHERE activa = true ORDER BY nombre, tipo");
        $total = count($rows);
        $results = ['success' => [], 'failed' => []];

        $this->jobRepo->markRunning($jobId, $total);
        $this->logger->startExecution('all', "[DISTRIBUCION] todas ($total)");

        foreach ($rows as $i => $dist) {
            $job = $this->jobRepo->getJob($jobId);
            if ($job['status'] === 'cancelled') break;

            $distId = intval($dist['id']);
            $label = "{$dist['nombre']}/{$dist['tipo']}";

            try {
                $svc = new DistribucionService($this->db, $this->logger);
                $result = $svc->copiar($distId);
                if ($result['ok']) {
                    $results['success'][] = ['id' => $distId, 'label' => $label, 'exitosos' => $result['exitosos'], 'total' => $result['total']];
                } else {
                    $results['failed'][] = ['id' => $distId, 'label' => $label, 'error' => $result['error'] ?? 'unknown'];
                }
            } catch (\Exception $e) {
                $results['failed'][] = ['id' => $distId, 'label' => $label, 'error' => $e->getMessage()];
            }

            $this->jobRepo->updateProgress($jobId, $i + 1);
        }

        $this->logger->finish(!empty($results['failed']) ? 'error' : 'success');
        $this->jobRepo->markDone($jobId, $results);
    }
}
