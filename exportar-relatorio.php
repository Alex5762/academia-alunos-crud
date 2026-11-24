<?php
declare(strict_types=1);

use FitControl\Domain\Reports\EnrollmentReportQuery;
use FitControl\Domain\Reports\FinanceReportQuery;
use FitControl\Domain\Reports\FrequencyReportQuery;
use FitControl\Domain\Reports\OperationalReportQuery;
use FitControl\Domain\Reports\ReportCsvExporter;
use FitControl\Domain\Reports\ReportPeriod;
use FitControl\Domain\Finance\FinanceMoneyFormatter;

require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/src/Domain/Reports/ReportPeriod.php';
require_once __DIR__ . '/src/Domain/Reports/OperationalReportQuery.php';
require_once __DIR__ . '/src/Domain/Reports/EnrollmentReportQuery.php';
require_once __DIR__ . '/src/Domain/Reports/FrequencyReportQuery.php';
require_once __DIR__ . '/src/Domain/Reports/FinanceReportQuery.php';
require_once __DIR__ . '/src/Domain/Reports/ReportCsvExporter.php';
require_once __DIR__ . '/src/Domain/Finance/FinanceDashboardStatus.php';
require_once __DIR__ . '/src/Domain/Finance/FinanceMoneyFormatter.php';

$usuario = exigirAutenticacao($pdo);
exigirPermissao($usuario, 'dashboard.view');

$reportType = $_GET['tipo'] ?? null;
$permissions = [
    'operacional' => 'students.view',
    'matriculas' => 'enrollments.view',
    'frequencia' => 'attendance.view',
    'financeiro' => 'finance.view',
];

if (!is_string($reportType) || !isset($permissions[$reportType])) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Tipo de relatório inválido.');
}

exigirPermissao($usuario, $permissions[$reportType]);

$timezone = new DateTimeZone(ReportPeriod::ACADEMY_TIMEZONE);
$asOf = new DateTimeImmutable('now', $timezone);

try {
    $period = ReportPeriod::fromInput($_GET['de'] ?? null, $_GET['ate'] ?? null, $asOf);
} catch (InvalidArgumentException | OutOfRangeException $exception) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Período inválido. Informe duas datas reais no formato AAAA-MM-DD, em ordem e com no máximo 365 dias inclusivos.');
}

$start = $period->getStartDateString();
$end = $period->getEndDateString();
$rows = [];
$money = static fn (string|int|null $value): string => FinanceMoneyFormatter::formatDecimal($value);

try {
    if ($reportType === 'operacional') {
        $students = (new OperationalReportQuery($pdo))->studentCounts();
        $rows = [
            ['Indicador', 'Valor'],
            ['Total de alunos', $students['total']],
            ['Alunos ativos', $students['active']],
            ['Alunos inativos', $students['inactive']],
        ];
    } elseif ($reportType === 'matriculas') {
        $query = new OperationalReportQuery($pdo);
        $snapshot = $query->enrollmentSnapshot($asOf);
        $movement = (new EnrollmentReportQuery($pdo))->forPeriod($period);
        $rows = [
            ['Seção', 'Indicador', 'Quantidade', 'Valor conhecido'],
            ['Situação atual', 'Agendadas', $snapshot['status']['scheduled'], ''],
            ['Situação atual', 'Ativas', $snapshot['status']['active'], ''],
            ['Situação atual', 'Vencidas', $snapshot['status']['expired'], ''],
            ['Situação atual', 'Canceladas', $snapshot['status']['cancelled'], ''],
            ['Alertas atuais', 'Inícios em até 7 dias', $snapshot['upcoming_starts']['days_7'], ''],
            ['Alertas atuais', 'Inícios em até 30 dias', $snapshot['upcoming_starts']['days_30'], ''],
            ['Alertas atuais', 'Términos em até 7 dias', $snapshot['upcoming_ends']['days_7'], ''],
            ['Alertas atuais', 'Términos em até 30 dias', $snapshot['upcoming_ends']['days_30'], ''],
            ['Movimentação no período', 'Inícios efetivos', $movement['starts_total'], ''],
            ['Movimentação no período', 'Cancelamentos', $movement['cancellations'], ''],
            ['Inícios por plano', 'Plano contratado', 'Quantidade', 'Soma contratada'],
        ];
        foreach ($movement['starts_by_plan'] as $group) {
            $rows[] = ['Inícios por plano', $group['name'], $group['count'], $group['known_value_sum'] === null ? 'Sem valores informados' : $money($group['known_value_sum'])];
        }
        $rows[] = ['Inícios por origem', 'Origem', 'Quantidade', ''];
        foreach ($movement['starts_by_origin'] as $group) {
            $rows[] = ['Inícios por origem', $group['origin'], $group['count'], ''];
        }
    } elseif ($reportType === 'frequencia') {
        $frequency = (new FrequencyReportQuery($pdo))->forPeriod($period);
        $rows = [
            ['Seção', 'Indicador', 'Valor', 'Unidade ou observação'],
            ['Resumo', 'Total de check-ins (FR-01)', $frequency['fr01_total_checkins'], 'Entradas'],
            ['Resumo', 'Alunos frequentadores (FR-02)', $frequency['fr02_distinct_students'], 'Alunos distintos'],
            ['Resumo', 'Média de dias por frequentador (FR-05)', $frequency['fr05_average_days_per_student'] === null ? 'Não aplicável' : number_format($frequency['fr05_average_days_per_student'], 4, '.', ''), 'Dias por aluno'],
            ['Evolução diária (FR-03)', 'Data', 'Check-ins', ''],
        ];
        foreach ($frequency['fr03_daily_series'] as $day) {
            $rows[] = ['Evolução diária (FR-03)', $day['date'], $day['checkins'], ''];
        }
        $rows[] = ['Média por dia da semana (FR-04)', 'Dia da semana', 'Média por ocorrência', 'Total bruto'];
        $weekdayLabels = [1=>'Segunda-feira',2=>'Terça-feira',3=>'Quarta-feira',4=>'Quinta-feira',5=>'Sexta-feira',6=>'Sábado',7=>'Domingo'];
        foreach ($frequency['fr04_by_weekday'] as $weekday) {
            $rows[] = [
                'Média por dia da semana (FR-04)',
                $weekdayLabels[$weekday['weekday_iso']],
                $weekday['average_per_occurrence'] === null ? 'Não aplicável' : number_format($weekday['average_per_occurrence'], 4, '.', ''),
                $weekday['total_checkins'],
            ];
        }
    } else {
        $finance = (new FinanceReportQuery($pdo))->forPeriod($period);
        $rows = [
            ['Seção', 'Indicador', 'Quantidade', 'Valor'],
            ['Recebimentos', 'Total recebido (FN-01)', $finance['fn02_payment_count'], $money($finance['fn01_received_decimal'])],
            ['Recebimentos por forma (FN-03)', 'Forma de pagamento', 'Quantidade', 'Total recebido'],
        ];
        foreach ($finance['fn03_by_method'] as $group) {
            $rows[] = ['Recebimentos por forma (FN-03)', $group['method'], $group['payment_count'], $money($group['received_decimal'])];
        }
        $rows[] = ['Cobranças por vencimento (FN-04)', 'Matrículas não canceladas', $finance['fn04_due_charges']['collectible']['charge_count'], $money($finance['fn04_due_charges']['collectible']['nominal_total_decimal'])];
        $rows[] = ['Cobranças por vencimento (FN-04)', 'Em revisão (matrículas canceladas)', $finance['fn04_due_charges']['review']['charge_count'], $money($finance['fn04_due_charges']['review']['nominal_total_decimal'])];
        $rows[] = ['Saldos atuais', 'Saldo cobrável (FN-05)', '', FinanceMoneyFormatter::formatCents($finance['fn05_collectible_balance_cents'])];
        $rows[] = ['Saldos atuais', 'Saldo em revisão (FN-06)', '', FinanceMoneyFormatter::formatCents($finance['fn06_review_balance_cents'])];
    }
} catch (Throwable $exception) {
    error_log('Exportação CSV do FitControl: ' . $exception->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Não foi possível gerar o relatório solicitado. Nenhum arquivo parcial foi exportado.');
}

$filename = sprintf('fitcontrol-%s-%s-a-%s.csv', $reportType, $start, $end);
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

$output = fopen('php://output', 'wb');
if ($output === false) {
    http_response_code(500);
    exit('Não foi possível iniciar a exportação.');
}

// BOM UTF-8 facilita abertura direta no Excel; a estrutura permanece UTF-8 CSV.
fwrite($output, "\xEF\xBB\xBF");
ReportCsvExporter::writeRows($output, [
    ['FitControl — Relatório agregado', 'Período', $start . ' a ' . $end],
    ['Gerado em', $asOf->format('Y-m-d H:i:s') . ' (' . ReportPeriod::ACADEMY_TIMEZONE . ')'],
    [],
    ...$rows,
]);
fclose($output);
