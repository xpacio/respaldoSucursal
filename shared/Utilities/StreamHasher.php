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
}