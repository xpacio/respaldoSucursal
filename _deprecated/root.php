<?php

function route_root(Router $r, string $resource) {
    $data = $r->getBody();
    $action = $data['action'] ?? 'health';

    match ($action) {
        'health'   => $r->jsonResponse(['ok' => true, 'status' => 'healthy', 'version' => '3.0.0']),
        'distinct' => root_distinct($r, $data),
        default    => $r->jsonResponse(['ok' => false, 'error' => "Acción '$action' no existe"], 404),
    };
}

/**
 * Endpoint genérico de distinct con búsqueda
 * POST /api/root  {action: "distinct", table, column, q}
 */
function root_distinct(Router $r, array $data) {
    $table = $data['table'] ?? '';
    $column = $data['column'] ?? '';
    $q = strtolower($data['q'] ?? '');

    $allowed = [
        'clients'      => ['rbfid', 'emp', 'plaza'],
        'distribucion' => ['tipo', 'plaza'],
        'overlays'     => ['overlay_src', 'overlay_dst', 'mode'],
    ];

    if (!isset($allowed[$table]) || !in_array($column, $allowed[$table])) {
        $r->jsonResponse(['ok' => false, 'error' => 'Tabla o columna no permitida'], 400);
    }

    $sql = "SELECT DISTINCT LOWER({$column}) as {$column} FROM {$table} WHERE {$column} IS NOT NULL AND {$column} != ''";
    $params = [];

    if ($q !== '') {
        $sql .= " AND LOWER({$column}) LIKE :q";
        $params[':q'] = "%{$q}%";
    }

    $sql .= " ORDER BY LOWER({$column}) LIMIT 50";

    $values = $r->db->fetchAll($sql, $params);
    $r->jsonResponse(['ok' => true, 'values' => array_column($values, $column)]);
}
