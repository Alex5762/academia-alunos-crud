<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/src/Domain/Users/UserAccountAdministrationService.php';

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

try {
    $service = new \FitControl\Domain\Users\UserAccountAdministrationService($pdo);
    $result = $service->changeStatus($usuarioAtualId, $usuarioAlvoId, $acao);

    if ($result === \FitControl\Domain\Users\UserAccountAdministrationService::UNAUTHORIZED) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Sua conta não possui mais permissão para administrar funcionários.');
    }

    $messages = [
        \FitControl\Domain\Users\UserAccountAdministrationService::NOT_FOUND =>
            'Conta não encontrada.',
        \FitControl\Domain\Users\UserAccountAdministrationService::SELF_DEACTIVATION =>
            'Você não pode desativar sua própria conta.',
        \FitControl\Domain\Users\UserAccountAdministrationService::ALREADY_INACTIVE =>
            'A conta já estava inativa.',
        \FitControl\Domain\Users\UserAccountAdministrationService::ALREADY_ACTIVE =>
            'A conta já estava ativa.',
        \FitControl\Domain\Users\UserAccountAdministrationService::LAST_ADMINISTRATOR =>
            'Não é possível desativar o último administrador ativo.',
        \FitControl\Domain\Users\UserAccountAdministrationService::DEACTIVATED =>
            'Conta desativada. O histórico de auditoria foi preservado.',
        \FitControl\Domain\Users\UserAccountAdministrationService::REACTIVATED =>
            'Conta reativada com sucesso.',
    ];

    $_SESSION['mensagem'] = $messages[$result] ?? 'Não foi possível alterar o status da conta.';
    redirecionar('usuarios.php');
} catch (Throwable $falha) {
    error_log($falha->getMessage());
    http_response_code(500);
    exit('Não foi possível alterar o status da conta. Tente novamente.');
}
