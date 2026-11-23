<?php
declare(strict_types=1);

use FitControl\Domain\Reports\EnrollmentReportQuery;
use FitControl\Domain\Reports\FrequencyReportQuery;
use FitControl\Domain\Reports\OperationalReportQuery;
use FitControl\Domain\Reports\ReportPeriod;
use FitControl\Domain\Finance\FinanceMoneyFormatter;

require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/src/Domain/Reports/ReportPeriod.php';
require_once __DIR__ . '/src/Domain/Reports/OperationalReportQuery.php';
require_once __DIR__ . '/src/Domain/Reports/EnrollmentReportQuery.php';
require_once __DIR__ . '/src/Domain/Reports/FrequencyReportQuery.php';
require_once __DIR__ . '/src/Domain/Finance/FinanceMoneyFormatter.php';

$usuario = exigirAutenticacao($pdo);
exigirPermissao($usuario, 'dashboard.view');
$fusoAcademia = new DateTimeZone(ReportPeriod::ACADEMY_TIMEZONE);
$asOf = new DateTimeImmutable('now', $fusoAcademia);

try {
    $period = ReportPeriod::fromInput($_GET['de'] ?? null, $_GET['ate'] ?? null, $asOf);
} catch (InvalidArgumentException | OutOfRangeException $exception) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Período inválido. Informe duas datas reais no formato AAAA-MM-DD, em ordem e com no máximo 365 dias inclusivos.');
}

$students = null;
$studentError = null;
$snapshot = null;
$snapshotError = null;
$movement = null;
$movementError = null;
$frequency = null;
$frequencyError = null;
$frequencyMaxDailyCheckins = 0;
$canViewFrequency = usuarioTemPermissao($usuario, 'attendance.view');

if (usuarioTemPermissao($usuario, 'students.view')) {
    try {
        $students = (new OperationalReportQuery($pdo))->studentCounts();
    } catch (Throwable $exception) {
        error_log('Relatório de alunos: ' . $exception->getMessage());
        $studentError = 'Não foi possível consultar os indicadores de alunos.';
    }
}

if ($canViewFrequency) {
    try {
        $frequency = (new FrequencyReportQuery($pdo))->forPeriod($period);
        if ($frequency['fr03_daily_series'] !== []) {
            $frequencyMaxDailyCheckins = max(array_column($frequency['fr03_daily_series'], 'checkins'));
        }
    } catch (Throwable $exception) {
        error_log('Relatório de frequência: ' . $exception->getMessage());
        $frequencyError = 'Não foi possível consultar os indicadores de frequência.';
    }
}

if (usuarioTemPermissao($usuario, 'enrollments.view')) {
    $operational = new OperationalReportQuery($pdo);
    try {
        $snapshot = $operational->enrollmentSnapshot($asOf);
    } catch (DomainException $exception) {
        error_log('Integridade das matrículas: ' . $exception->getMessage());
        $snapshotError = 'Há intervalos contratuais inválidos. Os indicadores foram interrompidos sem contagens parciais.';
    } catch (Throwable $exception) {
        error_log('Consulta de situação das matrículas: ' . $exception->getMessage());
        $snapshotError = 'Não foi possível consultar a situação atual das matrículas.';
    }

    try {
        $movement = (new EnrollmentReportQuery($pdo))->forPeriod($period);
    } catch (DomainException $exception) {
        error_log('Integridade do relatório de matrículas: ' . $exception->getMessage());
        $movementError = 'Há intervalos contratuais inválidos. O relatório foi interrompido sem contagens parciais.';
    } catch (Throwable $exception) {
        error_log('Consulta de movimentação de matrículas: ' . $exception->getMessage());
        $movementError = 'Não foi possível consultar a movimentação de matrículas.';
    }
}

function valorContratadoRelatorio(?string $value): string
{
    return $value === null ? 'Sem valores informados' : FinanceMoneyFormatter::formatDecimal($value);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Indicadores operacionais e relatórios de matrículas da academia.">
    <title>Relatórios | FitControl</title>
    <link rel="stylesheet" href="style.css">
    <?= renderizarMonitorSessao() ?>
    <script src="theme.js"></script>
</head>
<body class="settings-body">
<main class="settings-page reports-page">
    <a class="settings-back" href="index.php">← Voltar ao painel</a>
    <span class="eyebrow">ANÁLISE DA ACADEMIA</span>
    <h1>Relatórios</h1>
    <p class="settings-intro">Indicadores operacionais e movimentações contratuais, sem expor dados pessoais.</p>

    <section class="settings-card reports-section" aria-labelledby="reports-period-title">
        <div class="settings-card-heading">
            <span class="settings-icon" aria-hidden="true">▦</span>
            <div><h2 id="reports-period-title">Período de análise</h2><p>O período afeta os indicadores de movimentação e frequência.</p></div>
        </div>
        <form method="get" action="relatorios.php" class="reports-filter-form">
            <div><label for="report-start">De</label><input id="report-start" type="date" name="de" value="<?= escapar($period->getStartDateString()) ?>" required></div>
            <div><label for="report-end">Até</label><input id="report-end" type="date" name="ate" value="<?= escapar($period->getEndDateString()) ?>" required></div>
            <div class="reports-filter-actions"><button class="button button-primary" type="submit">Aplicar período</button><a class="button button-search" href="relatorios.php">Mês atual</a></div>
        </form>
        <p class="reports-note">Período inclusivo: <?= escapar($period->getStartInclusiveLocal()->format('d/m/Y')) ?> a <?= escapar($period->getEndInclusiveLocal()->format('d/m/Y')) ?>. Atualizado em <?= escapar($asOf->format('d/m/Y H:i:s')) ?> (horário da academia).</p>
    </section>

    <?php if ($students !== null || $studentError !== null) { ?>
    <section class="settings-card reports-section" aria-labelledby="reports-students-title">
        <div class="settings-card-heading"><span class="settings-icon" aria-hidden="true">◎</span><div><h2 id="reports-students-title">Situação cadastral</h2><p>Fotografia atual; não é alterada pelo período.</p></div></div>
        <?php if ($studentError !== null) { ?><p class="reports-error" role="alert"><?= escapar($studentError) ?></p><?php } else { ?>
        <div class="reports-stat-grid">
            <article class="reports-stat"><span>Total de alunos</span><strong><?= $students['total'] ?></strong></article>
            <article class="reports-stat"><span>Alunos ativos</span><strong><?= $students['active'] ?></strong></article>
            <article class="reports-stat"><span>Alunos inativos</span><strong><?= $students['inactive'] ?></strong></article>
        </div><?php } ?>
    </section><?php } ?>

    <?php if ($snapshot !== null || $snapshotError !== null) { ?>
    <section class="settings-card reports-section" aria-labelledby="reports-current-title">
        <div class="settings-card-heading"><span class="settings-icon" aria-hidden="true">◷</span><div><h2 id="reports-current-title">Situação atual das matrículas</h2><p>Fotografia no instante da consulta; independente do período selecionado.</p></div></div>
        <?php if ($snapshotError !== null) { ?><p class="reports-error" role="alert"><?= escapar($snapshotError) ?></p><?php } else { ?>
        <div class="reports-stat-grid">
            <article class="reports-stat"><span>Agendadas</span><strong><?= $snapshot['status']['scheduled'] ?></strong></article>
            <article class="reports-stat"><span>Ativas</span><strong><?= $snapshot['status']['active'] ?></strong></article>
            <article class="reports-stat"><span>Vencidas</span><strong><?= $snapshot['status']['expired'] ?></strong></article>
            <article class="reports-stat"><span>Canceladas</span><strong><?= $snapshot['status']['cancelled'] ?></strong></article>
            <article class="reports-stat"><span>Inícios em até 7 dias</span><strong><?= $snapshot['upcoming_starts']['days_7'] ?></strong></article>
            <article class="reports-stat"><span>Inícios em até 30 dias</span><strong><?= $snapshot['upcoming_starts']['days_30'] ?></strong></article>
            <article class="reports-stat"><span>Términos em até 7 dias</span><strong><?= $snapshot['upcoming_ends']['days_7'] ?></strong></article>
            <article class="reports-stat"><span>Términos em até 30 dias</span><strong><?= $snapshot['upcoming_ends']['days_30'] ?></strong></article>
        </div>
        <p class="reports-note">As janelas de 30 dias são cumulativas e incluem as de 7 dias. Matrículas canceladas não entram nas janelas próximas.</p><?php } ?>
    </section><?php } ?>

    <?php if ($movement !== null || $movementError !== null) { ?>
    <section class="settings-card reports-section" aria-labelledby="reports-movement-title">
        <div class="settings-card-heading"><span class="settings-icon" aria-hidden="true">▤</span><div><h2 id="reports-movement-title">Movimentação de matrículas</h2><p>Inícios efetivos e eventos de cancelamento dentro do período selecionado.</p></div></div>
        <?php if ($movementError !== null) { ?><p class="reports-error" role="alert"><?= escapar($movementError) ?></p><?php } else { ?>
        <div class="reports-stat-grid reports-stat-grid-compact">
            <article class="reports-stat"><span>Inícios efetivos</span><strong><?= $movement['starts_total'] ?></strong></article>
            <article class="reports-stat"><span>Cancelamentos no período</span><strong><?= $movement['cancellations'] ?></strong></article>
        </div>
        <div class="reports-table-section"><h3>Inícios por plano contratado</h3>
            <?php if ($movement['starts_by_plan'] === []) { ?><p class="reports-empty">Nenhum início efetivo encontrado no período.</p><?php } else { ?>
            <div class="table-scroll" role="region" aria-label="Inícios por plano contratado" tabindex="0"><table class="reports-table">
                <thead><tr><th scope="col">Plano contratado</th><th scope="col">Inícios</th><th scope="col">Valores conhecidos</th><th scope="col">Soma contratada</th></tr></thead>
                <tbody><?php foreach ($movement['starts_by_plan'] as $group) { ?><tr>
                    <th scope="row"><?= escapar($group['name']) ?></th><td><?= $group['count'] ?></td><td><?= $group['known_values_count'] ?></td><td><?= escapar(valorContratadoRelatorio($group['known_value_sum'])) ?></td>
                </tr><?php } ?></tbody>
            </table></div><?php } ?>
            <p class="reports-note">A soma considera somente valor contratado informado; ausência de valor não é convertida em zero. Valor contratado não representa recebimento.</p>
        </div>
        <div class="reports-table-section"><h3>Inícios por origem</h3>
            <?php if ($movement['starts_by_origin'] === []) { ?><p class="reports-empty">Nenhum início efetivo encontrado no período.</p><?php } else { ?>
            <div class="table-scroll" role="region" aria-label="Inícios por origem" tabindex="0"><table class="reports-table">
                <thead><tr><th scope="col">Origem</th><th scope="col">Inícios</th></tr></thead>
                <tbody><?php foreach ($movement['starts_by_origin'] as $group) { ?><tr><th scope="row"><?= escapar($group['origin']) ?></th><td><?= $group['count'] ?></td></tr><?php } ?></tbody>
            </table></div><?php } ?>
        </div><?php } ?>
    </section><?php } ?>
    <?php if ($canViewFrequency && ($frequency !== null || $frequencyError !== null)) { ?>
    <section class="settings-card reports-section" aria-labelledby="reports-frequency-title">
        <div class="settings-card-heading"><span class="settings-icon" aria-hidden="true">◷</span><div><h2 id="reports-frequency-title">Frequência</h2><p>Entradas registradas no período selecionado, pela data civil local.</p></div></div>
        <?php if ($frequencyError !== null) { ?>
            <p class="reports-error" role="alert"><?= escapar($frequencyError) ?></p>
        <?php } else { ?>
            <div class="reports-stat-grid">
                <article class="reports-stat"><span>Total de check-ins (FR-01)</span><strong><?= $frequency['fr01_total_checkins'] ?></strong></article>
                <article class="reports-stat"><span>Alunos frequentadores (FR-02)</span><strong><?= $frequency['fr02_distinct_students'] ?></strong></article>
                <article class="reports-stat"><span>Média de dias por frequentador (FR-05)</span><strong><?= $frequency['fr05_average_days_per_student'] === null ? '—' : escapar(number_format($frequency['fr05_average_days_per_student'], 1, ',', '.')) ?></strong></article>
            </div>
            <div class="reports-table-section">
                <h3>Evolução diária (FR-03)</h3>
                <div class="table-scroll" role="region" aria-label="Evolução diária dos check-ins" tabindex="0">
                    <table class="reports-table frequency-daily-table">
                        <thead><tr><th scope="col">Data</th><th scope="col">Check-ins</th><th scope="col">Representação visual</th></tr></thead>
                        <tbody>
                        <?php foreach ($frequency['fr03_daily_series'] as $day) {
                            $dayLabel = substr($day['date'], 8, 2) . '/' . substr($day['date'], 5, 2) . '/' . substr($day['date'], 0, 4);
                            $barWidth = $frequencyMaxDailyCheckins > 0 ? ($day['checkins'] / $frequencyMaxDailyCheckins) * 100 : 0;
                        ?>
                            <tr><th scope="row"><?= escapar($dayLabel) ?></th><td><?= $day['checkins'] ?></td>
                                <td class="frequency-visual-cell"><span class="frequency-bar-track" aria-hidden="true"><span class="frequency-bar" style="width: <?= escapar(number_format($barWidth, 2, '.', '')) ?>%;"></span></span></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
                <p class="reports-note">A tabela mantém os valores disponíveis sem depender da barra visual. Datas sem entrada aparecem com zero; isso não confirma que a academia estava aberta.</p>
            </div>
            <div class="reports-table-section">
                <h3>Média por dia da semana (FR-04)</h3>
                <div class="table-scroll" role="region" aria-label="Média de check-ins por ocorrência de dia da semana" tabindex="0">
                    <table class="reports-table">
                        <thead><tr><th scope="col">Dia da semana</th><th scope="col">Média por ocorrência</th><th scope="col">Total bruto</th><th scope="col">Ocorrências no calendário</th></tr></thead>
                        <tbody>
                        <?php
                        $frequencyWeekdayLabels = [1=>'Segunda-feira',2=>'Terça-feira',3=>'Quarta-feira',4=>'Quinta-feira',5=>'Sexta-feira',6=>'Sábado',7=>'Domingo'];
                        foreach ($frequency['fr04_by_weekday'] as $weekday) {
                            $weekdayLabel = $frequencyWeekdayLabels[$weekday['weekday_iso']] ?? 'Dia inválido';
                            $weekdayAverage = $weekday['average_per_occurrence'] === null ? '—' : number_format($weekday['average_per_occurrence'], 1, ',', '.');
                        ?>
                            <tr><th scope="row"><?= escapar($weekdayLabel) ?></th><td><?= escapar($weekdayAverage) ?></td><td><?= $weekday['total_checkins'] ?></td><td><?= $weekday['calendar_occurrences'] ?></td></tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
                <p class="reports-note">A média usa todas as ocorrências do dia no calendário do período, não apenas dias de funcionamento. Um dia sem ocorrência exibe “—”; uma ocorrência com zero entradas exibe média 0,0.</p>
            </div>
        <?php } ?>
    </section>
    <?php } ?>
    <a class="settings-back" href="index.php">Voltar ao painel</a>
</main>
</body>
</html>
