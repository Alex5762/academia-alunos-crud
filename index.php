<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';

// Pesquisa opcional pelo nome. O valor é enviado como parâmetro preparado.
$busca = $_GET['busca'] ?? '';
if (!is_string($busca)) {
    $busca = '';
}
$busca = trim($busca);

if ($busca !== '') {
    $consulta = $pdo->prepare(
        'SELECT id, nome, telefone, email, plano
         FROM alunos
         WHERE nome LIKE :busca
         ORDER BY id DESC'
    );
    $consulta->execute(['busca' => "%{$busca}%"]);
} else {
    $consulta = $pdo->query(
        'SELECT id, nome, telefone, email, plano
         FROM alunos
         ORDER BY id DESC'
    );
}
$alunos = $consulta->fetchAll();

// Os indicadores representam todos os registros, independentemente da busca.
$estatisticas = $pdo->query(
    'SELECT
        COUNT(*) AS total_alunos,
        COUNT(DISTINCT plano) AS total_planos
     FROM alunos'
)->fetch();

$totalAlunos = (int) ($estatisticas['total_alunos'] ?? 0);
$totalPlanos = (int) ($estatisticas['total_planos'] ?? 0);

$distribuicaoPlanos = $pdo->query(
    'SELECT plano, COUNT(*) AS quantidade
     FROM alunos
     GROUP BY plano
     ORDER BY quantidade DESC, plano ASC'
)->fetchAll();

$planoDestaque = $distribuicaoPlanos[0]['plano'] ?? '—';
$quantidadeDestaque = (int) ($distribuicaoPlanos[0]['quantidade'] ?? 0);

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);

// Classes visuais controladas pelos valores permitidos no formulário.
function classePlano($plano) {
    return match ($plano) {
        'Mensal' => 'mensal',
        'Trimestral' => 'trimestral',
        'Semestral' => 'semestral',
        'Anual' => 'anual',
        default => 'outro'
    };
}

function iniciaisNome($nome) {
    $partes = preg_split('/\s+/u', trim((string) $nome));
    $primeira = $partes[0] ?? '';
    $ultima = count($partes) > 1 ? $partes[count($partes) - 1] : '';

    $iniciais = mb_substr($primeira, 0, 1, 'UTF-8');
    if ($ultima !== '') {
        $iniciais .= mb_substr($ultima, 0, 1, 'UTF-8');
    }

    return mb_strtoupper($iniciais, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Painel de gestão e cadastro de alunos da academia.">
    <title>Visão geral | FitControl</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="dashboard-body">
<div class="app-shell" id="topo">
    <aside class="sidebar" aria-label="Navegação principal">
        <a class="brand" href="index.php" aria-label="FitControl - página inicial">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 32 32" fill="none">
                    <path d="M8 11.5h16M8 20.5h16M11 8v16M21 8v16" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
                </svg>
            </span>
            <span class="brand-copy">
                <strong>fitcontrol</strong>
                <small>GESTÃO DE ACADEMIA</small>
            </span>
        </a>

        <div class="sidebar-label">MENU PRINCIPAL</div>
        <nav class="main-nav">
            <a class="nav-link active" href="#topo" aria-current="page">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/></svg>
                <span>Visão geral</span>
            </a>
            <a class="nav-link" href="#alunos">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20"/><circle cx="10" cy="7.5" r="3.5"/><path d="M17 11a3.5 3.5 0 0 0 0-7M17 15h1a3 3 0 0 1 3 3v2"/></svg>
                <span>Alunos</span>
                <span class="nav-count"><?= $totalAlunos ?></span>
            </a>
            <a class="nav-link" href="cadastro.php">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/></svg>
                <span>Novo cadastro</span>
            </a>
        </nav>

        <div class="sidebar-bottom">
            <div class="sidebar-note">
                <span class="note-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M12 3 4.5 6v5.5c0 4.4 3.2 7.6 7.5 9.5 4.3-1.9 7.5-5.1 7.5-9.5V6L12 3Z"/><path d="m9 12 2 2 4-4"/></svg>
                </span>
                <strong>Área administrativa</strong>
                <span>Gerencie os cadastros em um só lugar.</span>
            </div>
            <div class="sidebar-footer">
                <span class="online-dot"></span>
                <span>Sistema local</span>
                <span class="version">v1.0</span>
            </div>
        </div>
    </aside>

    <main class="app-main">
        <header class="topbar">
            <div class="breadcrumb">
                <span>Academia</span>
                <span class="breadcrumb-separator">/</span>
                <strong>Visão geral</strong>
            </div>
            <div class="topbar-user">
                <span class="user-avatar" aria-hidden="true">AD</span>
                <span class="user-label">
                    <strong>Administrador</strong>
                    <small>Painel de gestão</small>
                </span>
            </div>
        </header>

        <div class="page-content">
            <section class="page-heading">
                <div>
                    <span class="eyebrow">PAINEL ADMINISTRATIVO</span>
                    <h1>Visão geral</h1>
                    <p>Acompanhe os cadastros e organize os alunos da academia.</p>
                </div>
                <a class="button button-primary" href="cadastro.php">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    Cadastrar aluno
                </a>
            </section>

            <?php if ($mensagem !== '') { ?>
                <div class="flash-message" role="status">
                    <span class="flash-check" aria-hidden="true">✓</span>
                    <?= escapar($mensagem) ?>
                </div>
            <?php } ?>

            <section class="stats-grid" aria-label="Indicadores gerais">
                <article class="stat-card">
                    <div class="stat-topline">
                        <span class="stat-label">Alunos cadastrados</span>
                        <span class="stat-icon stat-icon-blue" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20"/><circle cx="10" cy="7.5" r="3.5"/><path d="M17 8h4M19 6v4"/></svg>
                        </span>
                    </div>
                    <div class="stat-value"><?= $totalAlunos ?></div>
                    <p class="stat-footnote">Total de registros na base</p>
                </article>

                <article class="stat-card">
                    <div class="stat-topline">
                        <span class="stat-label">Planos em uso</span>
                        <span class="stat-icon stat-icon-green" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M4 6.5h16M4 12h16M4 17.5h16"/><circle cx="8" cy="6.5" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="11" cy="17.5" r="1.5"/></svg>
                        </span>
                    </div>
                    <div class="stat-value"><?= $totalPlanos ?></div>
                    <p class="stat-footnote">Categorias com alunos cadastrados</p>
                </article>

                <article class="stat-card stat-card-highlight">
                    <div class="stat-topline">
                        <span class="stat-label">Plano mais frequente</span>
                        <span class="stat-icon stat-icon-violet" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9L12 3Z"/></svg>
                        </span>
                    </div>
                    <div class="stat-value stat-value-text"><?= escapar($planoDestaque) ?></div>
                    <p class="stat-footnote"><?= $quantidadeDestaque ?> <?= $quantidadeDestaque === 1 ? 'aluno' : 'alunos' ?> neste plano</p>
                </article>
            </section>

            <section class="overview-grid">
                <article class="panel distribution-panel">
                    <div class="panel-heading">
                        <div>
                            <span class="eyebrow">VISÃO DA BASE</span>
                            <h2>Alunos por plano</h2>
                        </div>
                        <span class="panel-caption">Distribuição atual</span>
                    </div>

                    <?php if ($totalAlunos === 0) { ?>
                        <div class="compact-empty">
                            <span class="empty-illustration" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M4 19V5M4 19h16M8 15l3-4 3 2 5-7"/></svg>
                            </span>
                            <strong>Sem dados para exibir</strong>
                            <span>Os planos aparecerão aqui depois do primeiro cadastro.</span>
                        </div>
                    <?php } else { ?>
                        <div class="plan-list">
                            <?php foreach ($distribuicaoPlanos as $item) {
                                $quantidade = (int) $item['quantidade'];
                                $percentual = (int) round(($quantidade / $totalAlunos) * 100);
                                $classe = classePlano($item['plano']);
                            ?>
                                <div class="plan-row">
                                    <div class="plan-row-top">
                                        <span class="plan-name">
                                            <span class="plan-dot plan-dot-<?= escapar($classe) ?>"></span>
                                            <?= escapar($item['plano']) ?>
                                        </span>
                                        <span class="plan-amount"><?= $quantidade ?> <small>(<?= $percentual ?>%)</small></span>
                                    </div>
                                    <div class="progress-track" role="progressbar"
                                         aria-label="Alunos no plano <?= escapar($item['plano']) ?>"
                                         aria-valuenow="<?= $percentual ?>" aria-valuemin="0" aria-valuemax="100">
                                        <span class="progress-fill progress-<?= escapar($classe) ?>" style="width: <?= $percentual ?>%"></span>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                    <?php } ?>
                </article>

                <aside class="panel shortcut-panel">
                    <span class="shortcut-orb" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                    </span>
                    <span class="eyebrow">ACESSO RÁPIDO</span>
                    <h2>Mais um aluno na equipe?</h2>
                    <p>Adicione um novo cadastro e mantenha as informações organizadas.</p>
                    <a class="button button-secondary" href="cadastro.php">
                        Novo cadastro
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
                    </a>
                    <span class="shortcut-decoration" aria-hidden="true"></span>
                </aside>
            </section>

            <section class="panel students-panel" id="alunos">
                <div class="students-heading">
                    <div>
                        <span class="eyebrow">GERENCIAMENTO</span>
                        <h2>Alunos cadastrados</h2>
                        <p><?= count($alunos) ?> <?= count($alunos) === 1 ? 'registro encontrado' : 'registros encontrados' ?><?= $busca !== '' ? ' para sua pesquisa' : '' ?></p>
                    </div>
                    <form action="index.php#alunos" method="get" class="search-form">
                        <label class="search-field" for="busca">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 4.5 4.5"/></svg>
                            <input type="search" id="busca" name="busca"
                                   placeholder="Pesquisar aluno..." value="<?= escapar($busca) ?>">
                        </label>
                        <button class="button button-search" type="submit">Buscar</button>
                        <?php if ($busca !== '') { ?>
                            <a class="clear-search" href="index.php#alunos">Limpar</a>
                        <?php } ?>
                    </form>
                </div>

                <?php if (count($alunos) === 0) { ?>
                    <div class="empty-state">
                        <span class="empty-illustration" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M3 20v-1.5A4.5 4.5 0 0 1 7.5 14h3a4.5 4.5 0 0 1 4.5 4.5V20M17 8h4M19 6v4"/></svg>
                        </span>
                        <?php if ($busca !== '') { ?>
                            <h3>Nenhum aluno encontrado</h3>
                            <p>Não encontramos cadastros com “<?= escapar($busca) ?>”. Tente outro nome.</p>
                            <a class="button button-secondary" href="index.php#alunos">Limpar pesquisa</a>
                        <?php } else { ?>
                            <h3>Sua lista começa aqui</h3>
                            <p>Quando você cadastrar alunos, eles aparecerão nesta área.</p>
                            <a class="button button-primary" href="cadastro.php">Cadastrar primeiro aluno</a>
                        <?php } ?>
                    </div>
                <?php } else { ?>
                    <div class="table-scroll">
                        <table class="students-table">
                            <thead>
                                <tr>
                                    <th scope="col">Aluno</th>
                                    <th scope="col">Contato</th>
                                    <th scope="col">Plano</th>
                                    <th scope="col" class="actions-heading">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alunos as $aluno) {
                                    $classe = classePlano($aluno['plano']);
                                ?>
                                    <tr>
                                        <td>
                                            <div class="student-cell">
                                                <span class="student-avatar" aria-hidden="true"><?= escapar(iniciaisNome($aluno['nome'])) ?></span>
                                                <span class="student-info">
                                                    <strong><?= escapar($aluno['nome']) ?></strong>
                                                    <small>#<?= escapar($aluno['id']) ?></small>
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="contact-email"><?= escapar($aluno['email']) ?></span>
                                            <span class="contact-phone"><?= escapar($aluno['telefone']) ?></span>
                                        </td>
                                        <td><span class="plan-badge plan-badge-<?= escapar($classe) ?>"><?= escapar($aluno['plano']) ?></span></td>
                                        <td class="row-actions">
                                            <a class="action-link" href="cadastro.php?id=<?= escapar($aluno['id']) ?>" aria-label="Editar <?= escapar($aluno['nome']) ?>">Editar</a>
                                            <a class="action-link action-delete" href="excluir.php?id=<?= escapar($aluno['id']) ?>" aria-label="Excluir <?= escapar($aluno['nome']) ?>">Excluir</a>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php } ?>
            </section>

            <footer class="page-footer">
                <span>FitControl <span aria-hidden="true">·</span> Painel de gestão</span>
                <span>Projeto acadêmico de Desenvolvimento Web</span>
            </footer>
        </div>
    </main>
</div>
</body>
</html>
