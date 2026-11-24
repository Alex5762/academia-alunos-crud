<?php
declare(strict_types=1);

namespace FitControl\Security;

use PDO;
use Throwable;
use RuntimeException;

require_once __DIR__ . '/AuditIntegrityChain.php';

/**
 * Rate limit persistente do login.
 *
 * A chave usa hashes SHA-256 do e-mail normalizado e do IP de origem.
 * Valores puros e hashes de identificação não entram na trilha de auditoria.
 */
final class LoginAttemptRateLimiter
{
    private const WINDOW_MINUTES = 15;
    private const BLOCK_MINUTES = 15;
    private const MAX_ATTEMPTS = 5;
    private const RETENTION_DAYS = 1;

    private function __construct()
    {
    }

    private static function assertHash(string $hash): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new RuntimeException('Identificador do rate limit inválido.');
        }
    }

    public static function cleanupExpired(PDO $pdo): void
    {
        $pdo->exec(
            'DELETE FROM login_tentativas
             WHERE atualizado_em < DATE_SUB(NOW(), INTERVAL ' . self::RETENTION_DAYS . ' DAY)'
        );
    }

    public static function isBlocked(PDO $pdo, string $emailHash, string $originHash): bool
    {
        self::assertHash($emailHash);
        self::assertHash($originHash);

        $query = $pdo->prepare(
            'SELECT (bloqueado_ate IS NOT NULL AND bloqueado_ate > NOW()) AS bloqueado
             FROM login_tentativas
             WHERE email_hash = :email_hash AND origem_hash = :origem_hash
             LIMIT 1'
        );
        $query->execute([
            'email_hash' => $emailHash,
            'origem_hash' => $originHash,
        ]);

        return (int) ($query->fetchColumn() ?: 0) === 1;
    }

    /**
     * Incrementa o contador e grava a falha na cadeia de auditoria na mesma
     * transação. O UPSERT serializa a combinação; o contador fica limitado a 5.
     */
    public static function recordFailure(PDO $pdo, string $emailHash, string $originHash): void
    {
        self::assertHash($emailHash);
        self::assertHash($originHash);

        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = 'login_rate_limit_failure';

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $record = $pdo->prepare(
                'INSERT INTO login_tentativas
                    (email_hash, origem_hash, tentativas, janela_inicio, bloqueado_ate)
                 VALUES
                    (:email_hash, :origem_hash, 1, NOW(), NULL)
                 ON DUPLICATE KEY UPDATE
                    bloqueado_ate = IF(
                        janela_inicio <= DATE_SUB(NOW(), INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE),
                        NULL,
                        IF(
                            tentativas >= ' . (self::MAX_ATTEMPTS - 1) . ' AND bloqueado_ate IS NULL,
                            DATE_ADD(NOW(), INTERVAL ' . self::BLOCK_MINUTES . ' MINUTE),
                            bloqueado_ate
                        )
                    ),
                    tentativas = IF(
                        janela_inicio <= DATE_SUB(NOW(), INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE),
                        1,
                        LEAST(tentativas + 1, ' . self::MAX_ATTEMPTS . ')
                    ),
                    janela_inicio = IF(
                        janela_inicio <= DATE_SUB(NOW(), INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE),
                        NOW(),
                        janela_inicio
                    )'
            );
            $record->execute([
                'email_hash' => $emailHash,
                'origem_hash' => $originHash,
            ]);

            // Evento sem e-mail, IP, hash de identificação ou ID de conta.
            AuditIntegrityChain::append($pdo, null, 'login_falhou', 'autenticacao', null);

            if ($ownsTransaction) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            throw $error;
        }
    }

    public static function clear(PDO $pdo, string $emailHash, string $originHash): void
    {
        self::assertHash($emailHash);
        self::assertHash($originHash);

        $delete = $pdo->prepare(
            'DELETE FROM login_tentativas
             WHERE email_hash = :email_hash AND origem_hash = :origin_hash'
        );
        $delete->execute([
            'email_hash' => $emailHash,
            'origin_hash' => $originHash,
        ]);
    }
}
