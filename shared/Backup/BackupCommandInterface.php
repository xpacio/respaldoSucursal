<?php

namespace Shared\Backup;

// Command Interface
interface BackupCommandInterface {
    public function execute(): array;
    public function undo(): void; // Para rollback si falla
}
