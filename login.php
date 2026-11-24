<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/src/Security/PasswordPolicy.php';

if (usuarioAtual($pdo) !== null) {
    redirecionar('index.php');
}

$erro = '';
$sessaoExpirada = !empty($_SESSION['sessao_expirada']);
unset($_SESSION['sessao_expirada']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarToken();

    $emailRecebido = $_POST['email'] ?? '';
    $senhaRecebida = $_POST['senha'] ?? '';

    if (!is_string($emailRecebido) || !is_string($senhaRecebida)) {
        http_response_code(400);
        exit('Dados inválidos.');
    }

    $email = strtolower(trim($emailRecebido));
    $senha = $senhaRecebida;

    if (
        strlen($email) > 150 ||
        filter_var($email, FILTER_VALIDATE_EMAIL) === false ||
        $senha === '' ||
        strlen($senha) > \\FitControl\\Security\\PasswordPolicy::MAX_BYTES
    ) {
        $erro = 'E-mail ou senha inválidos.';
    } else {
        try {
            // Limite por combinação de e-mail e origem, sem guardar os valores puros.
            $ipOrigem = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            if (!is_string($ipOrigem) || filter_var($ipOrigem, FILTER_VALIDATE_IP) === false) {
                $ipOrigem = 'unknown';
            }

            $emailHash = hash('sha256', $email);
            $origemHash = hash('sha256', $ipOrigem);

            // Remove registros sem atividade recente para limitar o crescimento da tabela.
            $pdo->exec(
                'DELETE FROM login_tentativas
                 WHERE atualizado_em < DATE_SUB(NOW(), INTERVAL 1 DAY)'
            );

            $consultarLimite = $pdo->prepare(
                'SELECT (bloqueado_ate IS NOT NULL AND bloqueado_ate > NOW()) AS bloqueado
                 FROM login_tentativas
                 WHERE email_hash = :email_hash AND origem_hash = :origem_hash
                 LIMIT 1'
            );
            $consultarLimite->execute([
                'email_hash' => $emailHash,
                'origem_hash' => $origemHash
            ]);
            $bloqueado = (int) ($consultarLimite->fetchColumn() ?: 0) === 1;

            if ($bloqueado) {
                $erro = 'E-mail ou senha inválidos.';
            } else {
                $consulta = $pdo->prepare(
                    'SELECT id, nome, email, senha_hash, ativo, deve_trocar_senha
                     FROM usuarios
                     WHERE email = :email
                     LIMIT 1'
                );
                $consulta->execute(['email' => $email]);
                $usuario = $consulta->fetch();

                $credenciaisValidas = $usuario
                    && (int) $usuario['ativo'] === 1
                    && password_verify($senha, $usuario['senha_hash']);

                if (!$credenciaisValidas) {
                    // Janela móvel fixa de 15 minutos; a 5ª falha bloqueia por 15 minutos.
                    // O incremento é atômico para que requisições paralelas não percam tentativas.
                    $registrarFalha = $pdo->prepare(
                        'INSERT INTO login_tentativas
                            (email_hash, origem_hash, tentativas, janela_inicio, bloqueado_ate)
                         VALUES
                            (:email_hash, :origem_hash, 1, NOW(), NULL)
                         ON DUPLICATE KEY UPDATE
                            tentativas = IF(
                                janela_inicio <= DATE_SUB(NOW(), INTERVAL 15 MINUTE),
                                1,
                                tentativas + 1
                            ),
                            bloqueado_ate = IF(
                                tentativas >= 5,
                                DATE_ADD(NOW(), INTERVAL 15 MINUTE),
                                IF(
                                    janela_inicio <= DATE_SUB(NOW(), INTERVAL 15 MINUTE),
                                    NULL,
                                    bloqueado_ate
                                )
                            ),
                            janela_inicio = IF(
                                janela_inicio <= DATE_SUB(NOW(), INTERVAL 15 MINUTE),
                                NOW(),
                                janela_inicio
                            )'
                    );
                    $registrarFalha->execute([
                        'email_hash' => $emailHash,
                        'origem_hash' => $origemHash
                    ]);

                    $erro = 'E-mail ou senha inválidos.';
                } else {
                    if (!session_regenerate_id(true)) {
                        throw new RuntimeException('Não foi possível renovar a sessão.');
                    }

                    $pdo->beginTransaction();
                    registrarAuditoria(
                        $pdo,
                        (int) $usuario['id'],
                        'login',
                        'usuarios',
                        (int) $usuario['id']
                    );

                    // Um login válido limpa o contador dessa combinação.
                    $limparFalhas = $pdo->prepare(
                        'DELETE FROM login_tentativas
                         WHERE email_hash = :email_hash AND origem_hash = :origem_hash'
                    );
                    $limparFalhas->execute([
                        'email_hash' => $emailHash,
                        'origem_hash' => $origemHash
                    ]);
                    $pdo->commit();

                    // Limpa qualquer estado pré-autenticação e cria novo token CSRF.
                    $_SESSION = [];
                    $instanteAutenticacao = time();
                    $_SESSION['usuario_id'] = (int) $usuario['id'];
                    $_SESSION['usuario_autenticado_em'] = $instanteAutenticacao;
                    $_SESSION['usuario_ultimo_acesso'] = $instanteAutenticacao;
                    $_SESSION['token'] = bin2hex(random_bytes(32));

                    redirecionar((int) $usuario['deve_trocar_senha'] === 1 ? 'trocar-senha.php' : 'index.php');
                }
            }
        } catch (Throwable $falha) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($falha->getMessage());
            http_response_code(500);
            exit('Não foi possível concluir o login. Tente novamente.');
        }
    }
}

$token = escapar($_SESSION['token']);
$emailPreenchido = isset($_POST['email']) && is_string($_POST['email']) ? $_POST['email'] : '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Acesso ao painel administrativo FitControl.">
    <title>Entrar | FitControl</title>
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
</head>
<body class="login-body">
<main class="login-page">
    <a class="login-brand" href="login.php" aria-label="FitControl">
        <span class="brand-mark" aria-hidden="true">
            <svg viewBox="0 0 32 32" fill="none">
                <path d="M8 11.5h16M8 20.5h16M11 8v16M21 8v16" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
            </svg>
        </span>
        <span class="brand-copy">
            <strong>fitcontrol</strong>
            <small>GESTÃO DE ACADEMIA</small>
        </span>
    </a>

    <section class="login-card" aria-labelledby="login-title">
        <span class="eyebrow">ÁREA ADMINISTRATIVA</span>
        <h1 id="login-title">Bem-vindo de volta</h1>
        <p class="login-intro">Entre com sua conta individual para acessar o painel.</p>

        <?php if ($sessaoExpirada) { ?>
            <p class="login-error" role="status">Sua sessão expirou por inatividade ou pelo tempo máximo de acesso. Entre novamente.</p>
        <?php } ?>
        <?php if ($erro !== '') { ?>
            <p class="login-error" role="alert"><?= escapar($erro) ?></p>
        <?php } ?>

        <form action="login.php" method="post" class="login-form">
            <input type="hidden" name="token" value="<?= $token ?>">

            <label for="email">E-mail</label>
            <input
                type="email"
                id="email"
                name="email"
                maxlength="150"
                autocomplete="username"
                inputmode="email"
                required
                autofocus
                value="<?= escapar($emailPreenchido) ?>"
            >

            <label for="senha">Senha</label>
            <input
                type="password"
                id="senha"
                name="senha"
                autocomplete="current-password"
                maxlength="72"
                required
            >

            <button class="button button-primary login-submit" type="submit">Entrar no painel</button>
        </form>
        <p class="login-footnote">Acesso restrito a funcionários cadastrados.</p>
    </section>

    <footer class="login-footer">FitControl · Gestão de academia</footer>
</main>
</body>
</html>
