<?php
// Sessão segura compartilhada pelas páginas do FitControl.
$requisicaoHttps = isset($_SERVER['HTTPS'])
    && $_SERVER['HTTPS'] !== ''
    && strtolower((string) $_SERVER['HTTPS']) !== 'off';

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    session_name('FITCONTROLSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $requisicaoHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

if (!isset($_SESSION['token']) || !is_string($_SESSION['token'])) {
    $_SESSION['token'] = bin2hex(random_bytes(32));
}

// Use ao exibir textos no HTML, inclusive em atributos value.
function escapar($texto) {
    return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
}

function redirecionar($pagina) {
    header('Location: ' . $pagina, true, 303);
    exit;
}

function validarToken() {
    $token = $_POST['token'] ?? '';
    $tokenSessao = $_SESSION['token'] ?? '';

    if (
        !is_string($token) ||
        !is_string($tokenSessao) ||
        !hash_equals($tokenSessao, $token)
    ) {
        http_response_code(403);
        exit('Formulário inválido. Atualize a página e tente novamente.');
    }
}

/**
 * Retorna o usuário atual somente se a conta continuar ativa.
 * A situação é consultada no banco em cada requisição protegida,
 * permitindo revogar sessões quando uma conta é desativada.
 */
function usuarioAtual(PDO $pdo): ?array {
    $usuarioId = filter_var(
        $_SESSION['usuario_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($usuarioId === false || $usuarioId === null) {
        return null;
    }

    $consulta = $pdo->prepare(
        'SELECT id, nome, email
         FROM usuarios
         WHERE id = :id AND ativo = 1
         LIMIT 1'
    );
    $consulta->execute(['id' => $usuarioId]);
    $usuario = $consulta->fetch();

    if (!$usuario) {
        unset($_SESSION['usuario_id']);
        session_regenerate_id(true);
        $_SESSION['token'] = bin2hex(random_bytes(32));
        return null;
    }

    return $usuario;
}

/**
 * Bloqueia a requisição antes de qualquer consulta ou ação administrativa.
 */
function exigirAutenticacao(PDO $pdo): array {
    $usuario = usuarioAtual($pdo);

    if ($usuario === null) {
        redirecionar('login.php');
    }

    return $usuario;
}

/**
 * Persiste um evento de auditoria. Os dados de autoria são fornecidos
 * exclusivamente pelo código do servidor, nunca pelo formulário.
 */
function registrarAuditoria(
    PDO $pdo,
    ?int $usuarioId,
    string $acao,
    string $entidade,
    ?int $entidadeId = null
): void {
    $comando = $pdo->prepare(
        'INSERT INTO auditoria (usuario_id, acao, entidade, entidade_id)
         VALUES (:usuario_id, :acao, :entidade, :entidade_id)'
    );
    $comando->execute([
        'usuario_id' => $usuarioId,
        'acao' => $acao,
        'entidade' => $entidade,
        'entidade_id' => $entidadeId
    ]);
}

function inicialNome($nome): string {
    preg_match('/^\\X/u', trim((string) $nome), $resultado);
    return strtoupper($resultado[0] ?? '?');
}

function validarId($valor) {
    return filter_var($valor, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 4294967295]
    ]);
}

function buscarAluno($pdo, $id) {
    $consulta = $pdo->prepare('SELECT * FROM alunos WHERE id = :id');
    $consulta->execute(['id' => $id]);
    $aluno = $consulta->fetch();

    if (!$aluno) {
        http_response_code(404);
        exit('Aluno não encontrado. Volte à lista.');
    }

    return $aluno;
}
