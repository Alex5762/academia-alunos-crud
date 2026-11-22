<?php
declare(strict_types=1);

namespace FitControl\Domain\Finance;

use InvalidArgumentException;
use PDO;

/**
 * Consulta os lançamentos de várias cobranças em uma única ida ao banco.
 *
 * O resultado é agrupado por ID de cobrança para renderização do histórico
 * sem consultas N+1 na tela financeira.
 */
final class FinancePaymentHistoryQuery
{
    private function __construct()
    {
    }

    /**
     * @param int[] $chargeIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function forCharges(PDO $pdo, array $chargeIds): array
    {
        $normalizedIds = [];
        foreach ($chargeIds as $chargeId) {
            if (!is_int($chargeId) || $chargeId < 1) {
                throw new InvalidArgumentException('Os IDs de cobrança precisam ser inteiros positivos.');
            }
            $normalizedIds[$chargeId] = $chargeId;
        }

        if ($normalizedIds === []) {
            return [];
        }

        $ids = array_values($normalizedIds);
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $pdo->prepare(
            'SELECT
                p.id,
                p.cobranca_id,
                p.valor,
                p.forma_pagamento,
                p.data_pagamento,
                p.observacao,
                p.registrado_em_utc,
                p.registrado_por,
                u.nome AS responsavel_nome
             FROM pagamentos p
             INNER JOIN usuarios u ON u.id = p.registrado_por
             WHERE p.cobranca_id IN (' . $placeholders . ')
             ORDER BY p.cobranca_id ASC, p.data_pagamento DESC, p.id DESC'
        );
        $statement->execute($ids);

        $history = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $payment) {
            $chargeId = (int) $payment['cobranca_id'];
            $history[$chargeId][] = $payment;
        }

        return $history;
    }
}
