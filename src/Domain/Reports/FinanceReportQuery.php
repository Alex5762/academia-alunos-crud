<?php
declare(strict_types=1);

namespace FitControl\Domain\Reports;

use DomainException;
use FitControl\Domain\Finance\FinanceDashboardStatus;
use FitControl\Domain\Finance\FinanceMoneyFormatter;
use PDO;

/**
 * Consulta os seis indicadores financeiros aprovados para o Bloco 07.
 *
 * Valores monetários permanecem como DECIMAL textual ou centavos inteiros;
 * pagamentos históricos nunca são filtrados pelo estado atual da cobrança/matrícula.
 */
final class FinanceReportQuery
{
    private const PAYMENT_METHODS = [
        'DINHEIRO',
        'PIX',
        'TRANSFERENCIA',
        'CARTAO',
        'OUTRO',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{
     *   fn01_received_decimal:string,
     *   fn02_payment_count:int,
     *   fn03_by_method:list<array{method:string,payment_count:int,received_decimal:string}>,
     *   fn04_due_charges:array{
     *     collectible:array{charge_count:int,nominal_total_decimal:string},
     *     review:array{charge_count:int,nominal_total_decimal:string}
     *   },
     *   fn05_collectible_balance_cents:int,
     *   fn06_review_balance_cents:int
     * }
     */
    public function forPeriod(ReportPeriod $period): array
    {
        $start = $period->getStartDateString();
        $end = $period->getEndDateString();

        // Pagamentos são consultados sem JOIN: cancelamentos posteriores não
        // podem apagar lançamentos históricos dos indicadores FN-01 a FN-03.
        $summaryQuery = $this->pdo->prepare(
            'SELECT COUNT(*) AS payment_count,
                    COALESCE(SUM(valor), 0.00) AS received_total
             FROM pagamentos
             WHERE data_pagamento >= :start_date
               AND data_pagamento <= :end_date'
        );
        $summaryQuery->execute(['start_date' => $start, 'end_date' => $end]);
        $summary = $summaryQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($summary)) {
            throw new DomainException('Não foi possível consultar os pagamentos do período.');
        }

        $byMethodQuery = $this->pdo->prepare(
            'SELECT forma_pagamento, COUNT(*) AS payment_count,
                    SUM(valor) AS received_total
             FROM pagamentos
             WHERE data_pagamento >= :start_date
               AND data_pagamento <= :end_date
             GROUP BY forma_pagamento'
        );
        $byMethodQuery->execute(['start_date' => $start, 'end_date' => $end]);
        $methodRows = [];
        foreach ($byMethodQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $method = (string) $row['forma_pagamento'];
            if (!in_array($method, self::PAYMENT_METHODS, true)) {
                throw new DomainException('Foi encontrada uma forma de pagamento fora do catálogo financeiro.');
            }
            $methodRows[$method] = [
                'method' => $method,
                'payment_count' => (int) $row['payment_count'],
                'received_decimal' => (string) $row['received_total'],
            ];
        }

        $methodGroups = [];
        $groupedCents = 0;
        $groupedCount = 0;
        foreach (self::PAYMENT_METHODS as $method) {
            if (!isset($methodRows[$method])) {
                continue;
            }
            $methodGroups[] = $methodRows[$method];
            $groupedCents += FinanceMoneyFormatter::toCents($methodRows[$method]['received_decimal']);
            $groupedCount += $methodRows[$method]['payment_count'];
        }

        $receivedDecimal = (string) $summary['received_total'];
        $paymentCount = (int) $summary['payment_count'];
        if (
            $groupedCount !== $paymentCount
            || $groupedCents !== FinanceMoneyFormatter::toCents($receivedDecimal)
        ) {
            throw new DomainException('Os grupos por forma de pagamento não reconciliam com o total do período.');
        }

        // FN-04 considera a cobrança uma vez, sem juntar pagamentos.
        $dueQuery = $this->pdo->prepare(
            "SELECT
                CASE WHEN m.cancelada_em IS NULL THEN 'collectible' ELSE 'review' END AS group_key,
                COUNT(*) AS charge_count,
                SUM(c.valor_total) AS nominal_total
             FROM cobrancas c
             INNER JOIN matriculas m ON m.id = c.matricula_id
             WHERE c.cancelada_em_utc IS NULL
               AND c.data_vencimento >= :start_date
               AND c.data_vencimento <= :end_date
             GROUP BY CASE WHEN m.cancelada_em IS NULL THEN 'collectible' ELSE 'review' END"
        );
        $dueQuery->execute(['start_date' => $start, 'end_date' => $end]);
        $dueGroups = [
            'collectible' => ['charge_count' => 0, 'nominal_total_decimal' => '0.00'],
            'review' => ['charge_count' => 0, 'nominal_total_decimal' => '0.00'],
        ];
        foreach ($dueQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string) $row['group_key'];
            if (!array_key_exists($key, $dueGroups)) {
                throw new DomainException('O relatório encontrou um grupo de cobrança inválido.');
            }
            $dueGroups[$key] = [
                'charge_count' => (int) $row['charge_count'],
                'nominal_total_decimal' => (string) $row['nominal_total'],
            ];
        }

        // Pré-agregar pagamentos por cobrança evita multiplicação de linhas.
        // As regras canônicas de classificação são reutilizadas por cobrança.
        $balancesQuery = $this->pdo->query(
            'SELECT
                c.valor_total,
                c.cancelada_em_utc,
                m.cancelada_em AS matricula_cancelada_em,
                COALESCE(p.valor_pago, 0.00) AS valor_pago
             FROM cobrancas c
             INNER JOIN matriculas m ON m.id = c.matricula_id
             LEFT JOIN (
                SELECT cobranca_id, SUM(valor) AS valor_pago
                FROM pagamentos
                GROUP BY cobranca_id
             ) p ON p.cobranca_id = c.id'
        );

        $collectibleCents = 0;
        $reviewCents = 0;
        foreach ($balancesQuery->fetchAll(PDO::FETCH_ASSOC) as $charge) {
            $collectibleCents += FinanceDashboardStatus::collectibleBalanceCents($charge);
            $reviewCents += FinanceDashboardStatus::reviewBalanceCents($charge);
        }

        return [
            'fn01_received_decimal' => $receivedDecimal,
            'fn02_payment_count' => $paymentCount,
            'fn03_by_method' => $methodGroups,
            'fn04_due_charges' => $dueGroups,
            'fn05_collectible_balance_cents' => $collectibleCents,
            'fn06_review_balance_cents' => $reviewCents,
        ];
    }
}
