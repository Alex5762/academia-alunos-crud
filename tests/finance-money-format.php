<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Domain/Finance/FinanceMoneyFormatter.php';

use FitControl\Domain\Finance\FinanceMoneyFormatter;

function assertFinanceMoneyFormat(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$decimalCases = [
    ['0.00', 'R$ 0,00'],
    ['1.05', 'R$ 1,05'],
    ['1234.56', 'R$ 1.234,56'],
    ['1234567.89', 'R$ 1.234.567,89'],
    ['99999999.99', 'R$ 99.999.999,99'],
    ['-1234.56', 'R$ -1.234,56'],
    ['-0.00', 'R$ 0,00'],
    [1234, 'R$ 1.234,00'],
    [null, 'R$ 0,00'],
];

foreach ($decimalCases as [$input, $expected]) {
    assertFinanceMoneyFormat(
        FinanceMoneyFormatter::formatDecimal($input) === $expected,
        'Formatação decimal inesperada para ' . var_export($input, true)
    );
}

$centCases = [
    [0, 'R$ 0,00'],
    [5, 'R$ 0,05'],
    [105, 'R$ 1,05'],
    [123456, 'R$ 1.234,56'],
    [-123456, 'R$ -1.234,56'],
];

foreach ($centCases as [$input, $expected]) {
    assertFinanceMoneyFormat(
        FinanceMoneyFormatter::formatCents($input) === $expected,
        'Formatação em centavos inesperada para ' . $input
    );
}


$conversionCases = [
    ['0.00', 0],
    ['1.05', 105],
    ['1234.56', 123456],
    ['-1234.56', -123456],
    [1234, 123400],
    [null, 0],
];

foreach ($conversionCases as [$input, $expected]) {
    assertFinanceMoneyFormat(
        FinanceMoneyFormatter::toCents($input) === $expected,
        'Conversão para centavos inesperada para ' . var_export($input, true)
    );
}

foreach (['1', '1.2', '1.234', '1,23', 'abc', ''] as $invalid) {
    try {
        FinanceMoneyFormatter::formatDecimal($invalid);
    } catch (InvalidArgumentException) {
        continue;
    }

    throw new RuntimeException('O formatador deveria rejeitar o valor inválido: ' . $invalid);
}

try {
    FinanceMoneyFormatter::formatDecimal(1.23);
} catch (TypeError) {
    echo "Formatação monetária exata: OK; decimais, centavos, sinais e ausência de float validados." . PHP_EOL;
    exit(0);
}

throw new RuntimeException('O formatador não deve aceitar float.');
