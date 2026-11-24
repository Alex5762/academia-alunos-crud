<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/src/Security/PasswordPolicy.php';
$usuario = exigirAutenticacao($pdo);
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarToken();
    $senhaAtual = $_POST['senha_atual'] ?? '';
    $novaSenha = $_POST['nova_senha'] ?? '';
    $confirmacao = $_POST['confirmacao'] ?? '';

    if (!is_string($senhaAtual) || !is_string($novaSenha) || !is_string($confirmacao)) {
        http_response_code(400);
        exit('Dados inválidos.');
    }

    $erroPolitica = \FitControl\Security\PasswordPolicy::validationError($novaSenha);

    if (strlen($senhaAtual) > 4096 || strlen($novaSenha) > 4096 || strlen($confirmacao) > 4096) {
        $erro = 'Não foi possível validar os dados informados.';
    } elseif ($erroPolitica !== null) {
        $erro = $erroPolitica;
    } elseif (!hash_equals($novaSenha, $confirmacao)) {
        $erro = 'A confirmação não corresponde à nova senha.';
    } else {
        try {
            $pdo->beginTransaction();
            $buscar = $pdo->prepare(
                'SELECT senha_hash FROM usuarios
                 WHERE id = :id AND ativo = 1 AND deve_trocar_senha = 0
                 LIMIT 1 FOR UPDATE'
            );
            $buscar->execute(['id' => $usuario['id']]);
            $conta = $buscar->fetch();

            if (!$conta) {
                $pdo->rollBack();
                invalidarSessaoAutenticada();
                redirecionar('login.php');
            }
            if (!password_verify($senhaAtual, $conta['senha_hash'])) {
                $pdo->rollBack();
                $erro = 'A senha atual está incorreta.';
            } elseif (password_verify($novaSenha, $conta['senha_hash'])) {
                $pdo->rollBack();
                $erro = 'Escolha uma senha diferente da senha atual.';
            } else {
                $hash = \FitControl\Security\PasswordPolicy::hash($novaSenha);
                $atualizar = $pdo->prepare(
                    'UPDATE usuarios SET senha_hash = :hash
                     WHERE id = :id AND ativo = 1 AND deve_trocar_senha = 0'
                );
                $atualizar->execute(['hash' => $hash, 'id' => $usuario['id']]);
                if ($atualizar->rowCount() !== 1) {
                    throw new RuntimeException('A senha não pôde ser atualizada.');
                }
                registrarAuditoria($pdo, (int) $usuario['id'], 'senha_alterada', 'usuarios', (int) $usuario['id']);
                $pdo->commit();

                if (!session_regenerate_id(true)) {
                    invalidarSessaoAutenticada();
                    redirecionar('login.php');
                }
                $_SESSION['token'] = bin2hex(random_bytes(32));
                $_SESSION['usuario_ultimo_acesso'] = time();
                $_SESSION['mensagem'] = 'Senha alterada com sucesso.';
                redirecionar('index.php');
            }
        } catch (Throwable $falha) {
            if ($pdo->inTransaction()) $pdo->rollBack();
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
    <meta name="description" content="Altere a senha da sua conta FitControl.">
    <title>Alterar senha | FitControl</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="auth.css">
    <?= renderizarMonitorSessao() ?>
    <script src="theme.js"></script>
</head>
<body class="login-body">
<main class="login-page">
    <section class="login-card" aria-labelledby="password-title">
        <a class="settings-back" href="configuracoes.php">← Voltar às configurações</a>
        <span class="eyebrow">SEGURANÇA DA CONTA</span>
        <h1 id="password-title">Alterar senha</h1>
        <p class="login-intro">Confirme sua senha atual e escolha uma nova senha pessoal.</p>
        <?php if ($erro !== '') { ?>
            <p class="login-error" role="alert"><?= escapar($erro) ?></p>
        <?php } ?>
        <form action="alterar-senha.php" method="post" class="login-form">
            <input type="hidden" name="token" value="<?= $token ?>">
            <label for="senha-atual">Senha atual</label>
            <input type="password" id="senha-atual" name="senha_atual" autocomplete="current-password" maxlength="4096" required>
            <label for="nova-senha">Nova senha</label>
            <input type="password" id="nova-senha" name="nova_senha" autocomplete="new-password" minlength="12" maxlength="72" required>
            <label for="confirmacao">Confirme a nova senha</label>
            <input type="password" id="confirmacao" name="confirmacao" autocomplete="new-password" minlength="12" maxlength="72" required>
            <button class="button button-primary login-submit" type="submit">Salvar nova senha</button>
        </form>
        <p class="login-footnote">Use pelo menos 12 caracteres e até 72 bytes UTF-8. Não reutilize a senha atual.</p>
    </section>
</main>
</body>
</html>
