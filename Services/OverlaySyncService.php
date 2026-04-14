<?php
/**
 * OverlaySyncService - Sincroniza overlays entre base de datos y sistema de archivos
 */
require_once __DIR__ . '/ServiceInterfaces.php';
require_once __DIR__ . '/../Repositories/RepositoryFactory.php';

class OverlaySyncService implements OverlaySyncServiceInterface {
    private Database $db;
    private Logger $logger;
    private MountService $mountService;
    private OverlayRepository $overlayRepository;
    private ClientRepository $clientRepository;

    public function __construct(
        Database $db,
        Logger $logger,
        MountService $mountService,
        ?OverlayRepository $overlayRepository = null,
        ?ClientRepository $clientRepository = null
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->mountService = $mountService;
        $this->overlayRepository = $overlayRepository ?? RepositoryFactory::getOverlayRepository($db);
        $this->clientRepository = $clientRepository ?? RepositoryFactory::getClientRepository($db);
    }

    private function logStep(bool $useContext, int $step, int $result, string $message): void {
        if ($useContext && $this->logger->hasContext()) {
            $this->logger->stepWithContext($step, $result, $message);
        }
    }

    /**
     * Reconcile overlays for a specific client
     * @param string $clientId
     * @return array Result with created, deleted, and summary
     */
    public function reconcileClientOverlays(string $clientId): array {
        $useContext = $this->logger->hasContext();

        if (!$useContext) {
            $this->logger->startExecution($clientId, '[API] sync-overlays');
            $this->logger->enterContext(2, 30, 15, "Reconciliar overlays");
        }

        try {
            $this->logStep($useContext, 1, 0, "Iniciando reconciliación de overlays para cliente $clientId");

            // Verificar que el cliente existe
            $client = $this->clientRepository->findByRbfid($clientId);
            if (!$client) {
                $errorMsg = "Cliente no encontrado";
                $this->logStep($useContext, 2, 2, $errorMsg);
                if (!$useContext) {
                    $this->logger->exitContext();
                    $this->logger->finish('failed');
                }
                return ['ok' => false, 'error' => $errorMsg];
            }

            // 1. Calcular estado DESEADO (basado en BD)
            $this->logStep($useContext, 2, 0, "Calculando estado deseado desde BD");
            $desired = $this->calculateDesiredState($clientId, $client);

            // 2. Calcular estado REAL (sistema de archivos)
            $this->logStep($useContext, 3, 0, "Obteniendo estado actual del sistema de archivos");
            $actual = $this->getActualMountedState($clientId, $client);

            // 3. Calcular DIFERENCIAS
            $this->logStep($useContext, 4, 0, "Calculando diferencias");
            $toCreate = [];
            $toDelete = [];

            // Normalizar para comparación (usar strings)
            foreach ($desired as $d) {
                $found = false;
                foreach ($actual as $a) {
                    if ($d['overlay_dst'] === $a['overlay_dst'] && $d['mode'] === $a['mode']) {
                        // Además del destino y modo, ahora comparamos el ORIGEN (src)
                        // Si el origen real difiere del deseado (placeholder cambió), marcamos para re-crear
                        if ($d['overlay_src_processed'] === $a['overlay_src_real']) {
                            $found = true;
                        }
                        break;
                    }
                }
                if (!$found) {
                    $toCreate[] = $d;
                }
            }

            foreach ($actual as $a) {
                $found = false;
                foreach ($desired as $d) {
                    if ($a['overlay_dst'] === $d['overlay_dst'] && $a['mode'] === $d['mode']) {
                        // Si el origen real es diferente al deseado, lo tratamos como "sobrante" para desmontar
                        if ($a['overlay_src_real'] === $d['overlay_src_processed']) {
                            $found = true;
                        }
                        break;
                    }
                }
                if (!$found) {
                    $toDelete[] = $a;
                }
            }

            // 4. EJECUTAR creaciones
            $this->logStep($useContext, 5, 0, "Creando " . count($toCreate) . " overlays faltantes");
            $created = [];
            foreach ($toCreate as $overlay) {
                $this->logStep($useContext, 10, 0, "Creando overlay: {$overlay['overlay_dst']} ({$overlay['mode']})");
                $result = $this->createOverlayFromSpec($clientId, $overlay);
                if ($result['ok']) {
                    $created[] = array_merge($overlay, ['created_id' => $result['id'] ?? null]);
                    $this->logStep($useContext, 11, 0, "Overlay creado exitosamente");
                } else {
                    $this->logStep($useContext, 12, 2, "Error creando overlay {$overlay['overlay_dst']}: " . ($result['error'] ?? 'unknown'));
                }
            }

            // 5. EJECUTAR eliminaciones
            $this->logStep($useContext, 6, 0, "Eliminando " . count($toDelete) . " overlays sobrantes");
            $deleted = [];
            foreach ($toDelete as $overlay) {
                $this->logStep($useContext, 20, 0, "Eliminando overlay: {$overlay['overlay_dst']} ({$overlay['mode']})");
                $result = $this->deleteOverlayBySpec($clientId, $overlay);
                if ($result['ok']) {
                    $deleted[] = $overlay;
                    $this->logStep($useContext, 21, 0, "Overlay eliminado exitosamente");
                } else {
                    $this->logStep($useContext, 22, 2, "Error eliminando overlay {$overlay['overlay_dst']}: " . ($result['error'] ?? 'unknown'));
                }
            }

            // 6. RETORNAR resultado
            $this->logStep($useContext, 90, 0, "Reconciliación completada: " . count($created) . " creados, " . count($deleted) . " eliminados");

            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish('success');
            }

            return [
                'ok' => true,
                'created' => $created,
                'deleted' => $deleted,
                'summary' => count($created) . " creados, " . count($deleted) . " eliminados"
            ];

        } catch (\Exception $e) {
            $this->logStep($useContext, 99, 2, "Error inesperado en reconciliación: " . $e->getMessage());
            if (!$useContext) {
                $this->logger->exitContext();
                $this->logger->finish('failed');
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Calculate desired state from database (specific overlays + applicable templates)
     */
    private function calculateDesiredState(string $clientId, array $client): array {
        $desired = [];

        // 1. Obtener overlays específicos de la tabla overlays (solo para verificar conflictos)
        $specificOverlays = $this->overlayRepository->findByClient($clientId);

        // 2. Plantillas globales aplicables (auto_mount = true) que no entren en conflicto con overlays específicos
        $templates = $this->db->fetchAll(
            "SELECT id, overlay_src, overlay_dst, mode, dst_perms
             FROM overlay_plantillas 
             WHERE auto_mount = 't' 
             ORDER BY id"
        );

        foreach ($templates as $template) {
            // Verificar si ya existe un overlay específico con mismo destino y modo
            $conflict = false;
            foreach ($specificOverlays as $specific) {
                if ($specific['overlay_dst'] === $template['overlay_dst'] && $specific['mode'] === $template['mode']) {
                    $conflict = true;
                    break;
                }
            }

            if (!$conflict) {
                $desired[] = [
                    'overlay_src' => $template['overlay_src'],
                    'overlay_src_processed' => \PathUtils::processTemplateSource($template['overlay_src'], $clientId, $client),
                    'overlay_dst' => $template['overlay_dst'],
                    'mode' => $template['mode'],
                    'origen' => 'plantilla',
                    'origen_id' => $template['id'],
                    'dst_perms' => $template['dst_perms'] ?? 'default'
                ];
            }
        }

        return $desired;
    }

    /**
     * Get actual mounted state from filesystem
     */
    private function getActualMountedState(string $clientId, array $client): array {
        $actual = [];
        $homeDir = "/home/$clientId";

        // Obtener los overlays de plantilla del estado deseado para saber qué destinos buscar
        $desiredPlantillas = $this->calculateDesiredState($clientId, $client);

        foreach ($desiredPlantillas as $overlay) {
            $dst = "{$homeDir}/{$overlay['overlay_dst']}";
            if ($this->mountService->isMounted($dst)) {
                $actualSrc = $this->mountService->getMountSource($dst);
                
                $actual[] = [
                    'overlay_src_real' => $actualSrc,
                    'overlay_dst' => $overlay['overlay_dst'],
                    'mode' => $overlay['mode'],
                    'origen' => $overlay['origen'],
                    'origen_id' => $overlay['origen_id']
                ];
            }
        }

        return $actual;
    }

    /**
     * Create overlay from specification
     */
    private function createOverlayFromSpec(string $clientId, array $spec): array {
        try {
            // Normalizar path de origen (procesando placeholders si es necesario)
            $srcToMount = $spec['overlay_src_processed'] ?? \PathUtils::normalizeOverlayPath($spec['overlay_src']);
            $normalizedSrc = $srcToMount;

            // Asegurar directorio con permisos 0775 si es path relativo convertido
            if (strpos($normalizedSrc, '/srv/') === 0) {
                \PathUtils::ensureDirectory($normalizedSrc, 0775, 'root', 'users');
            }

            // Verificar si es una plantilla (origen = 'plantilla')
            $isPlantilla = ($spec['origen'] === 'plantilla');
            
            if ($isPlantilla) {
                // Insertar en overlay_cliente_mounts para plantillas
                $result = $this->db->execute(
                    "INSERT INTO overlay_cliente_mounts (rbfid, overlay_src, overlay_dst, mode, mounted, origen_id) 
                     VALUES (:rbfid, :overlay_src, :overlay_dst, :mode, 'true', :origen_id)",
                    [':rbfid' => $clientId, ':overlay_src' => $normalizedSrc, ':overlay_dst' => $spec['overlay_dst'], ':mode' => $spec['mode'], ':origen_id' => $spec['origen_id']]
                );
                
                // No usamos RETURNING con execute, necesitamos fetchOne
                $newId = $this->db->fetchOne(
                    "SELECT id FROM overlay_cliente_mounts WHERE rbfid = :rbfid AND overlay_dst = :overlay_dst AND mode = :mode",
                    [':rbfid' => $clientId, ':overlay_dst' => $spec['overlay_dst'], ':mode' => $spec['mode']]
                );
                
                $overlayId = $newId['id'];
            } else {
                // Para específicos, crear en tabla overlays (aunque esto no debería ocurrir en reconcileClientOverlays)
                $overlayId = $this->overlayRepository->createForClient(
                    $clientId,
                    $normalizedSrc,
                    $spec['overlay_dst'],
                    $spec['mode']
                );
            }

            // Montar overlay
            $homeDir = "/home/$clientId";
            $fullDst = "{$homeDir}/{$spec['overlay_dst']}";
            $mountResult = $this->mountService->mountBind(
                $normalizedSrc,
                $fullDst,
                $spec['mode'] === 'ro',
                $spec['dst_perms'] ?? 'exclusive'
            );

            if ($mountResult['ok']) {
                if ($isPlantilla) {
                    // Actualizar estado montado en overlay_cliente_mounts
                    $this->db->execute(
                        "UPDATE overlay_cliente_mounts SET mounted = 'true' WHERE id = :id",
                        [':id' => $overlayId]
                    );
                } else {
                    // Actualizar estado montado en BD
                    $this->db->execute(
                        "UPDATE overlays SET mounted = 'true' WHERE id = :id",
                        [':id' => $overlayId]
                    );
                }

                return [
                    'ok' => true,
                    'id' => $overlayId,
                    'mounted' => true
                ];
            } else {
                // Si falla el mount, aún así dejamos el registro en BD pero marcamos error
                return [
                    'ok' => false,
                    'error' => 'Error al montar overlay: ' . ($mountResult['error'] ?? 'unknown')
                ];
            }
        } catch (\Exception $e) {
            return [
                'ok' => false,
                'error' => 'Error al crear overlay: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Delete overlay by specification (find by clientId, dst, mode)
     */
    private function deleteOverlayBySpec(string $clientId, array $spec): array {
        try {
            // Verificar si es una plantilla (origen = 'plantilla')
            $isPlantilla = ($spec['origen'] === 'plantilla');
            
            if ($isPlantilla) {
                // Buscar overlay de plantilla en overlay_cliente_mounts
                $overlay = $this->db->fetchOne(
                    "SELECT id FROM overlay_cliente_mounts WHERE rbfid = :rbfid AND overlay_dst = :overlay_dst AND mode = :mode",
                    [':rbfid' => $clientId, ':overlay_dst' => $spec['overlay_dst'], ':mode' => $spec['mode']]
                );
            } else {
                // Buscar overlay específico en overlays
                $overlay = $this->db->fetchOne(
                    "SELECT id FROM overlays WHERE rbfid = :rbfid AND overlay_dst = :overlay_dst AND mode = :mode",
                    [':rbfid' => $clientId, ':overlay_dst' => $spec['overlay_dst'], ':mode' => $spec['mode']]
                );
            }

            if (!$overlay) {
                return ['ok' => false, 'error' => 'Overlay no encontrado para eliminar'];
            }

            $homeDir = "/home/$clientId";
            $fullDst = "{$homeDir}/{$spec['overlay_dst']}";

            // Desmontar si está montado
            if ($this->mountService->isMounted($fullDst)) {
                $this->mountService->umount($fullDst);
            }

            // Eliminar de base de datos
            if ($isPlantilla) {
                $this->db->execute(
                    "DELETE FROM overlay_cliente_mounts WHERE id = :id",
                    [':id' => $overlay['id']]
                );
            } else {
                $this->db->execute(
                    "DELETE FROM overlays WHERE id = :id",
                    [':id' => $overlay['id']]
                );
            }

            return ['ok' => true];
        } catch (\Exception $e) {
            return [
                'ok' => false,
                'error' => 'Error al eliminar overlay: ' . $e->getMessage()
            ];
        }
    }
}