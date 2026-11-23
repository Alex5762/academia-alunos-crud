<?php
declare(strict_types=1);

namespace FitControl\Domain\Classes;

use DomainException;
use InvalidArgumentException;
use LogicException;
use PDO;
use Throwable;

require_once __DIR__ . '/../../Security/AuditIntegrityChain.php';

/**
 * Gerencia horários semanais recorrentes.
 *
 * O bloqueio da linha do instrutor serializa gravações concorrentes para a
 * mesma pessoa, evitando duas aulas sobrepostas no mesmo dia da semana.
 */
final class ClassScheduleService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(
        int $actorUserId,
        int $modalityId,
        ?int $instructorId,
        int $weekday,
        string $startTime,
        string $endTime
    ): int {
        $this->assertIdentifiers($actorUserId, $modalityId, $instructorId);
        $slot = self::normalizeSlot($weekday, $startTime, $endTime);
        $this->beginOwnedTransaction();

        try {
            $this->lockActiveModality($modalityId);
            if ($instructorId !== null) {
                $this->lockActiveInstructor($instructorId);
                $this->assertNoInstructorOverlap(
                    $instructorId,
                    $weekday,
                    $slot['start'],
                    $slot['end'],
                    null
                );
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO horarios_aula (
                    modalidade_id, instrutor_id, dia_semana, hora_inicio, hora_fim
                 ) VALUES (
                    :modality_id, :instructor_id, :weekday, :start_time, :end_time
                 )'
            );
            $insert->execute([
                'modality_id' => $modalityId,
                'instructor_id' => $instructorId,
                'weekday' => $weekday,
                'start_time' => $slot['start'],
                'end_time' => $slot['end'],
            ]);
            $id = (int) $this->pdo->lastInsertId();
            if ($id < 1) {
                throw new DomainException('O banco não retornou o identificador do horário.');
            }

            $this->audit($actorUserId, 'horario_aula_criado', $id);
            $this->pdo->commit();
            return $id;
        } catch (Throwable $exception) {
            $this->rollbackIfNeeded();
            throw $exception;
        }
    }

    public function update(
        int $scheduleId,
        int $actorUserId,
        int $modalityId,
        ?int $instructorId,
        int $weekday,
        string $startTime,
        string $endTime
    ): void {
        $this->assertIdentifiers($actorUserId, $modalityId, $instructorId);
        if ($scheduleId < 1) {
            throw new InvalidArgumentException('O identificador do horário é inválido.');
        }
        $slot = self::normalizeSlot($weekday, $startTime, $endTime);
        $this->beginOwnedTransaction();

        try {
            // Snapshot inicial sem lock: permite descobrir as referências antigas.
            // Os locks efetivos seguem uma ordem estável: modalidades -> instrutores -> horário.
            $snapshotQuery = $this->pdo->prepare(
                'SELECT id, modalidade_id, instrutor_id, ativo
                 FROM horarios_aula WHERE id = :id'
            );
            $snapshotQuery->execute(['id' => $scheduleId]);
            $snapshot = $snapshotQuery->fetch(PDO::FETCH_ASSOC);
            if (!$snapshot) {
                throw new DomainException('Horário não encontrado.');
            }
            if ((int) $snapshot['ativo'] !== 1) {
                throw new DomainException('Horários inativos não podem ser editados; crie um novo horário.');
            }

            $modalityIds = array_values(array_unique([
                (int) $snapshot['modalidade_id'],
                $modalityId,
            ]));
            sort($modalityIds, SORT_NUMERIC);
            foreach ($modalityIds as $id) {
                $this->lockActiveModality($id);
            }

            $instructorIds = array_values(array_unique(array_filter([
                self::nullableInt($snapshot['instrutor_id']),
                $instructorId,
            ], static fn (?int $id): bool => $id !== null)));
            sort($instructorIds, SORT_NUMERIC);
            foreach ($instructorIds as $id) {
                $this->lockActiveInstructor($id);
            }

            $current = $this->lockSchedule($scheduleId);
            if (
                (int) $current['modalidade_id'] !== (int) $snapshot['modalidade_id']
                || self::nullableInt($current['instrutor_id']) !== self::nullableInt($snapshot['instrutor_id'])
                || (int) $current['ativo'] !== 1
            ) {
                throw new DomainException(
                    'O horário foi alterado por outra operação. Recarregue a grade e tente novamente.'
                );
            }

            if ($instructorId !== null) {
                $this->assertNoInstructorOverlap(
                    $instructorId,
                    $weekday,
                    $slot['start'],
                    $slot['end'],
                    $scheduleId
                );
            }

            $changed = (int) $current['modalidade_id'] !== $modalityId
                || self::nullableInt($current['instrutor_id']) !== $instructorId
                || (int) $current['dia_semana'] !== $weekday
                || (string) $current['hora_inicio'] !== $slot['start']
                || (string) $current['hora_fim'] !== $slot['end'];

            if (!$changed) {
                $this->pdo->commit();
                return;
            }

            $update = $this->pdo->prepare(
                'UPDATE horarios_aula
                 SET modalidade_id = :modality_id,
                     instrutor_id = :instructor_id,
                     dia_semana = :weekday,
                     hora_inicio = :start_time,
                     hora_fim = :end_time
                 WHERE id = :id'
            );
            $update->execute([
                'modality_id' => $modalityId,
                'instructor_id' => $instructorId,
                'weekday' => $weekday,
                'start_time' => $slot['start'],
                'end_time' => $slot['end'],
                'id' => $scheduleId,
            ]);
            $this->audit($actorUserId, 'horario_aula_editado', $scheduleId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollbackIfNeeded();
            throw $exception;
        }
    }

    public function deactivate(int $scheduleId, int $actorUserId): void
    {
        if ($scheduleId < 1 || $actorUserId < 1) {
            throw new InvalidArgumentException('Os identificadores precisam ser positivos.');
        }
        $this->assertActor($actorUserId);
        $this->beginOwnedTransaction();

        try {
            $current = $this->lockSchedule($scheduleId);
            if ((int) $current['ativo'] === 0) {
                $this->pdo->commit();
                return;
            }

            $update = $this->pdo->prepare('UPDATE horarios_aula SET ativo = 0 WHERE id = :id');
            $update->execute(['id' => $scheduleId]);
            $this->audit($actorUserId, 'horario_aula_inativado', $scheduleId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollbackIfNeeded();
            throw $exception;
        }
    }

    private function lockActiveModality(int $id): void
    {
        $query = $this->pdo->prepare(
            'SELECT id, ativo FROM modalidades WHERE id = :id FOR UPDATE'
        );
        $query->execute(['id' => $id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int) $row['ativo'] !== 1) {
            throw new DomainException('A modalidade não existe ou está inativa.');
        }
    }

    private function lockActiveInstructor(int $id): void
    {
        $query = $this->pdo->prepare(
            'SELECT id, ativo FROM instrutores WHERE id = :id FOR UPDATE'
        );
        $query->execute(['id' => $id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int) $row['ativo'] !== 1) {
            throw new DomainException('O instrutor não existe ou está inativo.');
        }
    }

    private function assertNoInstructorOverlap(
        int $instructorId,
        int $weekday,
        string $start,
        string $end,
        ?int $exceptScheduleId
    ): void {
        $sql = 'SELECT id
                FROM horarios_aula
                WHERE instrutor_id = :instructor_id
                  AND dia_semana = :weekday
                  AND ativo = 1
                  AND hora_inicio < :new_end
                  AND hora_fim > :new_start';
        $parameters = [
            'instructor_id' => $instructorId,
            'weekday' => $weekday,
            'new_end' => $end,
            'new_start' => $start,
        ];
        if ($exceptScheduleId !== null) {
            $sql .= ' AND id <> :except_id';
            $parameters['except_id'] = $exceptScheduleId;
        }
        $sql .= ' LIMIT 1 FOR UPDATE';

        $query = $this->pdo->prepare($sql);
        $query->execute($parameters);
        if ($query->fetchColumn() !== false) {
            throw new DomainException('O instrutor já possui uma aula que conflita com esse horário.');
        }
    }

    private function lockSchedule(int $id): array
    {
        $query = $this->pdo->prepare(
            'SELECT id, modalidade_id, instrutor_id, dia_semana, hora_inicio, hora_fim, ativo
             FROM horarios_aula WHERE id = :id FOR UPDATE'
        );
        $query->execute(['id' => $id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new DomainException('Horário não encontrado.');
        }
        return $row;
    }

    private function audit(int $actorId, string $action, int $scheduleId): void
    {
        \FitControl\Security\AuditIntegrityChain::append(
            $this->pdo,
            $actorId,
            $action,
            'horarios_aula',
            $scheduleId
        );
    }

    private function assertActor(int $actorId): void
    {
        $query = $this->pdo->prepare('SELECT ativo FROM usuarios WHERE id = :id');
        $query->execute(['id' => $actorId]);
        if ((int) $query->fetchColumn() !== 1) {
            throw new DomainException('O responsável não existe ou está inativo.');
        }
    }

    private function assertIdentifiers(int $actorId, int $modalityId, ?int $instructorId): void
    {
        if ($actorId < 1 || $modalityId < 1 || ($instructorId !== null && $instructorId < 1)) {
            throw new InvalidArgumentException('Os identificadores precisam ser positivos.');
        }
        $this->assertActor($actorId);
    }

    private function beginOwnedTransaction(): void
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('O serviço de horários precisa controlar sua própria transação.');
        }
        $this->pdo->beginTransaction();
    }

    private function rollbackIfNeeded(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private static function normalizeSlot(int $weekday, string $start, string $end): array
    {
        if ($weekday < 1 || $weekday > 7) {
            throw new InvalidArgumentException('O dia da semana deve seguir o padrão ISO de 1 a 7.');
        }
        if (
            preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $start) !== 1
            || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $end) !== 1
        ) {
            throw new InvalidArgumentException('Informe horários válidos no formato HH:MM ou HH:MM:SS.');
        }

        $start = strlen($start) === 5 ? $start . ':00' : $start;
        $end = strlen($end) === 5 ? $end . ':00' : $end;
        if ($end <= $start) {
            throw new InvalidArgumentException('O horário final precisa ser posterior ao horário inicial; aulas não atravessam a meia-noite.');
        }

        return ['start' => $start, 'end' => $end];
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
