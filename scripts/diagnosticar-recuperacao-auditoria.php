<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Recurso não encontrado.');
}

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/../src/Security/AuditIntegrityChain.php';
require_once __DIR__ . '/../src/Security/AuditIntegrityCheckpoint.php';
require_once __DIR__ . '/../src/Security/AuditRecoveryDiagnostic.php';

$secret = getenv('FITCONTROL_AUDIT_CHECKPOINT_SECRET');
$databaseName = getenv('DB_NAME') ?: 'academia_alunos';
$checkpointDirectory = $argv[1] ?? '';
$reportPath = $argv[2] ?? '';

if (!is_string($secret) || preg_match('/^[a-fA-F0-9]{64,}$/D', $secret) !== 1) {
    fwrite(STDERR, "Defina FITCONTROL_AUDIT_CHECKPOINT_SECRET com pelo menos 64 caracteres hexadecimais (32 bytes de entropia).\n");
    exit(1);
}

$projectRoot = realpath(__DIR__ . '/..');
$resolvedDirectory = $checkpointDirectory !== '' ? realpath($checkpointDirectory) : false;
if (
    $projectRoot === false
    || $resolvedDirectory === false
    || !is_dir($resolvedDirectory)
    || $resolvedDirectory === $projectRoot
    || str_starts_with($resolvedDirectory . DIRECTORY_SEPARATOR, $projectRoot . DIRECTORY_SEPARATOR)
) {
    fwrite(STDERR, "Informe uma pasta de checkpoints existente e externa ao projeto.\n");
    exit(1);
}

$checkpointPaths = glob($resolvedDirectory . DIRECTORY_SEPARATOR . '*.json') ?: [];
sort($checkpointPaths, SORT_STRING);

$report = \FitControl\Security\AuditRecoveryDiagnostic::diagnose(
    $pdo,
    $databaseName,
    $secret,
    $checkpointPaths
);

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($json)) {
    fwrite(STDERR, "Não foi possível serializar o relatório de diagnóstico.\n");
    exit(1);
}
$json .= PHP_EOL;

if ($reportPath !== '') {
    $reportDirectory = realpath(dirname($reportPath));
    if (
        $reportDirectory === false
        || $reportDirectory === $projectRoot
        || str_starts_with($reportDirectory . DIRECTORY_SEPARATOR, $projectRoot . DIRECTORY_SEPARATOR)
    ) {
        fwrite(STDERR, "O relatório deve ser salvo em uma pasta existente fora do projeto.\n");
        exit(1);
    }

    $target = $reportDirectory . DIRECTORY_SEPARATOR . basename($reportPath);
    if (file_exists($target)) {
        fwrite(STDERR, "O relatório de destino já existe; escolha um nome novo para preservar evidências anteriores.\n");
        exit(1);
    }

    $temporary = tempnam($reportDirectory, '.fitcontrol-recovery-');
    if ($temporary === false || file_put_contents($temporary, $json, LOCK_EX) === false) {
        if (is_string($temporary) && is_file($temporary)) {
            unlink($temporary);
        }
        fwrite(STDERR, "Não foi possível gravar o relatório temporário.\n");
        exit(1);
    }

    if (!rename($temporary, $target)) {
        unlink($temporary);
        fwrite(STDERR, "Não foi possível publicar o relatório de forma atômica.\n");
        exit(1);
    }

    fwrite(STDOUT, "Relatório salvo: " . $target . PHP_EOL);
}

fwrite(STDOUT, $json);

$exitCodes = [
    'consistent_through_checkpoint' => 0,
    'insufficient_evidence' => 3,
    'possible_older_restore' => 2,
    'chain_divergence' => 2,
    'chain_invalid' => 2,
    'diagnostic_error' => 1,
];

exit($exitCodes[$report['status']] ?? 1);
