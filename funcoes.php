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
header(
    "Content-Security-Policy-Report-Only: default-src 'none'; "
    . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'; "
    . "object-src 'none'; script-src 'self'; script-src-attr 'none'; "
    . "style-src 'self'; style-src-attr 'none'; img-src 'self'; "
    . "font-src 'self'; connect-src 'self'; frame-src 'none'; "
    . "media-src 'none'; worker-src 'none'"
);

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

    // Evita que o PHP substitua a política explícita de Cache-Control acima
    // pelo cabeçalho automático do session.cache_limiter ("nocache").
    session_cache_limiter('');

    session_start();
}

if (!isset($_SESSION['token']) || !is_string($_SESSION['token'])) {
    $_SESSION['token'] = bin2hex(random_bytes(32));
}

// Use ao exibir textos no HTML, inclusive em atributos value.
function escapar($texto) {
    return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
}

/**
 * Converte um DATETIME persistido como UTC para o horário local da academia.
 * Os campos contratuais/eventos usam UTC mesmo quando a conexão SQL tem outro fuso.
 */
function formatarInstanteUtcNaAcademia(?string $valor): string {
    if ($valor === null || trim($valor) === '') {
        return 'Não informado';
    }

    $utc = new DateTimeZone('UTC');
    $academia = new DateTimeZone('America/Sao_Paulo');
    $instante = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $valor, $utc);
    $erros = DateTimeImmutable::getLastErrors();

    if (
        !$instante instanceof DateTimeImmutable
        || $instante->format('Y-m-d H:i:s') !== $valor
        || (is_array($erros) && ($erros['warning_count'] > 0 || $erros['error_count'] > 0))
    ) {
        return 'Data inválida';
    }

    return $instante->setTimezone($academia)->format('d/m/Y H:i:s');
}

/**
 * Inclui o monitor de sessão nas páginas autenticadas.
 * O navegador recebe somente o token CSRF e o tempo restante da sessão.
 */
function renderizarMonitorSessao(): string {
    $autenticadoEm = (int) ($_SESSION['usuario_autenticado_em'] ?? 0);
    $tempoAbsolutoRestante = max(0, (8 * 60 * 60) - (time() - $autenticadoEm));

    return '<meta name="fitcontrol-csrf-token" content="' . escapar($_SESSION['token']) . '">' . "\n"
        . '<meta name="fitcontrol-absolute-remaining" content="' . $tempoAbsolutoRestante . '">' . "\n"
        . '<script src="sessao.js" defer></script>';
}

function redirecionar($pagina) {
    header('Location: ' . $pagina, true, 303);
    exit;
}

/**
 * Lê o contexto da listagem de alunos sem aceitar arrays ou estados arbitrários.
 * O prefixo permite reutilizar a mesma validação em campos hidden de formulários.
 */
function obterContextoListaAlunos(array $origem, string $prefixo = ''): array {
    $busca = $origem[$prefixo . 'busca'] ?? '';
    if (!is_string($busca)) {
        $busca = '';
    }

    $status = $origem[$prefixo . 'status'] ?? 'ativos';
    if (!is_string($status) || !in_array($status, ['ativos', 'inativos', 'todos'], true)) {
        $status = 'ativos';
    }

    return [
        'busca' => trim($busca),
        'status' => $status
    ];
}

/** Gera um retorno fixo para a listagem, sem aceitar URLs fornecidas pelo cliente. */
function urlListaAlunos(array $contexto): string {
    $parametros = ['status' => $contexto['status']];
    if ($contexto['busca'] !== '') {
        $parametros['busca'] = $contexto['busca'];
    }

    return 'index.php?' . http_build_query($parametros, '', '&', PHP_QUERY_RFC3986) . '#alunos';
}

/** Gera o endereço do formulário mantendo apenas o ID e os filtros validados. */
function urlCadastroAluno(?int $alunoId, array $contexto): string {
    $parametros = [
        'busca' => $contexto['busca'],
        'status' => $contexto['status']
    ];
    if ($alunoId !== null) {
        $parametros['id'] = $alunoId;
    }

    return 'cadastro.php?' . http_build_query($parametros, '', '&', PHP_QUERY_RFC3986);
}

/** Gera a URL do perfil individual com o contexto validado da listagem. */
function urlDetalhesAluno(int $alunoId, array $contexto): string {
    $parametros = [
        'id' => $alunoId,
        'busca' => $contexto['busca'],
        'status' => $contexto['status']
    ];

    return 'detalhes-aluno.php?' . http_build_query($parametros, '', '&', PHP_QUERY_RFC3986);
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

    $sessaoVersao = filter_var(
        $_SESSION['usuario_sessao_versao'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]]
    );

    if ($sessaoVersao === false || $sessaoVersao === null) {
        invalidarSessaoAutenticada(true);
        return null;
    }

    $consulta = $pdo->prepare(
        'SELECT id, nome, email, deve_trocar_senha, perfil, sessao_versao
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

    // A versão muda quando o acesso é revogado. Mesmo após reativação,
    // cookies de sessões anteriores continuam inválidos.
    if ((int) $usuario['sessao_versao'] !== $sessaoVersao) {
        invalidarSessaoAutenticada(true);
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
 * Autoriza uma permissão de domínio usando o perfil carregado do banco.
 * O perfil nunca é aceito a partir de GET, POST ou de um campo de formulário.
 */
function usuarioTemPermissao(array $usuario, string $permissao): bool {
    require_once __DIR__ . '/src/Security/AccessControl.php';

    $perfil = $usuario['perfil'] ?? null;
    if (!is_string($perfil)) {
        return false;
    }

    return \FitControl\Security\AccessControl::allows($perfil, $permissao);
}

/** Retorna 403 sem redirecionar para login quando o usuário está autenticado. */
function exigirPermissao(array $usuario, string $permissao): void {
    if (!usuarioTemPermissao($usuario, $permissao)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Você não tem permissão para realizar esta operação.');
    }
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
    require_once __DIR__ . '/src/Security/AuditIntegrityChain.php';
    \FitControl\Security\AuditIntegrityChain::append(
        $pdo,
        $usuarioId,
        $acao,
        $entidade,
        $entidadeId
    );
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
