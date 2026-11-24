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
$checkpointPath = $argv[1] ?? '';

if (!is_string($secret) || strlen($secret) < 32) {
    fwrite(STDERR, "Defina FITCONTROL_AUDIT_CHECKPOINT_SECRET com pelo menos 32 bytes aleatórios.\n");
    exit(1);
}

$actualDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!hash_equals($actualDatabase, $databaseName)) {
    fwrite(STDERR, "DB_NAME não corresponde ao banco conectado (" . $actualDatabase . ").\n");
    exit(1);
}

$projectRoot = realpath(__DIR__ . '/..');
$checkpointDirectory = $checkpointPath !== '' ? realpath(dirname($checkpointPath)) : false;
if ($projectRoot === false || $checkpointDirectory === false
    || $checkpointDirectory === $projectRoot
    || str_starts_with($checkpointDirectory . DIRECTORY_SEPARATOR, $projectRoot . DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "Informe um checkpoint armazenado fora da pasta do projeto.\n");
    exit(1);
}

$result = \FitControl\Security\AuditIntegrityCheckpoint::verify(
    $pdo,
    $databaseName,
    $secret,
    $checkpointPath
);

if (!$result['ok']) {
    fwrite(STDERR, "FALHA DE CHECKPOINT: " . $result['message'] . "\n");
    exit(1);
}

fwrite(STDOUT, $result['message'] . "\n");
fwrite(STDOUT, "Evento assinado: #" . $result['checkpoint_event_id'] . "\n");
fwrite(STDOUT, "Evento atual: #" . $result['current_event_id'] . "\n");
fwrite(STDOUT, "Eventos posteriores ao checkpoint: " . $result['events_since_checkpoint'] . "\n");
