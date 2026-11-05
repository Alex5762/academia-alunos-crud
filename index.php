<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';

// Pesquisa opcional pelo nome; os valores são enviados como parâmetro.
$busca = $_GET['busca'] ?? '';
if (!is_string($busca)) {
    $busca = '';
}
$busca = trim($busca);

if ($busca !== '') {
    $consulta = $pdo->prepare('SELECT * FROM alunos WHERE nome LIKE :busca ORDER BY id DESC');
    $consulta->execute(['busca' => "%{$busca}%"]);
} else {
    $consulta = $pdo->query('SELECT * FROM alunos ORDER BY id DESC');
}
$alunos = $consulta->fetchAll();

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']); // Mostra a mensagem apenas uma vez.
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de alunos</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main>
    <h1>Cadastro de alunos</h1>
    <p><a href="cadastro.php">Cadastrar aluno</a></p>

    <form action="index.php" method="get" class="busca">
        <label for="busca">Pesquisar pelo nome:</label>
        <input type="search" id="busca" name="busca" value="<?= escapar($busca) ?>">
        <button type="submit">Pesquisar</button>
        <?php if ($busca !== '') { ?>
            <a href="index.php">Limpar pesquisa</a>
        <?php } ?>
    </form>

    <?php if ($mensagem !== '') { ?>
        <p class="mensagem" role="status"><?= escapar($mensagem) ?></p>
    <?php } ?>

    <?php if (count($alunos) === 0) { ?>
        <p>Nenhum aluno cadastrado.</p>
    <?php } else { ?>
        <div class="tabela">
        <table>
            <caption>Alunos cadastrados</caption>
            <thead>
                <tr>
                    <th scope="col">ID</th>
                    <th scope="col">Nome</th>
                    <th scope="col">Telefone</th>
                    <th scope="col">E-mail</th>
                    <th scope="col">Plano</th>
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
                        <td>
                            <a href="cadastro.php?id=<?= escapar($aluno['id']) ?>">Editar</a>
                            <a href="excluir.php?id=<?= escapar($aluno['id']) ?>">Excluir</a>
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
