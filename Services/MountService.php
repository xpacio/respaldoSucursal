<?php
/**
 * MountService - Gestión de mounts y overlays
 */
require_once __DIR__ . '/ServiceInterfaces.php';
require_once __DIR__ . '/../Repositories/RepositoryFactory.php';
require_once __DIR__ . '/../Config/PathUtils.php';

class MountService implements MountServiceInterface {
    private Database $db;
    private Logger $logger;
    private SystemInterface $system;
    private OverlayRepository $overlayRepository;

    public function __construct(Database $db, Logger $logger, System $system, ?OverlayRepository $overlayRepository = null) {
        $this->db = $db;
        $this->logger = $logger;
        $this->system = $system;
        $this->overlayRepository = $overlayRepository ?? RepositoryFactory::getOverlayRepository($db);
    }
    
    private function logStep(int $step, int $result, string $message): void {
        if ($this->logger->hasContext()) {
            $this->logger->stepWithContext($step, $result, $message);
        }
    }

    /**
     * Regla #3: Un solo lookup de overlay por ID
     * Busca en ambas tablas: overlay_cliente_mounts primero, luego overlays
     */
    private function findOverlayById(int $id): ?array {
        // Unir con overlay_plantillas para obtener dst_perms de la plantilla original
        $row = $this->db->fetchOne(
            "SELECT cm.*, p.dst_perms, 'plantilla' as source 
             FROM overlay_cliente_mounts cm
             LEFT JOIN overlay_plantillas p ON p.id = cm.origen_id
             WHERE cm.id = :id",
            [':id' => $id]
        );
        if ($row) return $row;

        $row = $this->db->fetchOne(
            "SELECT *, 'directo' as source FROM overlays WHERE id = :id",
            [':id' => $id]
        );
        return $row;
    }

    public function mountBind(string $src, string $dst, bool $readonly = false, string $perms = 'exclusive'): array {
        $mode = $readonly ? 'ro' : 'rw';
        
        $exitCode = null;
        $this->logStep(10, 0, "Dir. src: mkdir -p $src");
        $this->system->sudo("mkdir -p " . escapeshellarg($src), $exitCode);
        $this->logStep(11, 0, "Dir. dst: mkdir -p $dst");
        $this->system->sudo("mkdir -p " . escapeshellarg($dst), $exitCode);
        
        // Determinar permisos y grupo según la configuración
        $targetPerms = '0755';
        $targetGroup = 'users';
        $clientUser = null;

        // Extraer rbfid del path de destino (/home/_abcde/...)
        if (preg_match('/\/home\/(_[a-z0-9]{5})/', $dst, $matches)) {
            $clientUser = $matches[1]; // Ya tiene el underscore prefix
        }

        switch ($perms) {
            case 'exclusive':
                $targetPerms = $readonly ? '0500' : '0700';
                $targetGroup = $clientUser ?? 'users';
                break;
            case 'group':
                $targetPerms = $readonly ? '0550' : '0770';
                $targetGroup = 'users';
                break;
        }

        $this->logStep(12, 0, "Verificando permisos de origen: $src (modo: $mode, perms: $perms -> $targetPerms:$targetGroup)");
        $this->ensurePermissions($src, $targetPerms, $targetGroup);
        
        $this->logStep(13, 0, "Mount bind $src $dst $mode");
        $this->system->sudo("mount --bind -o " . escapeshellarg($mode) . " " . escapeshellarg($src) . " " . escapeshellarg($dst), $exitCode);
        
        if ($exitCode !== 0) {
            $this->logStep(98, 2, "Error al ejecutar mount bind");
            return ['ok' => false];
        }
        
        return ['ok' => true];
    }

    public function umount(string $path): array {
        $exitCode = null;
        
        if ($this->isMounted($path)) {
            $this->logStep(13, 0, "Umount de $path");
            // Try normal umount first, then lazy if failed
            $cmd = "umount " . escapeshellarg($path) . " 2>&1";
            $output = $this->system->sudo($cmd, $exitCode);
            
            if ($exitCode !== 0) {
                // Force lazy unmount
                $cmd = "umount -l " . escapeshellarg($path) . " 2>&1";
                $output = $this->system->sudo($cmd, $exitCode);
            }
        }
        
        if (is_dir($path) && count(scandir($path)) <= 2) {
            $this->system->sudo("rm -rf " . escapeshellarg($path), $exitCode);
        }
        
        return ['ok' => !$this->isMounted($path), 'wasMounted' => true];
    }

    public function isMounted(string $path): bool {
        $exitCode = null;
        $this->system->sudo("mountpoint -q " . escapeshellarg($path) . " 2>/dev/null", $exitCode);
        return $exitCode === 0;
    }

    public function getMountSource(string $path): ?string {
        $exitCode = null;
        $source = $this->system->sudo("findmnt -n -o SOURCE " . escapeshellarg($path) . " 2>/dev/null", $exitCode);
        return ($exitCode === 0 && !empty(trim($source))) ? trim($source) : null;
    }

    public function addOverlay(string $clientId, string $src, string $dst, string $mode, string $dstPerms = 'exclusive'): array {
        // Validar inputs
        if (!PathUtils::isValidDestination($dst)) {
            return ['ok' => false, 'error' => 'Nombre de destino inválido'];
        }
        
        if (!PathUtils::isValidMode($mode)) {
            return ['ok' => false, 'error' => 'Modo inválido, debe ser "ro" o "rw"'];
        }
        
        // Proteger overlay crítico qbck de creaciones específicas
        if (strtolower($dst) === 'qbck') {
            return ['ok' => false, 'error' => 'No se puede crear un overlay específico para qbck (use plantillas)'];
        }
        
        try {
            // 1. Obtener datos del cliente para procesar placeholders
            $client = $this->db->fetchOne("SELECT rbfid, emp, plaza FROM clients WHERE rbfid = :rbfid", [':rbfid' => $clientId]);
            if (!$client) {
                return ['ok' => false, 'error' => 'Cliente no encontrado'];
            }

            // 2. Procesar placeholders si existen (ej: {emp}, {plaza}, {rbfid})
            $processedSrc = PathUtils::processTemplateSource($src, $clientId, $client);
            
            // 3. Normalizar path de origen
            $normalizedSrc = PathUtils::normalizeOverlayPath($processedSrc);
            
            // 4. Crear overlay en base de datos (guardamos el origen original con placeholders para que sea dinámico)
            $this->overlayRepository->createForClient($clientId, $src, $dst, $mode, $dstPerms);
            
            $homeDir = "/home/$clientId";
            $fullDst = "$homeDir/$dst";
            
            // 5. Montar usando el origen procesado y los permisos indicados
            return $this->mountBind($normalizedSrc, $fullDst, $mode === 'ro', $dstPerms);
            
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (Exception $e) {
            // Si hay error de unicidad (destino duplicado con mismo modo)
            if (strpos($e->getMessage(), 'overlays_rbfid_dst_mode_unique') !== false) {
                return ['ok' => false, 'error' => 'Ya existe un overlay con ese destino y modo para este cliente'];
            }
            return ['ok' => false, 'error' => 'Error al crear overlay: ' . $e->getMessage()];
        }
    }
    
    /**
     * Habilitar (montar) overlay por ID (Regla #3: usa findOverlayById)
     */
    public function enableOverlay(int $overlayId): array {
        try {
            $overlay = $this->findOverlayById($overlayId);
            if (!$overlay) {
                return ['ok' => false, 'error' => 'Overlay no encontrado'];
            }

            $source = $overlay['source'];
            $rbfid = $overlay['rbfid'];
            $homeDir = "/home/$rbfid";
            $fullDst = "{$homeDir}/{$overlay['overlay_dst']}";

            // Verificar y corregir permisos del directorio origen antes de montar
            $perms = $overlay['dst_perms'] ?? 'exclusive';
            if ($perms === 'group') {
                $targetPerms = ($overlay['mode'] === 'ro') ? '0550' : '0770';
                $this->ensurePermissions($overlay['overlay_src'], $targetPerms, 'users');
            }

            // Verificar si ya está montado
            if ($this->isMounted($fullDst)) {
                $this->updateOverlayState($overlayId, $source, true);
                return ['ok' => true, 'already_mounted' => true];
            }

            // Montar
            $result = $this->mountBind(
                $overlay['overlay_src'],
                $fullDst,
                $overlay['mode'] === 'ro',
                $overlay['dst_perms'] ?? 'exclusive'
            );

            if ($result['ok']) {
                $this->updateOverlayState($overlayId, $source, true);

                // Si es de plantilla, asegurar que existe overlay específico
                if ($source === 'plantilla') {
                    $existing = $this->db->fetchOne(
                        "SELECT id FROM overlays WHERE rbfid = :rbfid AND overlay_dst = :overlay_dst AND mode = :mode",
                        [':rbfid' => $rbfid, ':overlay_dst' => $overlay['overlay_dst'], ':mode' => $overlay['mode']]
                    );
                    if (!$existing) {
                        $newOverlayId = $this->overlayRepository->createForClient(
                            $rbfid, $overlay['overlay_src'], $overlay['overlay_dst'], $overlay['mode']
                        );
                        $this->db->execute("UPDATE overlays SET mounted = 'true' WHERE id = :id", [':id' => $newOverlayId]);
                    }
                }
            }

            return $result;

        } catch (Exception $e) {
            return ['ok' => false, 'error' => 'Error al habilitar overlay: ' . $e->getMessage()];
        }
    }

    /**
     * Deshabilitar (desmontar) overlay por ID (Regla #3: usa findOverlayById)
     */
    public function disableOverlay(int $overlayId): array {
        try {
            $overlay = $this->findOverlayById($overlayId);
            if (!$overlay) {
                return ['ok' => false, 'error' => 'Overlay no encontrado'];
            }

            $source = $overlay['source'];
            $rbfid = $overlay['rbfid'];
            $homeDir = "/home/$rbfid";
            $fullDst = "{$homeDir}/{$overlay['overlay_dst']}";

            // Verificar si ya está desmontado
            if (!$this->isMounted($fullDst)) {
                $this->updateOverlayState($overlayId, $source, false);
                return ['ok' => true, 'already_unmounted' => true];
            }

            // Desmontar
            $result = $this->umount($fullDst);

            if ($result['ok']) {
                $this->updateOverlayState($overlayId, $source, false);

                // Si es de plantilla, actualizar también el overlay específico
                if ($source === 'plantilla') {
                    $specificOverlay = $this->db->fetchOne(
                        "SELECT id FROM overlays WHERE rbfid = :rbfid AND overlay_dst = :overlay_dst AND mode = :mode",
                        [':rbfid' => $rbfid, ':overlay_dst' => $overlay['overlay_dst'], ':mode' => $overlay['mode']]
                    );
                    if ($specificOverlay) {
                        $this->db->execute("UPDATE overlays SET mounted = 'false' WHERE id = :id", [':id' => $specificOverlay['id']]);
                    }
                }
            }

            return $result;

        } catch (Exception $e) {
            return ['ok' => false, 'error' => 'Error al deshabilitar overlay: ' . $e->getMessage()];
        }
    }

    /**
     * Eliminar overlay por ID (Regla #3: usa findOverlayById)
     */
    public function deleteOverlay(int $overlayId): array {
        try {
            $overlay = $this->findOverlayById($overlayId);
            if (!$overlay) {
                return ['ok' => false, 'error' => 'Overlay no encontrado'];
            }

            $source = $overlay['source'];
            $rbfid = $overlay['rbfid'];
            $homeDir = "/home/$rbfid";
            $fullDst = "{$homeDir}/{$overlay['overlay_dst']}";

            // Desmontar si está montado
            if ($this->isMounted($fullDst)) {
                $this->umount($fullDst);
            }

            // Eliminar de la tabla correcta
            if ($source === 'plantilla') {
                $this->db->execute("DELETE FROM overlay_cliente_mounts WHERE id = :id", [':id' => $overlayId]);
            } else {
                $this->db->execute("DELETE FROM overlays WHERE id = :id", [':id' => $overlayId]);
            }

            return ['ok' => true, 'type' => $source];

        } catch (Exception $e) {
            return ['ok' => false, 'error' => 'Error al eliminar overlay: ' . $e->getMessage()];
        }
    }

    /**
     * Actualizar estado mounted de un overlay en la tabla correcta
     */
    private function updateOverlayState(int $id, string $source, bool $mounted): void {
        $table = ($source === 'plantilla') ? 'overlay_cliente_mounts' : 'overlays';
        $this->db->execute(
            "UPDATE {$table} SET mounted = :mounted WHERE id = :id",
            [':mounted' => $mounted ? 'true' : 'false', ':id' => $id]
        );
    }
    
    /**
     * Habilitar todos los overlays de un cliente
     */
    /**
     * Desmontar un overlay de plantilla de todos los clientes
     * Se usa cuando una plantilla cambia de auto_mount=true a auto_mount=false
     */
    public function unmountTemplateFromAllClients(string $overlayDst, string $mode): array {
        try {
            // Usar contexto de logging si está disponible
            if ($this->logger->hasContext()) {
                $this->logger->stepWithContext(1, 0, "Desmontando overlay de plantilla '{$overlayDst}' ({$mode}) de todos los clientes");
            }
            
            error_log("[UNMOUNT-TEMPLATE] Iniciando desmontaje de '{$overlayDst}' ({$mode})");
            
            // 1. Obtener todos los clientes activos
            $clients = $this->db->fetchAll("SELECT rbfid FROM clients WHERE enabled = 't'");
            error_log("[UNMOUNT-TEMPLATE] Clientes activos encontrados: " . count($clients));
            
            $results = [];
            $total = 0;
            $successful = 0;
            
            foreach ($clients as $client) {
                $clientId = $client['rbfid'];
                $total++;
                
                // 2. Construir path completo del overlay
                $homeDir = "/home/{$clientId}";
                $fullDst = "{$homeDir}/{$overlayDst}";
                
                error_log("[UNMOUNT-TEMPLATE] Cliente: {$clientId}, path: {$fullDst}");
                
                // 3. Verificar si está montado
                $mounted = $this->isMounted($fullDst);
                error_log("[UNMOUNT-TEMPLATE] ¿Está montado?: " . ($mounted ? 'SI' : 'NO'));
                
                if (!$mounted) {
                    $results[$clientId] = ['ok' => true, 'message' => 'No estaba montado'];
                    $successful++;
                    continue;
                }
                
                // 4. Desmontar
                error_log("[UNMOUNT-TEMPLATE] Ejecutando umount para {$fullDst}");
                $result = $this->umount($fullDst);
                error_log("[UNMOUNT-TEMPLATE] Resultado umount: " . json_encode($result));
                
                if ($result['ok']) {
                    $results[$clientId] = ['ok' => true, 'message' => 'Desmontado exitosamente'];
                    $successful++;
                    error_log("[UNMOUNT-TEMPLATE] ✓ Desmontado correctamente");
                    
                    // 5. Actualizar estado en tabla temporal si existe
                    $updateResult = $this->db->execute(
                        "UPDATE overlay_cliente_mounts SET mounted = 'false' WHERE rbfid = :rbfid AND overlay_dst = :overlay_dst AND mode = :mode",
                        [':rbfid' => $clientId, ':overlay_dst' => $overlayDst, ':mode' => $mode]
                    );
                    error_log("[UNMOUNT-TEMPLATE] BD actualizada: " . ($updateResult ? 'SI' : 'NO'));
                } else {
                    $results[$clientId] = ['ok' => false, 'error' => $result['error'] ?? 'Error desconocido'];
                    error_log("[UNMOUNT-TEMPLATE] ✗ Error al desmontar: " . ($result['error'] ?? 'Error desconocido'));
                }
            }
            
            error_log("[UNMOUNT-TEMPLATE] Resumen: {$successful}/{$total} desmontados");
            
            return [
                'ok' => true,
                'message' => "Desmontado de {$successful}/{$total} clientes",
                'results' => $results,
                'total' => $total,
                'successful' => $successful
            ];
            
        } catch (Exception $e) {
            return ['ok' => false, 'error' => 'Error al desmontar overlay de plantilla: ' . $e->getMessage()];
        }
    }

    /**
     * Evaluar y corregir permisos de directorio solo si es necesario
     * 
     * @param string $path Directorio a evaluar
     * @param string $perms Permisos esperados (ej: '0755' o '2775')
     * @param string $group Grupo esperado (ej: 'users')
     */
    public function ensurePermissions(string $path, string $perms, string $group): void {
        $exitCode = null;
        
        // Verificar que el directorio existe antes de modificar
        $this->system->sudo("test -d " . escapeshellarg($path), $exitCode);
        if ($exitCode !== 0) {
            $this->logStep(12, 1, "Directorio no existe, saltando permisos: $path");
            return;
        }
        
        // Evaluar permisos actuales
        $current = trim($this->system->sudo("stat -c '%U:%G %a' " . escapeshellarg($path), $exitCode));
        if ($exitCode !== 0) return;
        
        [$ownership, $currentPerms] = explode(' ', $current);
        [$currentOwner, $currentGroup] = explode(':', $ownership);
        
        $needsChgrp = ($currentGroup !== $group);
        $needsChmod = ($currentPerms !== $perms);
        
        if ($needsChgrp || $needsChmod) {
            $this->logStep(12, 0, "Corrigiendo permisos: $path (actual: $currentPerms/$currentGroup, esperado: $perms/$group)");
            
            if ($needsChgrp) {
                $this->system->sudo("chgrp -R " . escapeshellarg($group) . " " . escapeshellarg($path));
            }
            if ($needsChmod) {
                $this->system->sudo("chmod -R " . escapeshellarg($perms) . " " . escapeshellarg($path));
            }
        }
    }
}
