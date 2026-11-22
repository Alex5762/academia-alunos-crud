<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método não permitido.');
}

$usuario = exigirAutenticacao($pdo);
exigirPermissao($usuario, 'users.manage');
validarToken();

$nomeRecebido = $_POST['nome'] ?? '';
$emailRecebido = $_POST['email'] ?? '';

if (!is_string($nomeRecebido) || !is_string($emailRecebido)) {
    http_response_code(400);
    exit('Dados inválidos.');
}

$nome = trim($nomeRecebido);
$email = strtolower(trim($emailRecebido));
$quantidadeNome = preg_match_all('/./us', $nome);

if ($quantidadeNome === false || $quantidadeNome < 1 || $quantidadeNome > 100) {
    $_SESSION['mensagem'] = 'Nome inválido. Informe de 1 a 100 caracteres.';
    redirecionar('usuarios.php');
}

if (strlen($email) > 150 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $_SESSION['mensagem'] = 'E-mail inválido. Confira o endereço informado.';
    redirecionar('usuarios.php');
}

try {
    // A senha temporária tem 192 bits aleatórios e só é exibida nesta resposta.
    $senhaTemporaria = rtrim(
        strtr(base64_encode(random_bytes(24)), '+/', '-_'),
        '='
    );
    $senhaHash = password_hash($senhaTemporaria, PASSWORD_DEFAULT);

    if ($senhaHash === false) {
        throw new RuntimeException('Não foi possível proteger a senha temporária.');
    }

    $pdo->beginTransaction();

    $inserir = $pdo->prepare(
        'INSERT INTO usuarios (nome, email, senha_hash, ativo, deve_trocar_senha)
         VALUES (:nome, :email, :senha_hash, 1, 1)'
    );
    $inserir->execute([
        'nome' => $nome,
        'email' => $email,
        'senha_hash' => $senhaHash
    ]);

    $novoUsuarioId = (int) $pdo->lastInsertId();

    registrarAuditoria(
        $pdo,
        (int) $usuario['id'],
        'usuario_criado',
        'usuarios',
        $novoUsuarioId
    );

    $pdo->commit();

    $_SESSION['mensagem'] = 'Conta criada com sucesso.';
    $_SESSION['senha_temporaria_criada'] = $senhaTemporaria;
    redirecionar('usuarios.php');
} catch (PDOException $falha) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($falha->getCode() === '23000') {
        $_SESSION['mensagem'] = 'Não foi possível criar a conta. Verifique se esse e-mail já está cadastrado.';
        redirecionar('usuarios.php');
    }

    error_log($falha->getMessage());
    http_response_code(500);
    exit('Não foi possível criar a conta. Consulte o log do servidor.');
} catch (Throwable $falha) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($falha->getMessage());
    http_response_code(500);
    exit('Não foi possível criar a conta. Consulte o log do servidor.');
}
