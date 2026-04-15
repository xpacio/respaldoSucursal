<?php
declare(strict_types=1);

class StreamHasher
{
    /**
     * Calcula hash de php://input sin almacenar el stream.
     *
     * @param string $algo Algoritmo de hash (ej. 'md5', 'xxh3')
     * @param int $chunkSize Tamaño de chunk en bytes (por defecto 128KB)
     * @return string Hash en formato hexadecimal
     * @throws \RuntimeException Si no se puede abrir el stream
     */
    public static function hashInput(string $algo = 'md5', int $chunkSize = 131072): string
    {
        $ctx = hash_init($algo);
        $in = fopen('php://input', 'rb');
        if ($in === false) {
            throw new \RuntimeException('No se pudo abrir php://input');
        }

        try {
            while (!feof($in)) {
                $buf = fread($in, $chunkSize);
                if ($buf === false || $buf === '') {
                    break;
                }
                hash_update($ctx, $buf);
            }
        } finally {
            fclose($in);
        }

        return hash_final($ctx);
    }

    /**
     * Calcula hash de php://input y almacena el stream en un recurso temporal.
     *
     * @param string $algo Algoritmo de hash
     * @param int $chunkSize Tamaño de chunk en bytes
     * @param int $maxMemory Memoria máxima en bytes para php://temp (por defecto 1.5MB)
     * @return array{hash: string, stream: resource} Hash y recurso de stream (posicionado al inicio)
     * @throws \RuntimeException Si no se puede abrir el stream
     */
    public static function hashInputWithStream(string $algo = 'md5', int $chunkSize = 131072, int $maxMemory = 1572864): array
    {
        $ctx = hash_init($algo);
        $in = fopen('php://input', 'rb');
        if ($in === false) {
            throw new \RuntimeException('No se pudo abrir php://input');
        }

        $out = fopen("php://temp/maxmemory:$maxMemory", 'w+b');
        if ($out === false) {
            fclose($in);
            throw new \RuntimeException('No se pudo crear stream temporal');
        }

        try {
            while (!feof($in)) {
                $buf = fread($in, $chunkSize);
                if ($buf === false || $buf === '') {
                    break;
                }
                hash_update($ctx, $buf);
                fwrite($out, $buf);
            }
        } finally {
            fclose($in);
            rewind($out);
        }

        return ['hash' => hash_final($ctx), 'stream' => $out];
    }

    /**
     * Calcula hash de un archivo por chunks.
     *
     * @param string $filePath Ruta al archivo
     * @param string $algo Algoritmo de hash (por defecto 'xxh3')
     * @param int $chunkSize Tamaño de chunk en bytes (por defecto 8KB)
     * @return string Hash en formato hexadecimal
     * @throws \RuntimeException Si no se puede abrir el archivo
     */
    public static function hashFile(string $filePath, string $algo = 'xxh3', int $chunkSize = 8192): string
    {
        $ctx = hash_init($algo);
        $fh = fopen($filePath, 'rb');
        if ($fh === false) {
            throw new \RuntimeException("No se pudo abrir archivo: $filePath");
        }

        try {
            while (!feof($fh)) {
                $buf = fread($fh, $chunkSize);
                if ($buf === false || $buf === '') {
                    break;
                }
                hash_update($ctx, $buf);
            }
        } finally {
            fclose($fh);
        }

        return hash_final($ctx);
    }

    /**
     * Calcula hash de un archivo usando el método más eficiente disponible.
     * Para archivos pequeños (<5MB) usa hash_file() si está disponible.
     * Para archivos grandes (≥5MB) usa streaming por chunks para limitar uso de RAM.
     *
     * @param string $filePath Ruta al archivo
     * @param string $algo Algoritmo de hash (por defecto 'xxh3')
     * @param int $streamingThreshold Umbral en bytes para activar streaming (por defecto 5MB)
     * @return string Hash en formato hexadecimal
     * @throws \RuntimeException Si no se puede abrir el archivo
     */
    public static function hashFileEfficient(string $filePath, string $algo = 'xxh3', int $streamingThreshold = 5242880): string
    {
        $fileSize = @filesize($filePath);
        if ($fileSize === false) {
            throw new \RuntimeException("No se puede obtener tamaño del archivo: $filePath");
        }

        // Para archivos pequeños, usar hash_file() si está disponible (más eficiente)
        if ($fileSize < $streamingThreshold && function_exists('hash_file') && in_array($algo, hash_algos())) {
            $hash = hash_file($algo, $filePath);
            if ($hash !== false) {
                return $hash;
            }
        }

        // Para archivos grandes o fallback, usar streaming con chunk size adaptativo
        $chunkSize = self::calculateOptimalChunkSize($fileSize);
        return self::hashFile($filePath, $algo, $chunkSize);
    }

    /**
     * Calcula tamaño de chunk óptimo basado en tamaño de archivo.
     * Mantiene uso de RAM bajo (<5MB) incluso para archivos muy grandes.
     *
     * @param int $fileSize Tamaño del archivo en bytes
     * @return int Tamaño de chunk en bytes
     */
    private static function calculateOptimalChunkSize(int $fileSize): int
    {
        // Para archivos muy grandes, usar chunks más grandes para mejor rendimiento
        if ($fileSize > 100 * 1024 * 1024) { // >100MB
            return 1024 * 1024; // 1MB
        }
        if ($fileSize > 10 * 1024 * 1024) { // >10MB
            return 256 * 1024; // 256KB
        }
        if ($fileSize > 1 * 1024 * 1024) { // >1MB
            return 64 * 1024; // 64KB
        }
        return 8 * 1024; // 8KB para archivos pequeños
    }
}