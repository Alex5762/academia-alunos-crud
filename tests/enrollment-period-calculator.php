<?php
declare(strict_types=1);

use FitControl\Domain\Enrollment\EnrollmentPeriodCalculator;

require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentPeriodCalculator.php';

$assertions = 0;

function localDateTime(string $value): DateTimeImmutable
{
    return new DateTimeImmutable(
        $value,
        new DateTimeZone(EnrollmentPeriodCalculator::ACADEMY_TIMEZONE)
    );
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    global $assertions;
    $assertions++;

    if ($expected !== $actual) {
        throw new RuntimeException(
            sprintf(
                "%s\nEsperado: %s\nObtido: %s",
                $message,
                var_export($expected, true),
                var_export($actual, true)
            )
        );
    }
}

function assertThrows(string $exceptionClass, callable $operation, string $message): void
{
    global $assertions;
    $assertions++;

    try {
        $operation();
    } catch (Throwable $exception) {
        if ($exception instanceof $exceptionClass) {
            return;
        }

        throw new RuntimeException(
            $message . ': exceção inesperada ' . get_class($exception),
            0,
            $exception
        );
    }

    throw new RuntimeException($message . ': nenhuma exceção foi lançada.');
}

function assertEnd(string $expected, DateTimeImmutable $actual, string $message): void
{
    assertSameValue($expected, $actual->format('Y-m-d H:i:s'), $message);
    assertSameValue(
        EnrollmentPeriodCalculator::ACADEMY_TIMEZONE,
        $actual->getTimezone()->getName(),
        $message . ' (fuso)'
    );
}

// Mês de calendário, incluindo integralmente o último dia.
assertEnd(
    '2026-02-11 00:00:00',
    EnrollmentPeriodCalculator::calculateEndExclusive(localDateTime('2026-01-10 15:00:00'), 1, 'MES'),
    '10/01 às 15h + 1 mês deve incluir 10/02 inteiro'
);

assertEnd(
    '2025-03-01 00:00:00',
    EnrollmentPeriodCalculator::calculateEndExclusive(localDateTime('2025-01-31 00:00:00'), 1, 'MES', 31),
    '31/01 + 1 mês em ano comum deve incluir 28/02'
);

assertEnd(
    '2024-03-01 00:00:00',
    EnrollmentPeriodCalculator::calculateEndExclusive(localDateTime('2024-01-31 00:00:00'), 1, 'MES', 31),
    '31/01 + 1 mês em ano bissexto deve incluir 29/02'
);

assertEnd(
    '2025-04-01 00:00:00',
    EnrollmentPeriodCalculator::calculateEndExclusive(localDateTime('2025-01-31 00:00:00'), 2, 'MES', 31),
    '31/01 + 2 meses deve incluir 28/02 e 31/03'
);

assertEnd(
    '2026-12-02 00:00:00',
    EnrollmentPeriodCalculator::calculateEndExclusive(localDateTime('2026-11-01 00:00:00'), 1, 'MES', 1),
    'Início no dia 1 com referência 1 deve incluir 01/12'
);

// Renovações contínuas preservam o dia original, inclusive após fevereiro.
$firstRenewalStart = localDateTime('2026-03-01 00:00:00');
$firstRenewalEnd = EnrollmentPeriodCalculator::calculateEndExclusive($firstRenewalStart, 1, 'MES', 31);
assertEnd('2026-04-01 00:00:00', $firstRenewalEnd, 'Renovação em 01/03 deve preservar referência 31');

$secondRenewalEnd = EnrollmentPeriodCalculator::calculateEndExclusive($firstRenewalEnd, 1, 'MES', 31);
assertEnd('2026-05-01 00:00:00', $secondRenewalEnd, 'Renovação seguinte deve continuar usando referência 31');

// Dias e semanas são unidades de calendário e preservam a hora local.
assertEnd(
    '2026-01-11 15:00:00',
    EnrollmentPeriodCalculator::calculateEndExclusive(localDateTime('2026-01-10 15:00:00'), 1, 'DIA'),
    'Um dia deve preservar a hora local'
);

assertEnd(
    '2026-01-17 15:00:00',
    EnrollmentPeriodCalculator::calculateEndExclusive(localDateTime('2026-01-10 15:00:00'), 1, 'SEMANA'),
    'Uma semana deve preservar a hora local'
);

// A transição histórica de horário civil demonstra que a operação é por calendário,
// e não uma soma fixa de 86.400 segundos.
$beforeDst = localDateTime('2018-11-03 15:00:00');
$afterDst = EnrollmentPeriodCalculator::calculateEndExclusive($beforeDst, 1, 'DIA');
assertEnd('2018-11-04 15:00:00', $afterDst, 'Um dia através da transição histórica deve preservar a hora local');
assertSameValue(
    23 * 60 * 60,
    $afterDst->getTimestamp() - $beforeDst->getTimestamp(),
    'A transição de horário civil deve ser respeitada'
);

// Limite exclusivo: um instante anterior pertence ao período; o próprio limite não pertence.
function intervalContains(DateTimeImmutable $start, DateTimeImmutable $endExclusive, DateTimeImmutable $instant): bool
{
    return $instant >= $start && $instant < $endExclusive;
}

$periodStart = localDateTime('2026-01-10 15:00:00');
$periodEnd = EnrollmentPeriodCalculator::calculateEndExclusive($periodStart, 1, 'DIA');
$lastSecond = $periodEnd->sub(new DateInterval('PT1S'));
assertSameValue(
    true,
    intervalContains($periodStart, $periodEnd, $lastSecond),
    'O instante anterior ao fim deve estar dentro do intervalo'
);
assertSameValue(
    false,
    intervalContains($periodStart, $periodEnd, $periodEnd),
    'O próprio fim exclusivo não deve estar dentro do intervalo'
);

// Entradas inválidas e resultados fora do DATETIME são rejeitados.
assertThrows(
    InvalidArgumentException::class,
    fn () => EnrollmentPeriodCalculator::calculateEndExclusive(localDateTime('2026-01-10 15:00:00'), 0, 'DIA'),
    'Duração zero deve ser rejeitada'
);
assertThrows(
    InvalidArgumentException::class,
    fn () => EnrollmentPeriodCalculator::calculateEndExclusive(localDateTime('2026-01-10 15:00:00'), -1, 'MES'),
    'Duração negativa deve ser rejeitada'
);
assertThrows(
    InvalidArgumentException::class,
    fn () => EnrollmentPeriodCalculator::calculateEndExclusive(localDateTime('2026-01-10 15:00:00'), 1, 'ANO'),
    'Unidade desconhecida deve ser rejeitada'
);
assertThrows(
    InvalidArgumentException::class,
    fn () => EnrollmentPeriodCalculator::calculateEndExclusive(localDateTime('2026-01-10 15:00:00'), 1, 'MES', 32),
    'Referência superior a 31 deve ser rejeitada'
);
assertThrows(
    InvalidArgumentException::class,
    fn () => EnrollmentPeriodCalculator::calculateEndExclusive(localDateTime('2026-01-10 15:00:00'), 1, 'DIA', 10),
    'Referência não se aplica a dias'
);
assertThrows(
    InvalidArgumentException::class,
    fn () => EnrollmentPeriodCalculator::calculateEndExclusive(
        new DateTimeImmutable('2026-01-10 15:00:00', new DateTimeZone('UTC')),
        1,
        'DIA'
    ),
    'Data fora do fuso oficial deve ser rejeitada'
);
assertThrows(
    OutOfRangeException::class,
    fn () => EnrollmentPeriodCalculator::calculateEndExclusive(localDateTime('9999-12-31 00:00:00'), 1, 'MES', 31),
    'Limite além do DATETIME deve ser rejeitado'
);

printf("Testes da calculadora de vigência: OK (%d asserções)\n", $assertions);
