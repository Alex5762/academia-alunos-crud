<?php
declare(strict_types=1);

use FitControl\Domain\Reports\ReportPeriod;

require_once dirname(__DIR__) . '/src/Domain/Reports/ReportPeriod.php';

$reportPeriodAssertions = 0;

function reportPeriodLocalDateTime(string $value): DateTimeImmutable
{
    return new DateTimeImmutable(
        $value,
        new DateTimeZone(ReportPeriod::ACADEMY_TIMEZONE)
    );
}

function assertReportPeriodSame(mixed $expected, mixed $actual, string $message): void
{
    global $reportPeriodAssertions;
    $reportPeriodAssertions++;

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

function assertReportPeriodThrows(
    string $exceptionClass,
    callable $operation,
    string $message
): void {
    global $reportPeriodAssertions;
    $reportPeriodAssertions++;

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

// Padrão: primeiro dia do mês atual até a data local do instante recebido.
$asOf = reportPeriodLocalDateTime('2026-11-23 07:42:10');
$default = ReportPeriod::fromInput(null, null, $asOf);
assertReportPeriodSame('2026-11-01', $default->getStartDateString(), 'O padrão deve começar no primeiro dia do mês');
assertReportPeriodSame('2026-11-23', $default->getEndDateString(), 'O padrão deve terminar hoje no fuso local');
assertReportPeriodSame(23, $default->getInclusiveDayCount(), 'O período padrão deve contar ambas as datas');
assertReportPeriodSame('2026-11-01 00:00:00', $default->getStartInclusiveLocal()->format('Y-m-d H:i:s'), 'O início local deve usar a primeira hora da data');
assertReportPeriodSame('2026-11-24 00:00:00', $default->getEndExclusiveLocal()->format('Y-m-d H:i:s'), 'O fim exclusivo deve ser o início do dia seguinte');
assertReportPeriodSame('America/Sao_Paulo', $default->getStartInclusiveLocal()->getTimezone()->getName(), 'O início local deve usar o fuso da academia');
assertReportPeriodSame('UTC', $default->getStartInclusiveUtc()->getTimezone()->getName(), 'O início UTC deve ser convertido explicitamente');
assertReportPeriodSame('2026-11-01 03:00:00', $default->getStartInclusiveUtc()->format('Y-m-d H:i:s'), 'O início local deve converter para UTC corretamente');
assertReportPeriodSame('2026-11-24 03:00:00', $default->getEndExclusiveUtc()->format('Y-m-d H:i:s'), 'O limite exclusivo local deve converter para UTC corretamente');

// Fim de mês e ano bissexto permanecem períodos civis inclusivos.
$leapMonth = ReportPeriod::fromInput(null, null, reportPeriodLocalDateTime('2024-02-29 23:59:59'));
assertReportPeriodSame('2024-02-01', $leapMonth->getStartDateString(), 'O padrão deve respeitar o primeiro dia do mês bissexto');
assertReportPeriodSame('2024-02-29', $leapMonth->getEndDateString(), 'O padrão deve incluir 29 de fevereiro');
assertReportPeriodSame(29, $leapMonth->getInclusiveDayCount(), 'Fevereiro bissexto deve contar 29 datas civis');

// Datas civis permanecem corretas mesmo quando a meia-noite histórica foi pulada por DST.
$dstStartDate = ReportPeriod::fromInput('2018-11-04', '2018-11-04', $asOf);
assertReportPeriodSame('2018-11-04', $dstStartDate->getStartDateString(), 'A data civil de início deve sobreviver à transição histórica de DST');
assertReportPeriodSame('2018-11-04', $dstStartDate->getEndDateString(), 'A data civil final deve sobreviver à transição histórica de DST');
assertReportPeriodSame(1, $dstStartDate->getInclusiveDayCount(), 'A data de transição conta como um único dia civil');
assertReportPeriodSame('2018-11-04 01:00:00', $dstStartDate->getStartInclusiveLocal()->format('Y-m-d H:i:s'), 'Quando a meia-noite não existe, usar o primeiro horário local válido');
assertReportPeriodSame('2018-11-05 00:00:00', $dstStartDate->getEndExclusiveLocal()->format('Y-m-d H:i:s'), 'O limite exclusivo deve começar no dia civil seguinte');

// Datas explícitas são inclusivas e podem estar no futuro em relação a asOf.
$future = ReportPeriod::fromInput('2026-12-01', '2026-12-30', $asOf);
assertReportPeriodSame('2026-12-01', $future->getStartDateString(), 'O início explícito deve ser preservado');
assertReportPeriodSame('2026-12-30', $future->getEndDateString(), 'O fim explícito futuro deve ser preservado');
assertReportPeriodSame(30, $future->getInclusiveDayCount(), 'O período explícito deve contar as duas extremidades');

$singleDay = ReportPeriod::fromInput('2026-11-23', '2026-11-23', $asOf);
assertReportPeriodSame(1, $singleDay->getInclusiveDayCount(), 'Um intervalo de uma data deve ter um dia');

// O limite de 365 dias civis inclusivos é aceito; 366 é rejeitado.
$maximum = ReportPeriod::fromInput('2024-01-01', '2024-12-30', $asOf);
assertReportPeriodSame(365, $maximum->getInclusiveDayCount(), 'O limite máximo inclusivo deve ser aceito');
assertReportPeriodThrows(
    InvalidArgumentException::class,
    fn () => ReportPeriod::fromInput('2024-01-01', '2024-12-31', $asOf),
    'Um período de 366 dias civis deve ser rejeitado'
);

// Entradas parcialmente ausentes, invertidas e malformadas falham explicitamente.
assertReportPeriodThrows(
    InvalidArgumentException::class,
    fn () => ReportPeriod::fromInput('2026-11-01', null, $asOf),
    'Somente a data inicial informada deve ser rejeitada'
);
assertReportPeriodThrows(
    InvalidArgumentException::class,
    fn () => ReportPeriod::fromInput(null, '2026-11-23', $asOf),
    'Somente a data final informada deve ser rejeitada'
);
assertReportPeriodThrows(
    InvalidArgumentException::class,
    fn () => ReportPeriod::fromInput('2026-11-24', '2026-11-23', $asOf),
    'O início posterior ao fim deve ser rejeitado'
);
assertReportPeriodThrows(
    InvalidArgumentException::class,
    fn () => ReportPeriod::fromInput(['2026-11-01'], '2026-11-23', $asOf),
    'Um parâmetro enviado como array deve ser rejeitado de forma controlada'
);
assertReportPeriodThrows(
    InvalidArgumentException::class,
    fn () => ReportPeriod::fromInput('2026-11-01', 20261123, $asOf),
    'Um parâmetro numérico deve ser rejeitado de forma controlada'
);

foreach (['', '2026-2-03', '03/02/2026', '2026-02-30', '2026-11-23 00:00:00', ' 2026-11-23'] as $invalidDate) {
    assertReportPeriodThrows(
        InvalidArgumentException::class,
        fn () => ReportPeriod::fromInput($invalidDate, '2026-11-23', $asOf),
        'A data inválida precisa ser rejeitada: ' . var_export($invalidDate, true)
    );
}

// O instante de referência deve ser confiável e usar exatamente o fuso oficial.
assertReportPeriodThrows(
    InvalidArgumentException::class,
    fn () => ReportPeriod::fromInput(null, null, new DateTimeImmutable('2026-11-23 10:00:00', new DateTimeZone('UTC'))),
    'Um instante de referência em UTC deve ser rejeitado'
);
assertReportPeriodThrows(
    InvalidArgumentException::class,
    fn () => ReportPeriod::fromInput(null, null, new DateTimeImmutable('2026-11-23 07:00:00-03:00')),
    'Um offset fixo não substitui o fuso regional da academia'
);

// Datas fora de DATETIME ou sem limite exclusivo representável são rejeitadas.
assertReportPeriodThrows(
    OutOfRangeException::class,
    fn () => ReportPeriod::fromInput('0999-12-31', '1000-01-01', $asOf),
    'Datas anteriores ao limite DATETIME devem ser rejeitadas'
);
assertReportPeriodThrows(
    OutOfRangeException::class,
    fn () => ReportPeriod::fromInput('9999-12-31', '9999-12-31', $asOf),
    'O fim do intervalo DATETIME não pode gerar um limite exclusivo fora do range'
);

// A classe guarda somente DateTimeImmutable; chamadas de modify não alteram o objeto.
$copy = $default->getStartInclusiveLocal()->modify('+10 days');
assertReportPeriodSame('2026-11-01', $default->getStartDateString(), 'A data armazenada deve permanecer imutável');
assertReportPeriodSame('2026-11-11', $copy->format('Y-m-d'), 'O chamador pode derivar uma cópia independente');

printf("Testes do período de relatórios: OK (%d asserções)\n", $reportPeriodAssertions);
