<?php

require_once __DIR__ . '/../autoload.php';

use Shared\Backup\BackupApiController;
use Shared\Backup\FileSystemBackupRepository;

function assertEquals($expected, $actual, $message = '') {
    if ($expected !== $actual) {
        echo "ASSERTION FAILED: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n";
        exit(1);
    }
}

$repository = new FileSystemBackupRepository();
$controller = new BackupApiController($repository);

$original = 'hello world';
$chunkData = base64_encode('hello ');
$chunkHash = hash('xxh3', 'hello ');
$lastData = base64_encode('world');
$lastHash = hash('xxh3', 'world');
$fileHash = hash('xxh3', 'hello world');

$response = $controller->init(['filename' => 'test_backup.txt', 'file_hash' => $fileHash]);
assertEquals(true, isset($response['session_id']), 'init debe devolver session_id');
$sessionId = $response['session_id'];

$response = $controller->receiveChunk([
    'session_id' => $sessionId,
    'file_hash' => $fileHash,
    'chunk_index' => 0,
    'chunk_hash' => $chunkHash,
    'data' => $chunkData,
    'is_last' => false,
]);
assertEquals(true, $response['verified'], 'primer chunk debe estar verificado');

$response = $controller->receiveChunk([
    'session_id' => $sessionId,
    'file_hash' => $fileHash,
    'chunk_index' => 1,
    'chunk_hash' => $lastHash,
    'data' => $lastData,
    'is_last' => true,
]);
assertEquals(true, $response['verified'], 'último chunk debe estar verificado');
assertEquals(true, $response['finalized'], 'backup debe finalizar');

echo "Backup flow test passed\n";
