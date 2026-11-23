<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';

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

$modalidades = $pdo->query(
    'SELECT id, nome FROM modalidades WHERE ativo = 1 ORDER BY nome ASC, id ASC'
)->fetchAll(PDO::FETCH_ASSOC);
$instrutores = $pdo->query(
    'SELECT id, nome FROM instrutores WHERE ativo = 1 ORDER BY nome ASC, id ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$conditions = ['h.ativo = 1', 'm.ativo = 1'];
$parameters = [];
if ($diaRecebido !== 'todos') {
    $conditions[] = 'h.dia_semana = :weekday';
    $parameters['weekday'] = (int) $diaRecebido;
}
if ($modalidadeId !== null) {
    $conditions[] = 'h.modalidade_id = :modality_id';
    $parameters['modality_id'] = (int) $modalidadeId;
}
if ($instrutorId !== null) {
    $conditions[] = 'h.instrutor_id = :instructor_id';
    $parameters['instructor_id'] = (int) $instrutorId;
}

$query = $pdo->prepare(
    'SELECT h.id, h.dia_semana, h.hora_inicio, h.hora_fim,
            m.nome AS modalidade_nome,
            CASE WHEN i.ativo = 1 THEN i.nome ELSE NULL END AS instrutor_nome
     FROM horarios_aula h
     INNER JOIN modalidades m ON m.id = h.modalidade_id
     LEFT JOIN instrutores i ON i.id = h.instrutor_id
     WHERE ' . implode(' AND ', $conditions) . '
     ORDER BY h.dia_semana ASC, h.hora_inicio ASC, m.nome ASC, h.id ASC'
);
$query->execute($parameters);
$horarios = $query->fetchAll(PDO::FETCH_ASSOC);

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
