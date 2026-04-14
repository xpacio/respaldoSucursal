<?php
/**
 * SshService - Gestión de claves SSH
 * Las claves solo se almacenan en BD, no en filesystem
 */
require_once __DIR__ . '/ServiceInterfaces.php';
require_once __DIR__ . '/../Repositories/RepositoryFactory.php';

class SshService implements SshServiceInterface {
    private Database $db;
    private Logger $logger;
    private SystemInterface $system;
    private ClientRepository $clientRepository;

    public function __construct(Database $db, Logger $logger, System $system, ?ClientRepository $clientRepository = null) {
        $this->db = $db;
        $this->logger = $logger;
        $this->system = $system;
        $this->clientRepository = $clientRepository ?? RepositoryFactory::getClientRepository($db);
    }
    
    private function logStep(int $step, int $result, string $message): void {
        if ($this->logger->hasContext()) {
            $this->logger->stepWithContext($step, $result, $message);
        }
    }

    /**
     * Escribir archivo en destino con permisos de root via sudo cp
     * PHP escribe a /tmp, luego sudo cp + chown + chmod
     */
    private function writeFileAsRoot(string $content, string $destPath, string $owner, int $perms): bool {
        $tmpFile = tempnam('/tmp', 'ssh_write_');
        file_put_contents($tmpFile, $content);
        
        $exitCode = null;
        $this->system->sudo("cp " . escapeshellarg($tmpFile) . " " . escapeshellarg($destPath), $exitCode);
        unlink($tmpFile);
        
        if ($exitCode !== 0) return false;
        
        $this->system->sudo("chown " . escapeshellarg($owner) . ":" . escapeshellarg($owner) . " " . escapeshellarg($destPath), $exitCode);
        $this->system->sudo("chmod " . escapeshellarg(sprintf('%04o', $perms)) . " " . escapeshellarg($destPath), $exitCode);
        
        return $exitCode === 0;
    }

    public function generate(string $clientId): array {
        $this->logStep(10, 0, "Generando claves SSH para $clientId");

        // tempnam crea el archivo, hay que borrarlo para que ssh-keygen lo cree
        $tmpFile = tempnam('/tmp', 'ssh_keygen_');
        $pubFile = $tmpFile . '.pub';
        unlink($tmpFile);

        try {
            $cmd = sprintf(
                '/usr/bin/ssh-keygen -t ed25519 -f %s -N "" -C %s -q 2>&1',
                escapeshellarg($tmpFile),
                escapeshellarg("rsync@$clientId")
            );
            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0) {
                $this->logStep(98, 2, "ssh-keygen falló ($exitCode): " . implode("\n", $output));
                return ['ok' => false, 'error' => 'ssh-keygen falló'];
            }

            $privateKey = file_get_contents($tmpFile);
            $publicKey = trim(file_get_contents($pubFile));

            if (empty($privateKey) || empty($publicKey)) {
                return ['ok' => false, 'error' => 'Claves vacías después de ssh-keygen'];
            }

            return ['ok' => true, 'private_key' => $privateKey, 'public_key' => $publicKey];

        } finally {
            if (file_exists($tmpFile)) unlink($tmpFile);
            if (file_exists($pubFile)) unlink($pubFile);
        }
    }

    public function configureAuthorizedKeys(string $username, string $homeDir, string $publicKey): array {
        $restriction = "no-port-forwarding,no-X11-forwarding,no-agent-forwarding,no-pty,command=\"rsync-wrapper\"";
        $fullKey = "$restriction $publicKey";
        
        $exitCode = null;
        $this->logStep(11, 0, "Configurando authorized_keys para $username");
        
        $sshDir = "$homeDir/.ssh";
        $authKeys = "$sshDir/authorized_keys";
        
        // Crear .ssh con permisos correctos
        $this->system->sudo("mkdir -p " . escapeshellarg($sshDir), $exitCode);
        $this->system->sudo("chown " . escapeshellarg($username) . ":" . escapeshellarg($username) . " " . escapeshellarg($sshDir), $exitCode);
        $this->system->sudo("chmod 700 " . escapeshellarg($sshDir), $exitCode);
        
        // Escribir authorized_keys via sudo cp
        $this->writeFileAsRoot($fullKey, $authKeys, $username, 0600);

        return ['ok' => true];
    }

    public function enable(string $username): array {
        $exitCode = null;
        $this->logStep(12, 0, "Ejecutando script ssh-keys enable $username");
        
        $clientId = ltrim($username, '_');
        $authKeys = "/home/$clientId/.ssh/authorized_keys";
        
        // Habilitar: quitar # del inicio de cada línea
        $this->system->sudo("sed -i 's/^#//' " . escapeshellarg($authKeys) . " 2>/dev/null || true", $exitCode);
        $this->system->sudo("chown " . escapeshellarg($username) . ":" . escapeshellarg($username) . " " . escapeshellarg($authKeys) . " 2>/dev/null || true", $exitCode);
        $this->system->sudo("chmod 600 " . escapeshellarg($authKeys) . " 2>/dev/null || true", $exitCode);
        
        return ['ok' => $exitCode === 0];
    }

    public function disable(string $username): array {
        $exitCode = null;
        $this->logStep(13, 0, "Ejecutando script ssh-keys disable $username");
        
        $clientId = ltrim($username, '_');
        $authKeys = "/home/$clientId/.ssh/authorized_keys";
        
        // Deshabilitar: añadir # al inicio de cada línea
        $this->system->sudo("sed -i 's/^/#/' " . escapeshellarg($authKeys) . " 2>/dev/null || true", $exitCode);
        $this->system->sudo("chown " . escapeshellarg($username) . ":" . escapeshellarg($username) . " " . escapeshellarg($authKeys) . " 2>/dev/null || true", $exitCode);
        $this->system->sudo("chmod 600 " . escapeshellarg($authKeys) . " 2>/dev/null || true", $exitCode);
        
        return ['ok' => $exitCode === 0];
    }

    public function deleteKeys(string $clientId): array {
        return ['ok' => true];
    }

    public function ensureAuthorizedKeys(string $username, string $homeDir, string $publicKey): array {
        $clientId = ltrim($username, '_');
        $sshDir = "$homeDir/.ssh";
        $authKeys = "$sshDir/authorized_keys";
        $restriction = "no-port-forwarding,no-X11-forwarding,no-agent-forwarding,no-pty,command=\"rsync-wrapper\"";
        $fullKey = "$restriction $publicKey";
        
        $exitCode = null;
        
        // Verificar si .ssh existe, tiene permisos correctos Y authorized_keys existe
        $sshDirExists = is_dir($sshDir);
        $authKeysExists = file_exists($authKeys);
        
        if ($sshDirExists && $authKeysExists) {
            $perms = fileperms($sshDir) & 0777;
            if ($perms === 0700) {
                return ['ok' => true, 'action' => 'skipped', 'reason' => 'SSH ya configurado con permisos correctos'];
            }
        }
        
        // Crear/asegurar directorio .ssh con permisos correctos
        $this->system->sudo("mkdir -p " . escapeshellarg($sshDir), $exitCode);
        $this->system->sudo("chown " . escapeshellarg($username) . ":" . escapeshellarg($username) . " " . escapeshellarg($sshDir), $exitCode);
        $this->system->sudo("chmod 700 " . escapeshellarg($sshDir), $exitCode);
        
        // Escribir authorized_keys via sudo cp
        $this->writeFileAsRoot($fullKey, $authKeys, $username, 0600);
        
        // Verificar que se creó correctamente
        if (file_exists($authKeys) && (fileperms($authKeys) & 0777) === 0600) {
            return ['ok' => true, 'action' => 'created'];
        }
        
        return ['ok' => false, 'action' => 'error', 'error' => 'No se pudo configurar SSH'];
    }
}
