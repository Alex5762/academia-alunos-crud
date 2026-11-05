<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';

// Sem ID: formulário novo. Com ID: formulário de edição.
$aluno = ['id' => '', 'nome' => '', 'telefone' => '', 'email' => '', 'plano' => 'Mensal'];
$editando = isset($_GET['id']);

if ($editando) {
    $id = validarId($_GET['id']);
    if ($id === false) {
        http_response_code(400);
        exit('ID inválido. Volte à lista.');
    }
    $aluno = buscarAluno($pdo, $id);
}

$erros = $_SESSION['erros'] ?? [];
$dados = $_SESSION['dados'] ?? null;
unset($_SESSION['erros'], $_SESSION['dados']);

// Após um erro, recupera os valores que a pessoa digitou.
if ($dados !== null && $dados['id'] === (string) $aluno['id']) {
    $aluno = $dados;
}

$titulo = $editando ? 'Editar aluno' : 'Cadastrar aluno';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escapar($titulo) ?></title>
    <link rel="stylesheet" href="style.css">
    <script src="script.js" defer></script>
</head>
<body>
<main>
    <h1><?= escapar($titulo) ?></h1>
    <p><a href="index.php">Voltar à lista</a></p>

    <?php if (count($erros) > 0) { ?>
        <ul class="erros" role="alert">
            <?php foreach ($erros as $erro) { ?>
                <li><?= escapar($erro) ?></li>
            <?php } ?>
        </ul>
    <?php } ?>

    <p id="mensagem-validacao" class="erros" role="alert" aria-live="polite"></p>

    <form action="salvar.php" method="post" id="form-aluno">
        <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
        <input type="hidden" name="id" value="<?= escapar($aluno['id']) ?>">

        <label for="nome">Nome completo:</label>
        <input type="text" id="nome" name="nome" maxlength="100" required
               value="<?= escapar($aluno['nome']) ?>">

        <label for="telefone">Telefone:</label>
        <input type="tel" id="telefone" name="telefone" maxlength="20" required
               value="<?= escapar($aluno['telefone']) ?>">

        <label for="email">E-mail:</label>
        <input type="email" id="email" name="email" maxlength="150" required
               value="<?= escapar($aluno['email']) ?>">

        <label for="plano">Plano:</label>
        <select id="plano" name="plano" required>
            <?php foreach (['Mensal', 'Trimestral', 'Semestral', 'Anual'] as $plano) { ?>
                <option value="<?= escapar($plano) ?>" <?= $aluno['plano'] === $plano ? 'selected' : '' ?>>
                    <?= escapar($plano) ?>
                </option>
            <?php } ?>
        </select>

        <button type="submit">Salvar aluno</button>
    </form>
</main>
</body>
</html>
