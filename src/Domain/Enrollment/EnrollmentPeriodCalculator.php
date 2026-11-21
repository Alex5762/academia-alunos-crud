<?php
declare(strict_types=1);

namespace FitControl\Domain\Enrollment;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use OutOfRangeException;
use Throwable;

/**
 * Calcula limites exclusivos de vigência sem depender de banco ou estado global.
 * Todas as datas recebidas e retornadas representam horário local da academia.
 */
final class EnrollmentPeriodCalculator
{
    public const ACADEMY_TIMEZONE = 'America/Sao_Paulo';

    /**
     * Calcula o primeiro instante que já não pertence à vigência contratada.
     *
     * Para MES, o dia de referência é preservado durante renovações contínuas.
     * Para DIA e SEMANA, a hora local de início é preservada por aritmética de calendário.
     *
     * @throws InvalidArgumentException Para unidade, duração, fuso ou referência inválidos.
     * @throws OutOfRangeException Quando o resultado não cabe no intervalo de DATETIME do MySQL.
     */
    public static function calculateEndExclusive(
        DateTimeImmutable $startLocal,
        int $duration,
        string $unit,
        ?int $calendarReferenceDay = null
    ): DateTimeImmutable {
        self::assertAcademyLocalDate($startLocal);

        if ($duration < 1) {
            throw new InvalidArgumentException('A duração precisa ser um inteiro positivo.');
        }

        return match ($unit) {
            'DIA' => self::addCalendarDays($startLocal, $duration, $calendarReferenceDay),
            'SEMANA' => self::addCalendarWeeks($startLocal, $duration, $calendarReferenceDay),
            'MES' => self::addCalendarMonths($startLocal, $duration, $calendarReferenceDay),
            default => throw new InvalidArgumentException('Unidade de duração inválida.'),
        };
    }

    private static function addCalendarDays(
        DateTimeImmutable $startLocal,
        int $days,
        ?int $calendarReferenceDay
    ): DateTimeImmutable {
        self::assertNoCalendarReferenceForDayOrWeek($calendarReferenceDay);

        try {
            $endExclusive = $startLocal->add(new DateInterval('P' . $days . 'D'));
        } catch (Throwable $exception) {
            throw new OutOfRangeException('A duração ultrapassa o intervalo de datas suportado.', 0, $exception);
        }

        self::assertMySqlDateTimeRange($endExclusive);

        return $endExclusive;
    }

    private static function addCalendarWeeks(
        DateTimeImmutable $startLocal,
        int $weeks,
        ?int $calendarReferenceDay
    ): DateTimeImmutable {
        self::assertNoCalendarReferenceForDayOrWeek($calendarReferenceDay);

        if ($weeks > intdiv(PHP_INT_MAX, 7)) {
            throw new OutOfRangeException('A quantidade de semanas ultrapassa o intervalo suportado.');
        }

        return self::addCalendarDays($startLocal, $weeks * 7, null);
    }

    private static function addCalendarMonths(
        DateTimeImmutable $startLocal,
        int $months,
        ?int $calendarReferenceDay
    ): DateTimeImmutable {
        $referenceDay = $calendarReferenceDay ?? (int) $startLocal->format('j');

        if ($referenceDay < 1 || $referenceDay > 31) {
            throw new InvalidArgumentException('O dia de referência precisa estar entre 1 e 31.');
        }

        $startDay = (int) $startLocal->format('j');
        $firstCycleMonthOffset = $referenceDay > $startDay ? 0 : 1;
        $monthOffset = $months - 1;

        if ($firstCycleMonthOffset === 1) {
            if ($monthOffset === PHP_INT_MAX) {
                throw new OutOfRangeException('A duração mensal ultrapassa o intervalo suportado.');
            }
            $monthOffset++;
        }

        $startMonthIndex = ((int) $startLocal->format('Y') * 12)
            + ((int) $startLocal->format('n') - 1);

        if ($monthOffset > PHP_INT_MAX - $startMonthIndex) {
            throw new OutOfRangeException('A duração mensal ultrapassa o intervalo suportado.');
        }

        $targetMonthIndex = $startMonthIndex + $monthOffset;
        $targetYear = intdiv($targetMonthIndex, 12);
        $targetMonth = ($targetMonthIndex % 12) + 1;

        if ($targetYear < 1000 || $targetYear > 9999) {
            throw new OutOfRangeException('O mês calculado está fora do intervalo de DATETIME do MySQL.');
        }

        // Normaliza para o primeiro dia antes de escolher o dia de referência.
        // Isso evita o overflow de 29, 30 ou 31 de janeiro para março.
        $firstDayOfTargetMonth = $startLocal->setDate($targetYear, $targetMonth, 1);
        $lastDayOfTargetMonth = (int) $firstDayOfTargetMonth->format('t');
        $lastIncludedDay = min($referenceDay, $lastDayOfTargetMonth);

        $lastIncludedDate = $firstDayOfTargetMonth
            ->setDate($targetYear, $targetMonth, $lastIncludedDay)
            ->setTime(0, 0, 0, 0);

        try {
            $endExclusive = $lastIncludedDate->add(new DateInterval('P1D'));
        } catch (Throwable $exception) {
            throw new OutOfRangeException('O limite exclusivo ultrapassa o intervalo suportado.', 0, $exception);
        }

        self::assertMySqlDateTimeRange($endExclusive);

        return $endExclusive;
    }

    private static function assertAcademyLocalDate(DateTimeImmutable $date): void
    {
        if ($date->getTimezone()->getName() !== self::ACADEMY_TIMEZONE) {
            throw new InvalidArgumentException(
                'A data deve estar no fuso ' . self::ACADEMY_TIMEZONE . '.'
            );
        }

        self::assertMySqlDateTimeRange($date);
    }

    private static function assertMySqlDateTimeRange(DateTimeImmutable $date): void
    {
        $year = (int) $date->format('Y');

        if ($year < 1000 || $year > 9999) {
            throw new OutOfRangeException('A data está fora do intervalo de DATETIME do MySQL.');
        }
    }

    private static function assertNoCalendarReferenceForDayOrWeek(?int $calendarReferenceDay): void
    {
        if ($calendarReferenceDay !== null) {
            throw new InvalidArgumentException(
                'O dia de referência do calendário só se aplica a durações em meses.'
            );
        }
    }
}
