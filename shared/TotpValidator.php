<?php
/**
 * TOTP Validator para cliente AR (Zig)
 *
 * Algoritmo:
 *   seed  = timestamp[0..len-2]   (quitar últimos 2 dígitos)
 *   input = seed + rbfid
 *   token = base64(xxh3_64(input))  → 11 chars
 *
 * El timestamp lo provee el servidor en action=init.
 * Tolerancia: ventana actual ± 30 segundos (timestamp puede variar ±30s).
 */

function validateTotp(Database $db, string $rbfid, string $token): array {
    if (empty($rbfid) || empty($token)) {
        return ['ok' => false, 'error' => 'rbfid y token requeridos'];
    }

    $client = $db->fetchOne(
        "SELECT enabled FROM clients WHERE rbfid = :rbfid",
        [':rbfid' => $rbfid]
    );

    if (!$client) {
        return ['ok' => false, 'error' => 'Cliente no encontrado', 'code' => 'CLIENT_NOT_FOUND'];
    }

    if ($client['enabled'] !== true && $client['enabled'] !== 't') {
        return ['ok' => false, 'error' => 'Cliente deshabilitado', 'code' => 'CLIENT_DISABLED'];
    }

    // Probar ventana actual ± 30s para tolerar drift de reloj
    $now = time();
    for ($delta = -30; $delta <= 30; $delta += 1) {
        $ts = (string)($now + $delta);
        $seed = substr($ts, 0, -2);
        $expected = xxh3_token($seed . $rbfid);
        if (hash_equals($expected, $token)) {
            return ['ok' => true];
        }
    }

    return ['ok' => false, 'error' => 'Token invalido', 'code' => 'TOTP_INVALID'];
}
