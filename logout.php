<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método não permitido.');
}

validarToken();

$usuarioId = filter_var(
    $_SESSION['usuario_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($usuarioId !== false && $usuarioId !== null) {
    try {
        registrarAuditoria($pdo, (int) $usuarioId, 'logout', 'usuarios', (int) $usuarioId);
    } catch (Throwable $falha) {
        // O logout deve ocorrer mesmo se o registro de auditoria falhar.
        error_log($falha->getMessage());
    }
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $parametros = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $parametros['path'],
        'domain' => $parametros['domain'],
        'secure' => $parametros['secure'],
        'httponly' => $parametros['httponly'],
        'samesite' => $parametros['samesite'] ?? 'Lax'
    ]);
}

session_destroy();
redirecionar('login.php?logout=1');
