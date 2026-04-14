<?php

namespace Client\Pipeline;

// Interfaz para cada etapa del pipeline
interface PipelineStageInterface {
    public function process(mixed $payload): mixed;
}

// ETAPA 1: Cálculo de hash XXH3
class HashCalculationStage implements PipelineStageInterface {
    public function process(mixed $payload): mixed {
        $filePath = $payload['file_path'];
        
        // XXH3 es ultra-rápido para verificación de integridad
        $hash = hash_file('xxh3', $filePath);
        
        return [
            ...$payload,
            'file_hash' => $hash,
            'file_size' => filesize($filePath)
        ];
    }
}

// ETAPA 2: División en chunks
class ChunkingStage implements PipelineStageInterface {
    public function __construct(private int $chunkSize = 1024 * 1024) {} // 1MB default
    
    public function process(mixed $payload): mixed {
        $filePath = $payload['file_path'];
        $chunks = [];
        $handle = fopen($filePath, 'rb');
        $index = 0;
        
        while (!feof($handle)) {
            $chunkData = fread($handle, $this->chunkSize);
            $chunkHash = hash('xxh3', $chunkData); // Hash individual por chunk
            
            $chunks[] = [
                'index' => $index,
                'data' => base64_encode($chunkData), // o binario puro con streams
                'hash' => $chunkHash,
                'size' => strlen($chunkData)
            ];
            $index++;
        }
        
        fclose($handle);
        
        return [
            ...$payload,
            'chunks' => $chunks,
            'total_chunks' => $index
        ];
    }
}

// ETAPA 3: Transferencia con reintentos
class TransferStage implements PipelineStageInterface {
    public function __construct(
        private string $serverEndpoint,
        private int $maxRetries = 3
    ) {}
    
    public function process(mixed $payload): mixed {
        $sessionId = $this->initiateSession($payload);
        
        foreach ($payload['chunks'] as $chunk) {
            $this->uploadChunkWithRetry($sessionId, $chunk, $payload['file_hash']);
        }
        
        $this->finalizeSession($sessionId);
        return ['status' => 'completed', 'session_id' => $sessionId];
    }
    
    private function uploadChunkWithRetry(string $sessionId, array $chunk, string $fileHash): void {
        $attempts = 0;
        
        while ($attempts < $this->maxRetries) {
            try {
                $response = $this->sendChunk($sessionId, $chunk, $fileHash);
                
                if ($response['verified']) {
                    return; // Éxito
                }
                
                throw new \RuntimeException("Hash mismatch en chunk {$chunk['index']}");
                
            } catch (\Throwable $e) {
                $attempts++;
                if ($attempts >= $this->maxRetries) {
                    throw new \RuntimeException(
                        "Fallo transferencia chunk {$chunk['index']} tras {$this->maxRetries} intentos: " . $e->getMessage()
                    );
                }
                usleep(100000 * $attempts); // Backoff exponencial
            }
        }
    }
    
    private function sendChunk(string $sessionId, array $chunk, string $fileHash): array {
        $ch = curl_init("{$this->serverEndpoint}/chunk");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'session_id' => $sessionId,
                'file_hash' => $fileHash,
                'chunk_index' => $chunk['index'],
                'chunk_hash' => $chunk['hash'],
                'data' => $chunk['data'],
                'is_last' => $chunk['index'] === count($chunk) - 1
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true);
    }
    
    private function initiateSession(array $payload): string {
        // Inicializa sesión en servidor
        $ch = curl_init("{$this->serverEndpoint}/init");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'filename' => basename($payload['file_path']),
                'file_hash' => $payload['file_hash'],
                'total_size' => $payload['file_size'],
                'total_chunks' => $payload['total_chunks']
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);
        
        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        return $response['session_id'];
    }
}

// ORQUESTADOR del Pipeline
class BackupPipeline {
    private array $stages = [];
    
    public function addStage(PipelineStageInterface $stage): self {
        $this->stages[] = $stage;
        return $this;
    }
    
    public function execute(array $payload): array {
        $result = $payload;
        
        foreach ($this->stages as $stage) {
            $result = $stage->process($result);
        }
        
        return $result;
    }
}

// USO
$pipeline = new BackupPipeline();
$result = $pipeline
    ->addStage(new HashCalculationStage())
    ->addStage(new ChunkingStage(chunkSize: 2 * 1024 * 1024)) // 2MB chunks
    ->addStage(new TransferStage('https://backup.server.com/api'))
    ->execute(['file_path' => '/ruta/al/archivo.zip']);