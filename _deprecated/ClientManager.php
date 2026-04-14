<?php
/**
 * ClientManager - Singleton que maneja toda interacción de la UI
 * 
 * Para operaciones de 1 cliente: ejecuta directamente
 * Para operaciones de N clientes: crea job y retorna job_id
 */
require_once __DIR__ . '/../Config/PathUtils.php';
require_once __DIR__ . '/../Services/BackendWorker.php';

class ClientManager {
    private static ?self $instance = null;
    
    private Database $db;
    private Logger $logger;
    private System $system;
    private ClientService $clientService;
    private MountService $mountService;
    private SshService $sshService;
    private BackendWorker $worker;
    private JobRepository $jobRepo;
    
    private function __construct(Database $db, Logger $logger, System $system) {
        $this->db = $db;
        $this->logger = $logger;
        $this->system = $system;
        $this->clientService = new ClientService($db, $logger, $system);
        $this->mountService = new MountService($db, $logger, $system);
        $this->sshService = new SshService($db, $logger, $system);
        $this->worker = new BackendWorker($db, $logger, $system);
        $this->jobRepo = RepositoryFactory::getJobRepository($db);
    }
    
    public static function getInstance(Database $db, Logger $logger, System $system): self {
        if (self::$instance === null) {
            self::$instance = new self($db, $logger, $system);
        }
        return self::$instance;
    }
    
    // ─── Operaciones de 1 cliente (ejecución directa) ───
    
    public function validate(string $clientId, string $emp = '', string $plaza = ''): array {
        if (!preg_match('/^[a-z0-9]{5}$/', $clientId)) {
            return ['ok' => false, 'error' => 'Client ID debe ser 5 caracteres alfanuméricos', 'code' => 'INVALID_CLIENT_ID'];
        }
        if ($emp && !preg_match('/^[a-zA-Z0-9]{0,3}$/', $emp)) {
            return ['ok' => false, 'error' => 'emp debe ser máximo 3 caracteres alfanuméricos', 'code' => 'INVALID_EMP'];
        }
        if ($plaza && !preg_match('/^[a-zA-Z0-9]{0,5}$/', $plaza)) {
            return ['ok' => false, 'error' => 'plaza debe ser máximo 5 caracteres alfanuméricos', 'code' => 'INVALID_PLAZA'];
        }
        
        $existing = $this->db->fetchOne("SELECT rbfid FROM clients WHERE rbfid = :rbfid", [':rbfid' => $clientId]);
        if ($existing) {
            return ['ok' => false, 'error' => 'Cliente ya existe', 'code' => 'CLIENT_ALREADY_EXISTS'];
        }
        
        return ['ok' => true];
    }
    
    public function createUser(string $clientId): array {
        $username = "_$clientId";
        $homeDir = "/home/$clientId";
        
        $userService = new UserService($this->db, $this->logger, $this->system);
        $result = $userService->create($username, $homeDir);
        
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?? 'useradd failed', 'code' => 'USER_CREATE_FAILED'];
        }
        
        return ['ok' => true, 'data' => ['username' => $username, 'homeDir' => $homeDir]];
    }
    
    public function deleteUser(string $clientId): array {
        $username = "_$clientId";
        $homeDir = "/home/$clientId";
        
        $userService = new UserService($this->db, $this->logger, $this->system);
        $userService->delete($username);
        
        try {
            $this->system->sudo("pkill -u " . escapeshellarg($username) . " 2>/dev/null || true");
            $this->system->sudo("userdel -r -f " . escapeshellarg($username) . " 2>/dev/null || true");
            $this->system->sudo("rm -rf " . escapeshellarg($homeDir) . " 2>/dev/null || true");
        } catch (\Exception $e) {
            // Best effort cleanup
        }
        
        return ['ok' => true];
    }
    
    public function generateSsh(string $clientId): array {
        $keys = $this->sshService->generate($clientId);
        if (!$keys['ok']) {
            return ['ok' => false, 'error' => $keys['error'] ?? 'SSH keygen failed', 'code' => 'SSH_KEYGEN_FAILED'];
        }
        
        $username = "_$clientId";
        $homeDir = "/home/$clientId";
        $this->sshService->configureAuthorizedKeys($username, $homeDir, $keys['public_key']);
        
        return ['ok' => true, 'data' => ['public_key' => $keys['public_key'], 'private_key' => $keys['private_key']]];
    }
    
    public function saveToDb(string $clientId, bool $enabled, string $emp, string $plaza, string $privateKey, string $publicKey): array {
        try {
            $this->db->execute(
                "INSERT INTO clients (rbfid, enabled, private_key, public_key, emp, plaza) VALUES (:rbfid, :enabled, :private_key, :public_key, :emp, :plaza)",
                [':rbfid' => $clientId, ':enabled' => $enabled ? 'true' : 'false', ':private_key' => $privateKey, ':public_key' => $publicKey, ':emp' => $emp, ':plaza' => $plaza]
            );
            return ['ok' => true];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'code' => 'DB_INSERT_FAILED'];
        }
    }
    
    public function deleteFromDb(string $clientId): array {
        try {
            $this->db->execute("DELETE FROM overlays WHERE rbfid = :rbfid", [':rbfid' => $clientId]);
            $this->db->execute("DELETE FROM overlay_cliente_mounts WHERE rbfid = :rbfid", [':rbfid' => $clientId]);
            $this->db->execute("DELETE FROM clients WHERE rbfid = :rbfid", [':rbfid' => $clientId]);
            return ['ok' => true];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'code' => 'DB_DELETE_FAILED'];
        }
    }
    
    public function updateDbState(string $clientId, bool $enabled, bool $sshEnabled): array {
        try {
            $clientRepo = RepositoryFactory::getClientRepository($this->db);
            $clientRepo->toggleEnabled($clientId, $enabled);
            $clientRepo->toggleSsh($clientId, $sshEnabled);
            return ['ok' => true];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'code' => 'DB_UPDATE_FAILED'];
        }
    }
    
    public function enableSsh(string $clientId): array {
        $username = "_$clientId";
        $result = $this->sshService->enable($username);
        return $result['ok'] ? ['ok' => true] : ['ok' => false, 'error' => 'SSH enable failed', 'code' => 'SSH_ENABLE_FAILED'];
    }
    
    public function disableSsh(string $clientId): array {
        $username = "_$clientId";
        $result = $this->sshService->disable($username);
        return $result['ok'] ? ['ok' => true] : ['ok' => false, 'error' => 'SSH disable failed', 'code' => 'SSH_DISABLE_FAILED'];
    }
    
    public function saveKeys(string $clientId, string $privateKey, string $publicKey): array {
        try {
            $this->db->execute(
                "UPDATE clients SET private_key = :private_key, public_key = :public_key, ssh_regen_count = ssh_regen_count + 1, ssh_regen_last = NOW(), key_download_enabled = 'true', key_downloaded_at = NULL WHERE rbfid = :rbfid",
                [':private_key' => $privateKey, ':public_key' => $publicKey, ':rbfid' => $clientId]
            );
            return ['ok' => true];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'code' => 'DB_UPDATE_FAILED'];
        }
    }
    
    public function enableUser(string $clientId): array {
        $username = "_$clientId";
        $userService = new UserService($this->db, $this->logger, $this->system);
        $result = $userService->enable($username);
        return $result['ok'] ? ['ok' => true] : ['ok' => false, 'error' => 'User enable failed', 'code' => 'USER_ENABLE_FAILED'];
    }
    
    public function disableUser(string $clientId): array {
        $username = "_$clientId";
        $userService = new UserService($this->db, $this->logger, $this->system);
        $result = $userService->disable($username);
        return $result['ok'] ? ['ok' => true] : ['ok' => false, 'error' => 'User disable failed', 'code' => 'USER_DISABLE_FAILED'];
    }
    
    public function mountAllOverlays(string $clientId): array {
        $overlays = $this->db->fetchAll(
            "SELECT id FROM overlays WHERE rbfid = :rbfid AND mounted = 'false'",
            [':rbfid' => $clientId]
        );
        
        $results = ['succeeded' => [], 'failed' => []];
        foreach ($overlays as $overlay) {
            $result = $this->mountService->enableOverlay($overlay['id']);
            if ($result['ok']) {
                $results['succeeded'][] = $overlay['id'];
            } else {
                $results['failed'][] = ['id' => $overlay['id'], 'error' => $result['error']];
            }
        }
        
        return ['ok' => true, 'data' => $results];
    }
    
    public function unmountAllOverlays(string $clientId): array {
        $overlays = $this->db->fetchAll(
            "SELECT id FROM overlays WHERE rbfid = :rbfid AND mounted = 'true'",
            [':rbfid' => $clientId]
        );
        
        $results = ['succeeded' => [], 'failed' => []];
        foreach ($overlays as $overlay) {
            $result = $this->mountService->disableOverlay($overlay['id']);
            if ($result['ok']) {
                $results['succeeded'][] = $overlay['id'];
            } else {
                $results['failed'][] = ['id' => $overlay['id'], 'error' => $result['error']];
            }
        }
        
        return ['ok' => true, 'data' => $results];
    }
    
    public function applyTemplates(string $clientId): array {
        $result = $this->clientService->applyTemplatesToClient($clientId);
        return $result;
    }
    
    // ─── Operaciones bulk (delegan al BackendWorker) ───
    
    public function disableClients(array $clientIds, string $createdBy = 'agent'): array {
        $jobId = $this->worker->enqueue('disable_clients', ['client_ids' => $clientIds], $createdBy);
        return ['ok' => true, 'job_id' => $jobId, 'status' => 'queued'];
    }
    
    public function enableClients(array $clientIds, string $createdBy = 'agent'): array {
        $jobId = $this->worker->enqueue('enable_clients', ['client_ids' => $clientIds], $createdBy);
        return ['ok' => true, 'job_id' => $jobId, 'status' => 'queued'];
    }
    
    public function deleteClients(array $clientIds, string $createdBy = 'agent'): array {
        $jobId = $this->worker->enqueue('delete_clients', ['client_ids' => $clientIds], $createdBy);
        return ['ok' => true, 'job_id' => $jobId, 'status' => 'queued'];
    }
    
    public function syncAllOverlays(array $clientIds = [], string $createdBy = 'agent'): array {
        $jobId = $this->worker->enqueue('sync_overlays', ['client_ids' => $clientIds], $createdBy);
        return ['ok' => true, 'job_id' => $jobId, 'status' => 'queued'];
    }
    
    public function regenAllSshKeys(array $clientIds = [], string $createdBy = 'agent'): array {
        $jobId = $this->worker->enqueue('regen_ssh_keys', ['client_ids' => $clientIds], $createdBy);
        return ['ok' => true, 'job_id' => $jobId, 'status' => 'queued'];
    }
    
    public function mountAllClientsOverlays(array $clientIds, string $createdBy = 'agent'): array {
        $jobId = $this->worker->enqueue('mount_all_overlays', ['client_ids' => $clientIds], $createdBy);
        return ['ok' => true, 'job_id' => $jobId, 'status' => 'queued'];
    }
    
    public function unmountAllClientsOverlays(array $clientIds, string $createdBy = 'agent'): array {
        $jobId = $this->worker->enqueue('unmount_all_overlays', ['client_ids' => $clientIds], $createdBy);
        return ['ok' => true, 'job_id' => $jobId, 'status' => 'queued'];
    }
    
    // ─── Job status ───
    
    public function getJobStatus(string $jobId): array {
        return $this->jobRepo->getJobStatusResponse($jobId);
    }
    
    public function cancelJob(string $jobId): array {
        $cancelled = $this->jobRepo->cancelJob($jobId);
        return $cancelled
            ? ['ok' => true, 'message' => 'Job cancelado']
            : ['ok' => false, 'error' => 'No se pudo cancelar (ya terminó o no existe)', 'code' => 'JOB_CANCEL_FAILED'];
    }
    
    public function listJobs(string $status = 'running', int $limit = 50): array {
        $jobs = $this->jobRepo->getJobsByStatus($status, $limit);
        return ['ok' => true, 'jobs' => $jobs];
    }
}
