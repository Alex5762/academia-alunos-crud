<?php
require_once __DIR__ . '/funcoes.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private, max-age=0');

function responderRenovacao(int $status, array $dados): void {
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    responderRenovacao(405, ['ok' => false, 'mensagem' => 'Método não permitido.']);
}

$tokenRecebido = $_POST['token'] ?? '';
$tokenSessao = $_SESSION['token'] ?? '';

if (
    !is_string($tokenRecebido) ||
    !is_string($tokenSessao) ||
    !hash_equals($tokenSessao, $tokenRecebido)
) {
    responderRenovacao(403, ['ok' => false, 'mensagem' => 'Solicitação inválida.']);
}

require_once __DIR__ . '/conexao.php';

try {
    $usuario = usuarioAtual($pdo);
    if ($usuario === null) {
        responderRenovacao(401, ['ok' => false, 'mensagem' => 'Sessão expirada.']);
    }

    responderRenovacao(200, ['ok' => true]);
} catch (Throwable $erro) {
    error_log($erro->getMessage());
    responderRenovacao(500, ['ok' => false, 'mensagem' => 'Não foi possível renovar a sessão.']);
}