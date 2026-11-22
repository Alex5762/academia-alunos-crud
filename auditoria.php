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
if ($dataInicial !== null) {
    $where[] = 'a.criado_em >= :data_inicial';
    $parametros['data_inicial'] = $dataInicial . ' 00:00:00';
}
if ($dataFinal !== null) {
    $fimExclusivo = (new DateTimeImmutable($dataFinal))
        ->modify('+1 day')
        ->format('Y-m-d 00:00:00');
    $where[] = 'a.criado_em < :data_final';
    $parametros['data_final'] = $fimExclusivo;
}

$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
$contar = $pdo->prepare(
    'SELECT COUNT(*)
     FROM auditoria a
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
            u.nome AS responsavel_nome, u.email AS responsavel_email
     FROM auditoria a
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
    'SELECT DISTINCT acao FROM auditoria ORDER BY acao ASC'
)->fetchAll(PDO::FETCH_COLUMN);
$opcoesEntidade = $pdo->query(
    'SELECT DISTINCT entidade FROM auditoria ORDER BY entidade ASC'
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

require_once __DIR__ . '/includes/header.php';
?>
<main class="page-shell">
    <section class="page-heading">
        <div>
            <p class="eyebrow">SEGURANÇA E GOVERNANÇA</p>
            <h1>Central de auditoria</h1>
            <p>Consulte ações administrativas registradas no FitControl.</p>
        </div>
        <a class="button button-search" href="index.php">Voltar ao painel</a>
    </section>

    <section class="settings-card account-card" aria-labelledby="audit-filter-title">
        <div class="settings-card-heading">
            <span class="settings-icon" aria-hidden="true">⌕</span>
            <div>
                <h2 id="audit-filter-title">Filtrar eventos</h2>
                <p><?= number_format($total, 0, ',', '.') ?> evento(s) encontrado(s)</p>
            </div>
        </div>
        <form method="get" action="auditoria.php" class="account-create-form">
            <div>
                <label for="audit-search">Funcionário, e-mail ou ID</label>
                <input id="audit-search" name="busca" type="search" maxlength="100"
                       value="<?= escapar($termo) ?>" placeholder="Buscar responsável">
            </div>
            <div>
                <label for="audit-action">Ação</label>
                <select id="audit-action" name="acao">
                    <option value="">Todas as ações</option>
                    <?php foreach ($opcoesAcao as $opcao) { ?>
                        <option value="<?= escapar($opcao) ?>" <?= $acao === $opcao ? 'selected' : '' ?>>
                            <?= escapar($opcao) ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
            <div>
                <label for="audit-entity">Entidade</label>
                <select id="audit-entity" name="entidade">
                    <option value="">Todas as entidades</option>
                    <?php foreach ($opcoesEntidade as $opcao) { ?>
                        <option value="<?= escapar($opcao) ?>" <?= $entidade === $opcao ? 'selected' : '' ?>>
                            <?= escapar($opcao) ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
            <div>
                <label for="audit-from">De</label>
                <input id="audit-from" name="de" type="date" value="<?= escapar($dataInicial ?? '') ?>">
            </div>
            <div>
                <label for="audit-to">Até</label>
                <input id="audit-to" name="ate" type="date" value="<?= escapar($dataFinal ?? '') ?>">
            </div>
            <div class="plan-form-actions">
                <button class="button button-primary" type="submit">Aplicar filtros</button>
                <a class="button button-search" href="auditoria.php">Limpar</a>
            </div>
        </form>
    </section>

    <section class="settings-card account-card" aria-labelledby="audit-list-title">
        <div class="settings-card-heading account-list-heading">
            <div>
                <h2 id="audit-list-title">Eventos registrados</h2>
                <p>Mais recentes primeiro · <?= $porPagina ?> registros por página</p>
            </div>
        </div>
        <?php if ($eventos === []) { ?>
            <div class="compact-empty">
                <strong>Nenhum evento encontrado</strong>
                <span>Ajuste os filtros ou consulte outro período.</span>
            </div>
        <?php } else { ?>
            <div class="table-scroll" tabindex="0" aria-label="Tabela de eventos de auditoria">
                <table class="students-table accounts-table">
                    <thead>
                        <tr>
                            <th scope="col">DATA E HORA</th>
                            <th scope="col">RESPONSÁVEL</th>
                            <th scope="col">AÇÃO</th>
                            <th scope="col">ENTIDADE</th>
                            <th scope="col">REGISTRO</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($eventos as $evento) { ?>
                            <tr>
                                <td><?= escapar(formatarInstanteUtcNaAcademia($evento['criado_em'])) ?></td>
                                <td>
                                    <div class="account-person">
                                        <strong><?= escapar($evento['responsavel_nome'] ?? 'Conta indisponível') ?></strong>
                                        <small><?= escapar($evento['responsavel_email'] ?? ('ID ' . $evento['usuario_id'])) ?></small>
                                    </div>
                                </td>
                                <td><span class="account-status"><?= escapar($evento['acao']) ?></span></td>
                                <td><?= escapar($evento['entidade']) ?></td>
                                <td><?= $evento['entidade_id'] === null ? '—' : '#' . (int) $evento['entidade_id'] ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <nav class="pagination" aria-label="Paginação da auditoria">
                <span>Página <?= $pagina ?> de <?= $totalPaginas ?></span>
                <div class="plan-form-actions">
                    <?php if ($pagina > 1) { ?>
                        <a class="button button-search" href="<?= escapar(urlAuditoria(['pagina' => (string) ($pagina - 1)])) ?>">Anterior</a>
                    <?php } ?>
                    <?php if ($pagina < $totalPaginas) { ?>
                        <a class="button button-search" href="<?= escapar(urlAuditoria(['pagina' => (string) ($pagina + 1)])) ?>">Próxima</a>
                    <?php } ?>
                </div>
            </nav>
        <?php } ?>
    </section>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
