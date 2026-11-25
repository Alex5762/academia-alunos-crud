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
    fwrite(STDOUT, "ATTEMPTING\\n");
    fflush(STDOUT);

    \FitControl\Security\AuditIntegrityChain::append(
        $pdo,
        null,
        'integridade_concorrencia',
        'teste',
        null
    );

    fwrite(STDOUT, "APPENDED\\n");
    fflush(STDOUT);
    exit(0);
}

$before = \FitControl\Security\AuditIntegrityChain::verify($pdo);
assertAuditIntegrity($before['ok'], 'A cadeia deveria estar íntegra antes do teste.');
assertAuditIntegrity(
    $before['baseline_count'] >= 1,
    'O marco deve incluir pelo menos um evento criado antes da migration 008.'
);

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

$blockedOperations = [
    [
        'sql' => 'UPDATE auditoria SET acao = :acao WHERE id = :id',
        'params' => ['id' => $eventId, 'acao' => 'adulteracao'],
    ],
    [
        'sql' => 'DELETE FROM auditoria WHERE id = :id',
        'params' => ['id' => $eventId],
    ],
    [
        'sql' => 'UPDATE auditoria_integridade_eventos SET hash_evento = :hash WHERE evento_id = :id',
        'params' => ['id' => $eventId, 'hash' => str_repeat('a', 64)],
    ],
    [
        'sql' => 'DELETE FROM auditoria_integridade_eventos WHERE evento_id = :id',
        'params' => ['id' => $eventId],
    ],
    [
        'sql' => 'UPDATE auditoria_integridade_estado SET ultimo_hash = :hash WHERE id = 1',
        'params' => ['hash' => str_repeat('b', 64)],
    ],
    [
        'sql' => 'DELETE FROM auditoria_integridade_estado WHERE id = 1',
        'params' => [],
    ],
    [
        'sql' => 'INSERT INTO auditoria (usuario_id, acao, entidade, entidade_id) VALUES (NULL, :acao, :entidade, NULL)',
        'params' => ['acao' => 'insercao_direta', 'entidade' => 'teste'],
    ],
];

foreach ($blockedOperations as $operation) {
    $blocked = false;
    try {
        $statement = $pdo->prepare($operation['sql']);
        $statement->execute($operation['params']);
    } catch (PDOException) {
        $blocked = true;
    }

    assertAuditIntegrity($blocked, 'Trigger deveria bloquear: ' . $operation['sql']);
}

assertAuditIntegrity(
    \FitControl\Security\AuditIntegrityChain::verify($pdo)['ok'],
    'Tentativas bloqueadas não podem corromper a cadeia.'
);

// Mantém o lock da linha de estado enquanto os quatro workers iniciam.
 // Assim, todos confirmam que chegaram ao append e ficam disputando o mesmo lock
 // antes de o processo principal liberá-lo; a concorrência não depende de timing.
$beforeConcurrent = \FitControl\Security\AuditIntegrityChain::verify($pdo);
assertAuditIntegrity($beforeConcurrent['ok'], 'A cadeia deve estar íntegra antes da disputa concorrente.');

$processes = [];
$pdo->beginTransaction();

try {
    $pdo->query(
        'SELECT id FROM auditoria_integridade_estado WHERE id = 1 FOR UPDATE'
    )->fetchColumn();

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

        stream_set_timeout($pipes[1], 10);
        $signal = fgets($pipes[1]);
        assertAuditIntegrity(
            $signal === "ATTEMPTING\\n",
            'O worker não confirmou que iniciou a gravação concorrente: ' . (string) $signal
        );
    }

    // Todos os workers já iniciaram e aguardam o lock da linha de estado.
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    foreach ($processes as [$process, $stdout, $stderr]) {
        if (is_resource($stdout)) {
            fclose($stdout);
        }
        if (is_resource($stderr)) {
            fclose($stderr);
        }
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }

    throw $error;
}

foreach ($processes as [$process, $stdout, $stderr]) {
    stream_set_timeout($stdout, 10);
    $result = fgets($stdout);
    $errorOutput = stream_get_contents($stderr);
    fclose($stdout);
    fclose($stderr);
    $exitCode = proc_close($process);

    assertAuditIntegrity(
        $exitCode === 0 && $result === "APPENDED\\n",
        'Gravação concorrente falhou: ' . $errorOutput . ' ' . (string) $result
    );
}

$final = \FitControl\Security\AuditIntegrityChain::verify($pdo);
assertAuditIntegrity($final['ok'], 'A cadeia deve continuar íntegra após gravações concorrentes.');
assertAuditIntegrity(
    $final['chained_count'] === $beforeConcurrent['chained_count'] + 4,
    'As quatro gravações concorrentes devem produzir exatamente quatro novos hashes.'
);

echo "Cadeia SHA-256, rollback, triggers append-only e concorrência: OK." . PHP_EOL;
