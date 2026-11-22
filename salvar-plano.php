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
$nomeRecebido = $_POST['nome'] ?? '';
$descricaoRecebida = $_POST['descricao'] ?? '';
$duracaoRecebida = $_POST['duracao_valor'] ?? '';
$unidadeRecebida = $_POST['duracao_unidade'] ?? '';
$precoRecebido = $_POST['valor_atual'] ?? '';

foreach ([
    $idRecebido,
    $nomeRecebido,
    $descricaoRecebida,
    $duracaoRecebida,
    $unidadeRecebida,
    $precoRecebido
] as $campo) {
    if (!is_string($campo)) {
        http_response_code(400);
        exit('Dados inválidos.');
    }
}

$id = null;
if ($idRecebido !== '') {
    $idValidado = validarId($idRecebido);
    if ($idValidado === false) {
        http_response_code(400);
        exit('Identificador de plano inválido.');
    }
    $id = (int) $idValidado;
}

$nome = trim($nomeRecebido);
$descricao = trim($descricaoRecebida);
$descricao = $descricao === '' ? null : $descricao;

$duracao = null;
if (trim($duracaoRecebida) !== '') {
    $duracaoValidada = filter_var(
        $duracaoRecebida,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 65535]]
    );

    if ($duracaoValidada === false) {
        $_SESSION['mensagem'] = 'Informe uma duração inteira entre 1 e 65535.';
        redirecionar($id === null ? 'planos.php' : 'planos.php?editar=' . $id);
    }

    $duracao = (int) $duracaoValidada;
}

$unidade = trim($unidadeRecebida);
$unidade = $unidade === '' ? null : $unidade;

$preco = trim($precoRecebido);
if ($preco === '') {
    $preco = null;
} else {
    // Aceita vírgula ou ponto decimal, sem separadores de milhar ambíguos.
    if (preg_match('/^\d{1,8}(?:[,.]\d{1,2})?$/D', $preco) !== 1) {
        $_SESSION['mensagem'] = 'Preço inválido. Use até oito dígitos inteiros e, opcionalmente, dois centavos.';
        redirecionar($id === null ? 'planos.php' : 'planos.php?editar=' . $id);
    }

    $preco = str_replace(',', '.', $preco);
    if (!str_contains($preco, '.')) {
        $preco .= '.00';
    } else {
        [$inteiro, $centavos] = explode('.', $preco, 2);
        $preco = $inteiro . '.' . str_pad($centavos, 2, '0');
    }
}

try {
    $service = new PlanManagementService($pdo);

    if ($id === null) {
        $service->createDraft(
            (int) $usuario['id'],
            $nome,
            $descricao,
            $duracao,
            $unidade,
            $preco
        );

        $_SESSION['mensagem'] = 'Rascunho de plano criado com sucesso.';
    } else {
        $service->updateDetails(
            $id,
            (int) $usuario['id'],
            $nome,
            $descricao,
            $duracao,
            $unidade,
            $preco
        );

        $_SESSION['mensagem'] = 'Plano atualizado com sucesso.';
    }

    redirecionar('planos.php');
} catch (InvalidArgumentException | DomainException $exception) {
    $_SESSION['mensagem'] = $exception->getMessage();
    redirecionar($id === null ? 'planos.php' : 'planos.php?editar=' . $id);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    exit('Não foi possível salvar o plano. Consulte o log do servidor.');
}
