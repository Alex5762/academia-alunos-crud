<?php
declare(strict_types=1);

namespace FitControl\Domain\Finance;

use UnexpectedValueException;

/**
 * Classifica cobranças sem confundir saldo histórico com valor ainda cobrável.
 */
final class FinanceDashboardStatus
{
    private function __construct()
    {
    }

    private static function cents(string|int|float|null $value): int
    {
        $normalized = (string) ($value ?? '0.00');
        if (preg_match('/^(\\d{1,8})\\.(\\d{2})$/D', $normalized, $parts) !== 1) {
            throw new UnexpectedValueException('Valor monetário inválido retornado pelo banco.');
        }

        return ((int) $parts[1] * 100) + (int) $parts[2];
    }

    public static function balanceCents(array $charge): int
    {
        if (($charge['cancelada_em_utc'] ?? null) !== null) {
            return 0;
        }

        return max(
            0,
            self::cents($charge['valor_total'] ?? '0.00')
                - self::cents($charge['valor_pago'] ?? '0.00')
        );
    }

    public static function status(array $charge, string $today): array
    {
        if (($charge['cancelada_em_utc'] ?? null) !== null) {
            return ['Cancelada', 'is-inactive'];
        }

        if (($charge['matricula_cancelada_em'] ?? null) !== null) {
            return ['Em revisão', 'is-review'];
        }

        $totalCents = self::cents($charge['valor_total'] ?? '0.00');
        $paidCents = self::cents($charge['valor_pago'] ?? '0.00');

        if ($paidCents >= $totalCents) {
            return ['Quitada', 'is-active'];
        }
        if ((string) $charge['data_vencimento'] < $today) {
            return [$paidCents > 0 ? 'Parcial vencida' : 'Vencida', 'is-expired'];
        }
        if ($paidCents > 0) {
            return ['Parcial', 'is-pending'];
        }

        return ['Em aberto', 'is-scheduled'];
    }

    public static function collectibleBalanceCents(array $charge): int
    {
        if (($charge['matricula_cancelada_em'] ?? null) !== null) {
            return 0;
        }

        return self::balanceCents($charge);
    }

    public static function reviewBalanceCents(array $charge): int
    {
        if (($charge['matricula_cancelada_em'] ?? null) === null) {
            return 0;
        }

        return self::balanceCents($charge);
    }
}
