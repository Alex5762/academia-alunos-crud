<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';

$usuario = exigirAutenticacao($pdo);
exigirPermissao($usuario, 'students.view');

$contextoLista = obterContextoListaAlunos($_GET);
$idRecebido = $_GET['id'] ?? null;
$id = is_string($idRecebido) ? validarId($idRecebido) : false;

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
     WHERE a.entidade = :entidade
       AND a.entidade_id = :id
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
    'reativado' => 'aluno_reativado',
]);
$eventos = $historico->fetchAll(PDO::FETCH_ASSOC);

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);

$rotulosEventos = [
    'aluno_criado' => 'Aluno cadastrado',
    'aluno_editado' => 'Cadastro editado',
    'aluno_inativado' => 'Aluno inativado',
    'aluno_reativado' => 'Aluno reativado',
];

$alunoAtivo = (int) $aluno['ativo'] === 1;
$tituloStatus = $alunoAtivo ? 'Ativo' : 'Inativo';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalhes do aluno | Cadastro de alunos</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="auth.css">
    <?= renderizarMonitorSessao() ?>
</head>
<body>
<main>
    <h1>Detalhes do aluno</h1>
    <p><a href="<?= escapar(urlListaAlunos($contextoLista)) ?>">Voltar à lista</a></p>

    <?php if ($mensagem !== '') { ?>
        <p class="mensagem" role="status"><?= escapar($mensagem) ?></p>
    <?php } ?>

    <section aria-labelledby="dados-aluno-titulo">
        <h2 id="dados-aluno-titulo"><?= escapar($aluno['nome']) ?></h2>
        <p>
            Situação:
            <strong class="status-badge <?= $alunoAtivo ? 'status-ativo' : 'status-inativo' ?>">
                <?= escapar($tituloStatus) ?>
            </strong>
        </p>
        <dl class="dados-aluno">
            <dt>ID</dt>
            <dd><?= escapar($aluno['id']) ?></dd>
            <dt>Telefone</dt>
            <dd><?= escapar($aluno['telefone']) ?></dd>
            <dt>E-mail</dt>
            <dd><?= escapar($aluno['email']) ?></dd>
            <dt>Plano</dt>
            <dd><?= escapar($aluno['plano']) ?></dd>
        </dl>
    </section>

    <nav class="acoes-aluno" aria-label="Ações do aluno">
        <?php if (usuarioTemPermissao($usuario, 'students.update')) { ?>
            <a href="<?= escapar(urlCadastroAluno((int) $aluno['id'], $contextoLista)) ?>">Editar cadastro</a>
        <?php } ?>

        <?php if (usuarioTemPermissao($usuario, 'students.status')) { ?>
            <form action="alterar-status-aluno.php" method="post">
                <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
                <input type="hidden" name="id" value="<?= escapar($aluno['id']) ?>">
                <input type="hidden" name="busca" value="<?= escapar($contextoLista['busca']) ?>">
                <input type="hidden" name="status" value="<?= escapar($contextoLista['status']) ?>">
                <input type="hidden" name="retorno" value="detalhes">
                <input type="hidden" name="acao" value="<?= $alunoAtivo ? 'inativar' : 'reativar' ?>">
                <button type="submit" class="<?= $alunoAtivo ? 'perigo' : '' ?>">
                    <?= $alunoAtivo ? 'Inativar aluno' : 'Reativar aluno' ?>
                </button>
            </form>
        <?php } ?>
    </nav>

    <section aria-labelledby="historico-aluno-titulo">
        <h2 id="historico-aluno-titulo">Histórico de atividades</h2>
        <?php if ($eventos === []) { ?>
            <p>Nenhum evento de histórico disponível.</p>
        <?php } else { ?>
            <ol class="historico-aluno">
                <?php foreach ($eventos as $evento) { ?>
                    <li>
                        <strong><?= escapar($rotulosEventos[$evento['acao']] ?? 'Evento registrado') ?></strong>
                        <span><?= escapar(formatarInstanteUtcNaAcademia($evento['criado_em'])) ?></span>
                        <span>Por <?= escapar($evento['responsavel'] ?? 'Não informado') ?></span>
                    </li>
                <?php } ?>
            </ol>
        <?php } ?>
        <p class="observacao-historico">
            O histórico registra os eventos disponíveis, sem comparação de valores antigos e novos dos campos.
        </p>
    </section>
</main>
</body>
</html>
