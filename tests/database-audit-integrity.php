<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Recurso não encontrado.');
}

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/../src/Security/AuditIntegrityChain.php';

function assertAuditIntegrity(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

if (($argv[1] ?? '') === '--worker') {
    \FitControl\Security\AuditIntegrityChain::append(
        $pdo,
        null,
        'integridade_concorrencia',
        'teste',
        null
    );
    exit(0);
}

$before = \FitControl\Security\AuditIntegrityChain::verify($pdo);
assertAuditIntegrity($before['ok'], 'A cadeia deveria estar íntegra antes do teste.');

$beforeCount = (int) $pdo->query('SELECT COUNT(*) FROM auditoria')->fetchColumn();
$pdo->beginTransaction();
\FitControl\Security\AuditIntegrityChain::append(
    $pdo,
    null,
    'integridade_rollback',
    'teste',
    null
);
$pdo->rollBack();

$afterRollbackCount = (int) $pdo->query('SELECT COUNT(*) FROM auditoria')->fetchColumn();
assertAuditIntegrity($afterRollbackCount === $beforeCount, 'Rollback deve desfazer evento e hash juntos.');
assertAuditIntegrity(
    \FitControl\Security\AuditIntegrityChain::verify($pdo)['ok'],
    'Rollback não pode quebrar a cadeia.'
);

$eventId = \FitControl\Security\AuditIntegrityChain::append(
    $pdo,
    null,
    'integridade_teste',
    'teste',
    null
);
assertAuditIntegrity($eventId > 0, 'O evento de teste deve receber um identificador.');
assertAuditIntegrity(
    \FitControl\Security\AuditIntegrityChain::verify($pdo)['ok'],
    'Evento novo deve gerar hash válido.'
);

foreach ([
    ['UPDATE auditoria SET acao = :acao WHERE id = :id', 'adulteracao'],
    ['DELETE FROM auditoria WHERE id = :id', null],
    ['UPDATE auditoria_integridade_eventos SET hash_evento = :hash WHERE evento_id = :id', str_repeat('a', 64)],
    ['DELETE FROM auditoria_integridade_eventos WHERE evento_id = :id', null],
] as [$sql, $value]) {
    $blocked = false;
    try {
        $statement = $pdo->prepare($sql);
        $params = ['id' => $eventId];
        if ($value !== null) {
            $key = str_contains($sql, 'SET acao') ? 'acao' : 'hash';
            $params[$key] = $value;
        }
        $statement->execute($params);
    } catch (PDOException $error) {
        $blocked = true;
    }

    assertAuditIntegrity($blocked, 'Trigger deveria bloquear alteração/exclusão direta da trilha.');
}

assertAuditIntegrity(
    \FitControl\Security\AuditIntegrityChain::verify($pdo)['ok'],
    'Tentativas bloqueadas não podem corromper a cadeia.'
);

// Duas conexões/processos tentam gravar em paralelo. A linha de estado
// serializa o encadeamento para que cada evento receba o hash anterior correto.
$processes = [];
for ($index = 0; $index < 4; $index++) {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(
        [PHP_BINARY, __FILE__, '--worker'],
        $descriptors,
        $pipes,
        dirname(__DIR__)
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Não foi possível iniciar o processo concorrente.');
    }
    fclose($pipes[0]);
    $processes[] = [$process, $pipes[1], $pipes[2]];
}

foreach ($processes as [$process, $stdout, $stderr]) {
    stream_get_contents($stdout);
    $errorOutput = stream_get_contents($stderr);
    fclose($stdout);
    fclose($stderr);
    $exitCode = proc_close($process);
    assertAuditIntegrity($exitCode === 0, 'Gravação concorrente falhou: ' . $errorOutput);
}

$final = \FitControl\Security\AuditIntegrityChain::verify($pdo);
assertAuditIntegrity($final['ok'], 'A cadeia deve continuar íntegra após gravações concorrentes.');

echo "Cadeia SHA-256, rollback, triggers append-only e concorrência: OK." . PHP_EOL;
