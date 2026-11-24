<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/src/Security/AccessControl.php';
require_once __DIR__ . '/src/Domain/Users/UserAccountAdministrationService.php';

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

try {
    $service = new \FitControl\Domain\Users\UserAccountAdministrationService($pdo);
    $result = $service->changeProfile($usuarioAtualId, $usuarioAlvoId, $perfilRecebido);

    if ($result === \FitControl\Domain\Users\UserAccountAdministrationService::UNAUTHORIZED) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Sua conta não possui mais permissão para administrar funcionários.');
    }

    if ($result === \FitControl\Domain\Users\UserAccountAdministrationService::NOT_FOUND) {
        http_response_code(404);
        exit('Funcionário não encontrado.');
    }

    $messages = [
        \FitControl\Domain\Users\UserAccountAdministrationService::SELF_PROFILE =>
            'Você não pode alterar o próprio perfil.',
        \FitControl\Domain\Users\UserAccountAdministrationService::UNCHANGED =>
            'O funcionário já possui esse perfil.',
        \FitControl\Domain\Users\UserAccountAdministrationService::LAST_ADMINISTRATOR =>
            'Não é possível remover o perfil do último administrador ativo.',
        \FitControl\Domain\Users\UserAccountAdministrationService::UPDATED =>
            'Perfil do funcionário atualizado com sucesso.',
    ];

    $_SESSION['mensagem'] = $messages[$result] ?? 'Não foi possível alterar o perfil do funcionário.';
    redirecionar('usuarios.php');
} catch (Throwable $falha) {
    error_log($falha->getMessage());
    http_response_code(500);
    exit('Não foi possível alterar o perfil do funcionário. Tente novamente.');
}
