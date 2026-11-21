<?php
declare(strict_types=1);

namespace FitControl\Domain\Enrollment;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use OutOfRangeException;

/**
 * Deriva a situação operacional de uma matrícula no instante da consulta.
 *
 * Nenhum estado temporal é persistido. A classe não depende de banco nem de relógio global.
 */
final class EnrollmentStatus
{
    public const SCHEDULED = 'AGENDADA';
    public const ACTIVE = 'ATIVA';
    public const EXPIRED = 'VENCIDA';
    public const CANCELLED = 'CANCELADA';

    /**
     * Calcula a situação a partir do início, fim contratual e cancelamento.
     *
     * CANCELADA tem prioridade quando cancelada_em está preenchido, inclusive
     * quando o cancelamento foi registrado antes do início agendado.
     *
     * @throws InvalidArgumentException Para datas em fuso diferente ou intervalo inválido.
     * @throws OutOfRangeException Para datas fora do intervalo de DATETIME do MySQL.
     */
    public static function derive(
        DateTimeImmutable $startInclusive,
        DateTimeImmutable $contractEndExclusive,
        ?DateTimeImmutable $cancelledAt,
        DateTimeImmutable $queriedAt
    ): string {
        self::assertAcademyDateTime($startInclusive, 'O início');
        self::assertAcademyDateTime($contractEndExclusive, 'O fim contratual');
        self::assertAcademyDateTime($queriedAt, 'O instante consultado');

        if ($cancelledAt !== null) {
            self::assertAcademyDateTime($cancelledAt, 'O cancelamento');
        }

        if ($contractEndExclusive <= $startInclusive) {
            throw new InvalidArgumentException(
                'O fim contratual exclusivo precisa ser posterior ao início.'
            );
        }

        if ($cancelledAt !== null) {
            return self::CANCELLED;
        }

        if ($queriedAt < $startInclusive) {
            return self::SCHEDULED;
        }

        if ($queriedAt >= $contractEndExclusive) {
            return self::EXPIRED;
        }

        return self::ACTIVE;
    }

    /**
     * Apenas matrículas agendadas ou ativas podem receber cancelamento.
     * O chamador deve executar esta validação novamente dentro da transação,
     * após bloquear a linha do aluno e a matrícula.
     */
    public static function canBeCancelled(
        DateTimeImmutable $startInclusive,
        DateTimeImmutable $contractEndExclusive,
        ?DateTimeImmutable $cancelledAt,
        DateTimeImmutable $queriedAt
    ): bool {
        $status = self::derive(
            $startInclusive,
            $contractEndExclusive,
            $cancelledAt,
            $queriedAt
        );

        return $status === self::SCHEDULED || $status === self::ACTIVE;
    }

    /**
     * Falha explicitamente quando uma operação tenta cancelar uma matrícula inelegível.
     *
     * @throws DomainException Quando a situação atual não permite cancelamento.
     */
    public static function assertCanBeCancelled(
        DateTimeImmutable $startInclusive,
        DateTimeImmutable $contractEndExclusive,
        ?DateTimeImmutable $cancelledAt,
        DateTimeImmutable $queriedAt
    ): void {
        $status = self::derive(
            $startInclusive,
            $contractEndExclusive,
            $cancelledAt,
            $queriedAt
        );

        if ($status !== self::SCHEDULED && $status !== self::ACTIVE) {
            throw new DomainException(
                sprintf('Matrículas na situação %s não podem ser canceladas.', $status)
            );
        }
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
