<?php
declare(strict_types=1);

namespace FitControl\Domain\Reports;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;

/** Consulta somente leitura dos indicadores FR-01 a FR-05. */
final class FrequencyReportQuery
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array{
     * fr01_total_checkins:int,
     * fr02_distinct_students:int,
     * fr03_daily_series:list<array{date:string,checkins:int}>,
     * fr04_by_weekday:list<array{weekday_iso:int,calendar_occurrences:int,total_checkins:int,average_per_occurrence:?float}>,
     * fr05_average_days_per_student:?float
     * }
     */
    public function forPeriod(ReportPeriod $period): array
    {
        $start = $period->getStartDateString();
        $end = $period->getEndDateString();
        $statement = $this->pdo->prepare(
            'SELECT summary.total_checkins, summary.distinct_students,
                    daily.local_date, daily.daily_checkins
             FROM (
                SELECT COUNT(*) AS total_checkins,
                       COUNT(DISTINCT aluno_id) AS distinct_students
                FROM checkins
                WHERE data_local >= :summary_start AND data_local <= :summary_end
             ) summary
             LEFT JOIN (
                SELECT data_local AS local_date, COUNT(*) AS daily_checkins
                FROM checkins
                WHERE data_local >= :daily_start AND data_local <= :daily_end
                GROUP BY data_local
             ) daily ON 1 = 1
             ORDER BY daily.local_date ASC'
        );
        $statement->execute([
            'summary_start'=>$start, 'summary_end'=>$end,
            'daily_start'=>$start, 'daily_end'=>$end,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            throw new DomainException('Não foi possível obter os indicadores de frequência.');
        }

        $total = (int)$rows[0]['total_checkins'];
        $distinct = (int)$rows[0]['distinct_students'];
        $dailyCounts = [];
        foreach ($rows as $row) {
            if ($row['local_date'] !== null) {
                $dailyCounts[(string)$row['local_date']] = (int)$row['daily_checkins'];
            }
        }

        // Iterar datas civis em UTC evita saltos de horário de verão local.
        $utc = new DateTimeZone('UTC');
        $first = DateTimeImmutable::createFromFormat('!Y-m-d', $start, $utc);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$first instanceof DateTimeImmutable || $first->format('Y-m-d') !== $start
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new DomainException('Não foi possível construir a série diária de frequência.');
        }

        $weekdays = [];
        for ($weekday=1; $weekday<=7; $weekday++) {
            $weekdays[$weekday] = ['calendar_occurrences'=>0,'total_checkins'=>0];
        }
        $series = [];
        $seriesTotal = 0;
        for ($offset=0; $offset<$period->getInclusiveDayCount(); $offset++) {
            $day = $first->modify('+' . $offset . ' days');
            $date = $day->format('Y-m-d');
            $weekday = (int)$day->format('N');
            $count = $dailyCounts[$date] ?? 0;
            $series[] = ['date'=>$date,'checkins'=>$count];
            $weekdays[$weekday]['calendar_occurrences']++;
            $weekdays[$weekday]['total_checkins'] += $count;
            $seriesTotal += $count;
        }

        $days = $period->getInclusiveDayCount();
        if ($total < $distinct || $seriesTotal !== $total
            || ($distinct > 0 && $total / $distinct > $days)) {
            throw new DomainException('Os registros de frequência violam as invariantes de unicidade ou reconciliação.');
        }

        $byWeekday = [];
        foreach ($weekdays as $iso => $stats) {
            $occurrences = $stats['calendar_occurrences'];
            $byWeekday[] = [
                'weekday_iso'=>$iso,
                'calendar_occurrences'=>$occurrences,
                'total_checkins'=>$stats['total_checkins'],
                'average_per_occurrence'=>$occurrences > 0 ? (float)($stats['total_checkins'] / $occurrences) : null,
            ];
        }

        return [
            'fr01_total_checkins'=>$total,
            'fr02_distinct_students'=>$distinct,
            'fr03_daily_series'=>$series,
            'fr04_by_weekday'=>$byWeekday,
            'fr05_average_days_per_student'=>$distinct > 0 ? (float)($total / $distinct) : null,
        ];
    }
}
