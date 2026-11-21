<?php
// Sessão segura compartilhada pelas páginas do FitControl.
$requisicaoHttps = isset($_SERVER['HTTPS'])
    && $_SERVER['HTTPS'] !== ''
    && strtolower((string) $_SERVER['HTTPS']) !== 'off';

// Cabeçalhos defensivos para todas as páginas PHP, incluindo login e respostas
// que exibem dados de funcionários ou senhas temporárias.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', '28800');

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
 * Encerra o contexto autenticado e renova o identificador da sessão.
 * O token CSRF é recriado para que formulários antigos não sejam reutilizados.
 */
function invalidarSessaoAutenticada(bool $porExpiracao = false): void {
    $_SESSION = [];

    // Se a regeneração falhar, a sessão atual ainda fica sem qualquer dado autenticado.
    session_regenerate_id(true);
    $_SESSION['token'] = bin2hex(random_bytes(32));

    if ($porExpiracao) {
        $_SESSION['sessao_expirada'] = true;
    }
}

/**
 * Retorna o usuário atual somente se a conta continuar ativa e a sessão
 * estiver dentro dos limites de inatividade e duração total.
 *
 * Política: 30 minutos sem atividade ou 8 horas desde o login.
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

    $agora = time();
    $ultimoAcesso = filter_var(
        $_SESSION['usuario_ultimo_acesso'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $autenticadoEm = filter_var(
        $_SESSION['usuario_autenticado_em'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    $limiteInatividade = 30 * 60;
    $limiteAbsoluto = 8 * 60 * 60;

    // Sessões antigas sem os marcadores, timestamps futuros ou fora dos limites
    // exigem autenticação novamente.
    if (
        $ultimoAcesso === false || $ultimoAcesso === null ||
        $autenticadoEm === false || $autenticadoEm === null ||
        $ultimoAcesso > $agora || $autenticadoEm > $agora ||
        ($agora - $ultimoAcesso) >= $limiteInatividade ||
        ($agora - $autenticadoEm) >= $limiteAbsoluto
    ) {
        invalidarSessaoAutenticada(true);
        return null;
    }

    $consulta = $pdo->prepare(
        'SELECT id, nome, email, deve_trocar_senha
         FROM usuarios
         WHERE id = :id AND ativo = 1
         LIMIT 1'
    );
    $consulta->execute(['id' => $usuarioId]);
    $usuario = $consulta->fetch();

    if (!$usuario) {
        invalidarSessaoAutenticada();
        return null;
    }

    // A atividade é renovada somente após confirmar a conta ativa no banco.
    $_SESSION['usuario_ultimo_acesso'] = $agora;

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

    if ((int) ($usuario['deve_trocar_senha'] ?? 0) === 1) {
        redirecionar('trocar-senha.php');
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
