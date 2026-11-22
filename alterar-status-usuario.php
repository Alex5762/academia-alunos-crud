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

$id = validarId($_POST['id'] ?? null);
$acao = $_POST['acao'] ?? '';

if ($id === false || !is_string($acao) || !in_array($acao, ['desativar', 'reativar'], true)) {
    http_response_code(400);
    exit('Solicitação inválida.');
}

$usuarioAlvoId = (int) $id;
$usuarioAtualId = (int) $usuario['id'];

if ($acao === 'desativar' && $usuarioAlvoId === $usuarioAtualId) {
    $_SESSION['mensagem'] = 'Você não pode desativar sua própria conta.';
    redirecionar('usuarios.php');
}

try {
    $pdo->beginTransaction();

    // Trava as contas em ordem estável antes de contar/alterar status.
    // Isso serializa as mudanças concorrentes e reduz disputas de lock.
    $contas = $pdo->query(
        'SELECT id, ativo FROM usuarios ORDER BY id FOR UPDATE'
    )->fetchAll();

    $conta = null;
    $quantidadeAtivas = 0;

    foreach ($contas as $registro) {
        if ((int) $registro['ativo'] === 1) {
            $quantidadeAtivas++;
        }

        if ((int) $registro['id'] === $usuarioAlvoId) {
            $conta = $registro;
        }
    }

    if (!$conta) {
        $pdo->rollBack();
        $_SESSION['mensagem'] = 'Conta não encontrada.';
        redirecionar('usuarios.php');
    }

    $jaAtiva = (int) $conta['ativo'] === 1;

    if ($acao === 'desativar' && !$jaAtiva) {
        $pdo->commit();
        $_SESSION['mensagem'] = 'A conta já estava inativa.';
        redirecionar('usuarios.php');
    }

    if ($acao === 'reativar' && $jaAtiva) {
        $pdo->commit();
        $_SESSION['mensagem'] = 'A conta já estava ativa.';
        redirecionar('usuarios.php');
    }

    if ($acao === 'desativar') {
        if ($quantidadeAtivas <= 1) {
            $pdo->rollBack();
            $_SESSION['mensagem'] = 'Não é possível desativar a última conta ativa.';
            redirecionar('usuarios.php');
        }

        $atualizar = $pdo->prepare(
            'UPDATE usuarios SET ativo = 0 WHERE id = :id AND ativo = 1'
        );
        $atualizar->execute(['id' => $usuarioAlvoId]);
        $evento = 'usuario_desativado';
        $mensagem = 'Conta desativada. O histórico de auditoria foi preservado.';
    } else {
        $atualizar = $pdo->prepare(
            'UPDATE usuarios SET ativo = 1 WHERE id = :id AND ativo = 0'
        );
        $atualizar->execute(['id' => $usuarioAlvoId]);
        $evento = 'usuario_reativado';
        $mensagem = 'Conta reativada com sucesso.';
    }

    if ($atualizar->rowCount() !== 1) {
        throw new RuntimeException('O status da conta não foi alterado.');
    }

    registrarAuditoria(
        $pdo,
        $usuarioAtualId,
        $evento,
        'usuarios',
        $usuarioAlvoId
    );

    $pdo->commit();
    $_SESSION['mensagem'] = $mensagem;
    redirecionar('usuarios.php');
} catch (Throwable $falha) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($falha->getMessage());
    http_response_code(500);
    exit('Não foi possível alterar o status da conta. Tente novamente.');
}
