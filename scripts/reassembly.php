<?php
/**
 * AR Reassembly - Reconstruir archivos desde chunks
 * 
 * Uso: php reassembly.php {rbfid} {filename}
 * 
 * Proceso:
 * 1. Busca chunks en .work/{rbfid}/
 * 2. Los ordena por número de chunk
 * 3. Reconstruye el archivo
 * 4. Lo mueve a destino final
 */

require_once __DIR__ . '/Config/Database.php';

function main(array $argv): void {
    $rbfid = $argv[1] ?? '';
    $filename = $argv[2] ?? '';
    
    if (empty($rbfid) || empty($filename)) {
        echo "Uso: php reassembly.php {rbfid} {filename}\n";
        exit(1);
    }

    $config = require __DIR__ . '/Config/config.php';
    $db = new Database($config['db']);

    $clientData = getClientPath($db, $rbfid);
    if (!$clientData) {
        echo "Error: Cliente $rbfid no encontrado\n";
        exit(1);
    }

    $workDir = $clientData['work_dir'];
    $destDir = $clientData['base_dir'];
    
    if (!is_dir($workDir)) {
        echo "Error: Directorio de trabajo no existe: $workDir\n";
        exit(1);
    }

    echo "Reconstruyendo $filename para $rbfid...\n";
    
    $chunks = findChunks($workDir, $rbfid, $filename);
    if (empty($chunks)) {
        echo "Error: No se encontraron chunks para $filename\n";
        exit(1);
    }

    echo "Encontrados " . count($chunks) . " chunks\n";

    $tempFile = $workDir . '/' . $filename . '.tmp';
    $handle = fopen($tempFile, 'wb');
    if (!$handle) {
        echo "Error: No se pudo crear archivo temporal\n";
        exit(1);
    }

    foreach ($chunks as $chunk) {
        $data = file_get_contents($chunk['path']);
        if ($data === false) {
            echo "Error: No se pudo leer chunk {$chunk['index']}\n";
            fclose($handle);
            unlink($tempFile);
            exit(1);
        }
        fwrite($handle, $data);
        echo "  Chunk {$chunk['index']}: " . strlen($data) . " bytes\n";
    }

    fclose($handle);

    $destFile = $destDir . '/' . $filename;
    $destDir = dirname($destFile);
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    if (file_exists($destFile)) {
        $backupFile = $destFile . '.bak.' . date('YmdHis');
        rename($destFile, $backupFile);
        echo "Backup: $backupFile\n";
    }

    if (!rename($tempFile, $destFile)) {
        echo "Error: No se pudo mover archivo a destino\n";
        unlink($tempFile);
        exit(1);
    }

    cleanupChunks($workDir, $rbfid, $filename);

    echo "Completado: $destFile\n";

    $db->execute(
        "UPDATE ar_files SET updated_at = NOW() WHERE rbfid = :rbfid AND file_name = :file_name",
        [':rbfid' => $rbfid, ':file_name' => $filename]
    );
}

function getClientPath(PDODatabase $db, string $rbfid): ?array {
    $client = $db->fetchOne(
        "SELECT emp, plaza FROM clients WHERE rbfid = :rbfid",
        [':rbfid' => $rbfid]
    );

    if (!$client) {
        return null;
    }

    $basePath = '/srv/qbck/' . $client['emp'] . '/' . $client['plaza'] . '/' . $rbfid;
    return [
        'emp' => $client['emp'],
        'plaza' => $client['plaza'],
        'base_dir' => $basePath,
        'work_dir' => $basePath . '/.work'
    ];
}

function findChunks(string $workDir, string $rbfid, string $filename): array {
    $pattern = $workDir . '/' . $rbfid . '_' . $filename . '.*.chunk';
    $files = glob($pattern);
    
    $chunks = [];
    foreach ($files as $file) {
        $basename = basename($file);
        if (preg_match('/\.(\d{4})\.[a-zA-Z0-9+\/=]+\.chunk$/', $basename, $m)) {
            $chunks[] = [
                'path' => $file,
                'index' => (int) $m[1]
            ];
        }
    }

    usort($chunks, fn($a, $b) => $a['index'] - $b['index']);
    return $chunks;
}

function cleanupChunks(string $workDir, string $rbfid, string $filename): void {
    $pattern = $workDir . '/' . $rbfid . '_' . $filename . '.*.chunk';
    $files = glob($pattern);
    foreach ($files as $file) {
        unlink($file);
    }
}

main($argv);