<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';

$usuario = exigirAutenticacao($pdo);
$contextoLista = obterContextoListaAlunos($_GET);
$id = validarId($_GET['id'] ?? null);

if ($id === false) {
    http_response_code(400);
    exit('ID inválido.');
}

$consulta = $pdo->prepare(
    'SELECT id, nome, telefone, email, plano, ativo
     FROM alunos
     WHERE id = :id
     LIMIT 1'
);
$consulta->execute(['id' => $id]);
$aluno = $consulta->fetch(PDO::FETCH_ASSOC);

if (!$aluno) {
    http_response_code(404);
    exit('Aluno não encontrado.');
}

$historico = $pdo->prepare(
    'SELECT a.acao, a.criado_em, u.nome AS responsavel
     FROM auditoria a
     LEFT JOIN usuarios u ON u.id = a.usuario_id
     WHERE a.entidade = :entidade AND a.entidade_id = :id
       AND a.acao IN (:criado, :editado, :inativado, :reativado)
     ORDER BY a.criado_em DESC, a.id DESC
     LIMIT 50'
);
$historico->execute([
    'entidade' => 'alunos',
    'id' => $id,
    'criado' => 'aluno_criado',
    'editado' => 'aluno_editado',
    'inativado' => 'aluno_inativado',
    'reativado' => 'aluno_reativado'
]);
$eventos = $historico->fetchAll(PDO::FETCH_ASSOC);

$rotulosEventos = [
    'aluno_criado' => 'Cadastro criado',
    'aluno_editado' => 'Dados cadastrais atualizados',
    'aluno_inativado' => 'Aluno inativado',
    'aluno_reativado' => 'Aluno reativado'
];
$ativo = (int) $aluno['ativo'] === 1;
$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Dados cadastrais e histórico de atividade do aluno no FitControl.">
    <title><?= escapar($aluno['nome']) ?> | FitControl</title>
    <link rel="stylesheet" href="style.css">
    <?= renderizarMonitorSessao() ?>
    <script src="theme.js"></script>
</head>
<body class="settings-body">
<main class="settings-page student-detail-page">
    <a class="settings-back" href="<?= escapar(urlListaAlunos($contextoLista)) ?>">← Voltar à listagem</a>
    <span class="eyebrow">PERFIL DO ALUNO · #<?= (int) $aluno['id'] ?></span>
    <div class="student-detail-heading">
        <div>
            <h1><?= escapar($aluno['nome']) ?></h1>
            <p class="settings-intro">Dados cadastrais e histórico de atividade.</p>
        </div>
        <span class="student-status <?= $ativo ? 'is-active' : 'is-inactive' ?>">
            <?= $ativo ? 'Ativo' : 'Inativo' ?>
        </span>
    </div>

    <?php if ($mensagem !== '') { ?>
        <div class="flash-message" role="status">
            <span class="flash-check" aria-hidden="true">✓</span>
            <?= escapar($mensagem) ?>
        </div>
    <?php } ?>

    <section class="settings-card detail-card" aria-labelledby="student-data-title">
        <div class="settings-card-heading">
            <span class="settings-icon" aria-hidden="true">◎</span>
            <div>
                <h2 id="student-data-title">Informações cadastrais</h2>
                <p>Dados atuais registrados no sistema.</p>
            </div>
        </div>
        <dl class="student-detail-grid">
            <div><dt>Nome completo</dt><dd><?= escapar($aluno['nome']) ?></dd></div>
            <div><dt>E-mail</dt><dd><a href="mailto:<?= escapar($aluno['email']) ?>"><?= escapar($aluno['email']) ?></a></dd></div>
            <div><dt>Telefone</dt><dd><a href="tel:<?= escapar(preg_replace('/[^0-9+]/', '', $aluno['telefone'])) ?>"><?= escapar($aluno['telefone']) ?></a></dd></div>
            <div><dt>Plano</dt><dd><?= escapar($aluno['plano']) ?></dd></div>
            <div><dt>Situação</dt><dd><?= $ativo ? 'Ativo' : 'Inativo' ?></dd></div>
        </dl>
        <div class="student-detail-actions">
            <a class="button button-primary" href="<?= escapar(urlCadastroAluno((int) $aluno['id'], $contextoLista)) ?>">Editar cadastro</a>
            <form action="alterar-status-aluno.php" method="post">
                <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
                <input type="hidden" name="id" value="<?= (int) $aluno['id'] ?>">
                <input type="hidden" name="busca" value="<?= escapar($contextoLista['busca']) ?>">
                <input type="hidden" name="status" value="<?= escapar($contextoLista['status']) ?>">
                <input type="hidden" name="retorno" value="detalhes">
                <input type="hidden" name="acao" value="<?= $ativo ? 'inativar' : 'reativar' ?>">
                <button class="button <?= $ativo ? 'button-search' : 'button-primary' ?>" type="submit">
                    <?= $ativo ? 'Inativar aluno' : 'Reativar aluno' ?>
                </button>
            </form>
        </div>
    </section>

    <section class="settings-card detail-card" aria-labelledby="student-history-title">
        <div class="settings-card-heading">
            <span class="settings-icon" aria-hidden="true">↻</span>
            <div>
                <h2 id="student-history-title">Histórico de atividade</h2>
                <p>Últimos <?= count($eventos) ?> eventos registrados para este aluno.</p>
            </div>
        </div>
        <?php if (count($eventos) === 0) { ?>
            <p class="student-history-empty">Nenhum evento de auditoria disponível para este cadastro.</p>
        <?php } else { ?>
            <ol class="student-timeline">
                <?php foreach ($eventos as $evento) { ?>
                    <li>
                        <span class="timeline-marker" aria-hidden="true"></span>
                        <div class="timeline-event">
                            <strong><?= escapar($rotulosEventos[$evento['acao']] ?? 'Atividade registrada') ?></strong>
                            <time datetime="<?= escapar(date(DATE_ATOM, strtotime($evento['criado_em']))) ?>">
                                <?= escapar(date('d/m/Y H:i:s', strtotime($evento['criado_em']))) ?>
                            </time>
                            <small><?= $evento['responsavel'] !== null ? 'Por ' . escapar($evento['responsavel']) : 'Responsável não identificado' ?></small>
                        </div>
                    </li>
                <?php } ?>
            </ol>
        <?php } ?>
    </section>
</main>
</body>
</html>
