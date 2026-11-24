<?php
declare(strict_types=1);

use FitControl\Domain\Enrollment\EnrollmentInterval;
use FitControl\Domain\Enrollment\EnrollmentPeriodCalculator;

require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentPeriodCalculator.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentInterval.php';

$assertions = 0;

function enrollmentLocalDateTime(string $value): DateTimeImmutable
{
    return new DateTimeImmutable(
        $value,
        new DateTimeZone(EnrollmentPeriodCalculator::ACADEMY_TIMEZONE)
    );
}

function assertIntervalSame(mixed $expected, mixed $actual, string $message): void
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

function assertIntervalThrows(
    string $exceptionClass,
    callable $operation,
    string $message
): void {
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

function makeEnrollmentInterval(string $start, string $end, ?string $cancelledAt = null): ?EnrollmentInterval
{
    return EnrollmentInterval::fromContract(
        enrollmentLocalDateTime($start),
        enrollmentLocalDateTime($end),
        $cancelledAt === null ? null : enrollmentLocalDateTime($cancelledAt)
    );
}

// Intervalos adjacentes são permitidos nos dois sentidos.
$first = makeEnrollmentInterval('2026-01-10 09:00:00', '2026-01-11 09:00:00');
$adjacent = makeEnrollmentInterval('2026-01-11 09:00:00', '2026-01-12 09:00:00');

assertIntervalSame(false, $first->overlaps($adjacent), 'Períodos adjacentes não se sobrepõem');
assertIntervalSame(false, $adjacent->overlaps($first), 'A adjacência deve ser simétrica');

// Qualquer compartilhamento de tempo real representa sobreposição.
$partialOverlap = makeEnrollmentInterval('2026-01-10 20:00:00', '2026-01-11 20:00:00');
assertIntervalSame(true, $first->overlaps($partialOverlap), 'Sobreposição parcial deve ser bloqueada');
assertIntervalSame(true, $partialOverlap->overlaps($first), 'Sobreposição parcial deve ser simétrica');

$sameStart = makeEnrollmentInterval('2026-01-10 09:00:00', '2026-01-10 12:00:00');
assertIntervalSame(true, $first->overlaps($sameStart), 'Mesmo início com duração positiva se sobrepõe');

$contained = makeEnrollmentInterval('2026-01-10 08:00:00', '2026-01-11 10:00:00');
assertIntervalSame(true, $first->overlaps($contained), 'Um intervalo contido em outro se sobrepõe');

$gap = makeEnrollmentInterval('2026-01-11 09:00:01', '2026-01-12 09:00:00');
assertIntervalSame(false, $first->overlaps($gap), 'Um segundo de intervalo livre não deve gerar sobreposição');

// contains também respeita o limite semiaberto.
assertIntervalSame(
    true,
    $first->contains(enrollmentLocalDateTime('2026-01-10 09:00:00')),
    'O instante inicial pertence ao intervalo'
);
assertIntervalSame(
    true,
    $first->contains(enrollmentLocalDateTime('2026-01-11 08:59:59')),
    'O último segundo anterior ao fim pertence ao intervalo'
);
assertIntervalSame(
    false,
    $first->contains(enrollmentLocalDateTime('2026-01-11 09:00:00')),
    'O fim exclusivo não pertence ao intervalo'
);

// Cancelamento anterior ou exatamente no início deixa intervalo efetivo vazio.
$cancelledBeforeStart = makeEnrollmentInterval(
    '2026-01-10 09:00:00',
    '2026-02-10 09:00:00',
    '2026-01-10 08:59:59'
);
assertIntervalSame(null, $cancelledBeforeStart, 'Cancelamento anterior ao início gera período vazio');

$cancelledAtStart = makeEnrollmentInterval(
    '2026-01-10 09:00:00',
    '2026-02-10 09:00:00',
    '2026-01-10 09:00:00'
);
assertIntervalSame(null, $cancelledAtStart, 'Cancelamento exatamente no início gera período vazio');

// Cancelamento depois do início encerra no instante efetivo.
$cancelledDuringPeriod = makeEnrollmentInterval(
    '2026-01-10 09:00:00',
    '2026-02-10 09:00:00',
    '2026-01-10 14:00:00'
);
assertIntervalSame(
    '2026-01-10 14:00:00',
    $cancelledDuringPeriod->getEndExclusive()->format('Y-m-d H:i:s'),
    'Cancelamento deve encurtar a vigência no próprio instante'
);
assertIntervalSame(
    true,
    $cancelledDuringPeriod->contains(enrollmentLocalDateTime('2026-01-10 13:59:59')),
    'O instante imediatamente anterior ao cancelamento ainda pertence à vigência'
);
assertIntervalSame(
    false,
    $cancelledDuringPeriod->contains(enrollmentLocalDateTime('2026-01-10 14:00:00')),
    'O instante do cancelamento já está fora da vigência'
);

$nextAfterCancellation = makeEnrollmentInterval(
    '2026-01-10 14:00:00',
    '2026-02-10 14:00:00'
);
assertIntervalSame(
    false,
    $cancelledDuringPeriod->overlaps($nextAfterCancellation),
    'Nova matrícula pode começar exatamente no instante do cancelamento'
);

$previousBeforeCancellation = makeEnrollmentInterval(
    '2026-01-10 13:59:59',
    '2026-01-11 13:59:59'
);
assertIntervalSame(
    true,
    $cancelledDuringPeriod->overlaps($previousBeforeCancellation),
    'Nova matrícula iniciada antes do cancelamento ainda se sobrepõe'
);

// Cancelamento posterior ao fim contratado não amplia a vigência.
$cancelledAfterExpiry = makeEnrollmentInterval(
    '2026-01-10 09:00:00',
    '2026-01-11 09:00:00',
    '2026-01-12 09:00:00'
);
assertIntervalSame(
    '2026-01-11 09:00:00',
    $cancelledAfterExpiry->getEndExclusive()->format('Y-m-d H:i:s'),
    'O fim efetivo não pode ultrapassar o fim contratual'
);

// Fim contratual inválido nunca deve produzir intervalo.
assertIntervalThrows(
    InvalidArgumentException::class,
    fn () => makeEnrollmentInterval('2026-01-10 09:00:00', '2026-01-10 09:00:00'),
    'Intervalo de duração zero deve ser rejeitado'
);
assertIntervalThrows(
    InvalidArgumentException::class,
    fn () => makeEnrollmentInterval('2026-01-10 09:00:00', '2026-01-09 09:00:00'),
    'Fim anterior ao início deve ser rejeitado'
);

// Datas de outros fusos não devem entrar silenciosamente no domínio.
assertIntervalThrows(
    InvalidArgumentException::class,
    fn () => EnrollmentInterval::fromContract(
        new DateTimeImmutable('2026-01-10 09:00:00', new DateTimeZone('UTC')),
        enrollmentLocalDateTime('2026-01-11 09:00:00')
    ),
    'Início em UTC deve ser rejeitado'
);
assertIntervalThrows(
    InvalidArgumentException::class,
    fn () => EnrollmentInterval::fromContract(
        enrollmentLocalDateTime('2026-01-10 09:00:00'),
        new DateTimeImmutable('2026-01-11 09:00:00', new DateTimeZone('UTC'))
    ),
    'Fim em UTC deve ser rejeitado'
);
assertIntervalThrows(
    InvalidArgumentException::class,
    fn () => EnrollmentInterval::fromContract(
        enrollmentLocalDateTime('2026-01-10 09:00:00'),
        enrollmentLocalDateTime('2026-01-11 09:00:00'),
        new DateTimeImmutable('2026-01-10 10:00:00', new DateTimeZone('UTC'))
    ),
    'Cancelamento em UTC deve ser rejeitado'
);
assertIntervalThrows(
    InvalidArgumentException::class,
    fn () => $first->contains(new DateTimeImmutable('2026-01-10 10:00:00', new DateTimeZone('UTC'))),
    'Consulta em UTC deve ser rejeitada'
);

printf("Testes de intervalos de matrícula: OK (%d asserções)\n", $assertions);
