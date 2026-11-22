<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/src/Domain/Enrollment/PlanManagementService.php';

use FitControl\Domain\Enrollment\PlanManagementService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método não permitido.');
}

$usuario = exigirAutenticacao($pdo);
exigirPermissao($usuario, 'plans.manage');
validarToken();

$idRecebido = $_POST['id'] ?? '';
$acao = $_POST['acao'] ?? '';

if (!is_string($idRecebido) || !is_string($acao)) {
    http_response_code(400);
    exit('Dados inválidos.');
}

$id = validarId($idRecebido);
if ($id === false || !in_array($acao, ['ativar', 'inativar', 'excluir'], true)) {
    http_response_code(400);
    exit('Ação ou identificador inválido.');
}

try {
    $service = new PlanManagementService($pdo);
    $planId = (int) $id;

    if ($acao === 'ativar') {
        $service->activate($planId, (int) $usuario['id']);
        $_SESSION['mensagem'] = 'Plano ativado e disponível para novas matrículas.';
    } elseif ($acao === 'inativar') {
        $service->deactivate($planId, (int) $usuario['id']);
        $_SESSION['mensagem'] = 'Plano inativado. As matrículas existentes foram preservadas.';
    } else {
        $service->deleteIfUnused($planId, (int) $usuario['id']);
        $_SESSION['mensagem'] = 'Plano excluído. A auditoria da operação foi preservada.';
    }

    redirecionar('planos.php');
} catch (DomainException $exception) {
    $_SESSION['mensagem'] = $exception->getMessage();
    redirecionar('planos.php');
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    exit('Não foi possível concluir a operação do plano. Consulte o log do servidor.');
}
