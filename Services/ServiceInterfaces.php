<?php
/**
 * ServiceInterfaces - Interfaces para los servicios del sistema GIS
 * Permite implementar Factory Method Pattern para mejor testabilidad
 */

/**
 * ClientServiceInterface - Interfaz para el servicio de clientes
 */
interface ClientServiceInterface {
    public function create(string $clientId, bool $enabled, string $emp = '', string $plaza = ''): array;
    public function enable(string $clientId): array;
    public function disable(string $clientId): array;
    public function delete(string $clientId, bool $hard = false): array;
    public function getClient(string $clientId): ?array;
    public function listClients(): array;
    public function update(string $clientId, string $emp, string $plaza, bool $enabled): array;
    public function renewKey(string $clientId): array;
    // Métodos de gestión de overlays
    public function addOverlay(string $clientId, string $src, string $dst, string $mode, string $dstPerms = 'exclusive'): array;
    public function getMounts(string $clientId): array;
    // Métodos para descarga de claves
    public function enableKeyDownload(string $clientId): array;
    public function disableKeyDownload(string $clientId): array;
    public function resetKeyDownload(string $clientId): array;
    public function getKeyDownloadStatus(string $clientId): array;
}

/**
 * UserServiceInterface - Interfaz para el servicio de usuarios
 */
interface UserServiceInterface {
    public function create(string $username, string $homeDir): array;
    public function enable(string $username): array;
    public function disable(string $username): array;
    public function delete(string $username): array;
    public function exists(string $username): bool;
}

/**
 * SshServiceInterface - Interfaz para el servicio SSH
 */
interface SshServiceInterface {
    public function generate(string $clientId): array;
    public function enable(string $username): array;
    public function disable(string $username): array;
    public function deleteKeys(string $clientId): array;
    public function configureAuthorizedKeys(string $username, string $homeDir, string $publicKey): array;
}

/**
 * MountServiceInterface - Interfaz para el servicio de montaje
 */
interface MountServiceInterface {
    public function mountBind(string $src, string $dst, bool $readOnly = false): array;
    public function umount(string $path): array;
    public function isMounted(string $path): bool;
    public function getMountSource(string $path): ?string;
}

/**
 * OverlaySyncServiceInterface - Interfaz para el servicio de sincronización de overlays
 */
interface OverlaySyncServiceInterface {
    public function reconcileClientOverlays(string $clientId): array;
}

/**
 * SystemInterface - Interfaz para el wrapper de sistema
 */
interface SystemInterface {
    public function cmd(string $command, ?int &$exitCode = null): string;
    public function sudo(string $command, ?int &$exitCode = null): string;
}