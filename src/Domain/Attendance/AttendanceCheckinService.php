<?php
declare(strict_types=1);

namespace FitControl\Domain\Attendance;

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
 * Registra uma presença manual com elegibilidade, duplicidade e auditoria atômicas.
 *
 * Ordem de bloqueio: aluno -> matrícula vigente -> check-in diário.
 */
final class AttendanceCheckinService
{
    private readonly Closure $clock;
    private readonly DateTimeZone $academyTimezone;
    private readonly DateTimeZone $utcTimezone;

    public function __construct(
        private readonly PDO $pdo,
        callable $clock
    ) {
        $this->clock = Closure::fromCallable($clock);
        $this->academyTimezone = new DateTimeZone('America/Sao_Paulo');
        $this->utcTimezone = new DateTimeZone('UTC');
    }

    /**
     * Registra um check-in no instante informado pelo relógio confiável do servidor.
     *
     * @throws InvalidArgumentException Para identificadores inválidos.
     * @throws DomainException Para aluno inelegível ou duplicidade diária.
     * @throws LogicException Quando já existe transação externa.
     * @throws Throwable Para falhas do banco ou da cadeia de auditoria.
     */
    public function register(int $studentId, int $actorUserId): int
    {
        if ($studentId < 1 || $actorUserId < 1) {
            throw new InvalidArgumentException('Os identificadores precisam ser inteiros positivos.');
        }

        if ($this->pdo->inTransaction()) {
            throw new LogicException('O serviço de check-in precisa controlar sua própria transação.');
        }

        $this->pdo->beginTransaction();

        try {
            // O lock do aluno serializa check-ins concorrentes e segue a ordem
            // adotada pelos serviços de criação/cancelamento de matrícula.
            $lockStudent = $this->pdo->prepare(
                'SELECT id, ativo
                 FROM alunos
                 WHERE id = :student_id
                 FOR UPDATE'
            );
            $lockStudent->execute(['student_id' => $studentId]);
            $student = $lockStudent->fetch(PDO::FETCH_ASSOC);

            if (!$student) {
                throw new DomainException('Aluno não encontrado.');
            }

            if ((int) $student['ativo'] !== 1) {
                throw new DomainException('Alunos inativos não podem registrar presença.');
            }

            $nowLocal = ($this->clock)();
            if (!$nowLocal instanceof DateTimeImmutable) {
                throw new UnexpectedValueException('O relógio precisa retornar DateTimeImmutable.');
            }
            if ($nowLocal->getTimezone()->getName() !== $this->academyTimezone->getName()) {
                throw new InvalidArgumentException(
                    'O relógio precisa usar o fuso America/Sao_Paulo.'
                );
            }

            $year = (int) $nowLocal->format('Y');
            if ($year < 1000 || $year > 9999) {
                throw new InvalidArgumentException('O instante está fora do intervalo DATETIME suportado.');
            }

            $nowUtc = $nowLocal->setTimezone($this->utcTimezone)->format('Y-m-d H:i:s');
            $localDate = $nowLocal->format('Y-m-d');

            // Contratos são persistidos em UTC. O instante atual também é convertido
            // para UTC antes da comparação, sem depender do fuso da sessão MySQL.
            $lockEnrollments = $this->pdo->prepare(
                'SELECT id
                 FROM matriculas
                 WHERE aluno_id = :student_id
                   AND inicio_em <= :now_start_utc
                   AND fim_exclusivo_em > :now_end_utc
                   AND cancelada_em IS NULL
                 ORDER BY id
                 FOR UPDATE'
            );
            $lockEnrollments->execute([
                'student_id' => $studentId,
                'now_start_utc' => $nowUtc,
                'now_end_utc' => $nowUtc,
            ]);
            $activeEnrollments = $lockEnrollments->fetchAll(PDO::FETCH_COLUMN);

            if ($activeEnrollments === []) {
                throw new DomainException(
                    'O aluno não possui matrícula vigente neste momento.'
                );
            }

            if (count($activeEnrollments) !== 1) {
                throw new DomainException(
                    'Não foi possível identificar uma única matrícula vigente para o aluno.'
                );
            }

            $lockDailyCheckin = $this->pdo->prepare(
                'SELECT id
                 FROM checkins
                 WHERE aluno_id = :student_id
                   AND data_local = :local_date
                 LIMIT 1
                 FOR UPDATE'
            );
            $lockDailyCheckin->execute([
                'student_id' => $studentId,
                'local_date' => $localDate,
            ]);

            if ($lockDailyCheckin->fetchColumn() !== false) {
                throw new DomainException('Este aluno já possui check-in registrado hoje.');
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO checkins (
                    aluno_id,
                    matricula_id,
                    usuario_id,
                    data_local,
                    registrado_em_utc
                ) VALUES (
                    :student_id,
                    :enrollment_id,
                    :actor_id,
                    :local_date,
                    :recorded_at_utc
                )'
            );
            $insert->execute([
                'student_id' => $studentId,
                'enrollment_id' => (int) $activeEnrollments[0],
                'actor_id' => $actorUserId,
                'local_date' => $localDate,
                'recorded_at_utc' => $nowUtc,
            ]);

            $checkinId = (int) $this->pdo->lastInsertId();
            if ($checkinId < 1) {
                throw new UnexpectedValueException('O banco não retornou o ID do check-in.');
            }

            \FitControl\Security\AuditIntegrityChain::append(
                $this->pdo,
                $actorUserId,
                'checkin_registrado',
                'checkins',
                $checkinId,
                $nowUtc
            );

            $this->pdo->commit();

            return $checkinId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }
}
