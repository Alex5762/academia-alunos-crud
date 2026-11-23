<?php
declare(strict_types=1);

namespace FitControl\Domain\Finance;

use DomainException;
use PDO;

/**
 * Indicadores FN-01 a FN-06 do Bloco 07.
 *
 * Cada consulta agrega no banco; pagamentos são consultados sem JOIN para
 * preservar o histórico. Valores DECIMAL permanecem strings/centavos inteiros.
 */
final class FinanceReportQuery
{
    private const PAYMENT_METHODS = [
        'DINHEIRO' => 'Dinheiro',
        'PIX' => 'Pix',
        'TRANSFERENCIA' => 'Transferência',
        'CARTAO' => 'Cartão',
        'OUTRO' => 'Outro',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{
     *   fn01_received_total:string,
     *   fn02_payment_count:int,
     *   fn03_by_method:list<array{method:string,label:string,count:int,total:string}>,
     *   fn04_due_charges:array{
     *     regular:array{count:int,nominal_total:string},
     *     review:array{count:int,nominal_total:string}
     *   },
     *   fn05_collectible_balance:string,
     *   fn06_review_balance:string
     * }
     */
    public function forPeriod(\FitControl\Domain\Reports\ReportPeriod $period): array
    {
        $startDate = $period->getStartDateString();
        $endDate = $period->getEndDateString();

        // Não juntar cobranças/matrículas: pagamentos retroativos e históricos
        // permanecem no indicador mesmo após cancelamentos posteriores.
        $payments = $this->pdo->prepare(
            "SELECT
                forma_pagamento,
                COUNT(*) AS payment_count,
                COALESCE(SUM(valor), 0.00) AS payment_total
             FROM pagamentos
             WHERE data_pagamento >= :start_date
               AND data_pagamento <= :end_date
             GROUP BY forma_pagamento
             ORDER BY CASE forma_pagamento
                WHEN 'DINHEIRO' THEN 1
                WHEN 'PIX' THEN 2
                WHEN 'TRANSFERENCIA' THEN 3
                WHEN 'CARTAO' THEN 4
                WHEN 'OUTRO' THEN 5
                ELSE 6
             END"
        );
        $payments->execute([
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        $paymentCount = 0;
        $receivedCents = 0;
        $byMethod = [];
        foreach ($payments->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $method = (string) $row['forma_pagamento'];
            if (!isset(self::PAYMENT_METHODS[$method])) {
                throw new DomainException('O relatório financeiro encontrou uma forma de pagamento não reconhecida.');
            }

            $count = (int) $row['payment_count'];
            $amountCents = FinanceMoneyFormatter::toCents((string) $row['payment_total']);
            if ($count < 1 || $amountCents < 0) {
                throw new DomainException('O relatório financeiro encontrou um agregado de pagamentos inválido.');
            }

            $paymentCount += $count;
            $receivedCents += $amountCents;
            $byMethod[] = [
                'method' => $method,
                'label' => self::PAYMENT_METHODS[$method],
                'count' => $count,
                'total' => FinanceMoneyFormatter::decimalFromCents($amountCents),
            ];
        }

        $methodCount = array_sum(array_column($byMethod, 'count'));
        $methodCents = 0;
        foreach ($byMethod as $group) {
            $methodCents += FinanceMoneyFormatter::toCents($group['total']);
        }
        if ($methodCount !== $paymentCount || $methodCents !== $receivedCents) {
            throw new DomainException('Os grupos de pagamentos não reconciliam com os totais financeiros.');
        }

        // FN-04 usa o valor nominal da cobrança e não junta pagamentos:
        // pagamentos parciais não multiplicam nem reduzem o valor contratado.
        $due = $this->pdo->prepare(
            "SELECT
                CASE WHEN m.cancelada_em IS NULL THEN 'REGULAR' ELSE 'REVIEW' END AS charge_group,
                COUNT(c.id) AS charge_count,
                COALESCE(SUM(c.valor_total), 0.00) AS nominal_total
             FROM cobrancas c
             INNER JOIN matriculas m ON m.id = c.matricula_id
             WHERE c.cancelada_em_utc IS NULL
               AND c.data_vencimento >= :due_start
               AND c.data_vencimento <= :due_end
             GROUP BY CASE WHEN m.cancelada_em IS NULL THEN 'REGULAR' ELSE 'REVIEW' END"
        );
        $due->execute([
            'due_start' => $startDate,
            'due_end' => $endDate,
        ]);

        $dueGroups = [
            'regular' => ['count' => 0, 'nominal_total' => '0.00'],
            'review' => ['count' => 0, 'nominal_total' => '0.00'],
        ];
        foreach ($due->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['charge_group'] === 'REGULAR'
                ? 'regular'
                : ($row['charge_group'] === 'REVIEW' ? 'review' : null);
            if ($key === null) {
                throw new DomainException('O relatório financeiro encontrou um grupo de cobrança inválido.');
            }
            $dueGroups[$key] = [
                'count' => (int) $row['charge_count'],
                'nominal_total' => (string) $row['nominal_total'],
            ];
        }

        // Pré-agrega pagamentos por cobrança antes do JOIN, preservando uma
        // linha por cobrança e a paridade com FinanceDashboardStatus.
        $balances = $this->pdo->query(
            "SELECT
                COALESCE(SUM(
                    CASE
                        WHEN c.cancelada_em_utc IS NULL AND m.cancelada_em IS NULL
                        THEN GREATEST(c.valor_total - COALESCE(p.paid_total, 0.00), 0.00)
                        ELSE 0.00
                    END
                ), 0.00) AS collectible_balance,
                COALESCE(SUM(
                    CASE
                        WHEN c.cancelada_em_utc IS NULL AND m.cancelada_em IS NOT NULL
                        THEN GREATEST(c.valor_total - COALESCE(p.paid_total, 0.00), 0.00)
                        ELSE 0.00
                    END
                ), 0.00) AS review_balance
             FROM cobrancas c
             INNER JOIN matriculas m ON m.id = c.matricula_id
             LEFT JOIN (
                SELECT cobranca_id, SUM(valor) AS paid_total
                FROM pagamentos
                GROUP BY cobranca_id
             ) p ON p.cobranca_id = c.id"
        );
        $balanceRow = $balances->fetch(PDO::FETCH_ASSOC);
        if (!is_array($balanceRow)) {
            throw new DomainException('Não foi possível obter os saldos financeiros atuais.');
        }

        return [
            'fn01_received_total' => FinanceMoneyFormatter::decimalFromCents($receivedCents),
            'fn02_payment_count' => $paymentCount,
            'fn03_by_method' => $byMethod,
            'fn04_due_charges' => $dueGroups,
            'fn05_collectible_balance' => (string) $balanceRow['collectible_balance'],
            'fn06_review_balance' => (string) $balanceRow['review_balance'],
        ];
    }
}
