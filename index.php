<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';

$usuario = exigirAutenticacao($pdo);
exigirPermissao($usuario, 'students.view');
$contexto = obterContextoListaAlunos($_GET);
$busca = $contexto['busca'];
$status = $contexto['status'];

$condicoes = [];
$parametros = [];
if ($busca !== '') {
    $condicoes[] = '(nome LIKE :nome OR email LIKE :email OR telefone LIKE :telefone)';
    $termo = '%' . $busca . '%';
    $parametros = ['nome' => $termo, 'email' => $termo, 'telefone' => $termo];
}
if ($status === 'ativos') {
    $condicoes[] = 'ativo = 1';
} elseif ($status === 'inativos') {
    $condicoes[] = 'ativo = 0';
}
$sql = 'SELECT id, nome, telefone, email, plano, ativo FROM alunos';
if ($condicoes !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $condicoes);
}
$sql .= ' ORDER BY id DESC';
$consulta = $pdo->prepare($sql);
$consulta->execute($parametros);
$alunos = $consulta->fetchAll(PDO::FETCH_ASSOC);

$totais = $pdo->query(
    'SELECT COUNT(*) AS total,
            COALESCE(SUM(ativo = 1), 0) AS ativos,
            COALESCE(SUM(ativo = 0), 0) AS inativos
     FROM alunos'
)->fetch(PDO::FETCH_ASSOC);

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de alunos</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="auth.css">
    <?= renderizarMonitorSessao() ?>
</head>
<body>
<main id="alunos">
    <h1>Cadastro de alunos</h1>
    <nav class="auth-nav" aria-label="Conta">
        <span>Olá, <?= escapar($usuario['nome']) ?></span>
        <a href="alterar-senha.php">Alterar senha</a>
        <?php if (usuarioTemPermissao($usuario, 'students.create')) { ?>
            <a href="<?= escapar(urlCadastroAluno(null, $contexto)) ?>">Cadastrar aluno</a>
        <?php } ?>
        <?php if (usuarioTemPermissao($usuario, 'users.manage')) { ?>
            <a href="usuarios.php">Gerenciar funcionários</a>
        <?php } ?>
        <?php if (usuarioTemPermissao($usuario, 'plans.view')) { ?>
            <a href="planos.php">Planos e matrículas</a>
        <?php } ?>
        <?php if (usuarioTemPermissao($usuario, 'legacy.view')) { ?>
            <a href="revisoes-legado.php">Revisão de vínculos antigos</a>
        <?php } ?>
        <form action="logout.php" method="post" class="logout-form">
            <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
            <button type="submit">Sair</button>
        </form>
    </nav>

    <section class="student-summary" aria-label="Resumo de alunos">
        <p><strong>Alunos ativos:</strong> <?= escapar($totais['ativos']) ?></p>
        <p><strong>Alunos inativos:</strong> <?= escapar($totais['inativos']) ?></p>
        <p><strong>Total cadastrado:</strong> <?= escapar($totais['total']) ?></p>
    </section>

    <?php if ($mensagem !== '') { ?>
        <p class="mensagem" role="status"><?= escapar($mensagem) ?></p>
    <?php } ?>

    <form action="index.php" method="get" class="busca" aria-label="Pesquisar e filtrar alunos">
        <label for="busca">Pesquisar por nome, e-mail ou telefone:</label>
        <input type="search" id="busca" name="busca" value="<?= escapar($busca) ?>">
        <label for="status">Situação</label>
        <select id="status" name="status">
            <option value="ativos" <?= $status === 'ativos' ? 'selected' : '' ?>>Ativos</option>
            <option value="inativos" <?= $status === 'inativos' ? 'selected' : '' ?>>Inativos</option>
            <option value="todos" <?= $status === 'todos' ? 'selected' : '' ?>>Todos</option>
        </select>
        <button type="submit">Aplicar filtros</button>
        <?php if ($busca !== '' || $status !== 'ativos') { ?>
            <a href="index.php">Limpar filtros</a>
        <?php } ?>
    </form>

    <?php if ($alunos === []) { ?>
        <p role="status">Nenhum aluno encontrado com os filtros atuais.</p>
    <?php } else { ?>
        <div class="tabela">
            <table>
                <caption>Alunos encontrados: <?= count($alunos) ?></caption>
                <thead>
                    <tr>
                        <th scope="col">ID</th>
                        <th scope="col">Nome</th>
                        <th scope="col">Telefone</th>
                        <th scope="col">E-mail</th>
                        <th scope="col">Plano</th>
                        <th scope="col">Situação</th>
                        <th scope="col">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($alunos as $aluno) { ?>
                        <tr>
                            <td><?= escapar($aluno['id']) ?></td>
                            <td><?= escapar($aluno['nome']) ?></td>
                            <td><?= escapar($aluno['telefone']) ?></td>
                            <td><?= escapar($aluno['email']) ?></td>
                            <td><?= escapar($aluno['plano']) ?></td>
                            <td><?= (int) $aluno['ativo'] === 1 ? 'Ativo' : 'Inativo' ?></td>
                            <td>
                                <a href="<?= escapar(urlDetalhesAluno((int) $aluno['id'], $contexto)) ?>">Detalhes</a>
                                <?php if (usuarioTemPermissao($usuario, 'students.update')) { ?>
                                    <a href="<?= escapar(urlCadastroAluno((int) $aluno['id'], $contexto)) ?>">Editar</a>
                                <?php } ?>
                                <?php if (usuarioTemPermissao($usuario, 'students.status')) { ?>
                                    <form action="alterar-status-aluno.php" method="post" class="inline-form">
                                        <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
                                        <input type="hidden" name="id" value="<?= escapar($aluno['id']) ?>">
                                        <input type="hidden" name="busca" value="<?= escapar($busca) ?>">
                                        <input type="hidden" name="status" value="<?= escapar($status) ?>">
                                        <input type="hidden" name="acao" value="<?= (int) $aluno['ativo'] === 1 ? 'inativar' : 'reativar' ?>">
                                        <button type="submit" class="<?= (int) $aluno['ativo'] === 1 ? 'perigo' : '' ?>">
                                            <?= (int) $aluno['ativo'] === 1 ? 'Inativar' : 'Reativar' ?>
                                        </button>
                                    </form>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</main>
</body>
</html>
