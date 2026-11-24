<?php
declare(strict_types=1);

namespace FitControl\Domain\Reports;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OutOfRangeException;

/**
 * Representa um período civil inclusivo para consultas de relatórios.
 *
 * A classe não consulta o banco, não lê o relógio global e não conhece a camada HTTP.
 * Datas de entrada/saída representam o calendário local da academia; para colunas
 * DATETIME persistidas em UTC, use os limites semiabertos convertidos para UTC.
 */
final class ReportPeriod
{
    public const ACADEMY_TIMEZONE = 'America/Sao_Paulo';
    public const MAX_INCLUSIVE_DAYS = 365;

    private readonly DateTimeImmutable $startInclusiveLocal;
    private readonly DateTimeImmutable $endInclusiveLocal;
    private readonly DateTimeImmutable $endExclusiveLocal;
    private readonly int $inclusiveDayCount;

    private function __construct(
        DateTimeImmutable $startInclusiveLocal,
        DateTimeImmutable $endInclusiveLocal,
        DateTimeImmutable $endExclusiveLocal,
        int $inclusiveDayCount
    ) {
        $this->startInclusiveLocal = $startInclusiveLocal;
        $this->endInclusiveLocal = $endInclusiveLocal;
        $this->endExclusiveLocal = $endExclusiveLocal;
        $this->inclusiveDayCount = $inclusiveDayCount;
    }

    /**
     * Cria o período a partir das duas datas HTTP já extraídas pelo controller.
     *
     * Quando ambas são null, usa o primeiro dia do mês civil de $asOf até a data
     * local de $asOf. Se apenas uma for null, a entrada é inválida. Datas futuras
     * explícitas são aceitas; não são criadas projeções ou lançamentos futuros.
     *
     * @throws InvalidArgumentException Para entrada ausente parcialmente, formato,
     *                                  data impossível, intervalo invertido ou acima do limite.
     * @throws OutOfRangeException Quando os limites não cabem em DATETIME do MySQL.
     */
    public static function fromInput(
        mixed $startDate,
        mixed $endDate,
        DateTimeImmutable $asOf
    ): self {
        self::assertAcademyDateTime($asOf, 'O instante de referência');

        if (
            ($startDate !== null && !is_string($startDate))
            || ($endDate !== null && !is_string($endDate))
        ) {
            throw new InvalidArgumentException(
                'As datas inicial e final precisam ser strings no formato YYYY-MM-DD.'
            );
        }

        if (($startDate === null) !== ($endDate === null)) {
            throw new InvalidArgumentException(
                'Informe as datas inicial e final em conjunto.'
            );
        }

        $timezone = new DateTimeZone(self::ACADEMY_TIMEZONE);

        if ($startDate === null && $endDate === null) {
            $endInclusiveLocal = $asOf->setTime(0, 0, 0, 0);
            $startInclusiveLocal = $endInclusiveLocal->modify('first day of this month');
        } else {
            $startInclusiveLocal = self::parseDate($startDate, 'A data inicial', $timezone);
            $endInclusiveLocal = self::parseDate($endDate, 'A data final', $timezone);
        }

        if ($startInclusiveLocal > $endInclusiveLocal) {
            throw new InvalidArgumentException(
                'A data inicial precisa ser anterior ou igual à data final.'
            );
        }

        $inclusiveDayCount = self::countInclusiveCalendarDays(
            $startInclusiveLocal,
            $endInclusiveLocal
        );

        if ($inclusiveDayCount > self::MAX_INCLUSIVE_DAYS) {
            throw new InvalidArgumentException(
                sprintf(
                    'O período não pode ultrapassar %d dias civis inclusivos.',
                    self::MAX_INCLUSIVE_DAYS
                )
            );
        }

        $endExclusiveLocal = self::startOfNextCivilDate($endInclusiveLocal, $timezone);

        self::assertMySqlDateTimeRange($startInclusiveLocal, 'O início do período');
        self::assertMySqlDateTimeRange($endInclusiveLocal, 'O fim do período');
        self::assertMySqlDateTimeRange($endExclusiveLocal, 'O limite exclusivo do período');

        return new self(
            $startInclusiveLocal,
            $endInclusiveLocal,
            $endExclusiveLocal,
            $inclusiveDayCount
        );
    }

    /** Início civil inclusivo, à primeira hora válida da data local. */
    public function getStartInclusiveLocal(): DateTimeImmutable
    {
        return $this->startInclusiveLocal;
    }

    /** Fim civil inclusivo, à primeira hora válida da data local. */
    public function getEndInclusiveLocal(): DateTimeImmutable
    {
        return $this->endInclusiveLocal;
    }

    /** Primeiro instante local que já não pertence ao período. */
    public function getEndExclusiveLocal(): DateTimeImmutable
    {
        return $this->endExclusiveLocal;
    }

    /** Início inclusivo convertido para UTC, para colunas DATETIME persistidas em UTC. */
    public function getStartInclusiveUtc(): DateTimeImmutable
    {
        return $this->startInclusiveLocal->setTimezone(new DateTimeZone('UTC'));
    }

    /** Fim exclusivo convertido para UTC, para colunas DATETIME persistidas em UTC. */
    public function getEndExclusiveUtc(): DateTimeImmutable
    {
        return $this->endExclusiveLocal->setTimezone(new DateTimeZone('UTC'));
    }

    /** Data inicial para filtros de colunas SQL do tipo DATE no calendário local. */
    public function getStartDateString(): string
    {
        return $this->startInclusiveLocal->format('Y-m-d');
    }

    /** Data final inclusiva para filtros de colunas SQL do tipo DATE no calendário local. */
    public function getEndDateString(): string
    {
        return $this->endInclusiveLocal->format('Y-m-d');
    }

    public function getInclusiveDayCount(): int
    {
        return $this->inclusiveDayCount;
    }

    private static function parseDate(
        string $value,
        string $fieldName,
        DateTimeZone $timezone
    ): DateTimeImmutable {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            !$date instanceof DateTimeImmutable
            || $date->format('Y-m-d') !== $value
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new InvalidArgumentException(
                $fieldName . ' precisa ser uma data real no formato YYYY-MM-DD.'
            );
        }

        self::assertMySqlDateTimeRange($date, $fieldName);

        return $date;
    }

    private static function startOfNextCivilDate(
        DateTimeImmutable $endInclusiveLocal,
        DateTimeZone $academyTimezone
    ): DateTimeImmutable {
        // Avançar primeiro no calendário UTC e só então resolver a data local evita
        // preservar uma hora normalizada (por exemplo, 01:00) no dia seguinte após DST.
        $utc = new DateTimeZone('UTC');
        $endCalendarDate = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $endInclusiveLocal->format('Y-m-d'),
            $utc
        );

        if (!$endCalendarDate instanceof DateTimeImmutable) {
            throw new OutOfRangeException('Não foi possível calcular o limite exclusivo do período.');
        }

        $nextCalendarDate = $endCalendarDate->modify('+1 day');
        if (!$nextCalendarDate instanceof DateTimeImmutable) {
            throw new OutOfRangeException('Não foi possível calcular o limite exclusivo do período.');
        }

        $nextLocalDate = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $nextCalendarDate->format('Y-m-d'),
            $academyTimezone
        );
        $errors = DateTimeImmutable::getLastErrors();

        if (
            !$nextLocalDate instanceof DateTimeImmutable
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new OutOfRangeException('Não foi possível representar o limite exclusivo no fuso da academia.');
        }

        return $nextLocalDate;
    }

    private static function countInclusiveCalendarDays(
        DateTimeImmutable $startInclusiveLocal,
        DateTimeImmutable $endInclusiveLocal
    ): int {
        // Calcular por datas civis em UTC evita que dias de 23/25 horas em transições
        // históricas de horário de verão alterem o limite de dias do calendário.
        $utc = new DateTimeZone('UTC');
        $startCalendarDate = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $startInclusiveLocal->format('Y-m-d'),
            $utc
        );
        $endCalendarDate = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $endInclusiveLocal->format('Y-m-d'),
            $utc
        );

        if (!$startCalendarDate instanceof DateTimeImmutable || !$endCalendarDate instanceof DateTimeImmutable) {
            throw new OutOfRangeException('Não foi possível calcular a quantidade de dias civis.');
        }

        $difference = $startCalendarDate->diff($endCalendarDate);
        if ($difference->days === false) {
            throw new OutOfRangeException('Não foi possível calcular a quantidade de dias civis.');
        }

        return $difference->days + 1;
    }

    private static function assertAcademyDateTime(
        DateTimeImmutable $dateTime,
        string $fieldName
    ): void {
        if ($dateTime->getTimezone()->getName() !== self::ACADEMY_TIMEZONE) {
            throw new InvalidArgumentException(
                $fieldName . ' deve estar no fuso ' . self::ACADEMY_TIMEZONE . '.'
            );
        }

        self::assertMySqlDateTimeRange($dateTime, $fieldName);
    }

    private static function assertMySqlDateTimeRange(
        DateTimeImmutable $dateTime,
        string $fieldName
    ): void {
        $year = (int) $dateTime->format('Y');

        if ($year < 1000 || $year > 9999) {
            throw new OutOfRangeException(
                $fieldName . ' está fora do intervalo de DATETIME do MySQL.'
            );
        }
    }
}
