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

$idRecebido = $_POST['id'] ?? null;
$perfilRecebido = $_POST['perfil'] ?? null;
$id = is_string($idRecebido) ? validarId($idRecebido) : false;

require_once __DIR__ . '/src/Security/AccessControl.php';
$perfisPermitidos = \FitControl\Security\AccessControl::roles();

if (
    $id === false
    || !is_string($perfilRecebido)
    || !in_array($perfilRecebido, $perfisPermitidos, true)
) {
    http_response_code(400);
    exit('Solicitação inválida.');
}

$usuarioAlvoId = (int) $id;
$usuarioAtualId = (int) $usuario['id'];

if ($usuarioAlvoId === $usuarioAtualId) {
    $_SESSION['mensagem'] = 'Você não pode alterar o próprio perfil.';
    redirecionar('usuarios.php');
}

try {
    $pdo->beginTransaction();

    // Ordem estável de bloqueio; evita corrida ao preservar o último administrador.
    $contas = $pdo->query(
        'SELECT id, ativo, perfil FROM usuarios ORDER BY id FOR UPDATE'
    )->fetchAll(PDO::FETCH_ASSOC);

    $contaAlvo = null;
    $administradoresAtivos = 0;

    foreach ($contas as $conta) {
        if ((int) $conta['ativo'] === 1 && $conta['perfil'] === 'ADMINISTRADOR') {
            $administradoresAtivos++;
        }

        if ((int) $conta['id'] === $usuarioAlvoId) {
            $contaAlvo = $conta;
        }
    }

    if ($contaAlvo === null) {
        $pdo->rollBack();
        http_response_code(404);
        exit('Funcionário não encontrado.');
    }

    $perfilAtual = (string) $contaAlvo['perfil'];
    if ($perfilAtual === $perfilRecebido) {
        $pdo->commit();
        $_SESSION['mensagem'] = 'O funcionário já possui esse perfil.';
        redirecionar('usuarios.php');
    }

    if (
        (int) $contaAlvo['ativo'] === 1
        && $perfilAtual === 'ADMINISTRADOR'
        && $perfilRecebido !== 'ADMINISTRADOR'
        && $administradoresAtivos <= 1
    ) {
        $pdo->rollBack();
        $_SESSION['mensagem'] = 'Não é possível remover o perfil do último administrador ativo.';
        redirecionar('usuarios.php');
    }

    $atualizar = $pdo->prepare(
        'UPDATE usuarios SET perfil = :perfil WHERE id = :id AND perfil = :perfil_atual'
    );
    $atualizar->execute([
        'perfil' => $perfilRecebido,
        'id' => $usuarioAlvoId,
        'perfil_atual' => $perfilAtual,
    ]);

    if ($atualizar->rowCount() !== 1) {
        throw new RuntimeException('O perfil do funcionário não foi alterado.');
    }

    $registrarHistoricoPerfil = $pdo->prepare(
        'INSERT INTO usuarios_perfis_eventos
            (usuario_id, responsavel_id, perfil_anterior, perfil_novo)
         VALUES (:usuario_id, :responsavel_id, :perfil_anterior, :perfil_novo)'
    );
    $registrarHistoricoPerfil->execute([
        'usuario_id' => $usuarioAlvoId,
        'responsavel_id' => $usuarioAtualId,
        'perfil_anterior' => $perfilAtual,
        'perfil_novo' => $perfilRecebido,
    ]);

    registrarAuditoria(
        $pdo,
        $usuarioAtualId,
        'usuario_perfil_alterado',
        'usuarios',
        $usuarioAlvoId
    );

    $pdo->commit();
    $_SESSION['mensagem'] = 'Perfil do funcionário atualizado com sucesso.';
    redirecionar('usuarios.php');
} catch (Throwable $falha) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($falha->getMessage());
    http_response_code(500);
    exit('Não foi possível alterar o perfil do funcionário. Tente novamente.');
}
