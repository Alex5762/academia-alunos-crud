<?php
declare(strict_types=1);

namespace FitControl\Domain\Enrollment;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use LogicException;
use PDO;
use Throwable;
use UnexpectedValueException;

/**
 * Retifica somente o intervalo de uma matrícula de origem LEGADO.
 *
 * Ordem de bloqueio: aluno -> revisão -> evento mais recente -> matrícula-alvo -> demais matrículas.
 * A alteração, o evento especializado e a auditoria geral são atômicos.
 */
final class LegacyEnrollmentRectificationService
{
    private readonly DateTimeZone $utcTimezone;
    private readonly DateTimeZone $academyTimezone;

    public function __construct(private readonly PDO $pdo)
    {
        $this->utcTimezone = new DateTimeZone('UTC');
        $this->academyTimezone = new DateTimeZone(EnrollmentPeriodCalculator::ACADEMY_TIMEZONE);
    }

    /**
     * Corrige datas comprovadas sem alterar preço, duração, cancelamento ou plano do legado.
     *
     * @throws InvalidArgumentException Para IDs, texto, fuso ou intervalo inválidos.
     * @throws DomainException Para revisão/matrícula inelegível ou sobreposição.
     * @throws LogicException Se uma transação externa já estiver ativa.
     */
    public function rectify(
        int $studentId,
        int $reviewId,
        int $enrollmentId,
        int $actorUserId,
        string $sourceReference,
        string $observations,
        DateTimeImmutable $newStartLocal,
        DateTimeImmutable $newEndExclusiveLocal
    ): void {
        if ($studentId < 1 || $reviewId < 1 || $enrollmentId < 1 || $actorUserId < 1) {
            throw new InvalidArgumentException('Os identificadores precisam ser inteiros positivos.');
        }

        if ($this->pdo->inTransaction()) {
            throw new LogicException('O serviço de retificação precisa controlar sua própria transação.');
        }

        $sourceReference = trim($sourceReference);
        $observations = trim($observations);

        if ($sourceReference === '') {
            throw new InvalidArgumentException('A retificação exige referência de evidência confiável.');
        }

        if ($observations === '') {
            throw new InvalidArgumentException('A retificação exige justificativa.');
        }

        $sourceLength = preg_match_all('/./us', $sourceReference);
        if ($sourceLength === false || $sourceLength > 255) {
            throw new InvalidArgumentException(
                'A referência precisa ser UTF-8 válida e ter no máximo 255 caracteres.'
            );
        }

        if (preg_match('//u', $observations) !== 1 || strlen($observations) > 65535) {
            throw new InvalidArgumentException(
                'As observações precisam ser UTF-8 válidas e caber no campo TEXT.'
            );
        }

        $this->assertAcademyDateTime($newStartLocal, 'O novo início');
        $this->assertAcademyDateTime($newEndExclusiveLocal, 'O novo fim exclusivo');

        if ($newEndExclusiveLocal <= $newStartLocal) {
            throw new InvalidArgumentException('O novo fim exclusivo precisa ser posterior ao início.');
        }

        $newStartUtc = $newStartLocal->setTimezone($this->utcTimezone)->format('Y-m-d H:i:s');
        $newEndUtc = $newEndExclusiveLocal->setTimezone($this->utcTimezone)->format('Y-m-d H:i:s');

        $this->pdo->beginTransaction();

        try {
            // 1. Serializar todas as alterações que pertencem ao mesmo aluno.
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

            // 2. A revisão precisa estar confirmada antes de corrigir seu intervalo.
            $lockReview = $this->pdo->prepare(
                'SELECT id, resultado_atual
                 FROM revisoes_legado
                 WHERE id = :review_id
                   AND aluno_id_origem = :student_id
                 FOR UPDATE'
            );
            $lockReview->execute([
                'review_id' => $reviewId,
                'student_id' => $studentId,
            ]);
            $review = $lockReview->fetch();

            if (!$review || $review['resultado_atual'] !== 'VINCULO_CONFIRMADO') {
                throw new DomainException(
                    'Somente um vínculo legado confirmado pode receber retificação de vigência.'
                );
            }

            // 3. Confirmar que a matrícula-alvo foi materializada pela decisão vigente.
            $latestEventQuery = $this->pdo->prepare(
                'SELECT id, tipo_evento, resultado_novo, matricula_id,
                        inicio_novo, fim_exclusivo_novo, fonte_referencia, observacoes
                 FROM revisoes_legado_eventos
                 WHERE revisao_legado_id = :review_id
                 ORDER BY id DESC
                 LIMIT 1
                 FOR UPDATE'
            );
            $latestEventQuery->execute(['review_id' => $reviewId]);
            $latestEvent = $latestEventQuery->fetch();

            if (
                !$latestEvent
                || $latestEvent['resultado_novo'] !== 'VINCULO_CONFIRMADO'
                || (int) ($latestEvent['matricula_id'] ?? 0) !== $enrollmentId
            ) {
                throw new DomainException(
                    'A matrícula informada não é o vínculo legado associado à decisão vigente.'
                );
            }

            // 4. Bloquear o registro de origem. SISTEMA nunca pode ser retificado aqui.
            $lockTarget = $this->pdo->prepare(
                "SELECT id, inicio_em, fim_exclusivo_em, cancelada_em, origem
                 FROM matriculas
                 WHERE id = :enrollment_id
                   AND aluno_id = :student_id
                   AND origem = 'LEGADO'
                 FOR UPDATE"
            );
            $lockTarget->execute([
                'enrollment_id' => $enrollmentId,
                'student_id' => $studentId,
            ]);
            $target = $lockTarget->fetch();

            if (!$target) {
                throw new DomainException('Matrícula LEGADO não encontrada para este aluno.');
            }

            $oldStartLocal = $this->storedUtcToAcademyTime((string) $target['inicio_em']);
            $oldEndLocal = $this->storedUtcToAcademyTime((string) $target['fim_exclusivo_em']);
            $cancelledAtLocal = $target['cancelada_em'] === null
                ? null
                : $this->storedUtcToAcademyTime((string) $target['cancelada_em']);

            // Repetição exata da última retificação: não duplica evento nem auditoria.
            if (
                $latestEvent['tipo_evento'] === 'RETIFICACAO_VIGENCIA'
                && $latestEvent['inicio_novo'] === $newStartUtc
                && $latestEvent['fim_exclusivo_novo'] === $newEndUtc
                && $latestEvent['fonte_referencia'] === $sourceReference
                && $latestEvent['observacoes'] === $observations
                && $target['inicio_em'] === $newStartUtc
                && $target['fim_exclusivo_em'] === $newEndUtc
            ) {
                $this->pdo->commit();
                return;
            }

            if ($target['inicio_em'] === $newStartUtc && $target['fim_exclusivo_em'] === $newEndUtc) {
                throw new DomainException('O novo intervalo é idêntico ao atual; nenhuma retificação é necessária.');
            }

            // 5. Validar o intervalo efetivo resultante, preservando cancelada_em.
            $newEffectiveInterval = EnrollmentInterval::fromContract(
                $newStartLocal,
                $newEndExclusiveLocal,
                $cancelledAtLocal
            );

            // 6. Bloquear e comparar as demais matrículas, sem comparar a linha consigo mesma.
            $lockOtherEnrollments = $this->pdo->prepare(
                'SELECT id, inicio_em, fim_exclusivo_em, cancelada_em
                 FROM matriculas
                 WHERE aluno_id = :student_id
                   AND id <> :enrollment_id
                 ORDER BY id
                 FOR UPDATE'
            );
            $lockOtherEnrollments->execute([
                'student_id' => $studentId,
                'enrollment_id' => $enrollmentId,
            ]);

            foreach ($lockOtherEnrollments->fetchAll() as $other) {
                $otherInterval = EnrollmentInterval::fromContract(
                    $this->storedUtcToAcademyTime((string) $other['inicio_em']),
                    $this->storedUtcToAcademyTime((string) $other['fim_exclusivo_em']),
                    $other['cancelada_em'] === null
                        ? null
                        : $this->storedUtcToAcademyTime((string) $other['cancelada_em'])
                );

                if (
                    $newEffectiveInterval !== null
                    && $otherInterval !== null
                    && $newEffectiveInterval->overlaps($otherInterval)
                ) {
                    throw new DomainException(
                        'O novo intervalo legado se sobrepõe a outra matrícula do aluno.'
                    );
                }
            }

            // 7. Atualizar somente o intervalo contratual; o cancelamento permanece intacto.
            $updateEnrollment = $this->pdo->prepare(
                "UPDATE matriculas
                 SET inicio_em = :new_start,
                     fim_exclusivo_em = :new_end
                 WHERE id = :enrollment_id
                   AND aluno_id = :student_id
                   AND origem = 'LEGADO'"
            );
            $updateEnrollment->execute([
                'new_start' => $newStartUtc,
                'new_end' => $newEndUtc,
                'enrollment_id' => $enrollmentId,
                'student_id' => $studentId,
            ]);

            // A fonte atual da revisão passa a apontar para a evidência mais recente.
            $updateReview = $this->pdo->prepare(
                'UPDATE revisoes_legado
                 SET fonte_referencia = :source_reference
                 WHERE id = :review_id
                   AND aluno_id_origem = :student_id'
            );
            $updateReview->execute([
                'source_reference' => $sourceReference,
                'review_id' => $reviewId,
                'student_id' => $studentId,
            ]);

            // 8. Evento especializado append-only com os valores anteriores e novos em UTC.
            $insertEvent = $this->pdo->prepare(
                "INSERT INTO revisoes_legado_eventos (
                    revisao_legado_id, usuario_id, tipo_evento,
                    resultado_anterior, resultado_novo, matricula_id,
                    inicio_anterior, fim_exclusivo_anterior,
                    inicio_novo, fim_exclusivo_novo,
                    fonte_referencia, observacoes
                ) VALUES (
                    :review_id, :user_id, 'RETIFICACAO_VIGENCIA',
                    'VINCULO_CONFIRMADO', 'VINCULO_CONFIRMADO', :enrollment_id,
                    :old_start, :old_end, :new_start, :new_end,
                    :source_reference, :observations
                )"
            );
            $insertEvent->execute([
                'review_id' => $reviewId,
                'user_id' => $actorUserId,
                'enrollment_id' => $enrollmentId,
                'old_start' => $target['inicio_em'],
                'old_end' => $target['fim_exclusivo_em'],
                'new_start' => $newStartUtc,
                'new_end' => $newEndUtc,
                'source_reference' => $sourceReference,
                'observations' => $observations,
            ]);

            // 9. Auditoria geral na mesma transação das demais três alterações.
            $audit = $this->pdo->prepare(
                'INSERT INTO auditoria (usuario_id, acao, entidade, entidade_id)
                 VALUES (:user_id, :action_name, :entity_name, :entity_id)'
            );
            $audit->execute([
                'user_id' => $actorUserId,
                'action_name' => 'matricula_corrigida',
                'entity_name' => 'matriculas',
                'entity_id' => $enrollmentId,
            ]);

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function assertAcademyDateTime(DateTimeImmutable $dateTime, string $fieldName): void
    {
        if ($dateTime->getTimezone()->getName() !== EnrollmentPeriodCalculator::ACADEMY_TIMEZONE) {
            throw new InvalidArgumentException(
                $fieldName . ' deve estar no fuso ' . EnrollmentPeriodCalculator::ACADEMY_TIMEZONE . '.'
            );
        }

        $year = (int) $dateTime->format('Y');
        if ($year < 1000 || $year > 9999) {
            throw new InvalidArgumentException(
                $fieldName . ' está fora do intervalo de DATETIME do MySQL.'
            );
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
            throw new UnexpectedValueException('Foi encontrado um instante legado inválido no banco.');
        }

        return $storedUtc->setTimezone($this->academyTimezone);
    }
}
