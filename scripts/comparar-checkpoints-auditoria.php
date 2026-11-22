<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Recurso não encontrado.');
}

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/../src/Security/AuditIntegrityCheckpoint.php';

$secret = getenv('FITCONTROL_AUDIT_CHECKPOINT_SECRET');
$databaseName = getenv('DB_NAME') ?: 'academia_alunos';
$olderPath = $argv[1] ?? '';
$newerPath = $argv[2] ?? '';

if (!is_string($secret) || strlen($secret) < 32) {
    fwrite(STDERR, "Defina FITCONTROL_AUDIT_CHECKPOINT_SECRET com pelo menos 32 bytes aleatórios.\n");
    exit(1);
}

$result = \FitControl\Security\AuditIntegrityCheckpoint::compare(
    $pdo,
    $databaseName,
    $secret,
    $olderPath,
    $newerPath
);

if (!$result['ok']) {
    fwrite(STDERR, "FALHA NA COMPARAÇÃO: " . $result['message'] . "\n");
    exit(1);
}

fwrite(STDOUT, $result['message'] . "\n");
fwrite(STDOUT, "Checkpoint anterior: #" . $result['older_event_id'] . "\n");
fwrite(STDOUT, "Checkpoint posterior: #" . $result['newer_event_id'] . "\n");
fwrite(STDOUT, "Diferença de identificadores: " . $result['events_between'] . "\n");
