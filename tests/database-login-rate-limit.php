<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Recurso não encontrado.');
}

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/../src/Security/AuditIntegrityChain.php';
require_once __DIR__ . '/../src/Security/LoginAttemptRateLimiter.php';

use FitControl\Security\AuditIntegrityChain;
use FitControl\Security\LoginAttemptRateLimiter;

function assertLoginRateLimit(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$emailHash = hash('sha256', 'database-rate-limit-concurrency@example.test');
$originHash = hash('sha256', 'database-rate-limit-worker');

if (($argv[1] ?? '') === '--worker') {
    for ($index = 0; $index < 5; $index++) {
        LoginAttemptRateLimiter::recordFailure($pdo, $emailHash, $originHash);
    }
    exit(0);
}

$cleanup = $pdo->prepare(
    'DELETE FROM login_tentativas WHERE email_hash = :email_hash AND origem_hash = :origin_hash'
);
$cleanup->execute(['email_hash' => $emailHash, 'origin_hash' => $originHash]);

$processes = [];
for ($index = 0; $index < 4; $index++) {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([PHP_BINARY, __FILE__, '--worker'], $descriptors, $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        throw new RuntimeException('Não foi possível iniciar o processo concorrente de rate limit.');
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
    assertLoginRateLimit($exitCode === 0, 'Worker de rate limit falhou: ' . $errorOutput);
}

$find = $pdo->prepare(
    'SELECT tentativas, bloqueado_ate FROM login_tentativas
     WHERE email_hash = :email_hash AND origem_hash = :origin_hash'
);
$find->execute(['email_hash' => $emailHash, 'origin_hash' => $originHash]);
$attempt = $find->fetch(PDO::FETCH_ASSOC);
assertLoginRateLimit(is_array($attempt), 'A linha concorrente de rate limit deveria existir.');
assertLoginRateLimit((int) $attempt['tentativas'] === 5, 'O contador concorrente deve permanecer limitado a cinco.');
assertLoginRateLimit(
    $attempt['bloqueado_ate'] !== null && strtotime((string) $attempt['bloqueado_ate']) > time(),
    'A quinta falha concorrente deveria ativar o bloqueio.'
);
assertLoginRateLimit(
    LoginAttemptRateLimiter::isBlocked($pdo, $emailHash, $originHash),
    'A API de rate limit deveria reconhecer a combinação bloqueada.'
);

$auditCount = $pdo->prepare(
    "SELECT COUNT(*) FROM auditoria
     WHERE acao = 'login_falhou' AND entidade = 'autenticacao'
       AND usuario_id IS NULL AND entidade_id IS NULL"
);
$auditCount->execute();
assertLoginRateLimit((int) $auditCount->fetchColumn() === 20, 'Cada falha concorrente deveria produzir evento sem PII.');

$expire = $pdo->prepare(
    'UPDATE login_tentativas
     SET janela_inicio = DATE_SUB(NOW(), INTERVAL 16 MINUTE),
         bloqueado_ate = DATE_SUB(NOW(), INTERVAL 1 SECOND)
     WHERE email_hash = :email_hash AND origem_hash = :origin_hash'
);
$expire->execute(['email_hash' => $emailHash, 'origin_hash' => $originHash]);
assertLoginRateLimit(
    !LoginAttemptRateLimiter::isBlocked($pdo, $emailHash, $originHash),
    'O bloqueio expirado não deveria continuar ativo.'
);
LoginAttemptRateLimiter::recordFailure($pdo, $emailHash, $originHash);
$find->execute(['email_hash' => $emailHash, 'origin_hash' => $originHash]);
$reset = $find->fetch(PDO::FETCH_ASSOC);
assertLoginRateLimit((int) $reset['tentativas'] === 1, 'Uma nova janela deveria reiniciar o contador em um.');
assertLoginRateLimit($reset['bloqueado_ate'] === null, 'A janela reiniciada não deveria manter o bloqueio anterior.');

LoginAttemptRateLimiter::clear($pdo, $emailHash, $originHash);
$find->execute(['email_hash' => $emailHash, 'origin_hash' => $originHash]);
assertLoginRateLimit($find->fetch(PDO::FETCH_ASSOC) === false, 'Login válido deveria limpar a combinação.');

$staleEmailHash = hash('sha256', 'database-rate-limit-stale@example.test');
$staleOriginHash = hash('sha256', 'database-rate-limit-stale-origin');
LoginAttemptRateLimiter::recordFailure($pdo, $staleEmailHash, $staleOriginHash);
$markStale = $pdo->prepare(
    'UPDATE login_tentativas SET atualizado_em = DATE_SUB(NOW(), INTERVAL 2 DAY)
     WHERE email_hash = :email_hash AND origem_hash = :origin_hash'
);
$markStale->execute(['email_hash' => $staleEmailHash, 'origin_hash' => $staleOriginHash]);
LoginAttemptRateLimiter::cleanupExpired($pdo);
$staleCheck = $pdo->prepare(
    'SELECT COUNT(*) FROM login_tentativas WHERE email_hash = :email_hash AND origem_hash = :origin_hash'
);
$staleCheck->execute(['email_hash' => $staleEmailHash, 'origin_hash' => $staleOriginHash]);
assertLoginRateLimit((int) $staleCheck->fetchColumn() === 0, 'Registros inativos há mais de um dia deveriam ser removidos.');

assertLoginRateLimit(AuditIntegrityChain::verify($pdo)['ok'], 'A auditoria deve permanecer íntegra após falhas concorrentes.');
echo "Rate limit: concorrência, bloqueio, expiração, limpeza, auditoria sem PII: OK." . PHP_EOL;
