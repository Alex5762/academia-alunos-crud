<?php
declare(strict_types=1);

namespace FitControl\Domain\Reports;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use PDO;

/**
 * Consultas agregadas do painel operacional.
 *
 * Não aplica autorização: o controller valida a permissão da seção
 * antes de instanciar/executar qualquer consulta.
 */
final class OperationalReportQuery
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * OP-01 a OP-03: fotografia cadastral agregada, independente do período.
     *
     * @return array{total:int,active:int,inactive:int}
     */
    public function studentCounts(): array
    {
        $statement = $this->pdo->query(
            'SELECT
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN ativo = 1 THEN 1 ELSE 0 END), 0) AS active,
                COALESCE(SUM(CASE WHEN ativo = 0 THEN 1 ELSE 0 END), 0) AS inactive
             FROM alunos'
        );

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('Não foi possível obter os indicadores cadastrais.');
        }

        return [
            'total' => (int) $row['total'],
            'active' => (int) $row['active'],
            'inactive' => (int) $row['inactive'],
        ];
    }

    /**
     * OP-04 a OP-06: fotografia de matrículas no único instante recebido.
     *
     * @return array{
     *   status:array{scheduled:int,active:int,expired:int,cancelled:int},
     *   upcoming_starts:array{days_7:int,days_30:int},
     *   upcoming_ends:array{days_7:int,days_30:int}
     * }
     */
    public function enrollmentSnapshot(DateTimeImmutable $asOfLocal): array
    {
        if ($asOfLocal->getTimezone()->getName() !== ReportPeriod::ACADEMY_TIMEZONE) {
            throw new InvalidArgumentException(
                'O instante de referência precisa usar o fuso ' . ReportPeriod::ACADEMY_TIMEZONE . '.'
            );
        }

        $utcTimezone = new DateTimeZone('UTC');
        $asOfUtc = $asOfLocal->setTimezone($utcTimezone);
        $limit7Utc = $asOfLocal->add(new DateInterval('P7D'))->setTimezone($utcTimezone);
        $limit30Utc = $asOfLocal->add(new DateInterval('P30D'))->setTimezone($utcTimezone);

        $asOf = $asOfUtc->format('Y-m-d H:i:s');
        $limit7 = $limit7Utc->format('Y-m-d H:i:s');
        $limit30 = $limit30Utc->format('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            'SELECT
                COALESCE(SUM(CASE
                    WHEN fim_exclusivo_em <= inicio_em THEN 1 ELSE 0
                END), 0) AS invalid_interval_count,

                COALESCE(SUM(CASE
                    WHEN cancelada_em IS NOT NULL THEN 1 ELSE 0
                END), 0) AS cancelled_count,

                COALESCE(SUM(CASE
                    WHEN cancelada_em IS NULL
                     AND fim_exclusivo_em > inicio_em
                     AND inicio_em > :as_of_scheduled
                    THEN 1 ELSE 0
                END), 0) AS scheduled_count,

                COALESCE(SUM(CASE
                    WHEN cancelada_em IS NULL
                     AND fim_exclusivo_em > inicio_em
                     AND inicio_em <= :as_of_active
                     AND fim_exclusivo_em > :as_of_active_end
                    THEN 1 ELSE 0
                END), 0) AS active_count,

                COALESCE(SUM(CASE
                    WHEN cancelada_em IS NULL
                     AND fim_exclusivo_em > inicio_em
                     AND fim_exclusivo_em <= :as_of_expired
                    THEN 1 ELSE 0
                END), 0) AS expired_count,

                COALESCE(SUM(CASE
                    WHEN cancelada_em IS NULL
                     AND fim_exclusivo_em > inicio_em
                     AND inicio_em > :as_of_start_7
                     AND inicio_em <= :limit_start_7
                    THEN 1 ELSE 0
                END), 0) AS starts_7_days,

                COALESCE(SUM(CASE
                    WHEN cancelada_em IS NULL
                     AND fim_exclusivo_em > inicio_em
                     AND inicio_em > :as_of_start_30
                     AND inicio_em <= :limit_start_30
                    THEN 1 ELSE 0
                END), 0) AS starts_30_days,

                COALESCE(SUM(CASE
                    WHEN cancelada_em IS NULL
                     AND fim_exclusivo_em > inicio_em
                     AND inicio_em <= :as_of_end_7
                     AND fim_exclusivo_em > :as_of_end_7_exclusive
                     AND fim_exclusivo_em <= :limit_end_7
                    THEN 1 ELSE 0
                END), 0) AS ends_7_days,

                COALESCE(SUM(CASE
                    WHEN cancelada_em IS NULL
                     AND fim_exclusivo_em > inicio_em
                     AND inicio_em <= :as_of_end_30
                     AND fim_exclusivo_em > :as_of_end_30_exclusive
                     AND fim_exclusivo_em <= :limit_end_30
                    THEN 1 ELSE 0
                END), 0) AS ends_30_days
             FROM matriculas'
        );

        $statement->execute([
            'as_of_scheduled' => $asOf,
            'as_of_active' => $asOf,
            'as_of_active_end' => $asOf,
            'as_of_expired' => $asOf,
            'as_of_start_7' => $asOf,
            'limit_start_7' => $limit7,
            'as_of_start_30' => $asOf,
            'limit_start_30' => $limit30,
            'as_of_end_7' => $asOf,
            'as_of_end_7_exclusive' => $asOf,
            'limit_end_7' => $limit7,
            'as_of_end_30' => $asOf,
            'as_of_end_30_exclusive' => $asOf,
            'limit_end_30' => $limit30,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('Não foi possível obter os indicadores de matrícula.');
        }

        if ((int) $row['invalid_interval_count'] > 0) {
            throw new DomainException(
                'Há matrícula com fim exclusivo anterior ou igual ao início. '
                . 'Os indicadores de matrícula foram interrompidos sem apresentar contagens parciais.'
            );
        }

        return [
            'status' => [
                'scheduled' => (int) $row['scheduled_count'],
                'active' => (int) $row['active_count'],
                'expired' => (int) $row['expired_count'],
                'cancelled' => (int) $row['cancelled_count'],
            ],
            'upcoming_starts' => [
                'days_7' => (int) $row['starts_7_days'],
                'days_30' => (int) $row['starts_30_days'],
            ],
            'upcoming_ends' => [
                'days_7' => (int) $row['ends_7_days'],
                'days_30' => (int) $row['ends_30_days'],
            ],
        ];
    }
}
