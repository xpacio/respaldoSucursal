<?php

namespace Shared\Backup;

use Shared\Backup\BackupSessionRepositoryInterface;
use Shared\Backup\ChunkDTO;
use Shared\Backup\TotpValidator;

class BackupApiController {
    public function __construct(
        private BackupSessionRepositoryInterface $repository,
        private ?TotpValidator $totpValidator = null
    ) {}

    public function init(array $request): array {
        $this->validateAuth($request);

        $sessionId = $this->repository->create([
            'filename' => $request['filename'] ?? '',
            'file_hash' => $request['file_hash'] ?? '',
            'file_size' => $request['file_size'] ?? 0,
            'total_chunks' => $request['total_chunks'] ?? 0,
            'rbfid' => $request['rbfid'] ?? '',
        ]);

        return ['session_id' => $sessionId, 'status' => 'ready'];
    }

    public function receiveChunk(array $request): array {
        $this->validateAuth($request);

        $chunk = new ChunkDTO(
            $request['session_id'],
            $request['file_hash'],
            $request['chunk_index'],
            $request['chunk_hash'],
            $request['data'],
            $request['is_last']
        );

        $command = new ProcessChunkCommand($this->repository, $chunk);

        try {
            return $command->execute();
        } catch (\Throwable $e) {
            $command->undo();
            return ['error' => $e->getMessage()];
        }
    }

    public function finalize(array $request): array {
        $this->validateAuth($request);

        $sessionId = $request['session_id'] ?? '';
        if (empty($sessionId)) {
            return ['error' => 'session_id requerido'];
        }

        $verified = $this->repository->verifyAndFinalize($sessionId);

        return [
            'verified' => $verified,
            'finalized' => $verified,
            'session_id' => $sessionId
        ];
    }

    private function validateAuth(array $request): void {
        if ($this->totpValidator === null) {
            return;
        }

        $rbfid = $request['rbfid'] ?? '';
        $token = $request['totp_token'] ?? '';

        if (empty($rbfid) || empty($token)) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Faltan credenciales']);
            exit;
        }

        $result = $this->totpValidator->validate($rbfid, $token);
        if (!$result['ok']) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Token inválido']);
            exit;
        }
    }
}