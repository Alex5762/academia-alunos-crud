<?php
declare(strict_types=1);

namespace FitControl\Domain\Enrollment;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use LogicException;
use PDO;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Executa o cancelamento de matrícula em uma única transação.
 *
 * O relógio é injetado para que a situação seja recalculada somente depois
 * dos bloqueios transacionais, sem depender de time() ou estado global.
 */
final class EnrollmentCancellationService
{
    private readonly Closure $clock;
    private readonly DateTimeZone $utcTimezone;
    private readonly DateTimeZone $academyTimezone;

    public function __construct(
        private readonly PDO $pdo,
        callable $clock
    ) {
        $this->clock = Closure::fromCallable($clock);
        $this->utcTimezone = new DateTimeZone('UTC');
        $this->academyTimezone = new DateTimeZone(EnrollmentPeriodCalculator::ACADEMY_TIMEZONE);
    }

    /**
     * Cancela uma matrícula agendada ou ativa e registra a auditoria atomicamente.
     *
     * Os IDs do aluno, matrícula e funcionário devem vir do contexto autenticado
     * e da rota validada no servidor, nunca de autoria confiada ao formulário.
     *
     * @throws InvalidArgumentException Para identificadores ou motivo inválidos.
     * @throws DomainException Quando aluno/matrícula não existem ou o estado não permite cancelar.
     * @throws LogicException Se o chamador já iniciou outra transação na mesma conexão.
     * @throws Throwable Para falhas PDO; a transação é revertida antes da propagação.
     */
    public function cancel(
        int $studentId,
        int $enrollmentId,
        int $actorUserId,
        ?string $reason = null
    ): void {
        if ($studentId < 1 || $enrollmentId < 1 || $actorUserId < 1) {
            throw new InvalidArgumentException('Os identificadores precisam ser inteiros positivos.');
        }

        if ($this->pdo->inTransaction()) {
            throw new LogicException(
                'O serviço de cancelamento precisa controlar sua própria transação.'
            );
        }

        $normalizedReason = $reason === null ? null : trim($reason);

        if ($normalizedReason === '') {
            $normalizedReason = null;
        }

        $this->pdo->beginTransaction();

        try {
            // Ordem compartilhada com criação/revisão: aluno primeiro.
            $lockStudent = $this->pdo->prepare(
                'SELECT id
                 FROM alunos
                 WHERE id = :student_id
                 FOR UPDATE'
            );
            $lockStudent->execute(['student_id' => $studentId]);

            if ($lockStudent->fetchColumn() === false) {
                throw new DomainException('Aluno não encontrado.');
            }

            // O registro da matrícula é bloqueado somente depois da linha-pai.
            $lockEnrollment = $this->pdo->prepare(
                'SELECT id, inicio_em, fim_exclusivo_em, cancelada_em
                 FROM matriculas
                 WHERE id = :enrollment_id
                   AND aluno_id = :student_id
                 FOR UPDATE'
            );
            $lockEnrollment->execute([
                'enrollment_id' => $enrollmentId,
                'student_id' => $studentId,
            ]);
            $enrollment = $lockEnrollment->fetch();

            if (!$enrollment) {
                throw new DomainException('Matrícula não encontrada para este aluno.');
            }

            // O instante é obtido depois dos locks para não usar um "agora"
            // antigo caso a operação tenha aguardado outra transação.
            $confirmedAt = ($this->clock)();

            if (!$confirmedAt instanceof DateTimeImmutable) {
                throw new UnexpectedValueException(
                    'O relógio do serviço precisa retornar DateTimeImmutable.'
                );
            }

            $startLocal = $this->storedUtcToAcademyTime((string) $enrollment['inicio_em']);
            $endLocal = $this->storedUtcToAcademyTime((string) $enrollment['fim_exclusivo_em']);
            $cancelledAtLocal = $enrollment['cancelada_em'] === null
                ? null
                : $this->storedUtcToAcademyTime((string) $enrollment['cancelada_em']);

            // A situação é recalculada após os bloqueios, no instante confirmado.
            EnrollmentStatus::assertCanBeCancelled(
                $startLocal,
                $endLocal,
                $cancelledAtLocal,
                $confirmedAt
            );

            $update = $this->pdo->prepare(
                'UPDATE matriculas
                 SET cancelada_em = :cancelada_em,
                     motivo_cancelamento = :motivo_cancelamento
                 WHERE id = :enrollment_id
                   AND aluno_id = :student_id
                   AND cancelada_em IS NULL'
            );
            $update->execute([
                'cancelada_em' => $confirmedAt
                    ->setTimezone($this->utcTimezone)
                    ->format('Y-m-d H:i:s'),
                'motivo_cancelamento' => $normalizedReason,
                'enrollment_id' => $enrollmentId,
                'student_id' => $studentId,
            ]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException(
                    'A matrícula mudou durante o cancelamento; nenhuma alteração foi confirmada.'
                );
            }

            // Usa o mesmo contrato da função registrarAuditoria() do projeto.
            $audit = $this->pdo->prepare(
                'INSERT INTO auditoria (usuario_id, acao, entidade, entidade_id)
                 VALUES (:usuario_id, :acao, :entidade, :entidade_id)'
            );
            $audit->execute([
                'usuario_id' => $actorUserId,
                'acao' => 'matricula_cancelada',
                'entidade' => 'matriculas',
                'entidade_id' => $enrollmentId,
            ]);

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function storedUtcToAcademyTime(string $storedValue): DateTimeImmutable
    {
        $storedUtc = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $storedValue,
            $this->utcTimezone
        );

        if (!$storedUtc instanceof DateTimeImmutable) {
            throw new UnexpectedValueException(
                'Foi encontrado um instante contratual inválido no banco.'
            );
        }

        return $storedUtc->setTimezone($this->academyTimezone);
    }
}
