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
 * Cria uma matrícula operacional com snapshot do plano e trilha de auditoria.
 *
 * A ordem de bloqueios é fixa: aluno -> plano -> revisão de legado -> matrículas.
 * Toda a operação é confirmada ou revertida como uma única transação.
 */
final class EnrollmentCreationService
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
     * Cria uma matrícula a partir de um instante local já resolvido pela camada de entrada.
     *
     * A camada de entrada deve converter data futura sem horário para 00:00. Para hoje sem
     * horário, use $startAtExecutionTime para resolver o instante depois dos bloqueios, evitando
     * que a própria latência da transação transforme o horário padrão em passado.
     *
     * @throws InvalidArgumentException Para IDs, instante ou dados de plano inválidos.
     * @throws DomainException Para aluno/plano inelegível, legado pendente ou sobreposição.
     * @throws LogicException Se a conexão já estiver em uma transação externa.
     * @throws Throwable Para falhas PDO; a transação é revertida antes da propagação.
     */
    public function create(
        int $studentId,
        int $planId,
        int $actorUserId,
        DateTimeImmutable $startLocal,
        bool $startAtExecutionTime = false
    ): int {
        if ($studentId < 1 || $planId < 1 || $actorUserId < 1) {
            throw new InvalidArgumentException('Os identificadores precisam ser inteiros positivos.');
        }

        if ($this->pdo->inTransaction()) {
            throw new LogicException(
                'O serviço de criação precisa controlar sua própria transação.'
            );
        }

        $this->assertAcademyDateTime($startLocal, 'O início solicitado');
        $this->pdo->beginTransaction();

        try {
            // 1. Bloqueio da linha-pai: serializa todas as operações do mesmo aluno.
            $lockStudent = $this->pdo->prepare(
                'SELECT id, ativo
                 FROM alunos
                 WHERE id = :student_id
                 FOR UPDATE'
            );
            $lockStudent->execute(['student_id' => $studentId]);
            $student = $lockStudent->fetch();

            if (!$student) {
                throw new DomainException('Aluno não encontrado.');
            }

            if ((int) $student['ativo'] !== 1) {
                throw new DomainException('Alunos inativos não podem receber novas matrículas.');
            }

            // 2. Bloqueio do plano: serializa contratação com exclusão/inativação.
            $lockPlan = $this->pdo->prepare(
                'SELECT id, nome, duracao_valor, duracao_unidade, valor_atual, situacao
                 FROM planos
                 WHERE id = :plan_id
                 FOR UPDATE'
            );
            $lockPlan->execute(['plan_id' => $planId]);
            $plan = $lockPlan->fetch();

            if (!$plan) {
                throw new DomainException('Plano não encontrado.');
            }

            if ($plan['situacao'] !== 'ATIVO') {
                throw new DomainException('Somente planos ativos podem ser contratados.');
            }

            $planName = trim((string) $plan['nome']);
            if ($planName === '') {
                throw new InvalidArgumentException('O plano não possui um nome válido.');
            }

            $price = (string) $plan['valor_atual'];
            if (!self::isPositiveDecimal($price)) {
                throw new InvalidArgumentException('O plano não possui um preço positivo válido.');
            }

            $duration = filter_var(
                $plan['duracao_valor'],
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 65535]]
            );
            if ($duration === false) {
                throw new InvalidArgumentException('A duração do plano precisa ser um inteiro positivo.');
            }

            $unit = (string) $plan['duracao_unidade'];
            if (!in_array($unit, ['DIA', 'SEMANA', 'MES'], true)) {
                throw new InvalidArgumentException('A unidade de duração do plano é inválida.');
            }

            // 3. Bloqueio da revisão de legado na ordem documentada.
            $lockLegacyReview = $this->pdo->prepare(
                'SELECT id, resultado_atual
                 FROM revisoes_legado
                 WHERE aluno_id_origem = :student_id
                 FOR UPDATE'
            );
            $lockLegacyReview->execute(['student_id' => $studentId]);
            $legacyReview = $lockLegacyReview->fetch();

            if ($legacyReview && $legacyReview['resultado_atual'] === 'PENDENTE_REVISAO') {
                throw new DomainException(
                    'A matrícula está bloqueada enquanto a revisão do vínculo legado estiver pendente.'
                );
            }

            if (
                $legacyReview
                && $legacyReview['resultado_atual'] === 'VINCULO_CONFIRMADO'
            ) {
                // Confirmar somente a existência de um contrato antigo não basta:
                // o intervalo comprovado precisa estar materializado em matrícula LEGADO.
                $lockLegacyEnrollments = $this->pdo->prepare(
                    "SELECT id
                     FROM matriculas
                     WHERE aluno_id = :student_id
                       AND origem = 'LEGADO'
                     ORDER BY id
                     FOR UPDATE"
                );
                $lockLegacyEnrollments->execute(['student_id' => $studentId]);

                if ($lockLegacyEnrollments->fetchColumn() === false) {
                    throw new DomainException(
                        'O vínculo legado foi confirmado, mas seu intervalo comprovado ainda não está registrado.'
                    );
                }
            } elseif (
                $legacyReview
                && $legacyReview['resultado_atual'] !== 'LEGADO_SEM_VIGENCIA'
            ) {
                throw new DomainException('O resultado da revisão de legado não permite nova matrícula.');
            }

            // 4. Obter o instante depois dos locks para não aceitar um início que
            // tenha ficado no passado enquanto a operação aguardava.
            $nowLocal = ($this->clock)();
            if (!$nowLocal instanceof DateTimeImmutable) {
                throw new UnexpectedValueException(
                    'O relógio do serviço precisa retornar DateTimeImmutable.'
                );
            }
            $this->assertAcademyDateTime($nowLocal, 'O instante atual');

            if ($startAtExecutionTime) {
                if ($startLocal->format('Y-m-d') !== $nowLocal->format('Y-m-d')) {
                    throw new DomainException(
                        'A data selecionada deixou de ser hoje enquanto a contratação aguardava processamento.'
                    );
                }

                $startLocal = $nowLocal;
            } elseif ($startLocal < $nowLocal) {
                throw new DomainException('O início da matrícula não pode estar no passado.');
            }

            $calendarReferenceDay = $unit === 'MES'
                ? (int) $startLocal->format('j')
                : null;

            $endExclusiveLocal = EnrollmentPeriodCalculator::calculateEndExclusive(
                $startLocal,
                $duration,
                $unit,
                $calendarReferenceDay
            );

            $newInterval = EnrollmentInterval::fromContract(
                $startLocal,
                $endExclusiveLocal
            );

            if ($newInterval === null) {
                throw new LogicException('O período novo não pode ser vazio.');
            }

            // 5. Ler todos os intervalos sob bloqueio, inclusive cancelados:
            // cancelamentos antes/no início são intervalos vazios; cancelamentos
            // posteriores preservam o trecho de vigência já utilizado.
            $lockExistingEnrollments = $this->pdo->prepare(
                'SELECT id, inicio_em, fim_exclusivo_em, cancelada_em
                 FROM matriculas
                 WHERE aluno_id = :student_id
                 ORDER BY id
                 FOR UPDATE'
            );
            $lockExistingEnrollments->execute(['student_id' => $studentId]);

            foreach ($lockExistingEnrollments->fetchAll() as $existing) {
                $existingInterval = EnrollmentInterval::fromContract(
                    $this->storedUtcToAcademyTime((string) $existing['inicio_em']),
                    $this->storedUtcToAcademyTime((string) $existing['fim_exclusivo_em']),
                    $existing['cancelada_em'] === null
                        ? null
                        : $this->storedUtcToAcademyTime((string) $existing['cancelada_em'])
                );

                if ($existingInterval !== null && $newInterval->overlaps($existingInterval)) {
                    throw new DomainException(
                        'O período solicitado se sobrepõe a outra matrícula do aluno.'
                    );
                }
            }

            // 6. Inserir o snapshot contratado e os instantes contratuais em UTC.
            $insert = $this->pdo->prepare(
                "INSERT INTO matriculas (
                    aluno_id,
                    plano_id,
                    plano_nome_contratado,
                    valor_contratado,
                    duracao_contratada_valor,
                    duracao_contratada_unidade,
                    inicio_em,
                    fim_exclusivo_em,
                    dia_referencia_calendario,
                    origem
                ) VALUES (
                    :aluno_id,
                    :plano_id,
                    :plano_nome_contratado,
                    :valor_contratado,
                    :duracao_contratada_valor,
                    :duracao_contratada_unidade,
                    :inicio_em,
                    :fim_exclusivo_em,
                    :dia_referencia_calendario,
                    'SISTEMA'
                )"
            );
            $insert->execute([
                'aluno_id' => $studentId,
                'plano_id' => $planId,
                'plano_nome_contratado' => $planName,
                'valor_contratado' => $price,
                'duracao_contratada_valor' => $duration,
                'duracao_contratada_unidade' => $unit,
                'inicio_em' => $startLocal->setTimezone($this->utcTimezone)->format('Y-m-d H:i:s'),
                'fim_exclusivo_em' => $endExclusiveLocal->setTimezone($this->utcTimezone)->format('Y-m-d H:i:s'),
                'dia_referencia_calendario' => $calendarReferenceDay,
            ]);

            $enrollmentId = (int) $this->pdo->lastInsertId();
            if ($enrollmentId < 1) {
                throw new UnexpectedValueException('O banco não retornou o ID da nova matrícula.');
            }

            // 7. A auditoria faz parte da mesma transação do contrato.
            AuditIntegrityChain::append($this->pdo, $actorUserId, 'matricula_criada', 'matriculas', $enrollmentId);

            $this->pdo->commit();

            return $enrollmentId;
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
                $fieldName . ' deve estar no fuso '
                . EnrollmentPeriodCalculator::ACADEMY_TIMEZONE . '.'
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
            throw new UnexpectedValueException(
                'Foi encontrado um instante contratual inválido no banco.'
            );
        }

        return $storedUtc->setTimezone($this->academyTimezone);
    }

    private static function isPositiveDecimal(string $value): bool
    {
        if (preg_match('/^\d{1,8}\.\d{2}$/D', $value) !== 1) {
            return false;
        }

        return ltrim(str_replace('.', '', $value), '0') !== '';
    }
}
