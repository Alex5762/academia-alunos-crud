<?php
require_once __DIR__ . '/funcoes.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método não permitido.');
}

require_once __DIR__ . '/conexao.php';
$usuario = exigirAutenticacao($pdo);
validarToken();
$contextoLista = obterContextoListaAlunos($_POST);

$valorId = $_POST['id'] ?? null;
$acao = $_POST['acao'] ?? '';

$id = is_string($valorId) ? validarId($valorId) : false;

if (
    $id === false ||
    !is_string($acao) ||
    !in_array($acao, ['inativar', 'reativar'], true)
) {
    http_response_code(400);
    exit('Solicitação inválida.');
}

try {
    $pdo->beginTransaction();

    // O bloqueio da linha serializa operações concorrentes sobre o mesmo aluno.
    $consulta = $pdo->prepare(
        'SELECT id, ativo
         FROM alunos
         WHERE id = :id
         FOR UPDATE'
    );
    $consulta->execute(['id' => $id]);
    $aluno = $consulta->fetch();

    if (!$aluno) {
        $pdo->rollBack();
        http_response_code(404);
        exit('Aluno não encontrado.');
    }

    $statusAtual = (int) $aluno['ativo'];
    if (!in_array($statusAtual, [0, 1], true)) {
        throw new RuntimeException('O aluno possui um status inválido no banco de dados.');
    }

    $statusDesejado = $acao === 'inativar' ? 0 : 1;

    // Solicitações repetidas são idempotentes e não duplicam a auditoria.
    if ($statusAtual === $statusDesejado) {
        $pdo->commit();
        $_SESSION['mensagem'] = $statusDesejado === 0
            ? 'O aluno já estava inativo.'
            : 'O aluno já estava ativo.';
        redirecionar(urlListaAlunos($contextoLista));
    }

    $atualizar = $pdo->prepare(
        'UPDATE alunos
         SET ativo = :status_novo
         WHERE id = :id AND ativo = :status_atual'
    );
    $atualizar->execute([
        'status_novo' => $statusDesejado,
        'id' => $id,
        'status_atual' => $statusAtual
    ]);

    if ($atualizar->rowCount() !== 1) {
        throw new RuntimeException('O status do aluno não foi alterado.');
    }

    registrarAuditoria(
        $pdo,
        (int) $usuario['id'],
        $statusDesejado === 0 ? 'aluno_inativado' : 'aluno_reativado',
        'alunos',
        (int) $id
    );

    $pdo->commit();

    $_SESSION['mensagem'] = $statusDesejado === 0
        ? 'Aluno inativado com sucesso.'
        : 'Aluno reativado com sucesso.';
    redirecionar(urlListaAlunos($contextoLista));
} catch (Throwable $falha) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($falha->getMessage());
    http_response_code(500);
    exit('Não foi possível alterar o status do aluno. Tente novamente.');
}
