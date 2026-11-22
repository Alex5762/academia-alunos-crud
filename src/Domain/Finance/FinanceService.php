<?php
declare(strict_types=1);

namespace FitControl\Domain\Finance;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use LogicException;
use PDO;
use Throwable;
use UnexpectedValueException;

require_once __DIR__ . '/../../Security/AuditIntegrityChain.php';
require_once dirname(__DIR__) . '/Enrollment/EnrollmentPeriodCalculator.php';

/** Operações financeiras transacionais; pagamentos são lançamentos históricos. */
final class FinanceService
{
    private readonly Closure $clock;
    private readonly DateTimeZone $utcTimezone;
    private readonly DateTimeZone $academyTimezone;

    public function __construct(private readonly PDO $pdo, callable $clock)
    {
        $this->clock = Closure::fromCallable($clock);
        $this->utcTimezone = new DateTimeZone('UTC');
        $this->academyTimezone = new DateTimeZone(
            \FitControl\Domain\Enrollment\EnrollmentPeriodCalculator::ACADEMY_TIMEZONE
        );
    }

    /** Registra um pagamento parcial ou integral, sem permitir exceder o saldo. */
    public function registerPayment(
        int $chargeId,
        int $actorUserId,
        string $amount,
        string $method,
        string $paymentDateLocal,
        string $idempotencyKey,
        ?string $observation = null
    ): int {
        if ($chargeId < 1 || $actorUserId < 1) {
            throw new InvalidArgumentException('Os identificadores precisam ser positivos.');
        }
        if (!self::isPositiveDecimal($amount)) {
            throw new InvalidArgumentException('O valor do pagamento deve ser positivo e usar duas casas decimais.');
        }
        if (!in_array($method, ['DINHEIRO', 'PIX', 'TRANSFERENCIA', 'CARTAO', 'OUTRO'], true)) {
            throw new InvalidArgumentException('A forma de pagamento é inválida.');
        }
        if (preg_match('/^[a-f0-9]{32}$/D', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('A chave de idempotência é inválida.');
        }

        $nowLocal = $this->nowLocal();
        $paidDate = DateTimeImmutable::createFromFormat('!Y-m-d', $paymentDateLocal, $this->academyTimezone);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (
            !$paidDate instanceof DateTimeImmutable
            || $paidDate->format('Y-m-d') !== $paymentDateLocal
            || (is_array($dateErrors) && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $paymentDateLocal > $nowLocal->format('Y-m-d')
        ) {
            throw new InvalidArgumentException('A data do pagamento deve ser válida e não pode estar no futuro.');
        }

        $observation = $observation === null ? null : trim($observation);
        if ($observation === '') {
            $observation = null;
        }
        if ($observation !== null && self::unicodeLength($observation) > 255) {
            throw new InvalidArgumentException('A observação deve ter até 255 caracteres UTF-8.');
        }

        $requestPayload = json_encode([
            'charge_id' => $chargeId,
            'actor_user_id' => $actorUserId,
            'amount' => $amount,
            'method' => $method,
            'payment_date' => $paymentDateLocal,
            'observation' => $observation,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $requestFingerprint = hash('sha256', $requestPayload);

        $this->beginOwnedTransaction();
        try {
            $lock = $this->pdo->prepare(
                'SELECT c.id, c.valor_total, c.cancelada_em_utc, m.cancelada_em AS matricula_cancelada_em
                 FROM cobrancas c
                 INNER JOIN matriculas m ON m.id = c.matricula_id
                 WHERE c.id = :charge_id
                 FOR UPDATE'
            );
            $lock->execute(['charge_id' => $chargeId]);
            $charge = $lock->fetch();
            if (!$charge) {
                throw new DomainException('Cobrança não encontrada.');
            }

            // A linha da cobrança já está bloqueada: reenvios concorrentes da mesma
            // operação aguardam aqui e passam a enxergar o lançamento confirmado.
            $existingPaymentQuery = $this->pdo->prepare(
                'SELECT id, hash_requisicao
                 FROM pagamentos
                 WHERE cobranca_id = :charge_id
                   AND chave_idempotencia = :idempotency_key
                 LIMIT 1'
            );
            $existingPaymentQuery->execute([
                'charge_id' => $chargeId,
                'idempotency_key' => $idempotencyKey,
            ]);
            $existingPayment = $existingPaymentQuery->fetch();
            if ($existingPayment) {
                $storedFingerprint = (string) ($existingPayment['hash_requisicao'] ?? '');
                if ($storedFingerprint === '' || !hash_equals($storedFingerprint, $requestFingerprint)) {
                    throw new DomainException(
                        'A chave de idempotência já foi utilizada com dados diferentes.'
                    );
                }

                $existingPaymentId = (int) $existingPayment['id'];
                $this->pdo->commit();
                return $existingPaymentId;
            }

            if ($charge['cancelada_em_utc'] !== null) {
                throw new DomainException('Uma cobrança cancelada não aceita pagamentos.');
            }
            if ($charge['matricula_cancelada_em'] !== null) {
                throw new DomainException('A matrícula foi cancelada; a cobrança precisa de revisão financeira.');
            }

            $sum = $this->pdo->prepare(
                'SELECT COALESCE(SUM(valor), 0.00) FROM pagamentos WHERE cobranca_id = :charge_id'
            );
            $sum->execute(['charge_id' => $chargeId]);
            $paidCents = self::decimalToCents((string) $sum->fetchColumn());
            $totalCents = self::decimalToCents((string) $charge['valor_total']);
            $newCents = self::decimalToCents($amount);
            if ($paidCents + $newCents > $totalCents) {
                throw new DomainException('O pagamento excede o saldo em aberto da cobrança.');
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO pagamentos (
                    cobranca_id, valor, forma_pagamento, data_pagamento, observacao,
                    registrado_por, registrado_em_utc,
                    chave_idempotencia, hash_requisicao
                 ) VALUES (
                    :charge_id, :amount, :method, :payment_date, :observation,
                    :actor_id, :registered_at, :idempotency_key, :request_fingerprint
                 )'
            );
            $this->pdo->exec('SET @fitcontrol_payment_write = 1');
            try {
                $insert->execute([
                    'charge_id' => $chargeId,
                    'amount' => $amount,
                    'method' => $method,
                    'payment_date' => $paymentDateLocal,
                    'observation' => $observation,
                    'actor_id' => $actorUserId,
                    'registered_at' => $nowLocal->setTimezone($this->utcTimezone)->format('Y-m-d H:i:s'),
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $requestFingerprint,
                ]);
            } finally {
                $this->pdo->exec('SET @fitcontrol_payment_write = NULL');
            }
            // Recupera pela chave única da operação, sem depender do estado
            // de LAST_INSERT_ID() após a execução de gatilhos no banco.
            $paymentIdQuery = $this->pdo->prepare(
                'SELECT id
                 FROM pagamentos
                 WHERE cobranca_id = :charge_id
                   AND chave_idempotencia = :idempotency_key
                 LIMIT 1'
            );
            $paymentIdQuery->execute([
                'charge_id' => $chargeId,
                'idempotency_key' => $idempotencyKey,
            ]);
            $paymentId = (int) $paymentIdQuery->fetchColumn();
            if ($paymentId < 1) {
                throw new UnexpectedValueException('O banco não retornou o ID do pagamento recém-registrado.');
            }

            \FitControl\Security\AuditIntegrityChain::append(
                $this->pdo, $actorUserId, 'pagamento_registrado', 'pagamentos', $paymentId
            );
            $this->pdo->commit();
            return $paymentId;
        } catch (Throwable $exception) {
            try {
                $this->pdo->exec('SET @fitcontrol_payment_write = NULL');
            } catch (Throwable) {
                // A conexão pode estar indisponível; o rollback será tentado abaixo.
            }
            $this->rollBackIfNeeded();
            throw $exception;
        }
    }

    /** Cancela somente cobrança sem pagamentos e após o cancelamento do contrato. */
    public function cancelUnpaidCharge(int $chargeId, int $actorUserId, string $reason): void
    {
        if ($chargeId < 1 || $actorUserId < 1) {
            throw new InvalidArgumentException('Os identificadores precisam ser positivos.');
        }
        $reason = trim($reason);
        if ($reason === '' || self::unicodeLength($reason) > 255) {
            throw new InvalidArgumentException('Informe um motivo de cancelamento com até 255 caracteres.');
        }
        $nowLocal = $this->nowLocal();

        $this->beginOwnedTransaction();
        try {
            $lock = $this->pdo->prepare(
                'SELECT c.id, c.cancelada_em_utc, m.cancelada_em AS matricula_cancelada_em
                 FROM cobrancas c
                 INNER JOIN matriculas m ON m.id = c.matricula_id
                 WHERE c.id = :charge_id
                 FOR UPDATE'
            );
            $lock->execute(['charge_id' => $chargeId]);
            $charge = $lock->fetch();
            if (!$charge) {
                throw new DomainException('Cobrança não encontrada.');
            }
            if ($charge['cancelada_em_utc'] !== null) {
                throw new DomainException('A cobrança já está cancelada.');
            }
            if ($charge['matricula_cancelada_em'] === null) {
                throw new DomainException('Cancele a matrícula antes de cancelar a cobrança vinculada.');
            }

            $payments = $this->pdo->prepare(
                'SELECT COUNT(*) FROM pagamentos WHERE cobranca_id = :charge_id'
            );
            $payments->execute(['charge_id' => $chargeId]);
            if ((int) $payments->fetchColumn() !== 0) {
                throw new DomainException(
                    'A cobrança possui pagamentos registrados; estorno ou devolução exige fluxo próprio.'
                );
            }

            $update = $this->pdo->prepare(
                'UPDATE cobrancas
                 SET cancelada_em_utc = :cancelled_at,
                     cancelada_por = :actor_id,
                     motivo_cancelamento = :reason
                 WHERE id = :charge_id AND cancelada_em_utc IS NULL'
            );
            $update->execute([
                'cancelled_at' => $nowLocal->setTimezone($this->utcTimezone)->format('Y-m-d H:i:s'),
                'actor_id' => $actorUserId,
                'reason' => $reason,
                'charge_id' => $chargeId,
            ]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('A cobrança mudou durante a operação.');
            }

            \FitControl\Security\AuditIntegrityChain::append(
                $this->pdo, $actorUserId, 'cobranca_cancelada', 'cobrancas', $chargeId
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBackIfNeeded();
            throw $exception;
        }
    }

    private function nowLocal(): DateTimeImmutable
    {
        $now = ($this->clock)();
        if (
            !$now instanceof DateTimeImmutable
            || $now->getTimezone()->getName() !== \FitControl\Domain\Enrollment\EnrollmentPeriodCalculator::ACADEMY_TIMEZONE
        ) {
            throw new UnexpectedValueException('O relógio precisa retornar DateTimeImmutable no fuso da academia.');
        }
        return $now;
    }

    private function beginOwnedTransaction(): void
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('O serviço financeiro precisa controlar sua própria transação.');
        }
        $this->pdo->beginTransaction();
    }

    private function rollBackIfNeeded(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private static function isPositiveDecimal(string $value): bool
    {
        return preg_match('/^(?:0|[1-9]\d{0,7})\.\d{2}$/D', $value) === 1
            && self::decimalToCents($value) > 0;
    }

    private static function decimalToCents(string $value): int
    {
        if (preg_match('/^(\d{1,8})\.(\d{2})$/D', $value, $parts) !== 1) {
            throw new UnexpectedValueException('Valor DECIMAL inválido retornado pelo banco.');
        }
        return ((int) $parts[1] * 100) + (int) $parts[2];
    }

    private static function unicodeLength(string $value): int
    {
        $length = preg_match_all('/./us', $value);
        if ($length === false) {
            throw new InvalidArgumentException('O texto precisa ser UTF-8 válido.');
        }
        return $length;
    }
}
