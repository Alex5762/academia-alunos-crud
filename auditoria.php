<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';

$usuario = exigirAutenticacao($pdo);
exigirPermissao($usuario, 'audit.view');

function valorFiltroAuditoria(string $chave, int $limite): string
{
    $valor = $_GET[$chave] ?? '';
    if (!is_string($valor)) {
        return '';
    }

    $valor = trim($valor);
    return mb_substr($valor, 0, $limite, 'UTF-8');
}

function dataFiltroAuditoria(string $valor): ?string
{
    if ($valor === '') {
        return null;
    }

    $data = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    $erros = DateTimeImmutable::getLastErrors();
    if (
        $data === false
        || ($erros !== false && ($erros['warning_count'] > 0 || $erros['error_count'] > 0))
        || $data->format('Y-m-d') !== $valor
    ) {
        return null;
    }

    return $valor;
}

$termo = valorFiltroAuditoria('busca', 100);
$acao = valorFiltroAuditoria('acao', 80);
$entidade = valorFiltroAuditoria('entidade', 60);
$dataInicialRecebida = valorFiltroAuditoria('de', 10);
$dataFinalRecebida = valorFiltroAuditoria('ate', 10);
$dataInicial = dataFiltroAuditoria($dataInicialRecebida);
$dataFinal = dataFiltroAuditoria($dataFinalRecebida);

if (
    ($dataInicialRecebida !== '' && $dataInicial === null)
    || ($dataFinalRecebida !== '' && $dataFinal === null)
    || ($dataInicial !== null && $dataFinal !== null && $dataInicial > $dataFinal)
) {
    http_response_code(400);
    exit('Período inválido. Informe datas válidas e uma data inicial anterior à final.');
}

$paginaRecebida = $_GET['pagina'] ?? '1';
$pagina = is_string($paginaRecebida) && ctype_digit($paginaRecebida)
    ? max(1, min(1000000, (int) $paginaRecebida))
    : 1;
$porPagina = 25;

// Une a trilha geral aos eventos especializados de alteração de perfil.
// O evento genérico correspondente é omitido para não duplicar a mesma ação.
$fonteEventos = '(SELECT CONCAT(\'A-\', LPAD(id, 20, \'0\')) AS id,
                         usuario_id, acao, entidade, entidade_id, criado_em,
                         NULL AS detalhes
                  FROM auditoria
                  WHERE acao <> \'usuario_perfil_alterado\'
                  UNION ALL
                  SELECT CONCAT(\'P-\', LPAD(id, 20, \'0\')) AS id,
                         responsavel_id AS usuario_id,
                         \'usuario_perfil_alterado\' AS acao,
                         \'usuarios\' AS entidade,
                         usuario_id AS entidade_id,
                         criado_em,
                         CONCAT(perfil_anterior, \' → \', perfil_novo) AS detalhes
                  FROM usuarios_perfis_eventos)';

$where = [];
$parametros = [];

if ($termo !== '') {
    $where[] = '(u.nome LIKE :termo_nome OR u.email LIKE :termo_email OR CAST(a.usuario_id AS CHAR) = :termo_id)';
    $parametros['termo_nome'] = '%' . $termo . '%';
    $parametros['termo_email'] = '%' . $termo . '%';
    $parametros['termo_id'] = $termo;
}
if ($acao !== '') {
    $where[] = 'a.acao = :acao';
    $parametros['acao'] = $acao;
}
if ($entidade !== '') {
    $where[] = 'a.entidade = :entidade';
    $parametros['entidade'] = $entidade;
}
$fusoAcademia = new DateTimeZone('America/Sao_Paulo');
$fusoUtc = new DateTimeZone('UTC');
if ($dataInicial !== null) {
    $inicioUtc = (new DateTimeImmutable($dataInicial . ' 00:00:00', $fusoAcademia))
        ->setTimezone($fusoUtc)
        ->format('Y-m-d H:i:s');
    $where[] = 'a.criado_em >= :data_inicial';
    $parametros['data_inicial'] = $inicioUtc;
}
if ($dataFinal !== null) {
    $fimUtc = (new DateTimeImmutable($dataFinal . ' 00:00:00', $fusoAcademia))
        ->modify('+1 day')
        ->setTimezone($fusoUtc)
        ->format('Y-m-d H:i:s');
    $where[] = 'a.criado_em < :data_final';
    $parametros['data_final'] = $fimUtc;
}

$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
$contar = $pdo->prepare(
    'SELECT COUNT(*)
     FROM ' . $fonteEventos . ' a
     LEFT JOIN usuarios u ON u.id = a.usuario_id'
     . $whereSql
);
$contar->execute($parametros);
$total = (int) $contar->fetchColumn();
$totalPaginas = max(1, (int) ceil($total / $porPagina));
$pagina = min($pagina, $totalPaginas);
$offset = ($pagina - 1) * $porPagina;

$consulta = $pdo->prepare(
    'SELECT a.id, a.usuario_id, a.acao, a.entidade, a.entidade_id, a.criado_em,
            a.detalhes, u.nome AS responsavel_nome, u.email AS responsavel_email
     FROM ' . $fonteEventos . ' a
     LEFT JOIN usuarios u ON u.id = a.usuario_id'
     . $whereSql
     . ' ORDER BY a.criado_em DESC, a.id DESC
        LIMIT :limite OFFSET :offset'
);
foreach ($parametros as $chave => $valor) {
    $consulta->bindValue(':' . $chave, $valor, PDO::PARAM_STR);
}
$consulta->bindValue(':limite', $porPagina, PDO::PARAM_INT);
$consulta->bindValue(':offset', $offset, PDO::PARAM_INT);
$consulta->execute();
$eventos = $consulta->fetchAll();

$opcoesAcao = $pdo->query(
    'SELECT DISTINCT acao FROM ' . $fonteEventos . ' eventos ORDER BY acao ASC'
)->fetchAll(PDO::FETCH_COLUMN);
$opcoesEntidade = $pdo->query(
    'SELECT DISTINCT entidade FROM ' . $fonteEventos . ' eventos ORDER BY entidade ASC'
)->fetchAll(PDO::FETCH_COLUMN);

function urlAuditoria(array $sobrescritas = []): string
{
    $filtros = [
        'busca' => $_GET['busca'] ?? '',
        'acao' => $_GET['acao'] ?? '',
        'entidade' => $_GET['entidade'] ?? '',
        'de' => $_GET['de'] ?? '',
        'ate' => $_GET['ate'] ?? '',
    ];
    foreach ($sobrescritas as $chave => $valor) {
        $filtros[$chave] = $valor;
    }

    return 'auditoria.php?' . http_build_query(array_filter(
        $filtros,
        static fn ($valor): bool => is_string($valor) && $valor !== ''
    ));
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Trilha de auditoria administrativa do FitControl.">
    <title>Auditoria | FitControl</title>
    <link rel="stylesheet" href="style.css">
    <?= renderizarMonitorSessao() ?>
    <script src="theme.js"></script>
</head>
<body class="dashboard-body">
<div class="app-shell">
    <aside class="sidebar" aria-label="Navegação principal">
        <a class="brand" href="index.php" aria-label="FitControl - página inicial">
            <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none"><path d="M8 11.5h16M8 20.5h16M11 8v16M21 8v16" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg></span>
            <span class="brand-copy"><strong>fitcontrol</strong><small>GESTÃO DE ACADEMIA</small></span>
        </a>
        <div class="sidebar-label">SEGURANÇA</div>
        <nav class="main-nav" aria-label="Navegação de segurança">
            <a class="nav-link" href="index.php"><span>Visão geral</span></a>
            <a class="nav-link active" href="auditoria.php" aria-current="page"><span>Central de auditoria</span></a>
            <?php if (usuarioTemPermissao($usuario, 'users.manage')) { ?>
                <a class="nav-link" href="usuarios.php"><span>Funcionários</span></a>
            <?php } ?>
            <a class="nav-link" href="configuracoes.php"><span>Configurações</span></a>
        </nav>
        <div class="sidebar-bottom"><a class="nav-link" href="logout.php">Sair</a></div>
    </aside>
    <main class="app-main">
        <header class="topbar">
            <div><span class="eyebrow">SEGURANÇA E GOVERNANÇA</span><h1>Central de auditoria</h1></div>
            <span class="account-current"><?= escapar($usuario['nome']) ?> · <?= escapar(\FitControl\Security\AccessControl::label($usuario['perfil'])) ?></span>
        </header>
        <div class="page-content">
            <section class="page-heading">
                <div><h2>Histórico administrativo</h2><p>Consulte ações registradas no FitControl, com filtros e paginação.</p></div>
                <span class="account-status"><?= number_format($total, 0, ',', '.') ?> evento(s)</span>
            </section>

            <section class="settings-card account-card" aria-labelledby="audit-filter-title">
                <div class="settings-card-heading"><span class="settings-icon" aria-hidden="true">⌕</span><div><h2 id="audit-filter-title">Filtrar eventos</h2><p>Os períodos usam o fuso horário da academia (America/Sao_Paulo).</p></div></div>
                <form method="get" action="auditoria.php" class="account-create-form">
                    <div><label for="audit-search">Funcionário, e-mail ou ID</label><input id="audit-search" name="busca" type="search" maxlength="100" value="<?= escapar($termo) ?>" placeholder="Buscar responsável"></div>
                    <div><label for="audit-action">Ação</label><select id="audit-action" name="acao"><option value="">Todas as ações</option><?php foreach ($opcoesAcao as $opcao) { ?><option value="<?= escapar($opcao) ?>" <?= $acao === $opcao ? 'selected' : '' ?>><?= escapar($opcao) ?></option><?php } ?></select></div>
                    <div><label for="audit-entity">Entidade</label><select id="audit-entity" name="entidade"><option value="">Todas as entidades</option><?php foreach ($opcoesEntidade as $opcao) { ?><option value="<?= escapar($opcao) ?>" <?= $entidade === $opcao ? 'selected' : '' ?>><?= escapar($opcao) ?></option><?php } ?></select></div>
                    <div><label for="audit-from">De</label><input id="audit-from" name="de" type="date" value="<?= escapar($dataInicial ?? '') ?>"></div>
                    <div><label for="audit-to">Até</label><input id="audit-to" name="ate" type="date" value="<?= escapar($dataFinal ?? '') ?>"></div>
                    <div class="plan-form-actions"><button class="button button-primary" type="submit">Aplicar filtros</button><a class="button button-search" href="auditoria.php">Limpar</a></div>
                </form>
            </section>

            <section class="settings-card account-card" aria-labelledby="audit-list-title">
                <div class="settings-card-heading account-list-heading"><div><h2 id="audit-list-title">Eventos registrados</h2><p>Mais recentes primeiro · <?= $porPagina ?> registros por página</p></div></div>
                <?php if ($eventos === []) { ?>
                    <div class="compact-empty"><strong>Nenhum evento encontrado</strong><span>Ajuste os filtros ou consulte outro período.</span></div>
                <?php } else { ?>
                    <div class="table-scroll" tabindex="0" aria-label="Tabela de eventos de auditoria">
                        <table class="students-table accounts-table">
                            <thead><tr><th scope="col">DATA E HORA</th><th scope="col">RESPONSÁVEL</th><th scope="col">AÇÃO</th><th scope="col">ENTIDADE</th><th scope="col">REGISTRO</th><th scope="col">DETALHES</th></tr></thead>
                            <tbody><?php foreach ($eventos as $evento) { ?>
                                <tr>
                                    <td><?= escapar(formatarInstanteUtcNaAcademia($evento['criado_em'])) ?></td>
                                    <td><div class="account-person"><strong><?= escapar($evento['responsavel_nome'] ?? 'Conta indisponível') ?></strong><small><?= escapar($evento['responsavel_email'] ?? ('ID ' . $evento['usuario_id'])) ?></small></div></td>
                                    <td><span class="account-status"><?= escapar($evento['acao']) ?></span></td>
                                    <td><?= escapar($evento['entidade']) ?></td>
                                    <td>
                                        <?php if (in_array($evento['entidade'], ['alunos', 'usuarios', 'planos', 'matriculas', 'revisoes_legado'], true) && $evento['entidade_id'] !== null) { ?>
                                            <a class="audit-record-link" href="detalhes-auditoria.php?entidade=<?= rawurlencode($evento['entidade']) ?>&amp;id=<?= (int) $evento['entidade_id'] ?>">#<?= (int) $evento['entidade_id'] ?> · Ver histórico</a>
                                        <?php } else { ?>
                                            —
                                        <?php } ?>
                                    </td>
                                    <td><?= escapar($evento['detalhes'] ?? '—') ?></td>
                                </tr>
                            <?php } ?></tbody>
                        </table>
                    </div>
                    <nav class="pagination" aria-label="Paginação da auditoria"><span>Página <?= $pagina ?> de <?= $totalPaginas ?></span><div class="plan-form-actions">
                        <?php if ($pagina > 1) { ?><a class="button button-search" href="<?= escapar(urlAuditoria(['pagina' => (string) ($pagina - 1)])) ?>">Anterior</a><?php } ?>
                        <?php if ($pagina < $totalPaginas) { ?><a class="button button-search" href="<?= escapar(urlAuditoria(['pagina' => (string) ($pagina + 1)])) ?>">Próxima</a><?php } ?>
                    </div></nav>
                <?php } ?>
            </section>
            <footer class="page-footer"><span>FitControl · Central de auditoria</span><span>Projeto acadêmico de Desenvolvimento Web</span></footer>
        </div>
    </main>
</div>
</body>
</html>