<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Recurso não encontrado.');
}

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/../src/Security/AuditIntegrityChain.php';
require_once __DIR__ . '/../src/Security/AuditIntegrityCheckpoint.php';

function assertCheckpoint(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function checkpointTempPath(): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'fitcontrol-checkpoint-test-' . bin2hex(random_bytes(12)) . '.json';
}

function writeCheckpointFixture(string $path, array $envelope): void
{
    $json = json_encode(
        $envelope,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    if (file_put_contents($path, $json, LOCK_EX) !== strlen($json)) {
        throw new RuntimeException('Não foi possível escrever fixture de checkpoint.');
    }
}

$secret = bin2hex(random_bytes(32));
$databaseName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
assertCheckpoint($databaseName !== '', 'O teste precisa de um banco selecionado.');

$checkpointPath = checkpointTempPath();
$tamperedPath = checkpointTempPath();
$futurePath = checkpointTempPath();

try {
    $created = \FitControl\Security\AuditIntegrityCheckpoint::export(
        $pdo,
        $databaseName,
        $secret,
        $checkpointPath
    );
    assertCheckpoint(is_file($checkpointPath), 'Exportação deve criar arquivo externo.');
    assertCheckpoint($created['checkpoint_event_id'] >= 0, 'O checkpoint deve registrar o último identificador.');

    $initial = \FitControl\Security\AuditIntegrityCheckpoint::verify(
        $pdo,
        $databaseName,
        $secret,
        $checkpointPath
    );
    assertCheckpoint($initial['ok'], 'Checkpoint recém-criado deve validar: ' . $initial['message']);
    assertCheckpoint($initial['events_since_checkpoint'] === 0, 'Checkpoint novo deve começar sem eventos posteriores.');

    $overwriteBlocked = false;
    try {
        \FitControl\Security\AuditIntegrityCheckpoint::export(
            $pdo,
            $databaseName,
            $secret,
            $checkpointPath
        );
    } catch (RuntimeException) {
        $overwriteBlocked = true;
    }
    assertCheckpoint($overwriteBlocked, 'A exportação não pode sobrescrever checkpoint existente.');

    $extendedEventId = \FitControl\Security\AuditIntegrityChain::append(
        $pdo,
        null,
        'checkpoint_extensao_teste',
        'teste',
        null
    );
    assertCheckpoint($extendedEventId > $created['checkpoint_event_id'], 'O evento de extensão deve vir depois do checkpoint.');

    $extended = \FitControl\Security\AuditIntegrityCheckpoint::verify(
        $pdo,
        $databaseName,
        $secret,
        $checkpointPath
    );
    assertCheckpoint($extended['ok'], 'Eventos legítimos posteriores devem preservar checkpoint antigo: ' . $extended['message']);
    assertCheckpoint($extended['events_since_checkpoint'] >= 1, 'A extensão deve ser contabilizada.');

    $wrongSecret = \FitControl\Security\AuditIntegrityCheckpoint::verify(
        $pdo,
        $databaseName,
        bin2hex(random_bytes(32)),
        $checkpointPath
    );
    assertCheckpoint(!$wrongSecret['ok'], 'Uma chave diferente deve invalidar a assinatura.');

    $wrongDatabase = \FitControl\Security\AuditIntegrityCheckpoint::verify(
        $pdo,
        $databaseName . '_outro',
        $secret,
        $checkpointPath
    );
    assertCheckpoint(!$wrongDatabase['ok'], 'Um banco diferente deve ser rejeitado.');

    $envelope = json_decode((string) file_get_contents($checkpointPath), true, 16, JSON_THROW_ON_ERROR);
    $envelope['payload']['checkpoint_hash'] = str_repeat('a', 64);
    writeCheckpointFixture($tamperedPath, $envelope);
    $tampered = \FitControl\Security\AuditIntegrityCheckpoint::verify(
        $pdo,
        $databaseName,
        $secret,
        $tamperedPath
    );
    assertCheckpoint(!$tampered['ok'], 'Alteração do payload sem nova assinatura deve ser detectada.');

    $currentState = $pdo->query(
        'SELECT baseline_ultimo_id, baseline_hash, ultimo_evento_id
         FROM auditoria_integridade_estado WHERE id = 1'
    )->fetch(PDO::FETCH_ASSOC);
    $futurePayload = [
        'schema_version' => 1,
        'database_name' => $databaseName,
        'created_at_utc' => gmdate('Y-m-d H:i:s'),
        'baseline_event_id' => (int) $currentState['baseline_ultimo_id'],
        'baseline_hash' => (string) $currentState['baseline_hash'],
        'checkpoint_event_id' => (int) $currentState['ultimo_evento_id'] + 100,
        'checkpoint_hash' => str_repeat('b', 64),
    ];
    $futureEnvelope = [
        'payload' => $futurePayload,
        'signature' => hash_hmac(
            'sha256',
            \FitControl\Security\AuditIntegrityCheckpoint::canonicalPayload($futurePayload),
            $secret
        ),
    ];
    writeCheckpointFixture($futurePath, $futureEnvelope);

    $restored = \FitControl\Security\AuditIntegrityCheckpoint::verify(
        $pdo,
        $databaseName,
        $secret,
        $futurePath
    );
    assertCheckpoint(!$restored['ok'], 'Banco atrás do checkpoint deve ser rejeitado como possível restauração.');

    echo "Checkpoint HMAC, exportação sem sobrescrita, extensão legítima, segredo incorreto, banco incompatível, adulteração e restauração: OK." . PHP_EOL;
} finally {
    foreach ([$checkpointPath, $tamperedPath, $futurePath] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
