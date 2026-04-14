<?php
/**
 * LoggerFactory - Factory para crear Logger basado en configuración
 */
class LoggerFactory {
    /**
     * Crear Logger basado en configuración
     */
    public static function createFromConfig(array $config, Database $db): Logger {
        return Logger::create($db);
    }
}
