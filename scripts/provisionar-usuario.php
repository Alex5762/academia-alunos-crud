<?php
declare(strict_types=1);

// Este arquivo deve ser executado somente pelo terminal do servidor.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Recurso não encontrado.');
}

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/../src/Security/AuditIntegrityChain.php';

/**
 * Lê um campo não secreto da entrada padrão.
 */
function lerEntrada(string $rotulo): string
{
    fwrite(STDOUT, $rotulo);
    $valor = fgets(STDIN);

    return $valor === false ? '' : trim($valor);
}

function encerrarComErro(string $mensagem, int $codigo = 1): never
{
    fwrite(STDERR, $mensagem . PHP_EOL);
    exit($codigo);
}

$nome = lerEntrada('Nome do funcionário: ');
$email = strtolower(lerEntrada('E-mail do funcionário: '));

$quantidadeNome = preg_match_all('/./us', $nome);
if ($quantidadeNome === false || $quantidadeNome < 1 || $quantidadeNome > 100) {
    encerrarComErro('Nome inválido. Informe de 1 a 100 caracteres.');
}

if (
    strlen($email) > 150 ||
    filter_var($email, FILTER_VALIDATE_EMAIL) === false
) {
    encerrarComErro('E-mail inválido. Confira o endereço informado.');
}

try {
    // A senha é gerada no servidor, não é digitada, passada como argumento
    // nem armazenada em arquivo. São 192 bits aleatórios em formato URL-safe.
    $senhaTemporaria = rtrim(
        strtr(base64_encode(random_bytes(24)), '+/', '-_'),
        '='
    );
    $senhaHash = password_hash($senhaTemporaria, PASSWORD_DEFAULT);

    if ($senhaHash === false) {
        throw new RuntimeException('Não foi possível gerar o hash da senha.');
    }

    $pdo->beginTransaction();

    $inserirUsuario = $pdo->prepare(
        "INSERT INTO usuarios (nome, email, senha_hash, ativo, perfil, deve_trocar_senha)
         VALUES (:nome, :email, :senha_hash, 1, 'ADMINISTRADOR', 1)"
    );
    $inserirUsuario->execute([
        'nome' => $nome,
        'email' => $email,
        'senha_hash' => $senhaHash
    ]);

    $usuarioId = (int) $pdo->lastInsertId();

    // O provisionamento local não tem sessão web; a autoria fica explicitamente nula.
    \\FitControl\\Security\\AuditIntegrityChain::append(
        $pdo,
        null,
        'usuario_provisionado_cli',
        'usuarios',
        $usuarioId
    );

    $pdo->commit();

    fwrite(STDOUT, PHP_EOL . 'Conta criada com sucesso.' . PHP_EOL);
    fwrite(STDOUT, 'E-mail: ' . $email . PHP_EOL);
    fwrite(STDOUT, 'Senha temporária (copie agora): ' . $senhaTemporaria . PHP_EOL);
    fwrite(STDOUT, 'A senha não será exibida novamente.' . PHP_EOL);
} catch (PDOException $erro) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($erro->getCode() === '23000') {
        encerrarComErro('Não foi possível criar a conta. Verifique se esse e-mail já está cadastrado.');
    }

    error_log($erro->getMessage());
    encerrarComErro('Falha ao criar a conta. Consulte o log do servidor.');
} catch (Throwable $erro) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($erro->getMessage());
    encerrarComErro('Falha ao criar a conta. Consulte o log do servidor.');
}
