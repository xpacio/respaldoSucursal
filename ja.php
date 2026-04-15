<?php
/**
 * @deprecated Este archivo contenía código experimental que ha sido refactorizado.
 * 
 * calculateChunkSizejA() → ver Chunk::calculateChunkSizeDynamic() en cli/Chunk.php
 * StreamInputTrait → ver StreamHasher en shared/Utilities/StreamHasher.php
 * 
 * El código original ha sido movido y mejorado con strict typing, manejo de errores
 * y alineación con las constantes del proyecto (CHUNK_MIN_SIZE, CHUNK_MAX_SIZE).
 */

// Función mantenida por compatibilidad (usar Chunk::calculateChunkSizeDynamic)
function calculateChunkSizejA(int $fileSize): int
{
    trigger_error('calculateChunkSizejA() está deprecated. Usar Chunk::calculateChunkSizeDynamic().', E_USER_DEPRECATED);
    require_once __DIR__ . '/cli/Chunk.php';
    return Chunk::calculateChunkSizeDynamic($fileSize);
}

// Trait mantenido por compatibilidad (usar StreamHasher)
trait StreamInputTrait {
    public string $streamHash;
    public $streamBody;

    public function getBodyStreamWithHash(): void {
        trigger_error('StreamInputTrait::getBodyStreamWithHash() está deprecated. Usar StreamHasher::hashInputWithStream().', E_USER_DEPRECATED);
        require_once __DIR__ . '/shared/Utilities/StreamHasher.php';
        $result = StreamHasher::hashInputWithStream('md5', 131072, 1572864);
        $this->streamHash = $result['hash'];
        $this->streamBody = $result['stream'];
    }
}