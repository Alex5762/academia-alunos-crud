<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/src/Domain/Classes/ClassScheduleQuery.php';

use FitControl\\Domain\\Classes\\ClassScheduleQuery;

$usuario = exigirAutenticacao($pdo);
exigirPermissao($usuario, 'classes.view');

$diaRecebido = $_GET['dia'] ?? 'todos';
if (!is_string($diaRecebido) || !in_array($diaRecebido, ['todos', '1', '2', '3', '4', '5', '6', '7'], true)) {
    http_response_code(400);
    exit('Filtro de dia inválido.');
}

$modalidadeRecebida = $_GET['modalidade_id'] ?? '';
$instrutorRecebido = $_GET['instrutor_id'] ?? '';
if (!is_string($modalidadeRecebida) || !is_string($instrutorRecebido)) {
    http_response_code(400);
    exit('Filtro inválido.');
}

$modalidadeId = $modalidadeRecebida === '' ? null : validarId($modalidadeRecebida);
$instrutorId = $instrutorRecebido === '' ? null : validarId($instrutorRecebido);
if (($modalidadeRecebida !== '' && $modalidadeId === false)
    || ($instrutorRecebido !== '' && $instrutorId === false)) {
    http_response_code(400);
    exit('Identificador de filtro inválido.');
}

$scheduleQuery = new ClassScheduleQuery($pdo);
$modalidades = $scheduleQuery->activeModalities();
$instrutores = $scheduleQuery->activeInstructors();
$horarios = $scheduleQuery->activeWeeklySchedule(
    $diaRecebido === 'todos' ? null : (int) $diaRecebido,
    $modalidadeId === null ? null : (int) $modalidadeId,
    $instrutorId === null ? null : (int) $instrutorId
);

$dias = [
    1 => 'Segunda-feira',
    2 => 'Terça-feira',
    3 => 'Quarta-feira',
    4 => 'Quinta-feira',
    5 => 'Sexta-feira',
    6 => 'Sábado',
    7 => 'Domingo',
];
$porDia = array_fill_keys(array_keys($dias), []);
foreach ($horarios as $horario) {
    $porDia[(int) $horario['dia_semana']][] = $horario;
}

$totalDiasComAula = count(array_filter($porDia, static fn (array $items): bool => $items !== []));
$totalModalidades = count(array_unique(array_column($horarios, 'modalidade_nome')));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Consulte a grade semanal de aulas da academia.">
    <title>Grade de aulas | FitControl</title>
    <link rel="stylesheet" href="style.css">
    <?= renderizarMonitorSessao() ?>
    <script src="theme.js"></script>
</head>
<body class="settings-body">
<main class="settings-page class-schedule-page">
    <a class="settings-back" href="index.php">← Voltar ao painel</a>
    <span class="eyebrow">OPERAÇÃO DA ACADEMIA</span>
    <h1>Grade de aulas</h1>
    <p class="settings-intro">Consulte os horários semanais, as modalidades e os instrutores da academia.</p>

    <section class="class-schedule-summary" aria-label="Resumo da grade semanal">
        <article class="class-schedule-stat">
            <span>Horários ativos</span>
            <strong><?= count($horarios) ?></strong>
        </article>
        <article class="class-schedule-stat">
            <span>Dias com aulas</span>
            <strong><?= $totalDiasComAula ?></strong>
        </article>
        <article class="class-schedule-stat">
            <span>Modalidades na consulta</span>
            <strong><?= $totalModalidades ?></strong>
        </article>
    </section>

    <section class="settings-card account-card" aria-labelledby="class-schedule-filter-title">
        <div class="settings-card-heading">
            <span class="settings-icon" aria-hidden="true">⌕</span>
            <div>
                <h2 id="class-schedule-filter-title">Filtrar programação</h2>
                <p>Refine a consulta por dia, modalidade ou instrutor.</p>
            </div>
        </div>
        <form method="get" action="aulas.php" class="class-schedule-filter">
            <div>
                <label for="class-day">Dia da semana</label>
                <select id="class-day" name="dia">
                    <option value="todos" <?= $diaRecebido === 'todos' ? 'selected' : '' ?>>Todos os dias</option>
                    <?php foreach ($dias as $dayNumber => $dayName) { ?>
                        <option value="<?= $dayNumber ?>" <?= $diaRecebido === (string) $dayNumber ? 'selected' : '' ?>>
                            <?= escapar($dayName) ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
            <div>
                <label for="class-modality">Modalidade</label>
                <select id="class-modality" name="modalidade_id">
                    <option value="">Todas as modalidades</option>
                    <?php foreach ($modalidades as $modalidade) { ?>
                        <option value="<?= (int) $modalidade['id'] ?>" <?= $modalidadeId === (int) $modalidade['id'] ? 'selected' : '' ?>>
                            <?= escapar($modalidade['nome']) ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
            <div>
                <label for="class-instructor">Instrutor</label>
                <select id="class-instructor" name="instrutor_id">
                    <option value="">Todos os instrutores</option>
                    <?php foreach ($instrutores as $instrutor) { ?>
                        <option value="<?= (int) $instrutor['id'] ?>" <?= $instrutorId === (int) $instrutor['id'] ? 'selected' : '' ?>>
                            <?= escapar($instrutor['nome']) ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
            <div class="class-schedule-filter-actions">
                <button class="button button-primary" type="submit">Aplicar filtros</button>
                <a class="button button-search" href="aulas.php">Limpar</a>
            </div>
        </form>
    </section>

    <section class="class-schedule-board" aria-label="Horários da semana">
        <?php foreach ($dias as $dayNumber => $dayName) {
            if ($diaRecebido !== 'todos' && $diaRecebido !== (string) $dayNumber) {
                continue;
            }
            $dayClasses = $porDia[$dayNumber];
        ?>
            <article class="class-day-card">
                <header class="class-day-heading">
                    <h2><?= escapar($dayName) ?></h2>
                    <span><?= count($dayClasses) ?> <?= count($dayClasses) === 1 ? 'aula' : 'aulas' ?></span>
                </header>
                <?php if ($dayClasses === []) { ?>
                    <p class="class-day-empty">Nenhuma aula programada para este dia.</p>
                <?php } else { ?>
                    <div class="class-day-list">
                        <?php foreach ($dayClasses as $class) { ?>
                            <article class="class-slot">
                                <div class="class-slot-time">
                                    <strong><?= escapar(substr((string) $class['hora_inicio'], 0, 5)) ?></strong>
                                    <span><?= escapar(substr((string) $class['hora_fim'], 0, 5)) ?></span>
                                </div>
                                <div class="class-slot-details">
                                    <h3><?= escapar($class['modalidade_nome']) ?></h3>
                                    <p><?= escapar($class['instrutor_nome'] ?? 'Instrutor a definir') ?></p>
                                </div>
                            </article>
                        <?php } ?>
                    </div>
                <?php } ?>
            </article>
        <?php } ?>
    </section>

    <p class="class-schedule-footnote">Esta grade apresenta horários recorrentes. Ela não representa reserva de vaga nem registro de presença individual.</p>
</main>
</body>
</html>
