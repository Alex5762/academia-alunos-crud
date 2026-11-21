<?php
declare(strict_types=1);

require_once __DIR__ . '/../conexao.php';

$email = 'provision-ci@example.test';
$senha = trim(stream_get_contents(STDIN));

if ($senha === '') {
    fwrite(STDERR, "Senha de teste não recebida." . PHP_EOL);
    exit(1);
}

$consulta = $pdo->prepare(
    'SELECT id, senha_hash, ativo
     FROM usuarios
     WHERE email = :email'
);
$consulta->execute(['email' => $email]);
$usuario = $consulta->fetch();

if (!$usuario || (int) $usuario['ativo'] !== 1) {
    fwrite(STDERR, "Conta de teste não encontrada ou inativa." . PHP_EOL);
    exit(1);
}

if (!password_verify($senha, $usuario['senha_hash'])) {
    fwrite(STDERR, "A senha temporária não corresponde ao hash armazenado." . PHP_EOL);
    exit(1);
}

if ($usuario['senha_hash'] === $senha) {
    fwrite(STDERR, "A senha foi armazenada sem hash." . PHP_EOL);
    exit(1);
}

$auditoria = $pdo->prepare(
    "SELECT COUNT(*)
     FROM auditoria
     WHERE usuario_id IS NULL
       AND acao = 'usuario_provisionado_cli'
       AND entidade = 'usuarios'
       AND entidade_id = :usuario_id"
);
$auditoria->execute(['usuario_id' => $usuario['id']]);

if ((int) $auditoria->fetchColumn() !== 1) {
    fwrite(STDERR, "Evento de provisionamento não encontrado ou duplicado." . PHP_EOL);
    exit(1);
}

$totalContasTeste = $pdo->query(
    "SELECT COUNT(*) FROM usuarios WHERE email = 'provision-ci@example.test'"
)->fetchColumn();

if ((int) $totalContasTeste !== 1) {
    fwrite(STDERR, "Quantidade inesperada de contas de teste." . PHP_EOL);
    exit(1);
}

echo "Hash de senha e evento de auditoria validados." . PHP_EOL;
