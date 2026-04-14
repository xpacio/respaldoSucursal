<?php
require_once __DIR__ . '/ServiceInterfaces.php';
// require_once __DIR__ . '/UserService.php';
// require_once __DIR__ . '/SshService.php';
// require_once __DIR__ . '/MountService.php';
// require_once __DIR__ . '/../Repositories/RepositoryFactory.php';
// require_once __DIR__ . '/../Config/PathUtils.php';
// require_once __DIR__ . '/OverlaySyncService.php';

/**
 * ClientService - Orquestador de operaciones de cliente
 */
class ClientService implements ClientServiceInterface {
    private Database $db;
    private Logger $logger;
    private SystemInterface $system;
    // private UserServiceInterface $userService;
    // private SshServiceInterface $sshService;
    // private MountServiceInterface $mountService;
    // private ClientRepository $clientRepository;
    private OverlayRepository $overlayRepository;

    public function __construct(
        Database $db, 
        Logger $logger, 
        SystemInterface $system,
        ?UserServiceInterface $userService = null,
        ?SshServiceInterface $sshService = null,
        ?MountServiceInterface $mountService = null,
        ?ClientRepository $clientRepository = null,
        ?OverlayRepository $overlayRepository = null
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->system = $system;
        
        // Usar servicios inyectados o crear nuevos (backward compatibility)
        $this->userService = $userService ?? new UserService($db, $logger, $system);
        $this->sshService = $sshService ?? new SshService($db, $logger, $system);
        $this->mountService = $mountService ?? new MountService($db, $logger, $system);
        
        // Usar repositorios inyectados o crear nuevos
        $this->clientRepository = $clientRepository ?? RepositoryFactory::getClientRepository($db);
        $this->overlayRepository = $overlayRepository ?? RepositoryFactory::getOverlayRepository($db);
    }

    public function getUserService(): UserServiceInterface {
        return $this->userService;
    }

    public function getSshService(): SshServiceInterface {
        return $this->sshService;
    }

    /**
     * Helper para logging: siempre usa contexto jerárquico (9 dígitos)
     */
    private function logStep(bool $useContext, int $step, int $result, string $message): void {
        if ($useContext && $this->logger->hasContext()) {
            $this->logger->stepWithContext($step, $result, $message);
        }
    }

    public function create(string $clientId, bool $enabled, string $emp = '', string $plaza = ''): array {
        $useContext = $this->logger->hasContext();
        $username = "_$clientId";
        $homeDir = "/home/$clientId";
        
        try {
            $this->logStep($useContext, 1, 0, "Iniciando creación de cliente $clientId");

            // Validación 1: ID del cliente
            $this->logStep($useContext, 2, 0, "Validando formato del ID del cliente");
            if (!preg_match('/^[a-z0-9]{5}$/', $clientId)) {
                $errorMsg = "Client ID debe ser 5 caracteres alfanuméricos";
                $this->logStep($useContext, 3, 2, $errorMsg);
                return ['ok' => false, 'error' => $errorMsg];
            }

            // Validación 2: emp
            $this->logStep($useContext, 4, 0, "Validando campo emp");
            if (!preg_match('/^[a-zA-Z0-9]{0,3}$/', $emp)) {
                $errorMsg = "emp debe ser máximo 3 caracteres alfanuméricos";
                $this->logStep($useContext, 5, 2, $errorMsg);
                return ['ok' => false, 'error' => $errorMsg];
            }

            // Validación 3: plaza
            $this->logStep($useContext, 6, 0, "Validando campo plaza");
            if (!preg_match('/^[a-zA-Z0-9]{0,5}$/', $plaza)) {
                $errorMsg = "plaza debe ser máximo 5 caracteres alfanuméricos";
                $this->logStep($useContext, 7, 2, $errorMsg);
                return ['ok' => false, 'error' => $errorMsg];
            }

            // Verificar existencia en BD
            $this->logStep($useContext, 8, 0, "Verificando si cliente ya existe en base de datos");
            $existing = $this->clientRepository->findByRbfid($clientId);
            if ($existing) {
                $errorMsg = "Cliente ya existe";
                $this->logStep($useContext, 9, 2, $errorMsg);
                return ['ok' => false, 'error' => $errorMsg];
            }

        } catch (\Exception $e) {
            $this->logStep($useContext, 99, 2, "Error en validación: " . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        // ─── Fase crítica: crear recursos. Si falla cualquiera, rollback total ───
        $linuxUserCreated = false;
        $dbInserted = false;
        
        try {
            // Crear usuario Linux
            $this->logStep($useContext, 10, 0, "Creando usuario Linux");
            $result = $this->userService->create($username, $homeDir);
            if (!$result['ok']) {
                $errorMsg = "Error: " . ($result['error'] ?? 'unknown');
                $this->logStep($useContext, 11, 2, $errorMsg);
                return ['ok' => false, 'error' => $errorMsg];
            }
            $linuxUserCreated = true;
            $this->logStep($useContext, 12, 0, "Usuario Linux creado exitosamente: $username");

            // Generar claves SSH
            $this->logStep($useContext, 13, 0, "Generando claves SSH");
            $keys = $this->sshService->generate($clientId);
            if (!$keys['ok']) {
                throw new \RuntimeException("Error: " . ($keys['error'] ?? 'SSH keygen failed'));
            }
            $this->logStep($useContext, 15, 0, "Claves SSH generadas exitosamente");

            // Configurar authorized_keys
            $this->logStep($useContext, 16, 0, "Configurando authorized_keys");
            $this->sshService->configureAuthorizedKeys($username, $homeDir, $keys['public_key']);
            $this->logStep($useContext, 17, 0, "authorized_keys configurado");
            
            // Deshabilitar SSH si aplica
            if (!$enabled) {
                $this->logStep($useContext, 18, 0, "Deshabilitando acceso SSH (cliente deshabilitado)");
                $this->sshService->disable($username);
                $this->logStep($useContext, 19, 0, "Acceso SSH deshabilitado");
            }

            // Insertar en base de datos
            $this->logStep($useContext, 20, 0, "Insertando cliente en base de datos");
            $this->clientRepository->save([
                'rbfid' => $clientId,
                'enabled' => $enabled ? 'true' : 'false',
                'private_key' => $keys['private_key'],
                'public_key' => $keys['public_key'],
                'emp' => $emp,
                'plaza' => $plaza,
                'key_download_enabled' => 'true'
            ]);
            $dbInserted = true;
            $this->logStep($useContext, 21, 0, "Cliente insertado en base de datos");
            
        } catch (\Exception $e) {
            // Rollback: limpiar lo que se creó
            $this->logStep($useContext, 99, 2, "Error durante creación, rollback: " . $e->getMessage());
            
            $this->db->execute("DELETE FROM overlays WHERE rbfid = :rbfid", [':rbfid' => $clientId]);
            $this->db->execute("DELETE FROM overlay_cliente_mounts WHERE rbfid = :rbfid", [':rbfid' => $clientId]);
            $this->db->execute("DELETE FROM clients WHERE rbfid = :rbfid", [':rbfid' => $clientId]);

            if ($linuxUserCreated) {
                try {
                    $this->system->sudo("pkill -u " . escapeshellarg($username) . " 2>/dev/null || true");
                    $this->system->sudo("userdel -r -f " . escapeshellarg($username) . " 2>/dev/null");
                    $this->system->sudo("rm -rf " . escapeshellarg($homeDir) . " 2>/dev/null");
                    $this->logStep($useContext, 99, 0, "Rollback: usuario Linux eliminado");
                } catch (\Exception $rollbackEx) {
                    $this->logStep($useContext, 99, 2, "Rollback parcial: no se pudo eliminar usuario " . $rollbackEx->getMessage());
                }
            }
            
            return ['ok' => false, 'error' => $e->getMessage(), 'ejecucion' => $this->logger->getCurrentExecutionId()];
        }

        // ─── Fase post-DB: overlays. El cliente YA existe, fallo aquí NO deshace el cliente ───
        $templatesApplied = true;
        $templateWarning = null;
        
        if ($enabled) {
            $this->logStep($useContext, 25, 0, "Aplicando plantillas automáticamente a cliente habilitado");
            
            try {
                $templateResult = $this->applyTemplatesToClient($clientId);
                
                if ($templateResult['ok']) {
                    if (isset($templateResult['skipped']) && $templateResult['skipped']) {
                        $this->logStep($useContext, 26, 3, "Plantillas omitidas: " . ($templateResult['reason'] ?? ''));
                    } else {
                        $applied = $templateResult['summary']['applied'] ?? 0;
                        $errors = $templateResult['summary']['errors'] ?? 0;
                        $this->logStep($useContext, 27, 0, "Plantillas aplicadas: $applied creadas, $errors errores");
                        if ($errors > 0) {
                            $templatesApplied = false;
                            $templateWarning = "$errors overlays con errores";
                        }
                    }
                } else {
                    $templatesApplied = false;
                    $templateWarning = $templateResult['error'] ?? 'Error desconocido aplicando plantillas';
                    $this->logStep($useContext, 28, 2, "Error aplicando plantillas: $templateWarning");
                }
            } catch (\Exception $e) {
                $templatesApplied = false;
                $templateWarning = $e->getMessage();
                $this->logStep($useContext, 28, 2, "Excepción aplicando plantillas: " . $e->getMessage());
            }
        }
        
        // Resultado final
        if ($templatesApplied) {
            $this->logStep($useContext, 90, 0, "Cliente $clientId creado exitosamente");
        } else {
            $this->logStep($useContext, 90, 1, "Cliente $clientId creado con advertencias: $templateWarning");
        }
        
        return [
            'ok' => true, 
            'cliente' => $clientId, 
            'ejecucion' => $this->logger->getCurrentExecutionId(),
            'templates_applied' => $templatesApplied,
            'template_warning' => $templateWarning,
            'progress' => ['current' => 1, 'total' => 1]
        ];
    }
    
    /**
     * Aplicar plantillas automáticamente a un cliente
     * Solo aplica plantillas con auto_mount = true
     * Solo aplica si el cliente está activo (enabled = true)
     * Continúa si hay errores en alguna plantilla
     * Registra cada operación en el sistema de logging
     */
    public function applyTemplatesToClient(string $clientId): array {
        $useContext = $this->logger->hasContext();
        
        if (!$useContext) {
            $this->logger->startExecution($clientId, '[SYSTEM] apply-templates');
            $this->logger->enterContext(6, 0, 1, "Aplicar plantillas a cliente");
        }
        
        try {
            $this->logStep($useContext, 1, 0, "Iniciando aplicación de plantillas para cliente $clientId");
            
            // Obtener datos del cliente
            $client = $this->clientRepository->findByRbfid($clientId);
            if (!$client) {
                $this->logStep($useContext, 2, 2, "Cliente no encontrado");
                if (!$useContext) {
                    $this->logger->exitContext();
                    $this->logger->finish('failed');
                }
                return ['ok' => false, 'error' => 'Cliente no encontrado'];
            }
            
            // Verificar si el cliente está activo
            $isEnabled = $client['enabled'] === true || $client['enabled'] === 't';
            if (!$isEnabled) {
                $this->logStep($useContext, 3, 3, "Cliente no está activo, omitiendo aplicación de plantillas");
                if (!$useContext) {
                    $this->logger->exitContext();
                    $this->logger->finish('skipped');
                }
                return [
                    'ok' => true,
                    'skipped' => true,
                    'reason' => 'Cliente no está activo (enabled = false)'
                ];
            }
            
            $this->logStep($useContext, 4, 0, "Cliente activo, procediendo con aplicación de plantillas");
            
            // Obtener plantillas con auto_mount = true
            $this->logStep($useContext, 5, 0, "Obteniendo plantillas con auto_mount = true");
            $templates = $this->db->fetchAll(
                "SELECT * FROM overlay_plantillas WHERE auto_mount = 't' ORDER BY id"
            );
            
            $results = [];
            $applied = 0;
            $errors = 0;
            $skipped = 0;
            
            foreach ($templates as $template) {
                $templateName = "{$template['overlay_dst']} ({$template['mode']})";
                $templateId = $template['id'];
                
                try {
                    // Registrar inicio de aplicación para esta plantilla específica
                    $this->logStep($useContext, 10, 0, "Procesando plantilla ID $templateId: $templateName");
                    
                    // Verificar si ya existe overlay específico para este destino y modo
                    $existing = $this->db->fetchOne(
                        "SELECT id FROM overlays WHERE rbfid = :rbfid AND overlay_dst = :overlay_dst AND mode = :mode",
                        [':rbfid' => $clientId, ':overlay_dst' => $template['overlay_dst'], ':mode' => $template['mode']]
                    );
                    
                    if ($existing) {
                        $this->logStep($useContext, 11, 3, "Overlay ya existe para destino: {$template['overlay_dst']} (modo: {$template['mode']}), omitiendo");
                        $results[$template['overlay_dst']] = [
                            'status' => 'skipped',
                            'reason' => 'Overlay específico ya existe para este destino y modo',
                            'existing_overlay_id' => $existing['id']
                        ];
                        $skipped++;
                        continue;
                    }
                    
                    // Procesar template source con placeholders
                    $this->logStep($useContext, 12, 0, "Procesando source de plantilla: $templateName");
                    $src = PathUtils::processTemplateSource(
                        $template['overlay_src'],
                        $clientId,
                        [
                            'emp' => $client['emp'] ?? '',
                            'plaza' => $client['plaza'] ?? '',
                            'razon_social' => $client['razon_social'] ?? ''
                        ]
                    );
                    
                    // Asegurar directorio con permisos 0775 si es path relativo convertido
                    if (strpos($src, '/srv/') === 0) {
                        $this->logStep($useContext, 13, 0, "Asegurando directorio: $src");
                        PathUtils::ensureDirectory($src, 0775, 'root', 'users');
                    }
                    
                    // Para plantillas con auto_mount=true, NO crear overlay en tabla overlays
                    // Solo asegurar que el directorio fuente existe y tiene permisos correctos
                    $this->logStep($useContext, 14, 0, "Preparando directorio para plantilla: $templateName");
                    
                    $homeDir = "/home/$clientId";
                    $fullDst = "$homeDir/{$template['overlay_dst']}";
                    
                    // Verificar si el directorio destino existe en el home del cliente
                    $dstExists = is_dir($fullDst);
                    
                    if (!$dstExists) {
                        $this->logStep($useContext, 15, 0, "Directorio destino no existe: $fullDst");
                        // No crear el directorio destino - se creará al montar si es necesario
                    }
                    
                    // Intentar montar el overlay automáticamente desde la plantilla (idempotente)
                    try {
                        $this->logStep($useContext, 16, 0, "Verificando overlay: $templateName");
                        
                        if ($this->mountService->isMounted($fullDst)) {
                            // Ya montado - solo verificar permisos
                            $this->logStep($useContext, 17, 0, "Overlay ya montado, verificando permisos: $templateName");
                            $perms = $template['dst_perms'] ?? 'exclusive';
                            if ($perms === 'group') {
                                $targetPerms = ($template['mode'] === 'ro') ? '0550' : '0770';
                                $this->mountService->ensurePermissions($src, $targetPerms, 'users');
                            }
                            $results[$template['overlay_dst']] = [
                                'status' => 'template_already_mounted',
                                'template_id' => $templateId,
                                'mounted' => true,
                                'note' => 'Ya estaba montado'
                            ];
                        } else {
                            // No montado - montar
                            $this->logStep($useContext, 16, 0, "Intentando montar overlay desde plantilla: $templateName");
                            $mountResult = $this->mountService->mountBind($src, $fullDst, $template['mode'] === 'ro', $template['dst_perms'] ?? 'exclusive');
                            
                            if ($mountResult['ok']) {
                                $this->logStep($useContext, 17, 0, "Overlay montado exitosamente desde plantilla: $templateName");
                                $results[$template['overlay_dst']] = [
                                    'status' => 'template_applied_and_mounted',
                                    'template_id' => $templateId,
                                    'mounted' => true,
                                    'note' => 'Montado desde plantilla, no hay overlay_id específico'
                                ];
                            } else {
                                $this->logStep($useContext, 18, 1, "Plantilla aplicada pero no se pudo montar: $templateName");
                                $results[$template['overlay_dst']] = [
                                    'status' => 'template_applied_not_mounted',
                                    'template_id' => $templateId,
                                    'mounted' => false,
                                    'warning' => 'Plantilla aplicada pero no se pudo montar automáticamente'
                                ];
                            }
                        }
                    } catch (Exception $mountError) {
                        $this->logStep($useContext, 19, 1, "Error al montar overlay desde plantilla: " . $mountError->getMessage());
                        $results[$template['overlay_dst']] = [
                            'status' => 'template_mount_error',
                            'template_id' => $templateId,
                            'mounted' => false,
                            'error' => $mountError->getMessage()
                        ];
                    }
                    
                    $applied++;
                    
                } catch (Exception $e) {
                    // Continuar con siguiente plantilla
                    $errorMsg = "Error en plantilla $templateName: " . $e->getMessage();
                    $this->logStep($useContext, 20, 2, $errorMsg);
                    $results[$template['overlay_dst']] = [
                        'status' => 'error',
                        'error' => $e->getMessage(),
                        'template_id' => $templateId
                    ];
                    $errors++;
                    continue;
                }
            }
            
            // Resumen final con porcentajes
            $totalProcessed = $applied + $errors + $skipped;
            $successPercentage = $totalProcessed > 0 ? round(($applied / $totalProcessed) * 100) : 0;
            $summary = "Aplicado en $applied de $totalProcessed plantillas ($successPercentage% exitoso)";
            $this->logStep($useContext, 90, 0, "Aplicación de plantillas completada: $summary");
            $this->logStep($useContext, 91, 0, "Detalles: $applied creadas, $errors errores, $skipped omitidas");
            
            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish('success');
            }
            
            return [
                'ok' => true,
                'client_id' => $clientId,
                'client_enabled' => $isEnabled,
                'results' => $results,
                'summary' => [
                    'total_templates' => count($templates),
                    'applied' => $applied,
                    'errors' => $errors,
                    'skipped' => $skipped
                ],
                'progress' => ['current' => $applied + $errors + $skipped, 'total' => count($templates)]
            ];
            
        } catch (Exception $e) {
            $this->logStep($useContext, 99, 2, "Error inesperado en aplicación de plantillas: " . $e->getMessage());
            
            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish('failed');
            }
            
            return ['ok' => false, 'error' => $e->getMessage(), 'client_id' => $clientId];
        }
    }
    
    /**
     * Aplicar plantillas a TODOS los clientes activos
     * Útil cuando se crea/modifica una plantilla
     * Registra resultados individuales por cliente
     */
    public function applyTemplatesToAllActiveClients(): array {
        $this->logger->startExecution('system', '[SYSTEM] apply-templates-all');
        $this->logger->enterContext(6, 0, 2, "Aplicar plantillas a todos clientes activos");
        
        try {
            $this->logger->stepWithContext(1, 0, "Obteniendo lista de clientes activos");
            
            // Obtener todos los clientes activos
            $activeClients = $this->db->fetchAll(
                "SELECT rbfid FROM clients WHERE enabled = 'true' ORDER BY rbfid"
            );
            
            $totalClients = count($activeClients);
            $this->logger->stepWithContext(2, 0, "Encontrados $totalClients clientes activos");
            
            $results = [];
            $successful = 0;
            $failed = 0;
            $skipped = 0;
            
            foreach ($activeClients as $index => $client) {
                $clientId = $client['rbfid'];
                $current = $index + 1;
                $percentage = $totalClients > 0 ? round(($current / $totalClients) * 100) : 0;
                
                $this->logger->stepWithContext(10, 0, "Procesando cliente $current/$totalClients ($percentage%): $clientId");
                
                try {
                    // Aplicar plantillas a este cliente
                    $clientResult = $this->applyTemplatesToClient($clientId);
                    
                    if ($clientResult['ok']) {
                        if (isset($clientResult['skipped']) && $clientResult['skipped']) {
                            $results[$clientId] = [
                                'status' => 'skipped',
                                'reason' => $clientResult['reason'] ?? 'Cliente no activo'
                            ];
                            $skipped++;
                            $this->logger->stepWithContext(11, 3, "Cliente $clientId omitido: " . ($clientResult['reason'] ?? ''));
                        } else {
                            $results[$clientId] = [
                                'status' => 'success',
                                'summary' => $clientResult['summary'] ?? [],
                                'details' => $clientResult['results'] ?? []
                            ];
                            $successful++;
                            
                            // Calcular estadísticas para este cliente
                            $clientApplied = $clientResult['summary']['applied'] ?? 0;
                            $clientErrors = $clientResult['summary']['errors'] ?? 0;
                            $clientSkipped = $clientResult['summary']['skipped'] ?? 0;
                            $this->logger->stepWithContext(12, 0, "Plantillas aplicadas a $clientId: $clientApplied creadas, $clientErrors errores, $clientSkipped omitidas");
                        }
                    } else {
                        $results[$clientId] = [
                            'status' => 'error',
                            'error' => $clientResult['error'] ?? 'Error desconocido'
                        ];
                        $failed++;
                        $this->logger->stepWithContext(13, 2, "Error aplicando plantillas a $clientId: " . ($clientResult['error'] ?? ''));
                    }
                    
                } catch (Exception $e) {
                    $results[$clientId] = [
                        'status' => 'exception',
                        'error' => $e->getMessage()
                    ];
                    $failed++;
                    $this->logger->stepWithContext(14, 2, "Excepción procesando $clientId: " . $e->getMessage());
                    continue;
                }
            }
            
            // Resumen final con porcentajes
            $successPercentage = $totalClients > 0 ? round(($successful / $totalClients) * 100) : 0;
            $summary = "Aplicado en $successful de $totalClients clientes ($successPercentage% exitoso)";
            $this->logger->stepWithContext(90, 0, "Aplicación masiva completada: $summary");
            $this->logger->stepWithContext(91, 0, "Detalles: $successful exitosos, $failed fallidos, $skipped omitidos");
            
            $this->logger->exitContext();
            $this->logger->finish('success');
            
            return [
                'ok' => true,
                'results' => $results,
                'summary' => [
                    'total_clients' => $totalClients,
                    'successful' => $successful,
                    'failed' => $failed,
                    'skipped' => $skipped
                ]
            ];
            
        } catch (Exception $e) {
            $this->logger->stepWithContext(99, 2, "Error inesperado en aplicación masiva: " . $e->getMessage());
            $this->logger->exitContext();
            $this->logger->finish('failed');
            
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function enable(string $clientId): array {
        // Usar stepWithContext si hay contexto activo, de lo contrario usar step normal
        $useContext = $this->logger->hasContext();
        
        // Iniciar ejecución si no hay contexto
        if (!$useContext) {
            $this->logger->startExecution($clientId, '[API] enable');
            $this->logger->enterContext(2, 30, 2, "Habilitar cliente");
        }
        
        try {
            $this->logStep($useContext, 1, 0, "Iniciando enable para cliente $clientId");

            // Verificar si existe primero
            $this->logStep($useContext, 2, 0, "Verificando existencia del cliente");
            $client = $this->clientRepository->findByRbfid($clientId);
            if (!$client) {
                $errorMsg = "Client not found";
                $this->logStep($useContext, 3, 2, $errorMsg);
                $executionId = $this->logger->getCurrentExecutionId();
                if (!$useContext) {
                    $this->logger->finish('failed');
                }
                return ['ok' => false, 'error' => $errorMsg, 'ejecucion' => $executionId];
            }

            $username = "_$clientId";
            $homeDir = "/home/$clientId";

            // Habilitar usuario
            $this->logStep($useContext, 4, 0, "Habilitando usuario Linux");
            $this->userService->enable($username);
            $this->logStep($useContext, 5, 0, "Usuario habilitado");

            // Habilitar SSH
            $this->logStep($useContext, 6, 0, "Habilitando acceso SSH");
            $this->sshService->enable($username);
            $this->logStep($useContext, 7, 0, "SSH habilitado");
            
            // Actualizar base de datos
            $this->logStep($useContext, 8, 0, "Actualizando estado en base de datos");
            $this->clientRepository->toggleEnabled($clientId, true);
            $this->clientRepository->toggleSsh($clientId, true);
            $this->logStep($useContext, 9, 0, "Base de datos actualizada");

            // Montar overlays
            $this->logStep($useContext, 10, 0, "Montando overlays");
            $overlays = $this->overlayRepository->findByClient($clientId);
            foreach ($overlays as $o) {
                $src = $o['overlay_src'];
                $dst = "$homeDir/{$o['overlay_dst']}";
                $mode = $o['mode'] === 'rw' ? false : true;
                $this->mountService->mountBind($src, $dst, $mode, $o['dst_perms'] ?? 'exclusive');
            }
            $this->logStep($useContext, 11, 0, "Overlays montados");

            // Aplicar plantillas automáticamente ahora que el cliente está habilitado
            $this->logStep($useContext, 12, 0, "Aplicando plantillas automáticamente a cliente habilitado");
            $templateResult = $this->applyTemplatesToClient($clientId);
            
            if ($templateResult['ok']) {
                if (isset($templateResult['skipped']) && $templateResult['skipped']) {
                    $this->logStep($useContext, 13, 3, "Plantillas omitidas: " . ($templateResult['reason'] ?? ''));
                } else {
                    $applied = $templateResult['summary']['applied'] ?? 0;
                    $errors = $templateResult['summary']['errors'] ?? 0;
                    $this->logStep($useContext, 14, 0, "Plantillas aplicadas: $applied creadas, $errors errores");
                }
            } else {
                $this->logStep($useContext, 15, 1, "Error aplicando plantillas: " . ($templateResult['error'] ?? ''));
            }
            
            // Éxito final
            $this->logStep($useContext, 90, 0, "Cliente $clientId habilitado exitosamente");
            
            // Finalizar ejecución si no hay contexto y guardar ID antes
            $executionId = $this->logger->getCurrentExecutionId();
            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish('success');
            }
            
            return [
                'ok' => true,
                'cliente' => $clientId,
                'enabled' => true,
                'ejecucion' => $executionId,
                'templates_applied' => true,
                'template_result' => $templateResult
            ];
            
        } catch (\Exception $e) {
            // Manejo de errores inesperados
            $this->logStep($useContext, 99, 2, "Error inesperado durante enable: " . $e->getMessage());
            
            // Finalizar ejecución si no hay contexto y guardar ID antes
            $executionId = $this->logger->getCurrentExecutionId();
            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish('failed');
            }
            
            return ['ok' => false, 'error' => $e->getMessage(), 'ejecucion' => $executionId];
        }
    }

    public function disable(string $clientId): array {
        // Usar stepWithContext si hay contexto activo, de lo contrario usar step normal
        $useContext = $this->logger->hasContext();
        
        // Iniciar ejecución si no hay contexto
        if (!$useContext) {
            $this->logger->startExecution($clientId, '[API] disable');
            $this->logger->enterContext(2, 30, 3, "Deshabilitar cliente");
        }
        
        try {
            $this->logStep($useContext, 1, 0, "Iniciando disable para cliente $clientId");

            // Verificar si existe primero
            $this->logStep($useContext, 2, 0, "Verificando existencia del cliente");
            $client = $this->clientRepository->findByRbfid($clientId);
            if (!$client) {
                $errorMsg = "Client not found";
                $this->logStep($useContext, 3, 2, $errorMsg);
                $executionId = $this->logger->getCurrentExecutionId();
                if (!$useContext) {
                    $this->logger->exitContext();
                    $this->logger->finish('failed');
                }
                return ['ok' => false, 'error' => $errorMsg, 'ejecucion' => $executionId];
            }

            $username = "_$clientId";
            $homeDir = "/home/$clientId";

            // Desmontar overlays específicos
            $this->logStep($useContext, 4, 0, "Desmontando overlays específicos");
            $overlays = $this->overlayRepository->findDestinationsByClient($clientId);
            foreach ($overlays as $o) {
                $this->mountService->umount("{$homeDir}/{$o['overlay_dst']}");
            }
            
            // Desmontar overlays de plantilla (overlay_cliente_mounts)
            $this->logStep($useContext, 4, 1, "Desmontando overlays de plantilla");
            $plantillaOverlays = $this->db->fetchAll(
                "SELECT overlay_dst, overlay_src FROM overlay_cliente_mounts WHERE rbfid = :rbfid",
                [':rbfid' => $clientId]
            );
            $umountErrors = [];
            foreach ($plantillaOverlays as $o) {
                $fullDst = "{$homeDir}/{$o['overlay_dst']}";
                $umountResult = $this->mountService->umount($fullDst);
                if (!$umountResult['ok']) {
                    $umountErrors[] = $o['overlay_dst'];
                }
            }
            
            if (!empty($umountErrors)) {
                $this->logStep($useContext, 4, 2, "Error desmontando: " . implode(', ', $umountErrors));
            }
            
            $this->logStep($useContext, 5, 0, "Overlays desmontados");

            // Deshabilitar usuario
            $this->logStep($useContext, 6, 0, "Deshabilitando usuario Linux");
            $this->userService->disable($username);
            $this->logStep($useContext, 7, 0, "Usuario deshabilitado");

            // Deshabilitar SSH
            $this->logStep($useContext, 8, 0, "Deshabilitando acceso SSH");
            $this->sshService->disable($username);
            $this->logStep($useContext, 9, 0, "SSH deshabilitado");
            
            // Actualizar base de datos
            $this->logStep($useContext, 10, 0, "Actualizando estado en base de datos");
            $this->clientRepository->toggleEnabled($clientId, false);
            $this->clientRepository->toggleSsh($clientId, false);
            
            // Actualizar overlays específicos solo si no hubo errores
            $this->db->execute("UPDATE overlays SET mounted = 'false' WHERE rbfid = :rbfid", [':rbfid' => $clientId]);
            
            // Actualizar overlays de plantilla - marcar pendientes si hubo errores
            if (empty($umountErrors)) {
                $this->db->execute("UPDATE overlay_cliente_mounts SET mounted = 'false' WHERE rbfid = :rbfid", [':rbfid' => $clientId]);
            } else {
                // Mantener como mounted=true los que fallaron
                $this->logStep($useContext, 10, 1, "Overlays pendientes de desmontar: " . implode(', ', $umountErrors));
            }
            
            if (!empty($umountErrors)) {
                $this->logStep($useContext, 11, 2, "Base de datos actualizada (algunos overlays pendientes)");
            } else {
                $this->logStep($useContext, 11, 0, "Base de datos actualizada");
            }

            // Éxito final
            if (!empty($umountErrors)) {
                $this->logStep($useContext, 90, 1, "Cliente $clientId deshabilitado con overlays pendientes: " . implode(', ', $umountErrors));
            } else {
                $this->logStep($useContext, 90, 0, "Cliente $clientId deshabilitado exitosamente");
            }
            
            // Finalizar ejecución si no hay contexto y guardar ID antes
            $executionId = $this->logger->getCurrentExecutionId();
            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish(empty($umountErrors) ? 'success' : 'warning');
            }
            
            $result = ['ok' => true, 'cliente' => $clientId, 'enabled' => false, 'ejecucion' => $executionId];
            if (!empty($umountErrors)) {
                $result['warning'] = 'Algunos overlays no se pudieron desmontar: ' . implode(', ', $umountErrors);
                $result['pending_overlays'] = $umountErrors;
            }
            return $result;
            
        } catch (\Exception $e) {
            // Manejo de errores inesperados
            $this->logStep($useContext, 99, 2, "Error inesperado durante disable: " . $e->getMessage());
            
            // Finalizar ejecución si no hay contexto y guardar ID antes
            $executionId = $this->logger->getCurrentExecutionId();
            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish('failed');
            }
            
            return ['ok' => false, 'error' => $e->getMessage(), 'ejecucion' => $executionId];
        }
    }

    public function delete(string $clientId, bool $hard = false): array {
        // Usar stepWithContext si hay contexto activo, de lo contrario usar step normal
        $useContext = $this->logger->hasContext();
        
        // Iniciar ejecución si no hay contexto
        if (!$useContext) {
            $this->logger->startExecution($clientId, '[API] delete');
            $this->logger->enterContext(2, 30, 4, "Eliminar cliente");
        }
        
        try {
            $this->logStep($useContext, 1, 0, "Iniciando delete para cliente $clientId (hard=$hard)");

            // Verificar si existe primero
            $this->logStep($useContext, 2, 0, "Verificando existencia del cliente");
            $client = $this->clientRepository->findByRbfid($clientId);
            if (!$client) {
                $errorMsg = "Client not found";
                $this->logStep($useContext, 3, 2, $errorMsg);
                $executionId = $this->logger->getCurrentExecutionId();
                if (!$useContext) {
                    $this->logger->exitContext();
                    $this->logger->finish('failed');
                }
                return ['ok' => false, 'error' => $errorMsg, 'ejecucion' => $executionId];
            }

            if (!$hard) {
                $this->logStep($useContext, 4, 0, "Modo soft: llamando a disable()");
                if (!$useContext) {
                    $this->logger->exitContext();
                    $this->logger->finish('success');
                }
                return $this->disable($clientId);
            }

            // Modo hard: eliminación completa
            $username = "_$clientId";
            $homeDir = "/home/$clientId";

            // Desmontar overlays
            $this->logStep($useContext, 5, 0, "Desmontando overlays");
            $overlays = $this->db->fetchAll("SELECT overlay_dst FROM overlays WHERE rbfid = :rbfid", [':rbfid' => $clientId]);
            foreach ($overlays as $o) {
                $this->mountService->umount("{$homeDir}/{$o['overlay_dst']}");
            }
            $this->logStep($useContext, 6, 0, "Overlays desmontados");

            // Eliminar usuario
            $this->logStep($useContext, 7, 0, "Eliminando usuario Linux");
            $this->userService->delete($username);
            $this->logStep($useContext, 8, 0, "Usuario eliminado");

            // Eliminar claves SSH
            $this->logStep($useContext, 9, 0, "Eliminando claves SSH");
            $this->sshService->deleteKeys($clientId);
            $this->logStep($useContext, 10, 0, "Claves SSH eliminadas");
            
            // Eliminar directorio home
            $this->logStep($useContext, 11, 0, "Eliminando directorio home");
            $this->system->sudo("rm -rf " . escapeshellarg($homeDir) . " 2>&1", $exitCode);
            $this->logStep($useContext, 12, 0, "Directorio home eliminado");

            // Eliminar de base de datos
            $this->logStep($useContext, 13, 0, "Eliminando registros de base de datos");
            $this->db->execute("DELETE FROM overlays WHERE rbfid = :rbfid", [':rbfid' => $clientId]);
            $this->db->execute("DELETE FROM overlay_cliente_mounts WHERE rbfid = :rbfid", [':rbfid' => $clientId]);
            $this->db->execute("DELETE FROM clients WHERE rbfid = :rbfid", [':rbfid' => $clientId]);
            $this->logStep($useContext, 14, 0, "Registros eliminados de base de datos");

            // Éxito final
            $this->logStep($useContext, 90, 0, "Cliente $clientId eliminado completamente (hard delete)");
            
            // Finalizar ejecución si no hay contexto y guardar ID antes
            $executionId = $this->logger->getCurrentExecutionId();
            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish('success');
            }
            
            return ['ok' => true, 'cliente' => $clientId, 'ejecucion' => $executionId];
            
        } catch (\Exception $e) {
            // Manejo de errores inesperados
            $this->logStep($useContext, 99, 2, "Error inesperado durante delete: " . $e->getMessage());
            
            // Finalizar ejecución si no hay contexto y guardar ID antes
            $executionId = $this->logger->getCurrentExecutionId();
            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish('failed');
            }
            
            return ['ok' => false, 'error' => $e->getMessage(), 'ejecucion' => $executionId];
        }
    }

    public function getClient(string $clientId): ?array {
        $useContext = $this->logger->hasContext();
        
        if (!$useContext) {
            $this->logger->startExecution($clientId, '[API] getClient');
            $this->logger->enterContext(2, 30, 9, "Obtener cliente");
        }
        
        try {
            $this->logStep($useContext, 1, 0, "Consultando cliente $clientId");
            $client = $this->clientRepository->findByRbfid($clientId);
            
            if (!$client) {
                $this->logStep($useContext, 2, 2, "Cliente no encontrado");
            } else {
                $this->logStep($useContext, 90, 0, "Cliente encontrado");
            }
            
            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish($client ? 'success' : 'failed');
            }
            
            return $client;
        } catch (\Exception $e) {
            $this->logStep($useContext, 99, 2, "Error: " . $e->getMessage());
            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish('failed');
            }
            return null;
        }
    }

    public function update(string $clientId, string $emp, string $plaza, bool $enabled): array {
        $useContext = $this->logger->hasContext();
        
        if (!$useContext) {
            $this->logger->startExecution($clientId, '[API] update');
            $this->logger->enterContext(2, 30, 5, "Actualizar cliente");
        }
        
        try {
            $this->logStep($useContext, 1, 0, "Iniciando actualización para cliente $clientId");
            
            $client = $this->db->fetchOne("SELECT rbfid FROM clients WHERE rbfid = :rbfid", [':rbfid' => $clientId]);
            if (!$client) {
                $this->logStep($useContext, 2, 2, "Cliente no encontrado");
                if (!$useContext) {
                    $this->logger->exitContext();
                    $this->logger->finish('failed');
                }
                return ['ok' => false, 'error' => 'Client not found'];
            }

            $this->logStep($useContext, 3, 0, "Validando campos");
            if (!preg_match('/^[a-zA-Z0-9]{0,3}$/', $emp)) {
                $this->logStep($useContext, 4, 2, "Invalid emp");
                if (!$useContext) {
                    $this->logger->exitContext();
                    $this->logger->finish('failed');
                }
                return ['ok' => false, 'error' => 'Invalid emp: máximo 3 caracteres'];
            }

            if (!preg_match('/^[a-zA-Z0-9]{0,5}$/', $plaza)) {
                $this->logStep($useContext, 5, 2, "Invalid plaza");
                if (!$useContext) {
                    $this->logger->exitContext();
                    $this->logger->finish('failed');
                }
                return ['ok' => false, 'error' => 'Invalid plaza: máximo 5 caracteres'];
            }

            $this->logStep($useContext, 6, 0, "Actualizando base de datos");
            $this->db->execute(
                "UPDATE clients SET emp = :emp, plaza = :plaza, enabled = :enabled WHERE rbfid = :rbfid",
                [':emp' => $emp, ':plaza' => $plaza, ':enabled' => $enabled ? 'true' : 'false', ':rbfid' => $clientId]
            );
            
            $this->logStep($useContext, 90, 0, "Cliente actualizado exitosamente");

            // Gatillar reconciliación automática de overlays si cambian emp o plaza
            // Inyectamos OverlaySyncService manualmente si no está en la clase
            $this->logStep($useContext, 91, 0, "Gatillando reconciliación automática de overlays");
            $syncService = new OverlaySyncService($this->db, $this->logger, $this->mountService);
            $syncResult = $syncService->reconcileClientOverlays($clientId);
            
            if (!$syncResult['ok']) {
                $this->logStep($useContext, 92, 1, "Aviso: Error en reconciliación automática: " . ($syncResult['error'] ?? 'desconocido'));
            }
            
            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish('success');
            }

            return ['ok' => true, 'rbfid' => $clientId, 'sync' => $syncResult];
        } catch (\Exception $e) {
            $this->logStep($useContext, 99, 2, "Error: " . $e->getMessage());
            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish('failed');
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function listClients(?string $searchTerm = null): array {
        $useContext = $this->logger->hasContext();
        
        try {
            $this->logStep($useContext, 1, 0, "Listando clientes" . ($searchTerm ? " (búsqueda: $searchTerm)" : ""));
            $clients = $this->clientRepository->getAllBasic($searchTerm);
            $this->logStep($useContext, 90, 0, "Listado completado: " . count($clients) . " clientes");
            
            return $clients;
        } catch (\Exception $e) {
            $this->logStep($useContext, 99, 2, "Error: " . $e->getMessage());
            return [];
        }
    }

    public function renewKey(string $clientId): array {
        $useContext = $this->logger->hasContext();
        
        if (!$useContext) {
            $this->logger->startExecution($clientId, '[API] ssh-regen');
            $this->logger->enterContext(2, 30, 6, "Renovar clave SSH");
        }
        
        try {
            // Verificar si existe primero
            $this->logStep($useContext, 1, 0, "Verificando existencia del cliente");
            $client = $this->clientRepository->findByRbfid($clientId);
            if (!$client) {
                $this->logStep($useContext, 2, 2, "Cliente no encontrado");
                if (!$useContext) {
                    $this->logger->exitContext();
                    $this->logger->finish('failed');
                }
                return ['ok' => false, 'error' => 'Client not found'];
            }

            $this->logStep($useContext, 3, 0, "Generando nuevas claves SSH");
            $keys = $this->sshService->generate($clientId);
            if (!$keys['ok']) {
                $errorMsg = "Error: " . ($keys['error'] ?? 'failed');
                $this->logStep($useContext, 4, 2, $errorMsg);
                if (!$useContext) {
                    $this->logger->exitContext();
                    $this->logger->finish('failed');
                }
                return ['ok' => false, 'error' => $errorMsg];
            }

            $this->logStep($useContext, 5, 0, "Guardando claves en base de datos");
            $this->db->execute(
                "UPDATE clients SET private_key = :private_key, public_key = :public_key, ssh_regen_count = ssh_regen_count + 1, ssh_regen_last = NOW(), key_download_enabled = true, key_downloaded_at = NULL WHERE rbfid = :rbfid",
                [':private_key' => $keys['private_key'], ':public_key' => $keys['public_key'], ':rbfid' => $clientId]
            );

            $this->logStep($useContext, 90, 0, "Clave SSH renovada para $clientId");
            
            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish('success');
            }
            
            return ['ok' => true, 'ejecucion' => $this->logger->getCurrentExecutionId()];
        } catch (\Exception $e) {
            $this->logStep($useContext, 99, 2, "Error inesperado: " . $e->getMessage());
            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish('failed');
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function enableSsh(string $clientId): array {
        $this->logger->startExecution($clientId, '[API] enable-ssh');
        $this->logger->enterContext(2, 30, 11, "Habilitar SSH");
        
        $this->logger->stepWithContext(1, 0, "Iniciando enable SSH para cliente $clientId");
        
        $client = $this->getClient($clientId);
        if (!$client) {
            $this->logger->stepWithContext(3, 2, "Cliente no encontrado");
            $this->logger->exitContext();
            $this->logger->finish('failed');
            return ['ok' => false, 'error' => 'Cliente no encontrado'];
        }
        
        $username = "_{$clientId}";
        
        $this->logger->stepWithContext(4, 0, "Habilitando acceso SSH para usuario $username");
        $this->sshService->enable($username);
        
        $this->logger->stepWithContext(5, 0, "Actualizando estado en base de datos");
        $this->clientRepository->toggleSsh($clientId, true);
        
        $this->logger->stepWithContext(90, 0, "SSH habilitado exitosamente para $clientId");
        
        $this->logger->exitContext();
        $this->logger->finish('success');
        
        return ['ok' => true, 'rbfid' => $clientId, 'ssh_enabled' => true];
    }

    public function disableSsh(string $clientId): array {
        $this->logger->startExecution($clientId, '[API] disable-ssh');
        $this->logger->enterContext(2, 30, 12, "Deshabilitar SSH");
        
        $this->logger->stepWithContext(1, 0, "Iniciando disable SSH para cliente $clientId");
        
        $client = $this->getClient($clientId);
        if (!$client) {
            $this->logger->stepWithContext(3, 2, "Cliente no encontrado");
            $this->logger->exitContext();
            $this->logger->finish('failed');
            return ['ok' => false, 'error' => 'Cliente no encontrado'];
        }
        
        $username = "_{$clientId}";
        
        $this->logger->stepWithContext(4, 0, "Deshabilitando acceso SSH para usuario $username");
        $this->sshService->disable($username);
        
        $this->logger->stepWithContext(5, 0, "Actualizando estado en base de datos");
        $this->clientRepository->toggleSsh($clientId, false);
        
        $this->logger->stepWithContext(90, 0, "SSH deshabilitado exitosamente para $clientId");
        
        $this->logger->exitContext();
        $this->logger->finish('success');
        
        return ['ok' => true, 'rbfid' => $clientId, 'ssh_enabled' => false];
    }

    /**
     * Obtener overlays de un cliente (específicos + plantillas)
     * Puebla la tabla overlay_cliente_mounts y devuelve los datos de allí
     */
    public function getMounts(string $clientId): array {
        $useContext = $this->logger->hasContext();
        
        if (!$useContext) {
            $this->logger->startExecution($clientId, '[API] getMounts');
            $this->logger->enterContext(2, 30, 10, "Obtener mounts");
        }
        
        try {
            // 1. NO borrar - solo verificar y actualizar existentes
            // Mantener IDs estables
            
            $this->logStep($useContext, 2, 0, "Obteniendo overlays específicos del cliente");
            
            // 2. Obtener overlays específicos del cliente
            $specificOverlays = $this->overlayRepository->findByClient($clientId);
            
            // 3. (Eliminado) Ya no insertamos overlays específicos en tabla temporal

            
            $this->logStep($useContext, 4, 0, "Obteniendo plantillas de overlays");
            
            // 4. Obtener plantillas de overlays (globales)
            $sql = "SELECT id, overlay_src, overlay_dst, mode 
                    FROM overlay_plantillas 
                    WHERE auto_mount = 't' 
                    ORDER BY id";
            $templateOverlays = $this->db->fetchAll($sql);
            
            $this->logStep($useContext, 5, 0, "Insertando plantillas en tabla temporal");
            
            // 5. Insertar plantillas en tabla temporal
            foreach ($templateOverlays as $template) {
                // Verificar si ya existe un overlay de plantilla con el mismo destino
                $existing = $this->db->fetchOne(
                    "SELECT id FROM overlay_cliente_mounts WHERE rbfid = :rbfid AND overlay_dst = :overlay_dst AND mode = :mode AND origen_id = :origen_id",
                    [':rbfid' => $clientId, ':overlay_dst' => $template['overlay_dst'], ':mode' => $template['mode'], ':origen_id' => $template['id']]
                );
                
                if (!$existing) {
                    error_log("getMounts: inserting template id = " . $template['id'] . ", src = " . $template['overlay_src'] . ", dst = " . $template['overlay_dst']);
                    $sql = "INSERT INTO overlay_cliente_mounts 
                            (rbfid, overlay_src, overlay_dst, mode, mounted, origen_id) 
                            VALUES (:rbfid, :overlay_src, :overlay_dst, :mode, 'false', :origen_id)";
                    $this->db->execute($sql, [':rbfid' => $clientId, ':overlay_src' => $template['overlay_src'], ':overlay_dst' => $template['overlay_dst'], ':mode' => $template['mode'], ':origen_id' => $template['id']]);
                }
            }
            
            $this->logStep($useContext, 6, 0, "Verificando estado de montaje");
            
            // 6. Obtener overlays específicos desde tabla overlays
            $specificOverlays = $this->db->fetchAll(
                "SELECT id, rbfid, overlay_src, overlay_dst, mode, mounted, 'cliente' as origen, id as origen_id
                 FROM overlays 
                 WHERE rbfid = :rbfid 
                 ORDER BY overlay_dst",
                [':rbfid' => $clientId]
            );
            
            // 7. Obtener overlays de plantilla desde tabla temporal
            $plantillaOverlays = $this->db->fetchAll(
                "SELECT id, rbfid, overlay_src, overlay_dst, mode, mounted, 'plantilla' as origen, origen_id 
                 FROM overlay_cliente_mounts 
                 WHERE rbfid = :rbfid 
                 ORDER BY overlay_dst",
                [':rbfid' => $clientId]
            );
            
            // 8. Combinar ambas listas, dando precedencia a los específicos si comparten overlay_dst
            $overlays = [];
            $dstsInSpecific = [];
            
            // Primero agregar overlays específicos
            foreach ($specificOverlays as $overlay) {
                $dst = $overlay['overlay_dst'];
                $dstsInSpecific[$dst] = true;
                $overlays[] = $overlay;
            }
            
            // Luego agregar overlays de plantilla que no tengan equivalente específico
            foreach ($plantillaOverlays as $overlay) {
                $dst = $overlay['overlay_dst'];
                if (!isset($dstsInSpecific[$dst])) {
                    $overlays[] = $overlay;
                }
            }
            
    $result = [];
    foreach ($overlays as $overlay) {
        $dst = "/home/{$clientId}/{$overlay['overlay_dst']}";
        $isMounted = $this->mountService->isMounted($dst);
        // Asegurar que es un booleano válido para evitar problemas de tipo en PostgreSQL
        $isMounted = (bool) $isMounted;
        
        // Actualizar estado en tabla temporal solo para overlays de plantilla
        if ($overlay['origen'] === 'plantilla') {
            $this->db->execute(
                "UPDATE overlay_cliente_mounts SET mounted = :mounted WHERE id = :id",
                [':mounted' => $isMounted ? 'true' : 'false', ':id' => $overlay['id']]
            );
        }
        
        // Determinar estado origen (correcto) para comparar desviación
        $isDesviado = false;
        
        if ($overlay['origen'] === 'plantilla') {
            // Es una plantilla: verificar si debería estar montada (auto_mount = t)
            // Buscar la plantilla correspondiente
            $plantilla = $this->db->fetchOne(
                "SELECT id, auto_mount FROM overlay_plantillas WHERE id = :id",
                [':id' => $overlay['origen_id']]
            );
            
            if ($plantilla) {
                $expectedMounted = ($plantilla['auto_mount'] === 't' || $plantilla['auto_mount'] === true);
                $isDesviado = ($isMounted !== $expectedMounted);
            } else {
                // Plantilla no encontrada, considerar desviado
                $isDesviado = true;
            }
        } elseif ($overlay['origen'] === 'cliente') {
            // Es un overlay específico, comparamos con la base de datos
            $specificOverlay = $this->db->fetchOne(
                "SELECT id, mounted FROM overlays WHERE id = :id",
                [':id' => $overlay['origen_id']]
            );
            
            if ($specificOverlay) {
                $expectedMounted = ($specificOverlay['mounted'] === 't' || $specificOverlay['mounted'] === true);
                $isDesviado = ($isMounted !== $expectedMounted);
            }
        }
        
        // Usar el ID de la tabla temporal para plantillas, o el ID de overlays para específicos
        $overlay_id = ($overlay['origen'] === 'plantilla') ? $overlay['id'] : $overlay['origen_id'];
        
        $result[] = [
            'overlay_id' => $overlay_id,
            'overlay_src' => $overlay['overlay_src'],
            'overlay_dst' => $overlay['overlay_dst'],
            'mode' => $overlay['mode'],
            'origen' => $overlay['origen'],
            'origen_id' => $overlay['origen_id'],
            'mounted' => $isMounted,
            'is_desviado' => $isDesviado
        ];
    }
            
            $this->logStep($useContext, 90, 0, "Retornando " . count($result) . " overlays");
            
            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish('success');
            }
            
            return ['ok' => true, 'overlays' => $result];
            
        } catch (\Exception $e) {
            $this->logStep($useContext, 99, 2, "Error: " . $e->getMessage());
            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish('failed');
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Delegar creación de overlay a MountService
     */
    public function addOverlay(string $clientId, string $src, string $dst, string $mode, string $dstPerms = 'exclusive'): array {
        return $this->mountService->addOverlay($clientId, $src, $dst, $mode, $dstPerms);
    }

    public function enableKeyDownload(string $clientId): array {
        $useContext = $this->logger->hasContext();
        
        if (!$useContext) {
            $this->logger->startExecution($clientId, '[API] enable-key-download');
            $this->logger->enterContext(2, 30, 13, "Habilitar descarga de clave");
        }
        
        try {
            $this->logStep($useContext, 1, 0, "Habilitando descarga de clave para cliente $clientId");
            
            // Verificar si el cliente existe
            $client = $this->clientRepository->findByRbfid($clientId);
            if (!$client) {
                $this->logStep($useContext, 2, 2, "Cliente no encontrado");
                if (!$useContext) {
                    $this->logger->exitContext();
                    $this->logger->finish('failed');
                }
                return ['ok' => false, 'error' => 'Cliente no encontrado'];
            }
            
            // Habilitar descarga de clave
            $this->db->execute(
                "UPDATE clients SET key_download_enabled = true, key_downloaded_at = NULL WHERE rbfid = :rbfid",
                [':rbfid' => $clientId]
            );
            
            $this->logStep($useContext, 90, 0, "Descarga de clave habilitada para $clientId");
            
            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish('success');
            }
            
            return ['ok' => true, 'rbfid' => $clientId, 'key_download_enabled' => true];
            
        } catch (\Exception $e) {
            $this->logStep($useContext, 99, 2, "Error inesperado: " . $e->getMessage());
            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish('failed');
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function disableKeyDownload(string $clientId): array {
        $useContext = $this->logger->hasContext();
        
        if (!$useContext) {
            $this->logger->startExecution($clientId, '[API] disable-key-download');
            $this->logger->enterContext(2, 30, 14, "Deshabilitar descarga de clave");
        }
        
        try {
            $this->logStep($useContext, 1, 0, "Deshabilitando descarga de clave para cliente $clientId");
            
            // Verificar si el cliente existe
            $client = $this->clientRepository->findByRbfid($clientId);
            if (!$client) {
                $this->logStep($useContext, 2, 2, "Cliente no encontrado");
                if (!$useContext) {
                    $this->logger->exitContext();
                    $this->logger->finish('failed');
                }
                return ['ok' => false, 'error' => 'Cliente no encontrado'];
            }
            
            // Deshabilitar descarga de clave
            $this->db->execute(
                "UPDATE clients SET key_download_enabled = false WHERE rbfid = :rbfid",
                [':rbfid' => $clientId]
            );
            
            $this->logStep($useContext, 90, 0, "Descarga de clave deshabilitada para $clientId");
            
            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish('success');
            }
            
            return ['ok' => true, 'rbfid' => $clientId, 'key_download_enabled' => false];
            
        } catch (\Exception $e) {
            $this->logStep($useContext, 99, 2, "Error inesperado: " . $e->getMessage());
            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish('failed');
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function resetKeyDownload(string $clientId): array {
        $useContext = $this->logger->hasContext();
        
        if (!$useContext) {
            $this->logger->startExecution($clientId, '[API] reset-key-download');
            $this->logger->enterContext(2, 30, 15, "Resetear descarga de clave");
        }
        
        try {
            $this->logStep($useContext, 1, 0, "Reseteando descarga de clave para cliente $clientId");
            
            // Verificar si el cliente existe
            $client = $this->clientRepository->findByRbfid($clientId);
            if (!$client) {
                $this->logStep($useContext, 2, 2, "Cliente no encontrado");
                if (!$useContext) {
                    $this->logger->exitContext();
                    $this->logger->finish('failed');
                }
                return ['ok' => false, 'error' => 'Cliente no encontrado'];
            }
            
            // Resetear descarga de clave (habilitar y limpiar timestamp)
            $this->db->execute(
                "UPDATE clients SET key_download_enabled = true, key_downloaded_at = NULL WHERE rbfid = :rbfid",
                [':rbfid' => $clientId]
            );
            
            $this->logStep($useContext, 90, 0, "Descarga de clave reseteada para $clientId");
            
            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish('success');
            }
            
            return ['ok' => true, 'rbfid' => $clientId, 'key_download_enabled' => true];
            
        } catch (\Exception $e) {
            $this->logStep($useContext, 99, 2, "Error inesperado: " . $e->getMessage());
            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish('failed');
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function getKeyDownloadStatus(string $clientId): array {
        $useContext = $this->logger->hasContext();
        
        if (!$useContext) {
            $this->logger->startExecution($clientId, '[API] get-key-download-status');
            $this->logger->enterContext(2, 30, 16, "Obtener estado descarga clave");
        }
        
        try {
            $this->logStep($useContext, 1, 0, "Consultando estado de descarga para cliente $clientId");
            
            // Verificar si el cliente existe
            $client = $this->clientRepository->findByRbfid($clientId);
            if (!$client) {
                $this->logStep($useContext, 2, 2, "Cliente no encontrado");
                if (!$useContext) {
                    $this->logger->exitContext();
                    $this->logger->finish('failed');
                }
                return ['ok' => false, 'error' => 'Cliente no encontrado'];
            }
            
            // Obtener el estado de descarga
            $keyDownloadEnabled = $client['key_download_enabled'] === true || $client['key_download_enabled'] === 't';
            $keyDownloadedAt = $client['key_downloaded_at'] ?? null;
            
            $this->logStep($useContext, 90, 0, "Estado de descarga obtenido");
            
            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish('success');
            }
            
            return [
                'ok' => true,
                'key_download_enabled' => $keyDownloadEnabled,
                'key_downloaded_at' => $keyDownloadedAt
            ];
            
        } catch (\Exception $e) {
            $this->logStep($useContext, 99, 2, "Error inesperado: " . $e->getMessage());
            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish('failed');
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
