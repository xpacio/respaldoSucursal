<?php

namespace Shared\Backup;

class TotpValidator {
    public function validate(string $rbfid, string $token): array {
        if (empty($rbfid) || empty($token)) {
            return ['ok' => false, 'error' => 'rbfid y token requeridos'];
        }

        $now = time();
        for ($delta = -30; $delta <= 30; $delta += 1) {
            $ts = (string)($now + $delta);
            $seed = substr($ts, 0, -2);
            $expected = $this->xxh3Token($seed . $rbfid);
            if (hash_equals($expected, $token)) {
                return ['ok' => true];
            }
        }

        return ['ok' => false, 'error' => 'Token invalido', 'code' => 'TOTP_INVALID'];
    }

    private function xxh3Token(string $input): string {
        $hexHash = hash('xxh3', $input);
        $bytes = hex2bin($hexHash);
        $le = strrev($bytes);
        $b64 = base64_encode($le);
        return substr($b64, 0, 11);
    }
}