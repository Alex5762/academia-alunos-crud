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
$directory = $argv[1] ?? '';

if (!is_string($secret) || strlen($secret) < 32) {
    fwrite(STDERR, "Defina FITCONTROL_AUDIT_CHECKPOINT_SECRET com pelo menos 32 bytes aleatórios.\n");
    exit(1);
}

$resolvedDirectory = $directory !== '' ? realpath($directory) : false;
$projectRoot = realpath(__DIR__ . '/..');
if (
    $resolvedDirectory === false
    || !is_dir($resolvedDirectory)
    || $projectRoot === false
    || $resolvedDirectory === $projectRoot
    || str_starts_with($resolvedDirectory . DIRECTORY_SEPARATOR, $projectRoot . DIRECTORY_SEPARATOR)
) {
    fwrite(STDERR, "Informe uma pasta existente fora da pasta do projeto.\n");
    exit(1);
}

$files = glob($resolvedDirectory . DIRECTORY_SEPARATOR . '*.json') ?: [];
sort($files, SORT_STRING);

if ($files === []) {
    fwrite(STDOUT, "Nenhum checkpoint JSON encontrado.\n");
    exit(0);
}

$hasInvalid = false;
foreach ($files as $file) {
    $result = \FitControl\Security\AuditIntegrityCheckpoint::verify(
        $pdo,
        $databaseName,
        $secret,
        $file
    );

    $name = basename($file);
    if (!$result['ok']) {
        $hasInvalid = true;
        fwrite(STDOUT, "[INVALIDO] " . $name . " — " . $result['message'] . "\n");
        continue;
    }

    fwrite(
        STDOUT,
        "[VALIDO] " . $name
        . " — assinado #" . $result['checkpoint_event_id']
        . ", atual #" . $result['current_event_id']
        . ", posteriores " . $result['events_since_checkpoint']
        . "\n"
    );
}

if ($hasInvalid) {
    fwrite(STDERR, "Um ou mais checkpoints não passaram na verificação.\n");
    exit(1);
}

fwrite(STDOUT, "Todos os checkpoints encontrados são válidos.\n");
