<?php
/**
 * PathUtils - Utilidades para validación y normalización de paths
 */

class PathUtils {
    
    /**
     * Normalizar path de overlay
     * - Si empieza con /: path absoluto
     * - Si no: path relativo a /srv/
     * 
     * @param string $path Path a normalizar
     * @return string Path normalizado
     * @throws InvalidArgumentException Si el path es inválido
     */
    public static function normalizeOverlayPath(string $path): string {
        // Validar que no esté vacío
        if (empty(trim($path))) {
            throw new InvalidArgumentException("Path no puede estar vacío");
        }
        
        $path = trim($path);
        
        // Path absoluto (empieza con /)
        if (strpos($path, '/') === 0) {
            // Validar formato de path absoluto
            if (!self::isValidAbsolutePath($path)) {
                throw new InvalidArgumentException("Path absoluto inválido: $path");
            }
            return $path;
        }
        
        // Path relativo - convertir a /srv/
        $normalized = "/srv/" . ltrim($path, '/');
        
        // Validar formato de path relativo convertido
        if (!self::isValidAbsolutePath($normalized)) {
            throw new InvalidArgumentException("Path relativo inválido: $path");
        }
        
        return $normalized;
    }
    
    /**
     * Validar si un path absoluto es válido
     * 
     * @param string $path Path absoluto
     * @return bool True si es válido
     */
    private static function isValidAbsolutePath(string $path): bool {
        // Debe empezar con /
        if (strpos($path, '/') !== 0) {
            return false;
        }
        
        // Validar caracteres permitidos: letras, números, _, -, ., /
        if (!preg_match('/^\/[a-zA-Z0-9_\-\.\/]+$/', $path)) {
            return false;
        }
        
        // No permitir secuencias peligrosas
        if (strpos($path, '..') !== false || strpos($path, '//') !== false) {
            return false;
        }
        
        // Longitud máxima razonable
        if (strlen($path) > 255) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Validar nombre de destino (overlay_dst)
     * 
     * @param string $dst Nombre de destino
     * @return bool True si es válido
     */
    public static function isValidDestination(string $dst): bool {
        // No puede estar vacío
        if (empty(trim($dst))) {
            return false;
        }
        
        $dst = trim($dst);
        
        // Validar caracteres permitidos: letras, números, _, -, .
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $dst)) {
            return false;
        }
        
        // No puede empezar o terminar con punto o guión
        if (preg_match('/^[\.\-]|[\.\-]$/', $dst)) {
            return false;
        }
        
        // Longitud máxima razonable
        if (strlen($dst) > 100) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Crear directorio con permisos específicos
     * 
     * @param string $path Path del directorio
     * @param int $permissions Permisos (por defecto 0775)
     * @param string $owner Owner (por defecto root)
     * @param string $group Group (por defecto users)
     * @return bool True si se creó/existe con permisos correctos
     */
    public static function ensureDirectory(string $path, int $permissions = 0775, string $owner = 'root', string $group = 'users'): bool {
        // Crear directorio si no existe
        if (!is_dir($path)) {
            if (!mkdir($path, $permissions, true)) {
                return false;
            }
        }
        
        // Aplicar permisos
        if (!chmod($path, $permissions)) {
            // Log warning pero continuar
            error_log("Warning: No se pudieron cambiar permisos en $path");
        }
        
        // Aplicar ownership (solo si somos root)
        if (posix_geteuid() === 0) {
            if (!chown($path, $owner) || !chgrp($path, $group)) {
                error_log("Warning: No se pudo cambiar ownership de $path a $owner:$group");
            }
        }
        
        return true;
    }
    
    /**
     * Procesar template source con placeholders
     * 
     * @param string $template Template con placeholders
     * @param string $clientId ID del cliente
     * @param array $clientData Datos del cliente (emp, plaza, razon_social)
     * @return string Template procesado
     */
    public static function processTemplateSource(string $template, string $clientId, array $clientData = []): string {
        $replacements = [
            '{rbfid}' => $clientId,
            '{emp}' => $clientData['emp'] ?? '',
            '{plaza}' => $clientData['plaza'] ?? '',
            '{razon_social}' => $clientData['razon_social'] ?? '',
        ];
        
        $processed = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $template
        );
        
        // Normalizar el path resultante
        return self::normalizeOverlayPath($processed);
    }
    
    /**
     * Validar modo de overlay
     * 
     * @param string $mode Modo a validar
     * @return bool True si es válido
     */
    public static function isValidMode(string $mode): bool {
        return in_array($mode, ['ro', 'rw']);
    }
}