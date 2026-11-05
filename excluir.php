<?php
require_once __DIR__ . '/funcoes.php';

$metodo = $_SERVER['REQUEST_METHOD'];
if ($metodo !== 'GET' && $metodo !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Método não permitido.');
}

if ($metodo === 'POST') {
    validarToken();
}

require_once __DIR__ . '/conexao.php';
$valorId = $metodo === 'POST' ? ($_POST['id'] ?? '') : ($_GET['id'] ?? '');
$id = validarId($valorId);

if ($id === false) {
    http_response_code(400);
    exit('ID inválido. Volte à lista.');
}

$aluno = buscarAluno($pdo, $id);

if ($metodo === 'POST') {
    try {
        $comando = $pdo->prepare('DELETE FROM alunos WHERE id = :id');
        $comando->execute(['id' => $id]);
    } catch (PDOException $erro) {
        error_log($erro->getMessage());
        http_response_code(500);
        exit('Não foi possível excluir. Consulte o registro de erros do Apache.');
    }

    $_SESSION['mensagem'] = 'Aluno excluído com sucesso!';
    redirecionar('index.php');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Excluir aluno</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main>
    <h1>Excluir aluno</h1>
    <p>Deseja excluir <strong><?= escapar($aluno['nome']) ?></strong>?</p>
    <p>Esta operação remove o cadastro do banco de dados.</p>

    <form action="excluir.php" method="post">
        <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
        <input type="hidden" name="id" value="<?= escapar($aluno['id']) ?>">
        <button type="submit" class="perigo">Confirmar exclusão</button>
        <a href="index.php">Cancelar e voltar</a>
    </form>
</main>
</body>
</html>
