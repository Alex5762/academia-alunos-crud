<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';

$usuario = usuarioAtual($pdo);
if ($usuario === null) {
    redirecionar('login.php');
}

$consultaEstado = $pdo->prepare(
    'SELECT deve_trocar_senha FROM usuarios WHERE id = :id AND ativo = 1 LIMIT 1'
);
$consultaEstado->execute(['id' => $usuario['id']]);
$deveTrocarSenha = (int) $consultaEstado->fetchColumn() === 1;

if (!$deveTrocarSenha) {
    redirecionar('index.php');
}

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarToken();

    $senhaAtual = $_POST['senha_atual'] ?? '';
    $novaSenha = $_POST['nova_senha'] ?? '';
    $confirmacao = $_POST['confirmacao'] ?? '';

    if (!is_string($senhaAtual) || !is_string($novaSenha) || !is_string($confirmacao)) {
        http_response_code(400);
        exit('Dados inválidos.');
    }

    if (strlen($novaSenha) < 12 || strlen($novaSenha) > 4096) {
        $erro = 'A nova senha deve ter pelo menos 12 caracteres.';
    } elseif (!hash_equals($novaSenha, $confirmacao)) {
        $erro = 'A confirmação não corresponde à nova senha.';
    } else {
        try {
            $buscar = $pdo->prepare(
                'SELECT senha_hash, deve_trocar_senha
                 FROM usuarios WHERE id = :id AND ativo = 1 LIMIT 1'
            );
            $buscar->execute(['id' => $usuario['id']]);
            $conta = $buscar->fetch();

            if (!$conta || (int) $conta['deve_trocar_senha'] !== 1 ||
                !password_verify($senhaAtual, $conta['senha_hash'])) {
                $erro = 'A senha atual está incorreta.';
            } elseif (password_verify($novaSenha, $conta['senha_hash'])) {
                $erro = 'Escolha uma senha diferente da senha temporária.';
            } else {
                $novoHash = password_hash($novaSenha, PASSWORD_DEFAULT);
                if ($novoHash === false) {
                    throw new RuntimeException('Não foi possível proteger a nova senha.');
                }

                $pdo->beginTransaction();
                $atualizar = $pdo->prepare(
                    'UPDATE usuarios
                     SET senha_hash = :senha_hash, deve_trocar_senha = 0
                     WHERE id = :id AND ativo = 1 AND deve_trocar_senha = 1'
                );
                $atualizar->execute([
                    'senha_hash' => $novoHash,
                    'id' => $usuario['id']
                ]);

                if ($atualizar->rowCount() !== 1) {
                    throw new RuntimeException('A conta não pôde ser atualizada.');
                }

                registrarAuditoria(
                    $pdo,
                    (int) $usuario['id'],
                    'senha_alterada_primeiro_acesso',
                    'usuarios',
                    (int) $usuario['id']
                );
                $pdo->commit();

                if (!session_regenerate_id(true)) {
                    throw new RuntimeException('Não foi possível renovar a sessão.');
                }

                $_SESSION['token'] = bin2hex(random_bytes(32));
                redirecionar('index.php');
            }
        } catch (Throwable $falha) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($falha->getMessage());
            http_response_code(500);
            exit('Não foi possível atualizar a senha. Tente novamente.');
        }
    }
}

$token = escapar($_SESSION['token']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Defina uma senha pessoal para sua conta FitControl.">
    <title>Trocar senha | FitControl</title>
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
</head>
<body class="login-body">
<main class="login-page">
    <section class="login-card" aria-labelledby="password-title">
        <span class="eyebrow">PRIMEIRO ACESSO</span>
        <h1 id="password-title">Crie sua senha pessoal</h1>
        <p class="login-intro">Antes de continuar, substitua a senha temporária por uma senha que só você conheça.</p>
        <?php if ($erro !== '') { ?>
            <p class="login-error" role="alert"><?= escapar($erro) ?></p>
        <?php } ?>
        <form action="trocar-senha.php" method="post" class="login-form">
            <input type="hidden" name="token" value="<?= $token ?>">
            <label for="senha-atual">Senha temporária</label>
            <input type="password" id="senha-atual" name="senha_atual" autocomplete="current-password" required maxlength="4096>
            <label for="nova-senha">Nova senha</label>
            <input type="password" id="nova-senha" name="nova_senha" autocomplete="new-password" minlength="12" maxlength="4096" required>
            <label for="confirmacao">Confirme a nova senha</label>
            <input type="password" id="confirmacao" name="confirmacao" autocomplete="new-password" minlength="12" maxlength="4096" required>
            <button class="button button-primary login-submit" type="submit">Salvar nova senha</button>
        </form>
        <p class="login-footnote">Use pelo menos 12 caracteres e não reutilize a senha temporária.</p>
    </section>
</main>
</body>
</html>
