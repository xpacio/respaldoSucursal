<?php
/**
 * AdminService - Servicios de administración del sistema
 * Maneja operaciones de reset, limpieza de huérfanos, y operaciones globales
 */
require_once __DIR__ . '/ServiceInterfaces.php';
require_once __DIR__ . '/ClientService.php';
require_once __DIR__ . '/SshService.php';
require_once __DIR__ . '/UserService.php';

class AdminService {
    private Database $db;
    private Logger $logger;
    private SystemInterface $system;
    private ?ClientService $clientService = null;

    public function __construct(Database $db, Logger $logger, SystemInterface $system) {
        $this->db = $db;
        $this->logger = $logger;
        $this->system = $system;
    }

    public function setClientService(ClientService $clientService): void {
        $this->clientService = $clientService;
    }

    /**
     * Crear job de reset asincrónico
     */
    public function createResetJob(string $createdBy): array {
        $jobId = uniqid('reset_', true);
        
        $this->db->execute(
            "INSERT INTO procesos_desacoplados (job_id, job_type, state, created_by) 
             VALUES (:job_id, :job_type, :state, :created_by)",
            [':job_id' => $jobId, ':job_type' => 'system_reset', ':state' => 'pending', ':created_by' => $createdBy]
        );
        
        return ['ok' => true, 'job_id' => $jobId];
    }

    /**
     * Obtener estado de un job
     */
    public function getJobStatus(string $jobId): array {
        $job = $this->db->fetchOne(
            "SELECT * FROM procesos_desacoplados WHERE job_id = :job_id",
            [':job_id' => $jobId]
        );
        
        if (!$job) {
            return ['ok' => false, 'error' => 'Job no encontrado'];
        }
        
        return [
            'ok' => true,
            'job_id' => $job['job_id'],
            'state' => $job['state'],
            'current_step' => $job['current_step'],
            'steps_completed' => json_decode($job['steps_completed'] ?? '[]', true),
            'log' => $job['log'],
            'results' => json_decode($job['results'] ?? '{}', true),
            'started_at' => $job['started_at'],
            'completed_at' => $job['completed_at'],
            'error_message' => $job['error_message'],
            'progress' => ['current' => (int)$job['current_step'], 'total' => 5]
        ];
    }

    /**
     * Ejecutar reset asincrónico
     */
    public function executeAsyncReset(string $jobId): array {
        try {
            $this->updateJob($jobId, 'running', 'iniciando');
            
            $updateJobFn = function(string $step) use ($jobId) {
                $this->updateJob($jobId, 'running', $step);
            };
            
            $results = $this->doReset($updateJobFn);
            
            $this->updateJob(
                $jobId,
                'completed',
                null,
                json_encode($results)
            );
            
            return ['ok' => true, 'results' => $results];
            
        } catch (Exception $e) {
            $this->updateJob(
                $jobId,
                'failed',
                null,
                null,
                $e->getMessage()
            );
            throw $e;
        }
    }

    /**
     * Crear job de limpieza de cautivos
     */
    public function createLimpiarCautivosJob(string $createdBy): array {
        $jobId = 'limpiar_' . uniqid(true);
        
        $this->db->execute(
            "INSERT INTO procesos_desacoplados (job_id, job_type, state, created_by) 
             VALUES (:job_id, :job_type, :state, :created_by)",
            [':job_id' => $jobId, ':job_type' => 'limpiar_cautivos', ':state' => 'pending', ':created_by' => $createdBy]
        );
        
        return ['ok' => true, 'job_id' => $jobId];
    }

    /**
     * Ejecutar limpieza de cautivos asincrónico
     */
    public function executeLimpiarCautivosJob(string $jobId): array {
        try {
            $this->updateJob($jobId, 'running', 'iniciando');
            
            $this->db->execute("TRUNCATE TABLE clientes_cautivos RESTART IDENTITY CASCADE");
            
            $this->updateJob(
                $jobId,
                'completed',
                'completado',
                json_encode(['message' => 'Clientes cautivos eliminados'])
            );
            
            return ['ok' => true, 'message' => 'Clientes cautivos eliminados'];
            
        } catch (Exception $e) {
            $this->updateJob(
                $jobId,
                'failed',
                null,
                null,
                $e->getMessage()
            );
            throw $e;
        }
    }

    /**
     * Obtener todos los jobs en progreso
     */
    public function getActiveJobs(): array {
        $jobs = $this->db->fetchAll(
            "SELECT job_id, job_type, state, current_step, created_by, started_at, updated_at 
             FROM procesos_desacoplados 
             WHERE state IN ('pending', 'running') 
             ORDER BY started_at DESC"
        );
        
        return ['ok' => true, 'jobs' => $jobs];
    }

    /**
     * Crear job de regeneración de clientes desde cautivos
     */
    public function createRegenerateClientsJob(string $createdBy): array {
        $jobId = 'regenerar_' . uniqid(true);
        
        $this->db->execute(
            "INSERT INTO procesos_desacoplados (job_id, job_type, state, created_by) 
             VALUES (:job_id, :job_type, :state, :created_by)",
            [':job_id' => $jobId, ':job_type' => 'regenerar_cautivos', ':state' => 'pending', ':created_by' => $createdBy]
        );
        
        return ['ok' => true, 'job_id' => $jobId];
    }

    /**
     * Ejecutar regeneración de clientes desde cautivos (async)
     * BD es la fuente de verdad: si no está en BD, crear completo
     * Si ya está en BD, verificar y arreglar recursos faltantes
     */
    public function executeRegenerateClientsJob(string $jobId): array {
        try {
            $this->updateJob($jobId, 'running', 'iniciando');
            
            // Obtener clientes cautivos
            $cautivos = $this->db->fetchAll("SELECT * FROM clientes_cautivos ORDER BY rbfid");
            
            if (empty($cautivos)) {
                $this->updateJob(
                    $jobId,
                    'completed',
                    'completado',
                    json_encode(['message' => 'No hay clientes cautivos para regenerar'])
                );
                return ['ok' => true, 'message' => 'No hay clientes cautivos para regenerar'];
            }
            
            $total = count($cautivos);
            $created = 0;
            $synced = 0;
            $errors = [];
            
            $this->updateJob($jobId, 'running', "Procesando $total clientes cautivos");

            // Inicializar servicios
            if (!$this->clientService) {
                $userService = new UserService($this->db, $this->logger, $this->system);
                $sshService = new SshService($this->db, $this->logger, $this->system);
                $this->clientService = new ClientService($this->db, $this->logger, $this->system, $userService, $sshService);
            }
            
            $userService = $this->clientService->getUserService();
            $sshService = $this->clientService->getSshService();
            
            foreach ($cautivos as $i => $c) {
                $rbfid = $c['rbfid'];
                $emp = $c['emp'] ?? '';
                $plaza = $c['plaza'] ?? '';
                $username = "_$rbfid";
                $homeDir = "/home/$rbfid";
                
                $this->updateJob($jobId, 'running', "Procesando cliente $rbfid (" . ($i + 1) . "/$total)");
                
                // Crear execution para logging
                $execResult = $this->db->fetchOne(
                    "INSERT INTO executions (rbfid, action, status, source_type) VALUES (:rbfid, :action, :status, :source_type) RETURNING id",
                    [':rbfid' => $rbfid, ':action' => 'regenerar_cliente', ':status' => 'running', ':source_type' => 'db']
                );
                $execId = $execResult['id'] ?? null;
                
                $clientResult = [
                    'rbfid' => $rbfid,
                    'linux_user' => 'skipped',
                    'ssh' => 'skipped',
                    'bd' => 'skipped',
                    'overlays' => 'skipped',
                    'errors' => []
                ];
                
                try {
                    // Verificar si cliente existe en BD
                    $existsInDb = $this->db->fetchOne("SELECT rbfid, enabled FROM clients WHERE rbfid = :rbfid", [':rbfid' => $rbfid]);
                    
                    // 1. Verificar/Crear usuario Linux
                    $this->logStep($execId, 10, "Verificando usuario Linux $username");
                    $userResult = $userService->ensure($username, $homeDir);
                    $clientResult['linux_user'] = $userResult['action'] ?? 'error';
                    if (!$userResult['ok']) {
                        $clientResult['errors'][] = "Linux: " . ($userResult['error'] ?? 'failed');
                    }
                    
                    // 2. Generar SSH keys si no existen o verificar
                    if ($userResult['ok']) {
                        $this->logStep($execId, 20, "Verificando/generando SSH keys");
                        $keys = $sshService->generate($rbfid);
                        if (!$keys['ok']) {
                            $clientResult['errors'][] = "SSH keys: " . ($keys['error'] ?? 'failed');
                            $clientResult['ssh'] = 'error';
                        } else {
                            // 3. Verificar/arreglar authorized_keys
                            $this->logStep($execId, 30, "Verificando authorized_keys");
                            $authResult = $sshService->ensureAuthorizedKeys($username, $homeDir, $keys['public_key']);
                            $clientResult['ssh'] = $authResult['action'] ?? 'error';
                            if (!$authResult['ok']) {
                                $clientResult['errors'][] = "SSH config: " . ($authResult['error'] ?? 'failed');
                            }
                            
                            // 4. Insertar/verificar en BD
                            if (!$existsInDb) {
                                $this->logStep($execId, 40, "Insertando cliente en BD");
                                $this->db->execute(
                                    "INSERT INTO clients (rbfid, enabled, private_key, public_key, emp, plaza, key_download_enabled) VALUES (:rbfid, :enabled, :private_key, :public_key, :emp, :plaza, :key_download_enabled)",
                                    [':rbfid' => $rbfid, ':enabled' => 'true', ':private_key' => $keys['private_key'], ':public_key' => $keys['public_key'], ':emp' => $emp, ':plaza' => $plaza, ':key_download_enabled' => 'true']
                                );
                                $clientResult['bd'] = 'created';
                            } else {
                                $clientResult['bd'] = 'skipped';
                            }
                            
                            // 5. Aplicar overlays (solo si enabled)
                            $this->logStep($execId, 50, "Aplicando overlays");
                            $enabled = $existsInDb['enabled'] ?? 't';
                            if ($enabled === 't' || $enabled === true) {
                                $overlayResult = $this->clientService->applyTemplatesToClient($rbfid);
                                if ($overlayResult['ok']) {
                                    $clientResult['overlays'] = 'created';
                                } else {
                                    $clientResult['overlays'] = 'error';
                                    $clientResult['errors'][] = "Overlays: " . ($overlayResult['error'] ?? 'failed');
                                }
                            } else {
                                $clientResult['overlays'] = 'skipped';
                            }
                        }
                    }
                    
                    if ($existsInDb) {
                        $synced++;
                    } else {
                        $created++;
                    }
                    
                } catch (Exception $e) {
                    $clientResult['errors'][] = "Excepción: " . $e->getMessage();
                }
                
                // Actualizar execution con status final
                $status = empty($clientResult['errors']) ? 'success' : 'error';
                $this->db->execute(
                    "UPDATE executions SET status = :status, finished_at = NOW() WHERE id = :id",
                    [':status' => $status, ':id' => $execId]
                );
                
                if (!empty($clientResult['errors'])) {
                    $errors[] = $clientResult;
                }
                
                // Pequeña pausa entre clientes
                usleep(100000);
            }
            
            $result = [
                'total' => $total,
                'created' => $created,
                'synced' => $synced,
                'errors' => $errors
            ];
            
            $this->updateJob(
                $jobId,
                'completed',
                "Creados: $created, Sincronizados: $synced",
                json_encode($result)
            );
            
            return ['ok' => true, 'result' => $result];
            
        } catch (Exception $e) {
            $this->updateJob(
                $jobId,
                'failed',
                null,
                null,
                $e->getMessage()
            );
            throw $e;
        }
    }

    private function logStep(?string $execId, int $stepCode, string $message): void {
        if (!$execId) return;
        $this->db->execute(
            "INSERT INTO execution_steps (execution_id, step_code, step_message) VALUES (:execution_id, :step_code, :step_message)",
            [':execution_id' => $execId, ':step_code' => $stepCode, ':step_message' => $message]
        );
    }

    /**
     * Lógica compartida de reset (usada por fullReset y executeAsyncReset)
     * @param callable|null $stepCallback Callback para reportar progreso de cada paso
     */
    private function doReset(?callable $stepCallback = null): array {
        $report = function(string $step) use ($stepCallback) {
            if ($stepCallback) $stepCallback($step);
        };
        
        // Paso 1: Desmontar todos los overlays y binds (force para matar procesos)
        $report('unmount');
        $exitCode = null;
        $output = $this->system->sudo("/srv/app/stop_all.sh -f 2>&1", $exitCode);
        $unmountResult = [
            'ok' => $exitCode === 0,
            'exit_code' => $exitCode,
            'output' => $output
        ];
        
        // Paso 2: Eliminar TODOS los usuarios Linux (sin depender de BD)
        $report('cleanup_all_users');
        $allUsersResult = $this->cleanupAllSystemUsers();
        
        // Paso 3: Eliminar TODOS los homes residuales (respaldo, sin depender de BD)
        $report('cleanup_all_homes');
        $allHomesResult = $this->cleanupAllHomeDirs();
        
        // Paso 4: Limpiar BD (con try-catch, no debe impedir limpieza del sistema)
        $report('cleanup_db_state');
        try {
            $overlaysAffected = $this->db->execute("DELETE FROM overlays");
            $tempAffected = $this->db->execute("DELETE FROM overlay_cliente_mounts");
            $this->db->execute("TRUNCATE TABLE executions CASCADE");
            $this->db->execute("TRUNCATE TABLE execution_steps CASCADE");
            
            $dbCleanupResult = [
                'ok' => true,
                'overlays_deleted' => $overlaysAffected,
                'temp_mounts_deleted' => $tempAffected
            ];
        } catch (Exception $e) {
            $dbCleanupResult = ['ok' => false, 'error' => $e->getMessage()];
        }
        
        // Paso 5: Borrar clientes de la BD (con try-catch)
        $report('delete_clients');
        try {
            $affected = $this->db->execute("DELETE FROM clients");
            $deleteClientsResult = ['ok' => true, 'clients_deleted' => $affected];
        } catch (Exception $e) {
            $deleteClientsResult = ['ok' => false, 'error' => $e->getMessage()];
        }
        
        return [
            'unmount' => $unmountResult,
            'cleanup_all_users' => $allUsersResult,
            'cleanup_all_homes' => $allHomesResult,
            'db_cleanup' => $dbCleanupResult,
            'delete_clients' => $deleteClientsResult
        ];
    }

    private function updateJob(
        string $jobId,
        string $state,
        ?string $currentStep = null,
        ?string $results = null,
        ?string $errorMessage = null
    ): void {
        // Always set started_at on first 'running' state, always set updated_at
        $running = ($state === 'running' || $state === 'pending');
        
        if ($results !== null && $errorMessage !== null) {
            $params = [':state' => $state, ':current_step' => $currentStep, ':results' => $results, ':error_message' => $errorMessage, ':job_id' => $jobId];
            $sql = "UPDATE procesos_desacoplados SET state = :state, current_step = :current_step, results = :results, error_message = :error_message, updated_at = NOW()" . ($running ? ", started_at = NOW()" : "") . " WHERE job_id = :job_id";
        } elseif ($results !== null) {
            $params = [':state' => $state, ':current_step' => $currentStep, ':results' => $results, ':job_id' => $jobId];
            $sql = "UPDATE procesos_desacoplados SET state = :state, current_step = :current_step, results = :results, updated_at = NOW()" . ($running ? ", started_at = NOW()" : "") . " WHERE job_id = :job_id";
        } elseif ($errorMessage !== null) {
            $params = [':state' => $state, ':current_step' => $currentStep, ':error_message' => $errorMessage, ':job_id' => $jobId];
            $sql = "UPDATE procesos_desacoplados SET state = :state, current_step = :current_step, error_message = :error_message, updated_at = NOW()" . ($running ? ", started_at = NOW()" : "") . " WHERE job_id = :job_id";
        } elseif ($currentStep !== null) {
            $params = [':state' => $state, ':current_step' => $currentStep, ':job_id' => $jobId];
            $sql = "UPDATE procesos_desacoplados SET state = :state, current_step = :current_step, updated_at = NOW()" . ($running ? ", started_at = NOW()" : "") . " WHERE job_id = :job_id";
        } else {
            $params = [':state' => $state, ':job_id' => $jobId];
            $sql = "UPDATE procesos_desacoplados SET state = :state, updated_at = NOW()" . ($running ? ", started_at = NOW()" : "") . " WHERE job_id = :job_id";
        }
        
        $this->db->execute($sql, $params);
    }

    /**
     * Iniciar trabajo de reset (crea job para ejecución asíncrona)
     */
    public function disableAllSsh(): array {
        $this->logger->startExecution('admin', '[API] admin/disable-all-ssh');
        $this->logger->enterContext(1, 30, 99, "Deshabilitar SSH de todos los clientes");

        try {
            $this->logger->stepWithContext(1, 0, "Actualizando clientes en BD");
            
            $affected = $this->db->execute("UPDATE clients SET ssh_enabled = false");

            $this->logger->stepWithContext(2, 0, "Ejecutando stop_all.sh");
            
            $exitCode = null;
            $output = $this->system->sudo("/srv/app/stop_all.sh 2>&1", $exitCode);

            $this->logger->stepWithContext(3, 0, "Resultado: $affected clientes actualizados");

            $this->logger->exitContext();
            $this->logger->finish('success');

            return [
                'ok' => true,
                'message' => "SSH deshabilitado en $affected clientes",
                'clients_affected' => $affected,
                'shell_output' => $output
            ];
        } catch (Exception $e) {
            $this->logger->stepWithContext(99, 2, "Error: " . $e->getMessage());
            $this->logger->exitContext();
            $this->logger->finish('error');
            
            return [
                'ok' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Obtener lista de usuarios huérfanos (_xxxxx que no existen en la BD)
     */
    public function getOrphanUsers(): array {
        $this->logger->startExecution('admin', '[API] admin/orphans');
        $this->logger->enterContext(1, 30, 99, "Detectar usuarios huérfanos");

        try {
            $this->logger->stepWithContext(1, 0, "Obteniendo usuarios del sistema");
            
            $output = $this->system->sudo("getent passwd | grep -E '^_[a-z0-9]{5}:' | cut -d: -f1");
            $systemUsers = array_filter(array_map('trim', explode("\n", $output)));

            $this->logger->stepWithContext(2, 0, "Obteniendo clientes de BD");
            
            $dbClients = $this->db->fetchAll("SELECT rbfid FROM clients");
            $dbUserIds = array_column($dbClients, 'rbfid');

            $orphans = [];
            foreach ($systemUsers as $user) {
                $rbfid = ltrim($user, '_');
                if (!in_array($rbfid, $dbUserIds)) {
                    $homeDir = "/home/{$rbfid}";
                    $hasHome = is_dir($homeDir);
                    $orphans[] = [
                        'username' => $user,
                        'rbfid' => $rbfid,
                        'home_exists' => $hasHome,
                        'home_dir' => $homeDir
                    ];
                }
            }

            $this->logger->stepWithContext(3, 0, "Encontrados " . count($orphans) . " usuarios huérfanos");

            $this->logger->exitContext();
            $this->logger->finish('success');

            return [
                'ok' => true,
                'orphans' => $orphans,
                'total' => count($orphans)
            ];
        } catch (Exception $e) {
            $this->logger->stepWithContext(99, 2, "Error: " . $e->getMessage());
            $this->logger->exitContext();
            $this->logger->finish('error');
            
            return [
                'ok' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Eliminar usuarios huérfanos y sus directorios home
     */
    public function cleanupOrphans(): array {
        $this->logger->startExecution('admin', '[API] admin/orphans/cleanup');
        $this->logger->enterContext(1, 30, 99, "Limpiar usuarios huérfanos");

        try {
            $this->logger->stepWithContext(1, 0, "Obteniendo usuarios huérfanos");
            
            $orphansResult = $this->getOrphanUsers();
            if (!$orphansResult['ok']) {
                throw new Exception($orphansResult['error']);
            }
            
            $orphans = $orphansResult['orphans'];
            $deleted = [];
            $errors = [];

            foreach ($orphans as $orphan) {
                $username = $orphan['username'];
                
                $this->logger->stepWithContext(2, 0, "Eliminando usuario: $username");

                try {
                    $homeDir = $orphan['home_dir'];
                    
                    // Matar TODOS los procesos del usuario antes de cualquier limpieza
                    $this->system->sudo("fuser -k -9 " . escapeshellarg($homeDir) . " 2>/dev/null || true");
                    $this->system->sudo("pkill -u " . escapeshellarg($username) . " -9 2>/dev/null || true");
                    usleep(500000); // Esperar 500ms para que los procesos mueran
                    
                    if (!empty($homeDir) && is_dir($homeDir)) {
                        // Desmontar TODOS los mountpoints bajo home (deepest first)
                        $mounts = $this->system->sudo(
                            "findmnt -rn -o TARGET | grep '^" . escapeshellarg($homeDir) . "/' | sort -r 2>/dev/null || true"
                        );
                        $mountPoints = array_filter(array_map('trim', explode("\n", $mounts)));
                        foreach ($mountPoints as $mp) {
                            $this->system->sudo("umount -l " . escapeshellarg($mp) . " 2>/dev/null || true");
                        }
                    }
                    
                    $exitCode = 0;
                    $output = $this->system->sudo("userdel -r -f " . escapeshellarg($username) . " 2>&1", $exitCode);
                    
                    if ($exitCode === 0) {
                        $deleted[] = $username;
                    } else {
                        // Puede fallar si algún directorio adentro sigue montado (busy) o permisos
                        // Intentar forzar borrado del home si userdel quitó el usuario pero falló en el home
                        $this->system->sudo("rm -rf " . escapeshellarg($homeDir) . " 2>/dev/null || true");
                        
                        $errors[] = "$username: userdel failed - " . trim($output);
                    }
                } catch (Exception $e) {
                    $errors[] = "$username: " . $e->getMessage();
                }
            }

            $this->logger->stepWithContext(3, 0, "Eliminados: " . count($deleted) . ", Errores: " . count($errors));

            $this->logger->exitContext();
            $this->logger->finish('success');

            return [
                'ok' => true,
                'deleted' => $deleted,
                'errors' => $errors,
                'total_deleted' => count($deleted),
                'total_errors' => count($errors)
            ];
        } catch (Exception $e) {
            $this->logger->stepWithContext(99, 2, "Error: " . $e->getMessage());
            $this->logger->exitContext();
            $this->logger->finish('error');
            
            return [
                'ok' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Obtener lista de homes huérfanos (directorios /home/xxxxx sin usuario Linux _xxxxx)
     */
    public function getOrphanHomes(): array {
        $this->logger->startExecution('admin', '[API] admin/orphan-homes');
        $this->logger->enterContext(1, 30, 99, "Detectar homes huérfanos");

        try {
            $this->logger->stepWithContext(1, 0, "Obteniendo usuarios del sistema");
            
            // Obtener usuarios Linux existentes (_xxxxx)
            $output = $this->system->sudo("getent passwd | grep -E '^_[a-z0-9]{5}:' | cut -d: -f1");
            $systemUsers = array_filter(array_map('trim', explode("\n", $output)));

            $this->logger->stepWithContext(2, 0, "Escaneando directorios /home");
            
            $orphanHomes = [];
            $homePath = '/home';
            
            if (is_dir($homePath)) {
                $dirs = scandir($homePath);
                foreach ($dirs as $dir) {
                    if ($dir === '.' || $dir === '..') continue;
                    if (preg_match('/^[a-z0-9]{5}$/', $dir)) {
                        $fullPath = "$homePath/$dir";
                        if (!is_dir($fullPath)) continue;
                        
                        // Directorio existe (ej: roton), verificar si existe usuario _roton
                        $username = "_$dir";
                        if (!in_array($username, $systemUsers)) {
                            $orphanHomes[] = [
                                'path' => $fullPath,
                                'name' => $dir,
                                'size' => $this->getDirSize($fullPath)
                            ];
                        }
                    }
                }
            }

            $this->logger->stepWithContext(3, 0, "Encontrados " . count($orphanHomes) . " homes huérfanos");

            $this->logger->exitContext();
            $this->logger->finish('success');

            return [
                'ok' => true,
                'orphan_homes' => $orphanHomes,
                'total' => count($orphanHomes)
            ];
        } catch (Exception $e) {
            $this->logger->stepWithContext(99, 2, "Error: " . $e->getMessage());
            $this->logger->exitContext();
            $this->logger->finish('error');
            
            return [
                'ok' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Eliminar homes huérfanos (directorios sin usuario Linux)
     */
    public function cleanupOrphanHomes(): array {
        $this->logger->startExecution('admin', '[API] admin/orphan-homes/cleanup');
        $this->logger->enterContext(1, 30, 99, "Limpiar homes huérfanos");

        try {
            $this->logger->stepWithContext(1, 0, "Obteniendo homes huérfanos");
            
            $orphansResult = $this->getOrphanHomes();
            if (!$orphansResult['ok']) {
                throw new Exception($orphansResult['error']);
            }
            
            $orphanHomes = $orphansResult['orphan_homes'];
            $deleted = [];
            $errors = [];

            foreach ($orphanHomes as $orphan) {
                $homePath = $orphan['path'];
                $this->logger->stepWithContext(2, 0, "Eliminando: $homePath");

                try {
                    // Desmontar cualquier mount bajo este path
                    $mounts = $this->system->sudo(
                        "findmnt -rn -o TARGET | grep '^" . escapeshellarg($homePath) . "/\' | sort -r 2>/dev/null || true"
                    );
                    $mountPoints = array_filter(array_map('trim', explode("\n", $mounts)));
                    foreach ($mountPoints as $mp) {
                        $this->system->sudo("umount -l " . escapeshellarg($mp) . " 2>/dev/null || true");
                    }

                    // Eliminar directorio
                    $this->system->sudo("rm -rf " . escapeshellarg($homePath), $exitCode);
                    
                    if ($exitCode === 0 || !is_dir($homePath)) {
                        $deleted[] = $orphan['name'];
                    } else {
                        $errors[] = $orphan['name'] . ": no se pudo eliminar";
                    }
                } catch (Exception $e) {
                    $errors[] = $orphan['name'] . ": " . $e->getMessage();
                }
            }

            $this->logger->stepWithContext(3, 0, "Eliminados: " . count($deleted) . ", Errores: " . count($errors));

            $this->logger->exitContext();
            $this->logger->finish('success');

            return [
                'ok' => true,
                'deleted' => $deleted,
                'errors' => $errors,
                'total_deleted' => count($deleted),
                'total_errors' => count($errors)
            ];
        } catch (Exception $e) {
            $this->logger->stepWithContext(99, 2, "Error: " . $e->getMessage());
            $this->logger->exitContext();
            $this->logger->finish('error');
            
            return [
                'ok' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Eliminar TODOS los usuarios Linux del sistema (sin consultar BD)
     * Usado exclusivamente por doReset() para garantizar limpieza completa
     */
    private function cleanupAllSystemUsers(): array {
        $this->logger->startExecution('admin', '[RESET] cleanup_all_users');
        $this->logger->enterContext(1, 30, 99, "Eliminar todos los usuarios del sistema");

        try {
            $this->logger->stepWithContext(1, 0, "Obteniendo usuarios del sistema");

            $output = $this->system->sudo("getent passwd | grep -E '^_[a-z0-9]{5}:' | cut -d: -f1");
            $systemUsers = array_filter(array_map('trim', explode("\n", $output)));

            if (empty($systemUsers)) {
                $this->logger->stepWithContext(2, 0, "No hay usuarios que eliminar");
                $this->logger->exitContext();
                $this->logger->finish('success');
                return ['ok' => true, 'deleted' => [], 'errors' => [], 'total_deleted' => 0, 'total_errors' => 0];
            }

            $deleted = [];
            $errors = [];

            foreach ($systemUsers as $username) {
                $rbfid = ltrim($username, '_');
                $homeDir = "/home/{$rbfid}";

                $this->logger->stepWithContext(2, 0, "Eliminando: $username (home: $homeDir)");

                try {
                    // Matar procesos usando el home
                    $this->system->sudo("fuser -k -9 " . escapeshellarg($homeDir) . " 2>/dev/null || true");
                    // Matar procesos del usuario
                    $this->system->sudo("pkill -u " . escapeshellarg($username) . " -9 2>/dev/null || true");
                    usleep(500000);

                    // Desmontar overlays bajo el home
                    if (is_dir($homeDir)) {
                        $mounts = $this->system->sudo(
                            "findmnt -rn -o TARGET | grep '^" . escapeshellarg($homeDir) . "/' | sort -r 2>/dev/null || true"
                        );
                        $mountPoints = array_filter(array_map('trim', explode("\n", $mounts)));
                        foreach ($mountPoints as $mp) {
                            $this->system->sudo("umount -l " . escapeshellarg($mp) . " 2>/dev/null || true");
                        }
                    }

                    // Eliminar usuario y home
                    $exitCode = 0;
                    $output = $this->system->sudo("userdel -r -f " . escapeshellarg($username) . " 2>&1", $exitCode);

                    if ($exitCode === 0) {
                        $deleted[] = $username;
                    } else {
                        // Forzar borrado del home si userdel falló
                        $this->system->sudo("rm -rf " . escapeshellarg($homeDir) . " 2>/dev/null || true");
                        $errors[] = "$username: userdel failed - " . trim($output);
                    }
                } catch (Exception $e) {
                    $errors[] = "$username: " . $e->getMessage();
                }
            }

            $this->logger->stepWithContext(3, 0, "Eliminados: " . count($deleted) . ", Errores: " . count($errors));
            $this->logger->exitContext();
            $this->logger->finish('success');

            return [
                'ok' => true,
                'deleted' => $deleted,
                'errors' => $errors,
                'total_deleted' => count($deleted),
                'total_errors' => count($errors)
            ];
        } catch (Exception $e) {
            $this->logger->stepWithContext(99, 2, "Error: " . $e->getMessage());
            $this->logger->exitContext();
            $this->logger->finish('error');

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Eliminar TODOS los directorios /home/xxxxx (sin consultar BD ni getent)
     * Usado exclusivamente por doReset() como respaldo final
     */
    private function cleanupAllHomeDirs(): array {
        $this->logger->startExecution('admin', '[RESET] cleanup_all_homes');
        $this->logger->enterContext(1, 30, 99, "Eliminar todos los homes de clientes");

        try {
            $this->logger->stepWithContext(1, 0, "Escaneando /home");

            $homePath = '/home';
            $deleted = [];
            $errors = [];

            if (!is_dir($homePath)) {
                $this->logger->stepWithContext(2, 0, "/home no existe");
                $this->logger->exitContext();
                $this->logger->finish('success');
                return ['ok' => true, 'deleted' => [], 'errors' => [], 'total_deleted' => 0, 'total_errors' => 0];
            }

            $dirs = scandir($homePath);
            foreach ($dirs as $dir) {
                if ($dir === '.' || $dir === '..') continue;
                if (!preg_match('/^[a-z0-9]{5}$/', $dir)) continue;

                $fullPath = "$homePath/$dir";
                if (!is_dir($fullPath)) continue;

                $this->logger->stepWithContext(2, 0, "Eliminando: $fullPath");

                try {
                    // Matar procesos usando este directorio
                    $this->system->sudo("fuser -k -9 " . escapeshellarg($fullPath) . " 2>/dev/null || true");
                    usleep(200000);

                    // Desmontar cualquier mount bajo este path
                    $mounts = $this->system->sudo(
                        "findmnt -rn -o TARGET | grep '^" . escapeshellarg($fullPath) . "/' | sort -r 2>/dev/null || true"
                    );
                    $mountPoints = array_filter(array_map('trim', explode("\n", $mounts)));
                    foreach ($mountPoints as $mp) {
                        $this->system->sudo("umount -l " . escapeshellarg($mp) . " 2>/dev/null || true");
                    }

                    // Eliminar directorio
                    $exitCode = 0;
                    $this->system->sudo("rm -rf " . escapeshellarg($fullPath), $exitCode);

                    if ($exitCode === 0 || !is_dir($fullPath)) {
                        $deleted[] = $dir;
                    } else {
                        $errors[] = "$dir: no se pudo eliminar";
                    }
                } catch (Exception $e) {
                    $errors[] = "$dir: " . $e->getMessage();
                }
            }

            $this->logger->stepWithContext(3, 0, "Eliminados: " . count($deleted) . ", Errores: " . count($errors));
            $this->logger->exitContext();
            $this->logger->finish('success');

            return [
                'ok' => true,
                'deleted' => $deleted,
                'errors' => $errors,
                'total_deleted' => count($deleted),
                'total_errors' => count($errors)
            ];
        } catch (Exception $e) {
            $this->logger->stepWithContext(99, 2, "Error: " . $e->getMessage());
            $this->logger->exitContext();
            $this->logger->finish('error');

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function getDirSize(string $path): string {
        $output = $this->system->sudo("du -sh " . escapeshellarg($path) . " 2>/dev/null | cut -f1");
        return trim($output) ?: '0';
    }
}
