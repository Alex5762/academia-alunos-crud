<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';

$usuario = exigirAutenticacao($pdo);
exigirPermissao($usuario, 'attendance.view');

$fusoAcademia = new DateTimeZone('America/Sao_Paulo');
$fusoUtc = new DateTimeZone('UTC');
$agoraLocal = new DateTimeImmutable('now', $fusoAcademia);
$hoje = $agoraLocal->format('Y-m-d');
$inicioMes = $agoraLocal->modify('first day of this month')->format('Y-m-d');

function dataFiltroCheckin(string $value, DateTimeZone $timezone): ?string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
    $errors = DateTimeImmutable::getLastErrors();

    if (
        !$date instanceof DateTimeImmutable
        || $date->format('Y-m-d') !== $value
        || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
    ) {
        return null;
    }

    return $date->format('Y-m-d');
}

$dataInicialRecebida = $_GET['de'] ?? $inicioMes;
$dataFinalRecebida = $_GET['ate'] ?? $hoje;
if (!is_string($dataInicialRecebida) || !is_string($dataFinalRecebida)) {
    http_response_code(400);
    exit('Período inválido.');
}
$dataInicial = dataFiltroCheckin($dataInicialRecebida, $fusoAcademia);
$dataFinal = dataFiltroCheckin($dataFinalRecebida, $fusoAcademia);
if ($dataInicial === null || $dataFinal === null || $dataInicial > $dataFinal) {
    http_response_code(400);
    exit('Informe um período válido, com data inicial anterior ou igual à final.');
}

$alunoFiltro = $_GET['aluno_id'] ?? '';
if (!is_string($alunoFiltro)) {
    http_response_code(400);
    exit('Filtro de aluno inválido.');
}
$alunoId = $alunoFiltro === '' ? null : validarId($alunoFiltro);
if ($alunoFiltro !== '' && $alunoId === false) {
    http_response_code(400);
    exit('Identificador de aluno inválido.');
}

$contagemHoje = $pdo->prepare('SELECT COUNT(*) FROM checkins WHERE data_local = :today');
$contagemHoje->execute(['today' => $hoje]);
$totalHoje = (int) $contagemHoje->fetchColumn();

$contagemMes = $pdo->prepare(
    'SELECT COUNT(*) FROM checkins WHERE data_local >= :month_start AND data_local <= :today'
);
$contagemMes->execute(['month_start' => $inicioMes, 'today' => $hoje]);
$totalMes = (int) $contagemMes->fetchColumn();

$alunosElegiveis = $pdo->prepare(
    'SELECT a.id, a.nome
     FROM alunos a
     WHERE a.ativo = 1
       AND (
           SELECT COUNT(*)
           FROM matriculas m
           WHERE m.aluno_id = a.id
             AND m.inicio_em <= :now_start
             AND m.fim_exclusivo_em > :now_end
             AND m.cancelada_em IS NULL
       ) = 1
       AND NOT EXISTS (
           SELECT 1 FROM checkins c
           WHERE c.aluno_id = a.id AND c.data_local = :today
       )
     ORDER BY a.nome ASC, a.id ASC'
);
$agoraUtc = $agoraLocal->setTimezone($fusoUtc)->format('Y-m-d H:i:s');
$alunosElegiveis->execute(['now_start' => $agoraUtc, 'now_end' => $agoraUtc, 'today' => $hoje]);
$opcoesAlunos = $alunosElegiveis->fetchAll(PDO::FETCH_ASSOC);

$condicoes = ['c.data_local >= :date_from', 'c.data_local <= :date_to'];
$parametros = ['date_from' => $dataInicial, 'date_to' => $dataFinal];
if ($alunoId !== null) {
    $condicoes[] = 'c.aluno_id = :student_id';
    $parametros['student_id'] = (int) $alunoId;
}
$historico = $pdo->prepare(
    'SELECT c.id, c.aluno_id, c.matricula_id, c.data_local, c.registrado_em_utc,
            a.nome AS aluno_nome, u.nome AS responsavel_nome
     FROM checkins c
     INNER JOIN alunos a ON a.id = c.aluno_id
     INNER JOIN usuarios u ON u.id = c.usuario_id
     WHERE ' . implode(' AND ', $condicoes) . '
     ORDER BY c.registrado_em_utc DESC, c.id DESC
     LIMIT 100'
);
$historico->execute($parametros);
$registros = $historico->fetchAll(PDO::FETCH_ASSOC);

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Registro manual e histórico de frequência dos alunos.">
    <title>Frequência | FitControl</title>
    <link rel="stylesheet" href="style.css">
    <?= renderizarMonitorSessao() ?>
    <script src="theme.js"></script>
</head>
<body class="settings-body">
<main class="settings-page attendance-page">
    <a class="settings-back" href="index.php">← Voltar ao painel</a>
    <span class="eyebrow">OPERAÇÃO DA ACADEMIA</span>
    <h1>Frequência</h1>
    <p class="settings-intro">Registre entradas e consulte o histórico de presença dos alunos.</p>

    <?php if ($mensagem !== '') { ?>
        <div class="flash-message" role="status"><?= escapar($mensagem) ?></div>
    <?php } ?>

    <section class="attendance-summary" aria-label="Indicadores de frequência">
        <article class="attendance-stat">
            <span>Check-ins hoje</span>
            <strong><?= $totalHoje ?></strong>
        </article>
        <article class="attendance-stat">
            <span>Check-ins neste mês</span>
            <strong><?= $totalMes ?></strong>
        </article>
    </section>

    <?php if (usuarioTemPermissao($usuario, 'attendance.register')) { ?>
    <section class="settings-card account-card" aria-labelledby="attendance-register-title">
        <div class="settings-card-heading">
            <span class="settings-icon" aria-hidden="true">✓</span>
            <div>
                <h2 id="attendance-register-title">Registrar entrada</h2>
                <p>Somente alunos ativos com matrícula vigente podem registrar presença.</p>
            </div>
        </div>

        <?php if ($opcoesAlunos === []) { ?>
            <p class="plan-setup-note">Nenhum aluno possui matrícula vigente disponível para check-in neste momento.</p>
        <?php } else { ?>
            <form action="registrar-checkin.php" method="post" class="attendance-register-form">
                <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
                <div>
                    <label for="checkin-aluno">Aluno</label>
                    <select id="checkin-aluno" name="aluno_id" required>
                        <option value="">Selecione um aluno</option>
                        <?php foreach ($opcoesAlunos as $opcao) { ?>
                            <option value="<?= (int) $opcao['id'] ?>">
                                <?= escapar($opcao['nome']) ?> · #<?= (int) $opcao['id'] ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>
                <button class="button button-primary" type="submit">Confirmar check-in</button>
            </form>
            <p class="field-hint">A data e o horário são definidos pelo servidor. Cada aluno pode registrar uma entrada por dia.</p>
        <?php } ?>
    </section>
    <?php } ?>

    <section class="settings-card account-card" aria-labelledby="attendance-history-title">
        <div class="settings-card-heading">
            <span class="settings-icon" aria-hidden="true">▤</span>
            <div>
                <h2 id="attendance-history-title">Histórico de presenças</h2>
                <p>Filtre por período e, se desejar, por aluno.</p>
            </div>
        </div>

        <form method="get" action="checkins.php" class="attendance-filter-form">
            <div>
                <label for="checkin-de">De</label>
                <input id="checkin-de" type="date" name="de" value="<?= escapar($dataInicial) ?>" required>
            </div>
            <div>
                <label for="checkin-ate">Até</label>
                <input id="checkin-ate" type="date" name="ate" value="<?= escapar($dataFinal) ?>" required>
            </div>
            <div>
                <label for="checkin-filtro-aluno">Aluno</label>
                <select id="checkin-filtro-aluno" name="aluno_id">
                    <option value="">Todos os alunos</option>
                    <?php
                    $todosAlunos = $pdo->query('SELECT id, nome FROM alunos ORDER BY nome ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($todosAlunos as $aluno) {
                    ?>
                        <option value="<?= (int) $aluno['id'] ?>" <?= $alunoId === (int) $aluno['id'] ? 'selected' : '' ?>>
                            <?= escapar($aluno['nome']) ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
            <div class="attendance-filter-actions">
                <button class="button button-primary" type="submit">Aplicar filtros</button>
                <a class="button button-search" href="checkins.php">Limpar</a>
            </div>
        </form>

        <?php if ($registros === []) { ?>
            <div class="student-history-empty">Nenhum check-in encontrado no período selecionado.</div>
        <?php } else { ?>
            <div class="table-scroll" tabindex="0" aria-label="Histórico de check-ins">
                <table class="attendance-table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Horário</th>
                            <th>Aluno</th>
                            <th>Matrícula</th>
                            <th>Registrado por</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($registros as $registro) {
                        $instanteLocal = (new DateTimeImmutable($registro['registrado_em_utc'], $fusoUtc))
                            ->setTimezone($fusoAcademia);
                    ?>
                        <tr>
                            <td><?= escapar((new DateTimeImmutable($registro['data_local'], $fusoAcademia))->format('d/m/Y')) ?></td>
                            <td><?= escapar($instanteLocal->format('H:i:s')) ?></td>
                            <td>
                                <a href="detalhes-aluno.php?id=<?= (int) $registro['aluno_id'] ?>">
                                    <?= escapar($registro['aluno_nome']) ?>
                                </a>
                            </td>
                            <td>#<?= (int) $registro['matricula_id'] ?></td>
                            <td><?= escapar($registro['responsavel_nome']) ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
            <p class="field-hint">Exibindo até 100 registros, do mais recente para o mais antigo.</p>
        <?php } ?>
    </section>

    <footer class="page-footer"><span>FitControl · Frequência</span><span>Projeto acadêmico/local</span></footer>
</main>
</body>
</html>
