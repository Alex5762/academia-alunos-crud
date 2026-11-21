<?php
declare(strict_types=1);

use FitControl\Domain\Enrollment\EnrollmentPeriodCalculator;
use FitControl\Domain\Enrollment\EnrollmentStatus;

require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentPeriodCalculator.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentStatus.php';

$assertions = 0;

function statusLocalDateTime(string $value): DateTimeImmutable
{
    return new DateTimeImmutable(
        $value,
        new DateTimeZone(EnrollmentPeriodCalculator::ACADEMY_TIMEZONE)
    );
}

function assertStatusSame(mixed $expected, mixed $actual, string $message): void
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

function assertStatusThrows(
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

$start = statusLocalDateTime('2026-01-10 09:00:00');
$end = statusLocalDateTime('2026-02-10 09:00:00');

// AGENDADA: qualquer instante anterior ao início.
assertStatusSame(
    EnrollmentStatus::SCHEDULED,
    EnrollmentStatus::derive(
        $start,
        $end,
        null,
        statusLocalDateTime('2026-01-10 08:59:59')
    ),
    'Um segundo antes do início deve estar AGENDADA'
);

// ATIVA: o próprio início já pertence à vigência.
assertStatusSame(
    EnrollmentStatus::ACTIVE,
    EnrollmentStatus::derive($start, $end, null, $start),
    'No instante exato do início a matrícula deve estar ATIVA'
);

// ATIVA: o último segundo antes do fim exclusivo continua dentro do período.
assertStatusSame(
    EnrollmentStatus::ACTIVE,
    EnrollmentStatus::derive(
        $start,
        $end,
        null,
        statusLocalDateTime('2026-02-10 08:59:59')
    ),
    'Um segundo antes do fim exclusivo deve permanecer ATIVA'
);

// VENCIDA: o próprio fim exclusivo já está fora da vigência.
assertStatusSame(
    EnrollmentStatus::EXPIRED,
    EnrollmentStatus::derive($start, $end, null, $end),
    'No instante exato do fim exclusivo a matrícula deve estar VENCIDA'
);
assertStatusSame(
    EnrollmentStatus::EXPIRED,
    EnrollmentStatus::derive(
        $start,
        $end,
        null,
        statusLocalDateTime('2026-02-10 09:00:01')
    ),
    'Depois do fim exclusivo a matrícula deve continuar VENCIDA'
);

// CANCELADA tem prioridade sobre os estados temporais.
$cancelledBeforeStart = statusLocalDateTime('2026-01-10 08:00:00');
assertStatusSame(
    EnrollmentStatus::CANCELLED,
    EnrollmentStatus::derive($start, $end, $cancelledBeforeStart, $start),
    'Uma matrícula cancelada antes do início deve ser CANCELADA'
);
assertStatusSame(
    EnrollmentStatus::CANCELLED,
    EnrollmentStatus::derive(
        $start,
        $end,
        statusLocalDateTime('2026-01-10 09:00:00'),
        statusLocalDateTime('2026-01-10 08:00:00')
    ),
    'CANCELADA tem prioridade mesmo antes do instante de início'
);
assertStatusSame(
    EnrollmentStatus::CANCELLED,
    EnrollmentStatus::derive(
        $start,
        $end,
        statusLocalDateTime('2026-01-10 14:00:00'),
        statusLocalDateTime('2026-01-10 15:00:00')
    ),
    'Cancelamento durante a vigência deve apresentar CANCELADA'
);

// Elegibilidade de cancelamento: AGENDADA e ATIVA podem; VENCIDA e CANCELADA não.
assertStatusSame(
    true,
    EnrollmentStatus::canBeCancelled(
        $start,
        $end,
        null,
        statusLocalDateTime('2026-01-10 08:00:00')
    ),
    'Matrícula AGENDADA pode ser cancelada'
);
assertStatusSame(
    true,
    EnrollmentStatus::canBeCancelled(
        $start,
        $end,
        null,
        statusLocalDateTime('2026-01-10 09:00:00')
    ),
    'Matrícula ATIVA pode ser cancelada'
);
assertStatusSame(
    false,
    EnrollmentStatus::canBeCancelled($start, $end, null, $end),
    'Matrícula VENCIDA não pode ser cancelada'
);
assertStatusSame(
    false,
    EnrollmentStatus::canBeCancelled(
        $start,
        $end,
        $cancelledBeforeStart,
        statusLocalDateTime('2026-01-10 08:30:00')
    ),
    'Matrícula CANCELADA não pode receber novo cancelamento'
);

// A asserção de domínio torna rejeições explícitas para o serviço chamador.
EnrollmentStatus::assertCanBeCancelled(
    $start,
    $end,
    null,
    statusLocalDateTime('2026-01-10 08:00:00')
);
$assertions++;

EnrollmentStatus::assertCanBeCancelled(
    $start,
    $end,
    null,
    statusLocalDateTime('2026-01-10 10:00:00')
);
$assertions++;

assertStatusThrows(
    DomainException::class,
    fn () => EnrollmentStatus::assertCanBeCancelled($start, $end, null, $end),
    'O cancelamento de matrícula vencida deve ser rejeitado'
);
assertStatusThrows(
    DomainException::class,
    fn () => EnrollmentStatus::assertCanBeCancelled(
        $start,
        $end,
        $cancelledBeforeStart,
        statusLocalDateTime('2026-01-10 08:30:00')
    ),
    'O cancelamento repetido deve ser rejeitado'
);

// Intervalos inválidos e fusos incompatíveis são rejeitados.
assertStatusThrows(
    InvalidArgumentException::class,
    fn () => EnrollmentStatus::derive($start, $start, null, $start),
    'Fim igual ao início deve ser rejeitado'
);
assertStatusThrows(
    InvalidArgumentException::class,
    fn () => EnrollmentStatus::derive(
        $start,
        $end,
        null,
        new DateTimeImmutable('2026-01-10 10:00:00', new DateTimeZone('UTC'))
    ),
    'Instante consultado em UTC deve ser rejeitado'
);
assertStatusThrows(
    InvalidArgumentException::class,
    fn () => EnrollmentStatus::derive(
        new DateTimeImmutable('2026-01-10 09:00:00', new DateTimeZone('UTC')),
        $end,
        null,
        $start
    ),
    'Início em UTC deve ser rejeitado'
);
assertStatusThrows(
    InvalidArgumentException::class,
    fn () => EnrollmentStatus::derive(
        $start,
        $end,
        new DateTimeImmutable('2026-01-10 08:00:00', new DateTimeZone('UTC')),
        $start
    ),
    'Cancelamento em UTC deve ser rejeitado'
);

printf("Testes de situação e elegibilidade de matrícula: OK (%d asserções)\n", $assertions);
