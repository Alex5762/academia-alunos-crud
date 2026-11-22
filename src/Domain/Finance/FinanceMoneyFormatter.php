<?php
declare(strict_types=1);

namespace FitControl\Domain\Finance;

use InvalidArgumentException;
use OverflowException;

/**
 * Formata valores monetários sem passar por ponto flutuante.
 *
 * Aceita DECIMAL vindo do PDO como texto, inteiros em unidades monetárias
 * e centavos inteiros para valores calculados pelo domínio.
 */
final class FinanceMoneyFormatter
{
    private function __construct()
    {
    }

    public static function formatDecimal(string|int|null $value): string
    {
        if ($value === null) {
            $normalized = '0.00';
        } elseif (is_int($value)) {
            $normalized = (string) $value . '.00';
        } else {
            $normalized = $value;
        }

        if (preg_match('/^(-?)(\d+)\.(\d{2})$/D', $normalized, $parts) !== 1) {
            throw new InvalidArgumentException('Valor monetário inválido para formatação.');
        }

        $whole = ltrim($parts[2], '0');
        if ($whole === '') {
            $whole = '0';
        }

        $groupedWhole = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole);
        if (!is_string($groupedWhole)) {
            throw new InvalidArgumentException('Não foi possível agrupar o valor monetário.');
        }

        $isZero = $whole === '0' && $parts[3] === '00';
        $sign = $parts[1] === '-' && !$isZero ? '-' : '';

        return 'R$ ' . $sign . $groupedWhole . ',' . $parts[3];
    }

    public static function toCents(string|int|null $value): int
    {
        if ($value === null) {
            $normalized = '0.00';
        } elseif (is_int($value)) {
            $normalized = (string) $value . '.00';
        } else {
            $normalized = $value;
        }

        if (preg_match('/^(-?)(\\d+)\\.(\\d{2})$/D', $normalized, $parts) !== 1) {
            throw new InvalidArgumentException('Valor monetário inválido para conversão em centavos.');
        }

        $wholeDigits = ltrim($parts[2], '0');
        if ($wholeDigits === '') {
            $wholeDigits = '0';
        }

        $whole = (int) $wholeDigits;
        $fraction = (int) $parts[3];
        $maximumWhole = intdiv(PHP_INT_MAX, 100);
        $maximumFraction = PHP_INT_MAX % 100;
        if ($whole > $maximumWhole || ($whole === $maximumWhole && $fraction > $maximumFraction)) {
            throw new OverflowException('O valor monetário excede o intervalo inteiro suportado.');
        }

        $absolute = ($whole * 100) + $fraction;
        return $parts[1] === '-' ? -$absolute : $absolute;
    }

    public static function formatCents(int $cents): string
    {
        if ($cents === PHP_INT_MIN) {
            throw new OverflowException('O valor em centavos excede o intervalo suportado.');
        }

        $negative = $cents < 0;
        $absolute = $negative ? -$cents : $cents;
        $whole = (string) intdiv($absolute, 100);
        $fraction = str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);

        return self::formatDecimal(($negative ? '-' : '') . $whole . '.' . $fraction);
    }
}
