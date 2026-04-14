<?php
/**
 * OverlayAuditService - Auditoría y corrección de overlays
 *
 * Compara el estado deseado (BD) con el estado real (filesystem)
 * y corrige desviaciones: permisos, montajes faltantes, montajes sobrantes.
 *
 * Ejecución: boot + cada hora via systemd timer
 */
require_once __DIR__ . '/ServiceInterfaces.php';
require_once __DIR__ . '/../Repositories/RepositoryFactory.php';

class OverlayAuditService {
    private Database $db;
    private Logger $logger;
    private MountService $mountService;
    private ClientRepository $clientRepository;

    public function __construct(
        Database $db,
        Logger $logger,
        MountService $mountService,
        ?ClientRepository $clientRepository = null
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->mountService = $mountService;
        $this->clientRepository = $clientRepository ?? RepositoryFactory::getClientRepository($db);
    }

    /**
     * Auditoría completa de todos los clientes activos
     */
    public function auditAll(): array {
        error_log("[AUDIT] Iniciando auditoría de overlays");

        $activeClients = $this->db->fetchAll(
            "SELECT rbfid FROM clients WHERE enabled = 'true' ORDER BY rbfid"
        );

        $totalClients = count($activeClients);
        error_log("[AUDIT] Encontrados {$totalClients} clientes activos");

        $results = [];
        $globalStats = [
            'clients' => 0,
            'mounted' => 0,
            'unmounted' => 0,
            'permissions_fixed' => 0,
            'db_corrected' => 0,
            'errors' => 0,
        ];

        foreach ($activeClients as $client) {
            $clientId = $client['rbfid'];
            $clientResult = $this->auditClient($clientId);
            $results[$clientId] = $clientResult;

            if ($clientResult['ok']) {
                $globalStats['clients']++;
                foreach (['mounted', 'unmounted', 'permissions_fixed', 'db_corrected', 'errors'] as $key) {
                    $globalStats[$key] += $clientResult['stats'][$key] ?? 0;
                }
            } else {
                $globalStats['errors']++;
                error_log("[AUDIT] Error en cliente {$clientId}: " . ($clientResult['error'] ?? 'unknown'));
            }
        }

        error_log("[AUDIT] Completado: {$globalStats['clients']} clientes, {$globalStats['mounted']} remontados, {$globalStats['unmounted']} desmontados, {$globalStats['permissions_fixed']} permisos corregidos, {$globalStats['db_corrected']} DB corregidas, {$globalStats['errors']} errores");

        return [
            'ok' => true,
            'results' => $results,
            'summary' => $globalStats,
        ];
    }

    /**
     * Auditoría de un cliente específico
     */
    public function auditClient(string $clientId): array {
        $stats = [
            'mounted' => 0,
            'unmounted' => 0,
            'permissions_fixed' => 0,
            'db_corrected' => 0,
            'errors' => 0,
        ];

        try {
            // 1. Overlays específicos (tabla overlays)
            $specificOverlays = $this->db->fetchAll(
                "SELECT id, rbfid, overlay_src, overlay_dst, mode, mounted, dst_perms, 'specific' as source
                 FROM overlays
                 WHERE rbfid = :rbfid
                 ORDER BY overlay_dst",
                [':rbfid' => $clientId]
            );

            // 2. Overlays de plantilla (tabla overlay_cliente_mounts)
            $templateOverlays = $this->db->fetchAll(
                "SELECT cm.id, cm.rbfid, cm.overlay_src, cm.overlay_dst, cm.mode, cm.mounted,
                        COALESCE(p.dst_perms, 'exclusive') as dst_perms, 'template' as source,
                        p.auto_mount as template_auto_mount
                 FROM overlay_cliente_mounts cm
                 LEFT JOIN overlay_plantillas p ON p.id = cm.origen_id
                 WHERE cm.rbfid = :rbfid
                 ORDER BY cm.overlay_dst",
                [':rbfid' => $clientId]
            );

            // 3. Auditar cada overlay
            foreach (array_merge($specificOverlays, $templateOverlays) as $overlay) {
                $result = $this->auditOverlay($overlay, $clientId);
                if ($result['action'] === 'mounted') $stats['mounted']++;
                if ($result['action'] === 'unmounted') $stats['unmounted']++;
                if ($result['action'] === 'permissions_fixed') $stats['permissions_fixed']++;
                if ($result['action'] === 'db_corrected') $stats['db_corrected']++;
                if ($result['action'] === 'error') $stats['errors']++;
            }

            return ['ok' => true, 'stats' => $stats];

        } catch (\Exception $e) {
            error_log("[AUDIT] Excepción en cliente {$clientId}: " . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage(), 'stats' => $stats];
        }
    }

    /**
     * Auditoría de un overlay individual
     *
     * Lógica (BD = fuente de verdad):
     * - specific overlays: campo 'mounted' determina estado deseado
     * - template overlays: campo 'auto_mount' determina estado deseado
     *
     * | Desired | Actual | Acción                    |
     * |---------|--------|---------------------------|
     * | mount   | mount  | OK - verificar permisos   |
     * | mount   | no     | REMONTAR                  |
     * | no      | mount  | DESMONTAR                 |
     * | no      | no     | OK                        |
     */
    private function auditOverlay(array $overlay, string $clientId): array {
        $id = $overlay['id'];
        $source = $overlay['source'];
        $dst = "/home/{$clientId}/{$overlay['overlay_dst']}";
        $src = $overlay['overlay_src'];
        $mode = $overlay['mode'];
        $perms = $overlay['dst_perms'] ?? 'exclusive';
        $readonly = ($mode === 'ro');

        // Determinar estado deseado
        if ($source === 'specific') {
            $shouldBeMounted = ($overlay['mounted'] === 't' || $overlay['mounted'] === true);
        } else {
            // Template: auto_mount determina estado deseado
            $shouldBeMounted = ($overlay['template_auto_mount'] === 't' || $overlay['template_auto_mount'] === true);
        }

        $isActuallyMounted = $this->mountService->isMounted($dst);

        // Caso 1: Debe estar montado y está montado - OK, verificar permisos
        if ($shouldBeMounted && $isActuallyMounted) {
            $this->fixPermissions($src, $readonly, $perms);
            // Asegurar que DB refleja estado real
            if ($source === 'template' && $overlay['mounted'] !== 't' && $overlay['mounted'] !== true) {
                $this->db->execute(
                    "UPDATE overlay_cliente_mounts SET mounted = 'true' WHERE id = :id",
                    [':id' => $id]
                );
                error_log("[AUDIT] DB corregida: {$dst} mounted=false -> true");
                return ['action' => 'db_corrected', 'dst' => $dst];
            }
            return ['action' => 'ok', 'dst' => $dst];
        }

        // Caso 2: Debe estar montado pero NO está montado - REMONTAR
        if ($shouldBeMounted && !$isActuallyMounted) {
            error_log("[AUDIT] Remontando: {$src} -> {$dst} (mode: {$mode})");
            $result = $this->mountService->mountBind($src, $dst, $readonly, $perms);
            if ($result['ok']) {
                // Actualizar DB si es necesario
                if ($source === 'template') {
                    $this->db->execute(
                        "UPDATE overlay_cliente_mounts SET mounted = 'true' WHERE id = :id",
                        [':id' => $id]
                    );
                }
                error_log("[AUDIT] ✓ Remontado: {$dst}");
                return ['action' => 'mounted', 'dst' => $dst];
            } else {
                error_log("[AUDIT] ✗ Error remontando: {$dst}");
                return ['action' => 'error', 'dst' => $dst];
            }
        }

        // Caso 3: NO debe estar montado pero está montado - DESMONTAR
        if (!$shouldBeMounted && $isActuallyMounted) {
            error_log("[AUDIT] Desmontando: {$dst} (no debería estar montado)");
            $result = $this->mountService->umount($dst);
            if ($result['ok']) {
                // Actualizar DB si es necesario
                if ($source === 'specific') {
                    $this->db->execute(
                        "UPDATE overlays SET mounted = 'false' WHERE id = :id",
                        [':id' => $id]
                    );
                } else {
                    $this->db->execute(
                        "UPDATE overlay_cliente_mounts SET mounted = 'false' WHERE id = :id",
                        [':id' => $id]
                    );
                }
                error_log("[AUDIT] ✓ Desmontado: {$dst}");
                return ['action' => 'unmounted', 'dst' => $dst];
            } else {
                error_log("[AUDIT] ✗ Error desmontando: {$dst}");
                return ['action' => 'error', 'dst' => $dst];
            }
        }

        // Caso 4: No debe estar montado y no está montado - OK
        return ['action' => 'ok', 'dst' => $dst];
    }

    /**
     * Verificar y corregir permisos de un directorio origen
     */
    private function fixPermissions(string $src, bool $readonly, string $perms): void {
        if ($perms === 'group') {
            $targetPerms = $readonly ? '0550' : '0770';
            $this->mountService->ensurePermissions($src, $targetPerms, 'users');
        }
    }
}
