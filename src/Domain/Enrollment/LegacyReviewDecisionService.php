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
use Throwable;
use UnexpectedValueException;

require_once __DIR__ . '/../../Security/AuditIntegrityChain.php';

/**
 * Registra decisões humanas sobre o vínculo legado.
 *
 * Ordem dos locks: aluno -> revisão -> matrículas legadas/operacionais.
 * A decisão, o evento especializado e a auditoria geral são atômicos.
 */
final class LegacyReviewDecisionService
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
     * Registra uma decisão de revisão.
     *
     * VINCULO_CONFIRMADO exige fonte e intervalo local exato; cria uma matrícula
     * LEGADO sem inventar plano/preço/duração. LEGADO_SEM_VIGENCIA exige evidência,
     * mas não cria matrícula. PENDENTE_REVISAO exige observações e continua bloqueando.
     *
     * Valores históricos opcionais só devem ser enviados quando comprovados.
     *
     * @return int|null ID da matrícula LEGADO materializada, ou null quando não aplicável.
     */
    public function decide(
        int $studentId,
        int $reviewId,
        int $actorUserId,
        string $newResult,
        ?string $sourceReference,
        ?string $observations,
        ?DateTimeImmutable $legacyStartLocal = null,
        ?DateTimeImmutable $legacyEndExclusiveLocal = null,
        ?string $provenPrice = null,
        ?int $provenDuration = null,
        ?string $provenDurationUnit = null,
        ?int $provenCalendarReferenceDay = null
    ): ?int {
        if ($studentId < 1 || $reviewId < 1 || $actorUserId < 1) {
            throw new InvalidArgumentException('Os identificadores precisam ser inteiros positivos.');
        }

        if ($this->pdo->inTransaction()) {
            throw new LogicException('O serviço de revisão precisa controlar sua própria transação.');
        }

        if (!in_array($newResult, ['VINCULO_CONFIRMADO', 'LEGADO_SEM_VIGENCIA', 'PENDENTE_REVISAO'], true)) {
            throw new InvalidArgumentException('Resultado de revisão inválido.');
        }

        $sourceReference = self::normalizeText($sourceReference);
        $observations = self::normalizeText($observations);

        if ($sourceReference !== null) {
            $sourceLength = preg_match_all('/./us', $sourceReference);
            if ($sourceLength === false || $sourceLength > 255) {
                throw new InvalidArgumentException(
                    'A referência da fonte deve conter texto UTF-8 válido com no máximo 255 caracteres.'
                );
            }
        }

        if (in_array($newResult, ['VINCULO_CONFIRMADO', 'LEGADO_SEM_VIGENCIA'], true) && $sourceReference === null) {
            throw new InvalidArgumentException('Essa decisão exige uma referência de evidência confiável.');
        }

        if ($newResult === 'PENDENTE_REVISAO' && $observations === null) {
            throw new InvalidArgumentException('A revisão pendente exige observações sobre a evidência insuficiente.');
        }

        if ($newResult === 'VINCULO_CONFIRMADO') {
            if (!$legacyStartLocal instanceof DateTimeImmutable || !$legacyEndExclusiveLocal instanceof DateTimeImmutable) {
                throw new InvalidArgumentException('A confirmação exige início e fim exclusivo comprovados.');
            }

            $this->assertAcademyDateTime($legacyStartLocal, 'O início legado');
            $this->assertAcademyDateTime($legacyEndExclusiveLocal, 'O fim legado');

            if ($legacyEndExclusiveLocal <= $legacyStartLocal) {
                throw new InvalidArgumentException('O fim legado precisa ser posterior ao início.');
            }

            self::validateOptionalHistoricalSnapshot(
                $provenPrice,
                $provenDuration,
                $provenDurationUnit,
                $provenCalendarReferenceDay
            );
        } elseif (
            $legacyStartLocal !== null
            || $legacyEndExclusiveLocal !== null
            || $provenPrice !== null
            || $provenDuration !== null
            || $provenDurationUnit !== null
            || $provenCalendarReferenceDay !== null
        ) {
            throw new InvalidArgumentException(
                'Dados de vigência e snapshot só podem acompanhar VINCULO_CONFIRMADO.'
            );
        }

        $this->pdo->beginTransaction();

        try {
            // 1. Linha-pai primeiro, compartilhada por operações de matrícula e revisão.
            $lockStudent = $this->pdo->prepare(
                'SELECT id, plano
                 FROM alunos
                 WHERE id = :student_id
                 FOR UPDATE'
            );
            $lockStudent->execute(['student_id' => $studentId]);
            $student = $lockStudent->fetch();

            if (!$student) {
                throw new DomainException('Aluno não encontrado.');
            }

            // 2. Bloquear e validar o controle de revisão do aluno.
            $lockReview = $this->pdo->prepare(
                'SELECT id, aluno_id_origem, plano_texto_original,
                        resultado_atual, fonte_referencia
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

            if (!$review) {
                throw new DomainException('Registro de revisão não encontrado para este aluno.');
            }

            // 3. Bloquear eventos anteriores para leitura idempotente.
            $latestEventQuery = $this->pdo->prepare(
                'SELECT id, resultado_novo, matricula_id, fonte_referencia, observacoes
                 FROM revisoes_legado_eventos
                 WHERE revisao_legado_id = :review_id
                 ORDER BY id DESC
                 LIMIT 1
                 FOR UPDATE'
            );
            $latestEventQuery->execute(['review_id' => $reviewId]);
            $latestEvent = $latestEventQuery->fetch();

            if (
                $latestEvent
                && $latestEvent['resultado_novo'] === $newResult
                && $latestEvent['fonte_referencia'] === $sourceReference
                && $latestEvent['observacoes'] === $observations
            ) {
                $existingLegacyEnrollmentId = $latestEvent['matricula_id'] === null
                    ? null
                    : (int) $latestEvent['matricula_id'];

                if ($newResult === 'VINCULO_CONFIRMADO') {
                    if ($existingLegacyEnrollmentId === null) {
                        throw new LogicException('Evento de vínculo confirmado sem matrícula associada.');
                    }

                    $storedLegacy = $this->lockLegacyEnrollment(
                        $studentId,
                        $existingLegacyEnrollmentId
                    );
                    $expectedStartUtc = $legacyStartLocal->setTimezone($this->utcTimezone)->format('Y-m-d H:i:s');
                    $expectedEndUtc = $legacyEndExclusiveLocal->setTimezone($this->utcTimezone)->format('Y-m-d H:i:s');

                    if (
                        $storedLegacy['inicio_em'] !== $expectedStartUtc
                        || $storedLegacy['fim_exclusivo_em'] !== $expectedEndUtc
                    ) {
                        throw new DomainException(
                            'O intervalo já confirmado não pode ser alterado por nova decisão; use a retificação de vigência.'
                        );
                    }
                }

                $this->pdo->commit();
                return $existingLegacyEnrollmentId;
            }

            $previousResult = (string) $review['resultado_atual'];

            if (
                $previousResult === 'VINCULO_CONFIRMADO'
                && $newResult === 'VINCULO_CONFIRMADO'
            ) {
                throw new DomainException(
                    'O intervalo de um vínculo já confirmado só pode mudar pelo fluxo de retificação de vigência.'
                );
            }

            // 4. Bloquear os registros de matrícula do aluno antes de verificar vigência/conflitos.
            $lockEnrollments = $this->pdo->prepare(
                'SELECT id, plano_id, plano_nome_contratado, valor_contratado,
                        duracao_contratada_valor, duracao_contratada_unidade,
                        inicio_em, fim_exclusivo_em, cancelada_em, origem
                 FROM matriculas
                 WHERE aluno_id = :student_id
                 ORDER BY id
                 FOR UPDATE'
            );
            $lockEnrollments->execute(['student_id' => $studentId]);
            $existingEnrollments = $lockEnrollments->fetchAll();

            $nowLocal = ($this->clock)();
            if (!$nowLocal instanceof DateTimeImmutable) {
                throw new UnexpectedValueException('O relógio precisa retornar DateTimeImmutable.');
            }
            $this->assertAcademyDateTime($nowLocal, 'O instante atual');

            $legacyEnrollmentId = null;

            if ($newResult === 'VINCULO_CONFIRMADO') {
                $legacyStartUtc = $legacyStartLocal->setTimezone($this->utcTimezone)->format('Y-m-d H:i:s');
                $legacyEndUtc = $legacyEndExclusiveLocal->setTimezone($this->utcTimezone)->format('Y-m-d H:i:s');

                // Uma revisão pode voltar a PENDENTE após uma confirmação anterior.
                // Nesse caso, preservar e reutilizar a matrícula LEGADO já materializada;
                // nunca criar uma segunda linha nem alterar seu intervalo por DECISAO.
                $existingLegacyRows = array_values(array_filter(
                    $existingEnrollments,
                    static fn (array $row): bool => $row['origem'] === 'LEGADO'
                ));

                if (count($existingLegacyRows) > 1) {
                    throw new LogicException('Há mais de uma matrícula LEGADO para o mesmo aluno; revisar integridade.');
                }

                $newInterval = EnrollmentInterval::fromContract(
                    $legacyStartLocal,
                    $legacyEndExclusiveLocal
                );

                foreach ($existingEnrollments as $existing) {
                    if ($existing['origem'] === 'LEGADO' && $existingLegacyRows !== []) {
                        // O registro LEGADO existente é validado separadamente abaixo.
                        continue;
                    }

                    $existingInterval = EnrollmentInterval::fromContract(
                        $this->storedUtcToAcademyTime((string) $existing['inicio_em']),
                        $this->storedUtcToAcademyTime((string) $existing['fim_exclusivo_em']),
                        $existing['cancelada_em'] === null
                            ? null
                            : $this->storedUtcToAcademyTime((string) $existing['cancelada_em'])
                    );

                    if ($existingInterval !== null && $newInterval !== null && $newInterval->overlaps($existingInterval)) {
                        throw new DomainException(
                            'O intervalo legado comprovado se sobrepõe a outra matrícula do aluno.'
                        );
                    }
                }

                if ($existingLegacyRows !== []) {
                    $existingLegacy = $existingLegacyRows[0];
                    $existingStart = $this->storedUtcToAcademyTime((string) $existingLegacy['inicio_em']);
                    $existingEnd = $this->storedUtcToAcademyTime((string) $existingLegacy['fim_exclusivo_em']);

                    if (
                        $existingStart != $legacyStartLocal
                        || $existingEnd != $legacyEndExclusiveLocal
                    ) {
                        throw new DomainException(
                            'O intervalo já materializado não pode ser alterado por uma decisão comum; use a retificação de vigência.'
                        );
                    }

                    $legacyEnrollmentId = (int) $existingLegacy['id'];
                } else {
                    $originalPlanText = (string) $review['plano_texto_original'];
                    if ($originalPlanText === '') {
                        $originalPlanText = (string) $student['plano'];
                    }

                    $insertLegacy = $this->pdo->prepare(
                        "INSERT INTO matriculas (
                            aluno_id, plano_id, plano_nome_contratado, valor_contratado,
                            duracao_contratada_valor, duracao_contratada_unidade,
                            inicio_em, fim_exclusivo_em, dia_referencia_calendario,
                            cancelada_em, origem
                        ) VALUES (
                            :student_id, NULL, :plan_name, :price,
                            :duration, :duration_unit, :start_at, :end_at,
                            :calendar_reference_day, NULL, 'LEGADO'
                        )"
                    );
                    $insertLegacy->execute([
                        'student_id' => $studentId,
                        'plan_name' => $originalPlanText,
                        'price' => $provenPrice,
                        'duration' => $provenDuration,
                        'duration_unit' => $provenDurationUnit,
                        'start_at' => $legacyStartUtc,
                        'end_at' => $legacyEndUtc,
                        'calendar_reference_day' => $provenCalendarReferenceDay,
                    ]);

                    $legacyEnrollmentId = (int) $this->pdo->lastInsertId();

                    if ($legacyEnrollmentId < 1) {
                        throw new UnexpectedValueException('O banco não retornou o ID da matrícula LEGADO.');
                    }
                }
            } elseif ($newResult === 'LEGADO_SEM_VIGENCIA') {
                foreach ($existingEnrollments as $existing) {
                    if ($existing['origem'] !== 'LEGADO') {
                        continue;
                    }

                    $effectiveInterval = EnrollmentInterval::fromContract(
                        $this->storedUtcToAcademyTime((string) $existing['inicio_em']),
                        $this->storedUtcToAcademyTime((string) $existing['fim_exclusivo_em']),
                        $existing['cancelada_em'] === null
                            ? null
                            : $this->storedUtcToAcademyTime((string) $existing['cancelada_em'])
                    );

                    if ($effectiveInterval !== null && $effectiveInterval->contains($nowLocal)) {
                        throw new DomainException(
                            'Existe uma matrícula LEGADO efetivamente vigente; não é possível declarar ausência de vigência.'
                        );
                    }
                }
            }

            // 5. Atualizar somente o estado atual da revisão.
            $updateReview = $this->pdo->prepare(
                'UPDATE revisoes_legado
                 SET resultado_atual = :new_result,
                     fonte_referencia = :source_reference
                 WHERE id = :review_id
                   AND aluno_id_origem = :student_id'
            );
            $updateReview->execute([
                'new_result' => $newResult,
                'source_reference' => $sourceReference,
                'review_id' => $reviewId,
                'student_id' => $studentId,
            ]);

            // 6. Histórico especializado append-only.
            $insertEvent = $this->pdo->prepare(
                "INSERT INTO revisoes_legado_eventos (
                    revisao_legado_id, usuario_id, tipo_evento,
                    resultado_anterior, resultado_novo, matricula_id,
                    inicio_anterior, fim_exclusivo_anterior,
                    inicio_novo, fim_exclusivo_novo,
                    fonte_referencia, observacoes
                ) VALUES (
                    :review_id, :user_id, 'DECISAO',
                    :previous_result, :new_result, :enrollment_id,
                    NULL, NULL, :inicio_novo, :fim_exclusivo_novo,
                    :source_reference, :observations
                )"
            );
            $insertEvent->execute([
                'review_id' => $reviewId,
                'user_id' => $actorUserId,
                'previous_result' => $previousResult,
                'new_result' => $newResult,
                'enrollment_id' => $legacyEnrollmentId,
                'inicio_novo' => $newResult === 'VINCULO_CONFIRMADO' ? $legacyStartUtc : null,
                'fim_exclusivo_novo' => $newResult === 'VINCULO_CONFIRMADO' ? $legacyEndUtc : null,
                'source_reference' => $sourceReference,
                'observations' => $observations,
            ]);

            // 7. Auditoria geral na mesma transação.
            \\FitControl\\Security\\AuditIntegrityChain::append($this->pdo, $actorUserId, 'revisao_legado_decidida', 'revisoes_legado', $reviewId);

            $this->pdo->commit();

            return $legacyEnrollmentId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function lockLegacyEnrollment(int $studentId, int $enrollmentId): array
    {
        $query = $this->pdo->prepare(
            "SELECT id, inicio_em, fim_exclusivo_em
             FROM matriculas
             WHERE id = :enrollment_id
               AND aluno_id = :student_id
               AND origem = 'LEGADO'
             FOR UPDATE"
        );
        $query->execute([
            'enrollment_id' => $enrollmentId,
            'student_id' => $studentId,
        ]);
        $row = $query->fetch();

        if (!$row) {
            throw new LogicException('Matrícula LEGADO associada ao evento não foi encontrada.');
        }

        return $row;
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
            throw new InvalidArgumentException($fieldName . ' está fora do intervalo DATETIME do MySQL.');
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

    private static function normalizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);
        return $normalized === '' ? null : $normalized;
    }

    private static function validateOptionalHistoricalSnapshot(
        ?string $price,
        ?int $duration,
        ?string $durationUnit,
        ?int $calendarReferenceDay
    ): void {
        if ($price !== null) {
            if (preg_match('/^\d{1,8}\.\d{2}$/D', $price) !== 1
                || ltrim(str_replace('.', '', $price), '0') === '') {
                throw new InvalidArgumentException('O preço legado comprovado precisa ser positivo e decimal exato.');
            }
        }

        if (($duration === null) !== ($durationUnit === null)) {
            throw new InvalidArgumentException('Duração e unidade históricas devem ser informadas em conjunto.');
        }

        if ($duration !== null && ($duration < 1 || $duration > 65535)) {
            throw new InvalidArgumentException('A duração histórica está fora do intervalo permitido.');
        }

        if ($durationUnit !== null && !in_array($durationUnit, ['DIA', 'SEMANA', 'MES'], true)) {
            throw new InvalidArgumentException('A unidade histórica é inválida.');
        }

        if ($durationUnit !== 'MES' && $calendarReferenceDay !== null) {
            throw new InvalidArgumentException('Dia de referência só se aplica a duração mensal.');
        }

        if ($calendarReferenceDay !== null && ($calendarReferenceDay < 1 || $calendarReferenceDay > 31)) {
            throw new InvalidArgumentException('O dia de referência mensal deve estar entre 1 e 31.');
        }
    }
}
