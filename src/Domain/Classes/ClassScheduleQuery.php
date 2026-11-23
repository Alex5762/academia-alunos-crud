<?php
declare(strict_types=1);

namespace FitControl\Domain\Classes;

use InvalidArgumentException;
use PDO;

/**
 * Consultas de leitura para a grade semanal.
 * Retorna apenas horários e modalidades ativos; não altera estado.
 */
final class ClassScheduleQuery
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{id:int,nome:string}> */
    public function activeModalities(): array
    {
        return $this->pdo->query(
            'SELECT id, nome
             FROM modalidades
             WHERE ativo = 1
             ORDER BY nome ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array{id:int,nome:string}> */
    public function activeInstructors(): array
    {
        return $this->pdo->query(
            'SELECT id, nome
             FROM instrutores
             WHERE ativo = 1
             ORDER BY nome ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array{
     *   id:int,
     *   dia_semana:int,
     *   hora_inicio:string,
     *   hora_fim:string,
     *   modalidade_nome:string,
     *   instrutor_nome:?string
     * }>
     */
    public function activeWeeklySchedule(
        ?int $weekday = null,
        ?int $modalityId = null,
        ?int $instructorId = null
    ): array {
        if ($weekday !== null && ($weekday < 1 || $weekday > 7)) {
            throw new InvalidArgumentException('O dia da semana deve seguir o padrão ISO de 1 a 7.');
        }
        if (($modalityId !== null && $modalityId < 1)
            || ($instructorId !== null && $instructorId < 1)) {
            throw new InvalidArgumentException('Os identificadores dos filtros precisam ser positivos.');
        }

        $conditions = ['h.ativo = 1', 'm.ativo = 1'];
        $parameters = [];
        if ($weekday !== null) {
            $conditions[] = 'h.dia_semana = :weekday';
            $parameters['weekday'] = $weekday;
        }
        if ($modalityId !== null) {
            $conditions[] = 'h.modalidade_id = :modality_id';
            $parameters['modality_id'] = $modalityId;
        }
        if ($instructorId !== null) {
            $conditions[] = 'h.instrutor_id = :instructor_id';
            $parameters['instructor_id'] = $instructorId;
        }

        $query = $this->pdo->prepare(
            'SELECT h.id, h.dia_semana, h.hora_inicio, h.hora_fim,
                    m.nome AS modalidade_nome,
                    CASE WHEN i.ativo = 1 THEN i.nome ELSE NULL END AS instrutor_nome
             FROM horarios_aula h
             INNER JOIN modalidades m ON m.id = h.modalidade_id
             LEFT JOIN instrutores i ON i.id = h.instrutor_id
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY h.dia_semana ASC, h.hora_inicio ASC, m.nome ASC, h.id ASC'
        );
        $query->execute($parameters);

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
