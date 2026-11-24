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
$outputPath = $argv[1] ?? '';

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
$targetDirectory = $outputPath !== '' ? realpath(dirname($outputPath)) : false;
if ($projectRoot === false || $targetDirectory === false
    || $targetDirectory === $projectRoot
    || str_starts_with($targetDirectory . DIRECTORY_SEPARATOR, $projectRoot . DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "Informe um destino existente fora da pasta do projeto.\n");
    exit(1);
}

try {
    $result = \FitControl\Security\AuditIntegrityCheckpoint::export(
        $pdo,
        $databaseName,
        $secret,
        $outputPath
    );
    fwrite(STDOUT, "Checkpoint assinado criado.\n");
    fwrite(STDOUT, "Evento: #" . $result['checkpoint_event_id'] . "\n");
    fwrite(STDOUT, "SHA-256: " . $result['checkpoint_hash'] . "\n");
    fwrite(STDOUT, "Arquivo: " . $result['path'] . "\n");
    fwrite(STDOUT, "Copie o arquivo para armazenamento independente e protegido.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Falha ao exportar checkpoint: " . $error->getMessage() . "\n");
    exit(1);
}
