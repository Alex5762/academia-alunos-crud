<?php
declare(strict_types=1);

namespace FitControl\Domain\Reports;

use DomainException;
use PDO;

/**
 * Consulta agregada do relatório de matrículas.
 *
 * Uma única instrução SQL fornece as dimensões e a contagem defensiva de
 * intervalos inválidos no mesmo snapshot de leitura.
 */
final class EnrollmentReportQuery
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * MT-01 a MT-05 para o período civil inclusivo informado.
     *
     * @return array{
     *   starts_total:int,
     *   starts_known_values_count:int,
     *   starts_known_value_sum:?string,
     *   starts_by_plan:list<array{name:string,count:int,known_values_count:int,known_value_sum:?string}>,
     *   starts_by_origin:list<array{origin:string,count:int}>,
     *   cancellations:int
     * }
     */
    public function forPeriod(ReportPeriod $period): array
    {
        $startUtc = $period->getStartInclusiveUtc()->format('Y-m-d H:i:s');
        $endExclusiveUtc = $period->getEndExclusiveUtc()->format('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            "WITH integrity AS (
                SELECT COALESCE(SUM(CASE
                    WHEN fim_exclusivo_em <= inicio_em THEN 1 ELSE 0
                END), 0) AS invalid_interval_count
                FROM matriculas
            ),
            effective_starts AS (
                SELECT
                    m.plano_nome_contratado,
                    m.valor_contratado,
                    m.origem
                FROM matriculas m
                INNER JOIN integrity i
                    ON i.invalid_interval_count = 0
                WHERE m.inicio_em >= :period_start_utc
                  AND m.inicio_em < :period_end_exclusive_utc
                  AND m.fim_exclusivo_em > m.inicio_em
                  AND (m.cancelada_em IS NULL OR m.cancelada_em > m.inicio_em)
            ),
            report_rows AS (
                SELECT
                    'TOTAL' AS metric,
                    CAST('' AS CHAR(100)) AS label,
                    COUNT(*) AS quantity,
                    COUNT(valor_contratado) AS known_values_count,
                    SUM(valor_contratado) AS known_value_sum
                FROM effective_starts

                UNION ALL

                SELECT
                    'PLAN' AS metric,
                    COALESCE(
                        NULLIF(TRIM(plano_nome_contratado), ''),
                        'Plano não informado no snapshot'
                    ) AS label,
                    COUNT(*) AS quantity,
                    COUNT(valor_contratado) AS known_values_count,
                    SUM(valor_contratado) AS known_value_sum
                FROM effective_starts
                GROUP BY COALESCE(
                    NULLIF(TRIM(plano_nome_contratado), ''),
                    'Plano não informado no snapshot'
                )

                UNION ALL

                SELECT
                    'ORIGIN' AS metric,
                    CAST(origem AS CHAR(100)) AS label,
                    COUNT(*) AS quantity,
                    COUNT(valor_contratado) AS known_values_count,
                    SUM(valor_contratado) AS known_value_sum
                FROM effective_starts
                GROUP BY origem

                UNION ALL

                SELECT
                    'CANCELLATIONS' AS metric,
                    CAST('' AS CHAR(100)) AS label,
                    COUNT(*) AS quantity,
                    0 AS known_values_count,
                    CAST(NULL AS DECIMAL(10,2)) AS known_value_sum
                FROM matriculas
                WHERE cancelada_em >= :cancel_start_utc
                  AND cancelada_em < :cancel_end_exclusive_utc
            )
            SELECT
                r.metric,
                r.label,
                r.quantity,
                r.known_values_count,
                r.known_value_sum,
                i.invalid_interval_count
            FROM report_rows r
            CROSS JOIN integrity i
            ORDER BY
                CASE r.metric
                    WHEN 'TOTAL' THEN 1
                    WHEN 'PLAN' THEN 2
                    WHEN 'ORIGIN' THEN 3
                    ELSE 4
                END,
                r.label"
        );

        $statement->execute([
            'period_start_utc' => $startUtc,
            'period_end_exclusive_utc' => $endExclusiveUtc,
            'cancel_start_utc' => $startUtc,
            'cancel_end_exclusive_utc' => $endExclusiveUtc,
        ]);

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            throw new DomainException('Não foi possível obter o relatório de matrículas.');
        }

        if ((int) $rows[0]['invalid_interval_count'] > 0) {
            throw new DomainException(
                'Há matrícula com fim exclusivo anterior ou igual ao início. '
                . 'O relatório de matrículas foi interrompido sem apresentar contagens parciais.'
            );
        }

        $summary = null;
        $cancellations = null;
        $byPlan = [];
        $byOrigin = [];

        foreach ($rows as $row) {
            $quantity = (int) $row['quantity'];

            if ($row['metric'] === 'TOTAL') {
                $summary = [
                    'starts_total' => $quantity,
                    'starts_known_values_count' => (int) $row['known_values_count'],
                    'starts_known_value_sum' => $row['known_value_sum'] === null
                        ? null
                        : (string) $row['known_value_sum'],
                ];
            } elseif ($row['metric'] === 'PLAN') {
                $byPlan[] = [
                    'name' => (string) $row['label'],
                    'count' => $quantity,
                    'known_values_count' => (int) $row['known_values_count'],
                    'known_value_sum' => $row['known_value_sum'] === null
                        ? null
                        : (string) $row['known_value_sum'],
                ];
            } elseif ($row['metric'] === 'ORIGIN') {
                $byOrigin[] = [
                    'origin' => (string) $row['label'],
                    'count' => $quantity,
                ];
            } elseif ($row['metric'] === 'CANCELLATIONS') {
                $cancellations = $quantity;
            }
        }

        if ($summary === null || $cancellations === null) {
            throw new DomainException('O relatório de matrículas retornou uma estrutura incompleta.');
        }

        return $summary + [
            'starts_by_plan' => $byPlan,
            'starts_by_origin' => $byOrigin,
            'cancellations' => $cancellations,
        ];
    }
}
