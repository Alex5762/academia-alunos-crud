<?php
declare(strict_types=1);

namespace FitControl\Domain\Enrollment;

use DateTimeImmutable;
use InvalidArgumentException;
use OutOfRangeException;

/**
 * Representa um intervalo efetivo e não vazio de matrícula.
 *
 * Os limites são semiabertos: [início inclusivo, fim exclusivo).
 * A classe não acessa banco, relógio do sistema ou estado global.
 */
final class EnrollmentInterval
{
    private function __construct(
        private readonly DateTimeImmutable $startInclusive,
        private readonly DateTimeImmutable $endExclusive
    ) {
    }

    /**
     * Cria o intervalo efetivo a partir das datas contratuais e de um possível cancelamento.
     *
     * Retorna null quando o cancelamento ocorreu antes ou exatamente no início,
     * pois nesse caso a matrícula não chegou a produzir vigência utilizada.
     *
     * @throws InvalidArgumentException Para datas em fuso diferente ou intervalo contratual inválido.
     * @throws OutOfRangeException Para datas fora do intervalo de DATETIME do MySQL.
     */
    public static function fromContract(
        DateTimeImmutable $startInclusive,
        DateTimeImmutable $contractEndExclusive,
        ?DateTimeImmutable $cancelledAt = null
    ): ?self {
        self::assertAcademyDateTime($startInclusive, 'O início');
        self::assertAcademyDateTime($contractEndExclusive, 'O fim contratual');

        if ($cancelledAt !== null) {
            self::assertAcademyDateTime($cancelledAt, 'O cancelamento');
        }

        if ($contractEndExclusive <= $startInclusive) {
            throw new InvalidArgumentException(
                'O fim contratual exclusivo precisa ser posterior ao início.'
            );
        }

        // Cancelamento antes ou exatamente no início produz intervalo vazio.
        if ($cancelledAt !== null && $cancelledAt <= $startInclusive) {
            return null;
        }

        $effectiveEndExclusive = $contractEndExclusive;

        if ($cancelledAt !== null && $cancelledAt < $contractEndExclusive) {
            $effectiveEndExclusive = $cancelledAt;
        }

        return new self($startInclusive, $effectiveEndExclusive);
    }

    /**
     * Verifica se um instante pertence ao intervalo.
     * O início pertence; o fim exclusivo não pertence.
     */
    public function contains(DateTimeImmutable $instant): bool
    {
        self::assertAcademyDateTime($instant, 'O instante consultado');

        return $instant >= $this->startInclusive
            && $instant < $this->endExclusive;
    }

    /**
     * Intervalos adjacentes são permitidos: terminar no instante em que outro começa
     * não constitui sobreposição.
     */
    public function overlaps(self $other): bool
    {
        return $this->startInclusive < $other->endExclusive
            && $other->startInclusive < $this->endExclusive;
    }

    public function getStartInclusive(): DateTimeImmutable
    {
        return $this->startInclusive;
    }

    public function getEndExclusive(): DateTimeImmutable
    {
        return $this->endExclusive;
    }

    private static function assertAcademyDateTime(
        DateTimeImmutable $dateTime,
        string $fieldName
    ): void {
        if ($dateTime->getTimezone()->getName() !== EnrollmentPeriodCalculator::ACADEMY_TIMEZONE) {
            throw new InvalidArgumentException(
                $fieldName . ' deve estar no fuso '
                . EnrollmentPeriodCalculator::ACADEMY_TIMEZONE . '.'
            );
        }

        $year = (int) $dateTime->format('Y');

        if ($year < 1000 || $year > 9999) {
            throw new OutOfRangeException(
                $fieldName . ' está fora do intervalo de DATETIME do MySQL.'
            );
        }
    }
}
