<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';

$usuario = exigirAutenticacao($pdo);
exigirPermissao($usuario, 'legacy.view');

function rotuloResultadoRevisaoLegado(string $resultado): string
{
    return match ($resultado) {
        'VINCULO_CONFIRMADO' => 'Vínculo confirmado',
        'LEGADO_SEM_VIGENCIA' => 'Sem vigência',
        default => 'Pendente de revisão',
    };
}

function classeResultadoRevisaoLegado(string $resultado): string
{
    return match ($resultado) {
        'VINCULO_CONFIRMADO' => 'is-active',
        'LEGADO_SEM_VIGENCIA' => 'is-inactive',
        default => 'is-pending',
    };
}

$estruturaDisponivel = true;
$colunasObrigatorias = [
    'alunos' => ['id', 'nome', 'email', 'ativo', 'plano'],
    'revisoes_legado' => [
        'id', 'aluno_id_origem', 'plano_texto_original', 'resultado_atual',
        'fonte_referencia', 'criado_em', 'atualizado_em',
    ],
    'revisoes_legado_eventos' => [
        'id', 'revisao_legado_id', 'usuario_id', 'tipo_evento',
        'resultado_anterior', 'resultado_novo', 'matricula_id',
        'inicio_anterior', 'fim_exclusivo_anterior', 'inicio_novo',
        'fim_exclusivo_novo', 'fonte_referencia', 'observacoes', 'criado_em',
    ],
];

foreach ($colunasObrigatorias as $tabela => $colunas) {
    $placeholders = [];
    $parametros = ['tabela' => $tabela];

    foreach ($colunas as $indice => $coluna) {
        $placeholder = 'coluna_' . $indice;
        $placeholders[] = ':' . $placeholder;
        $parametros[$placeholder] = $coluna;
    }

    $consultaEstrutura = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :tabela
           AND COLUMN_NAME IN (' . implode(', ', $placeholders) . ')'
    );
    $consultaEstrutura->execute($parametros);

    if ((int) $consultaEstrutura->fetchColumn() !== count($colunas)) {
        $estruturaDisponivel = false;
        break;
    }
}

$filtrosPermitidos = ['pendentes', 'todos', 'confirmados', 'sem-vigencia'];
$filtro = $_GET['filtro'] ?? 'pendentes';
if (!is_string($filtro) || !in_array($filtro, $filtrosPermitidos, true)) {
    $filtro = 'pendentes';
}

$buscaRecebida = $_GET['busca'] ?? '';
$busca = is_string($buscaRecebida) ? trim($buscaRecebida) : '';
if (preg_match('//u', $busca) !== 1) {
    $busca = '';
} else {
    $comprimentoBusca = preg_match_all('/./us', $busca);

    if ($comprimentoBusca === false) {
        $busca = '';
    } elseif ($comprimentoBusca > 120) {
        preg_match('/^.{0,120}/us', $busca, $buscaLimitada);
        $busca = $buscaLimitada[0] ?? '';
    }
}

$reviewIdRecebido = $_GET['review_id'] ?? null;
$reviewId = null;
if ($reviewIdRecebido !== null) {
    $reviewIdValidado = validarId($reviewIdRecebido);
    if ($reviewIdValidado === false) {
        http_response_code(400);
        exit('Identificador de revisão inválido.');
    }
    $reviewId = (int) $reviewIdValidado;
}

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);

$contagens = [
    'PENDENTE_REVISAO' => 0,
    'VINCULO_CONFIRMADO' => 0,
    'LEGADO_SEM_VIGENCIA' => 0,
];
$revisoes = [];
$revisaoSelecionada = null;
$eventosSelecionados = [];

if ($estruturaDisponivel) {
    $consultaContagens = $pdo->query(
        'SELECT resultado_atual, COUNT(*) AS total
         FROM revisoes_legado
         GROUP BY resultado_atual'
    );

    foreach ($consultaContagens->fetchAll(PDO::FETCH_ASSOC) as $contagem) {
        if (array_key_exists($contagem['resultado_atual'], $contagens)) {
            $contagens[$contagem['resultado_atual']] = (int) $contagem['total'];
        }
    }

    if ($reviewId !== null) {
        $consultaSelecionada = $pdo->prepare(
            'SELECT
                r.id AS revisao_id,
                r.aluno_id_origem,
                r.plano_texto_original,
                r.resultado_atual,
                r.fonte_referencia,
                r.criado_em,
                r.atualizado_em,
                a.nome AS aluno_nome,
                a.email AS aluno_email,
                a.ativo AS aluno_ativo
             FROM revisoes_legado r
             INNER JOIN alunos a ON a.id = r.aluno_id_origem
             WHERE r.id = :review_id
             LIMIT 1'
        );
        $consultaSelecionada->execute(['review_id' => $reviewId]);
        $revisaoSelecionada = $consultaSelecionada->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($revisaoSelecionada === null) {
            http_response_code(404);
            exit('Registro de revisão não encontrado.');
        }

        $consultaEventos = $pdo->prepare(
            'SELECT
                e.tipo_evento,
                e.resultado_anterior,
                e.resultado_novo,
                e.matricula_id,
                e.inicio_anterior,
                e.fim_exclusivo_anterior,
                e.inicio_novo,
                e.fim_exclusivo_novo,
                e.fonte_referencia,
                e.observacoes,
                e.criado_em,
                u.nome AS responsavel
             FROM revisoes_legado_eventos e
             LEFT JOIN usuarios u ON u.id = e.usuario_id
             WHERE e.revisao_legado_id = :review_id
             ORDER BY e.id DESC
             LIMIT 20'
        );
        $consultaEventos->execute(['review_id' => $reviewId]);
        $eventosSelecionados = $consultaEventos->fetchAll(PDO::FETCH_ASSOC);
    }

    $condicoes = [];
    $parametros = [];

    if ($filtro === 'pendentes') {
        $condicoes[] = 'r.resultado_atual = :resultado';
        $parametros['resultado'] = 'PENDENTE_REVISAO';
    } elseif ($filtro === 'confirmados') {
        $condicoes[] = 'r.resultado_atual = :resultado';
        $parametros['resultado'] = 'VINCULO_CONFIRMADO';
    } elseif ($filtro === 'sem-vigencia') {
        $condicoes[] = 'r.resultado_atual = :resultado';
        $parametros['resultado'] = 'LEGADO_SEM_VIGENCIA';
    }

    if ($busca !== '') {
        $condicoes[] = '(a.nome LIKE :busca_nome OR a.email LIKE :busca_email
            OR r.plano_texto_original LIKE :busca_plano
            OR r.fonte_referencia LIKE :busca_fonte)';
        $termoBusca = '%' . $busca . '%';
        $parametros['busca_nome'] = $termoBusca;
        $parametros['busca_email'] = $termoBusca;
        $parametros['busca_plano'] = $termoBusca;
        $parametros['busca_fonte'] = $termoBusca;
    }

    $where = $condicoes === [] ? '' : 'WHERE ' . implode(' AND ', $condicoes);
    $consultaFila = $pdo->prepare(
        'SELECT
            r.id AS revisao_id,
            r.aluno_id_origem,
            r.plano_texto_original,
            r.resultado_atual,
            r.fonte_referencia,
            r.atualizado_em,
            a.nome AS aluno_nome,
            a.email AS aluno_email,
            a.ativo AS aluno_ativo,
            (SELECT COUNT(*) FROM revisoes_legado_eventos e
             WHERE e.revisao_legado_id = r.id) AS total_eventos
         FROM revisoes_legado r
         INNER JOIN alunos a ON a.id = r.aluno_id_origem
         ' . $where . '
         ORDER BY
            CASE r.resultado_atual
                WHEN \'PENDENTE_REVISAO\' THEN 1
                WHEN \'VINCULO_CONFIRMADO\' THEN 2
                ELSE 3
            END,
            r.atualizado_em ASC,
            r.id ASC
         LIMIT 100'
    );
    $consultaFila->execute($parametros);
    $revisoes = $consultaFila->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Revise vínculos legados de alunos com evidências e histórico auditável.">
    <title>Revisão de vínculos | FitControl</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="auth.css">
    <?= renderizarMonitorSessao() ?>
    <script src="theme.js"></script>
</head>
<body class="settings-body">
<main class="settings-page users-page legacy-review-page">
    <a class="settings-back" href="index.php">← Voltar ao painel</a>
    <span class="eyebrow">REGULARIZAÇÃO DE CONTRATOS</span>
    <h1>Revisão de vínculos legados</h1>
    <p class="settings-intro">
        Analise o texto antigo de cada aluno e registre somente conclusões sustentadas por evidências.
        Nenhuma data, preço ou condição será estimada.
    </p>

    <?php if ($mensagem !== '') { ?>
        <div class="flash-message" role="status">
            <span class="flash-check" aria-hidden="true">✓</span>
            <?= escapar($mensagem) ?>
        </div>
    <?php } ?>

    <?php if (!$estruturaDisponivel) { ?>
        <section class="settings-card account-card">
            <h2>Revisão ainda não instalada</h2>
            <p class="plan-setup-note">
                A fila de vínculos legados será habilitada depois da instalação e validação das migrations 005 e 006.
                Nenhum registro foi alterado.
            </p>
        </section>
    <?php } else { ?>
        <section class="legacy-review-counts" aria-label="Resumo das revisões">
            <a class="legacy-review-count <?= $filtro === 'pendentes' ? 'is-selected' : '' ?>" href="revisoes-legado.php?filtro=pendentes">
                <span>Pendentes</span>
                <strong><?= $contagens['PENDENTE_REVISAO'] ?></strong>
            </a>
            <a class="legacy-review-count <?= $filtro === 'confirmados' ? 'is-selected' : '' ?>" href="revisoes-legado.php?filtro=confirmados">
                <span>Vínculos confirmados</span>
                <strong><?= $contagens['VINCULO_CONFIRMADO'] ?></strong>
            </a>
            <a class="legacy-review-count <?= $filtro === 'sem-vigencia' ? 'is-selected' : '' ?>" href="revisoes-legado.php?filtro=sem-vigencia">
                <span>Sem vigência</span>
                <strong><?= $contagens['LEGADO_SEM_VIGENCIA'] ?></strong>
            </a>
        </section>

        <section class="settings-card account-card" aria-labelledby="legacy-review-queue-title">
            <div class="settings-card-heading account-list-heading">
                <div>
                    <h2 id="legacy-review-queue-title">Fila de revisão</h2>
                    <p><?= count($revisoes) ?> registro(s) exibido(s), com limite de 100 por consulta.</p>
                </div>
            </div>

            <form action="revisoes-legado.php" method="get" class="legacy-review-search">
                <input type="hidden" name="filtro" value="<?= escapar($filtro) ?>">
                <div>
                    <label for="busca-revisao">Pesquisar aluno ou plano antigo</label>
                    <input type="search" id="busca-revisao" name="busca" maxlength="120"
                           value="<?= escapar($busca) ?>" placeholder="Nome, e-mail ou texto do plano">
                </div>
                <button class="button button-primary" type="submit">Pesquisar</button>
                <?php if ($busca !== '') { ?>
                    <a class="button button-search" href="revisoes-legado.php?filtro=<?= escapar($filtro) ?>">Limpar</a>
                <?php } ?>
            </form>

            <div class="legacy-review-filters" aria-label="Filtrar revisões">
                <a href="revisoes-legado.php?filtro=pendentes" class="<?= $filtro === 'pendentes' ? 'is-active' : '' ?>">Pendentes</a>
                <a href="revisoes-legado.php?filtro=todos" class="<?= $filtro === 'todos' ? 'is-active' : '' ?>">Todos</a>
                <a href="revisoes-legado.php?filtro=confirmados" class="<?= $filtro === 'confirmados' ? 'is-active' : '' ?>">Confirmados</a>
                <a href="revisoes-legado.php?filtro=sem-vigencia" class="<?= $filtro === 'sem-vigencia' ? 'is-active' : '' ?>">Sem vigência</a>
            </div>

            <?php if ($revisoes === []) { ?>
                <div class="compact-empty">
                    <strong>Nenhuma revisão encontrada</strong>
                    <span>Os registros que correspondem a este filtro aparecerão aqui.</span>
                </div>
            <?php } else { ?>
                <div class="table-scroll" tabindex="0" aria-label="Tabela de revisões legadas; role horizontalmente para ver todas as colunas">
                    <table class="students-table accounts-table legacy-review-table">
                        <thead>
                            <tr>
                                <th scope="col">ALUNO</th>
                                <th scope="col">PLANO ORIGINAL</th>
                                <th scope="col">SITUAÇÃO</th>
                                <th scope="col">EVENTOS</th>
                                <th scope="col">AÇÕES</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($revisoes as $revisao) { ?>
                                <tr>
                                    <td>
                                        <div class="account-person">
                                            <strong><?= escapar($revisao['aluno_nome']) ?></strong>
                                            <small><?= escapar($revisao['aluno_email']) ?></small>
                                            <small>Aluno #<?= (int) $revisao['aluno_id_origem'] ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="account-person legacy-original-plan">
                                            <strong><?= escapar($revisao['plano_texto_original'] !== '' ? $revisao['plano_texto_original'] : 'Sem texto original') ?></strong>
                                            <?php if (!empty($revisao['fonte_referencia'])) { ?>
                                                <small>Fonte: <?= escapar($revisao['fonte_referencia']) ?></small>
                                            <?php } ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="account-status <?= escapar(classeResultadoRevisaoLegado((string) $revisao['resultado_atual'])) ?>">
                                            <?= escapar(rotuloResultadoRevisaoLegado((string) $revisao['resultado_atual'])) ?>
                                        </span>
                                        <?php if ((int) $revisao['aluno_ativo'] !== 1) { ?>
                                            <small class="legacy-review-inactive">Cadastro de aluno inativo</small>
                                        <?php } ?>
                                    </td>
                                    <td><?= (int) $revisao['total_eventos'] ?></td>
                                    <td>
                                        <a class="button button-search" href="revisoes-legado.php?filtro=<?= escapar($filtro) ?>&amp;review_id=<?= (int) $revisao['revisao_id'] ?>">
                                            Analisar
                                        </a>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } ?>
        </section>

        <?php if ($revisaoSelecionada !== null) {
            $resultadoSelecionado = (string) $revisaoSelecionada['resultado_atual'];
            $somenteReabrir = $resultadoSelecionado === 'VINCULO_CONFIRMADO';
        ?>
            <section class="settings-card account-card legacy-review-decision" aria-labelledby="legacy-review-decision-title">
                <div class="settings-card-heading">
                    <span class="settings-icon" aria-hidden="true">⌕</span>
                    <div>
                        <h2 id="legacy-review-decision-title">Analisar vínculo legado</h2>
                        <p>Revise a evidência antes de escolher o resultado.</p>
                    </div>
                </div>

                <div class="legacy-review-student">
                    <div>
                        <strong><?= escapar($revisaoSelecionada['aluno_nome']) ?></strong>
                        <span><?= escapar($revisaoSelecionada['aluno_email']) ?> · Aluno #<?= (int) $revisaoSelecionada['aluno_id_origem'] ?></span>
                    </div>
                    <span class="account-status <?= escapar(classeResultadoRevisaoLegado($resultadoSelecionado)) ?>">
                        <?= escapar(rotuloResultadoRevisaoLegado($resultadoSelecionado)) ?>
                    </span>
                </div>

                <dl class="legacy-review-original">
                    <div>
                        <dt>Texto original em alunos.plano</dt>
                        <dd><?= escapar($revisaoSelecionada['plano_texto_original'] !== '' ? $revisaoSelecionada['plano_texto_original'] : 'Sem texto original') ?></dd>
                    </div>
                    <div>
                        <dt>Fonte atualmente registrada</dt>
                        <dd><?= escapar($revisaoSelecionada['fonte_referencia'] ?: 'Nenhuma') ?></dd>
                    </div>
                    <div>
                        <dt>Última atualização</dt>
                        <dd><?= escapar($revisaoSelecionada['atualizado_em']) ?></dd>
                    </div>
                </dl>

                <?php if ($somenteReabrir) { ?>
                    <p class="legacy-review-warning">
                        Este vínculo já foi confirmado e possui um intervalo histórico materializado.
                        Para corrigir suas datas com uma nova evidência, use a retificação auditada. A revisão pode ser reaberta se a confirmação anterior deixar de ser confiável.
                    </p>
                    <?php if (usuarioTemPermissao($usuario, 'legacy.rectify')) { ?>
                        <a class="button button-primary legacy-review-profile-link"
                           href="retificar-vigencia-legado.php?review_id=<?= (int) $revisaoSelecionada['revisao_id'] ?>">
                            Retificar vigência comprovada
                        </a>
                    <?php } ?>
                <?php } else { ?>
                    <p class="plan-setup-note">
                        <strong>Vínculo confirmado:</strong> exige início e fim exclusivo comprovados.
                        <strong>Sem vigência:</strong> exige uma fonte confiável que confirme a ausência de contrato vigente.
                        <strong>Pendente:</strong> exige observações explicando o que ainda falta comprovar.
                    </p>
                <?php } ?>

                <?php if (usuarioTemPermissao($usuario, 'legacy.review')) { ?>
                <form action="salvar-revisao-legado.php" method="post" class="account-create-form legacy-review-form">
                    <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
                    <input type="hidden" name="review_id" value="<?= (int) $revisaoSelecionada['revisao_id'] ?>">

                    <div>
                        <label for="resultado-revisao">Resultado da revisão</label>
                        <select id="resultado-revisao" name="resultado" required>
                            <?php if ($somenteReabrir) { ?>
                                <option value="PENDENTE_REVISAO">Reabrir revisão — manter bloqueio</option>
                            <?php } else { ?>
                                <option value="PENDENTE_REVISAO" <?= $resultadoSelecionado === 'PENDENTE_REVISAO' ? 'selected' : '' ?>>Não foi possível confirmar — manter pendente</option>
                                <option value="VINCULO_CONFIRMADO">Vínculo confirmado — intervalo comprovado</option>
                                <option value="LEGADO_SEM_VIGENCIA">Sem contrato vigente — evidência confirmada</option>
                            <?php } ?>
                        </select>
                    </div>

                    <div>
                        <label for="fonte-referencia">Referência da evidência <span class="optional-label">obrigatória para confirmar</span></label>
                        <input type="text" id="fonte-referencia" name="fonte_referencia" maxlength="255"
                               value="<?= escapar($revisaoSelecionada['fonte_referencia'] ?? '') ?>"
                               placeholder="Ex.: contrato físico, pasta 2025, folha 18">
                    </div>

                    <div class="legacy-review-wide">
                        <label for="observacoes-revisao">Observações e justificativa</label>
                        <textarea id="observacoes-revisao" name="observacoes" rows="3" maxlength="65535"
                                  placeholder="Descreva o que foi verificado e eventuais limitações da evidência."></textarea>
                    </div>

                    <?php if (!$somenteReabrir) { ?>
                        <fieldset class="legacy-review-period">
                            <legend>Vigência comprovada <span class="optional-label">preencher somente em Vínculo confirmado</span></legend>
                            <div>
                                <label for="inicio-legado">Início local</label>
                                <input type="datetime-local" id="inicio-legado" name="inicio_em">
                            </div>
                            <div>
                                <label for="fim-legado">Fim exclusivo local</label>
                                <input type="datetime-local" id="fim-legado" name="fim_exclusivo_em">
                            </div>
                            <small class="field-hint">
                                Informe o limite exclusivo: o instante em que a vigência deixa de valer.
                                Os horários serão interpretados no fuso America/Sao_Paulo.
                            </small>
                        </fieldset>

                        <fieldset class="legacy-review-period">
                            <legend>Condições históricas <span class="optional-label">opcionais, somente se comprovadas</span></legend>
                            <div>
                                <label for="preco-legado">Preço comprovado (R$)</label>
                                <input type="text" id="preco-legado" name="preco_comprovado"
                                       inputmode="decimal" maxlength="11" placeholder="Ex.: 149,90">
                            </div>
                            <div>
                                <label for="duracao-legado">Duração comprovada</label>
                                <input type="number" id="duracao-legado" name="duracao_comprovada"
                                       min="1" max="65535" step="1" inputmode="numeric">
                            </div>
                            <div>
                                <label for="unidade-legado">Unidade da duração</label>
                                <select id="unidade-legado" name="unidade_comprovada">
                                    <option value="">Não comprovada</option>
                                    <option value="DIA">Dias</option>
                                    <option value="SEMANA">Semanas</option>
                                    <option value="MES">Meses</option>
                                </select>
                            </div>
                            <div>
                                <label for="referencia-legado">Dia de referência mensal</label>
                                <input type="number" id="referencia-legado" name="dia_referencia_calendario"
                                       min="1" max="31" step="1" inputmode="numeric">
                            </div>
                            <small class="field-hint">Na primeira confirmação, preço e duração ficam nulos quando não há prova. Na reconfirmação, valores já registrados são preservados; informe somente dados adicionais comprovados que coincidam com o snapshot. O dia de referência só se aplica a uma duração mensal.</small>
                        </fieldset>
                    <?php } ?>

                    <div class="legacy-review-form-actions">
                        <button class="button button-primary" type="submit">
                            <?= $somenteReabrir ? 'Reabrir revisão' : 'Registrar decisão auditada' ?>
                        </button>
                        <a class="button button-search" href="revisoes-legado.php?filtro=<?= escapar($filtro) ?>">Fechar análise</a>
                        <a class="button button-search" href="detalhes-aluno.php?id=<?= (int) $revisaoSelecionada['aluno_id_origem'] ?>">Abrir perfil do aluno</a>
                    </div>
                </form>
                <?php } ?>
            </section>

            <section class="settings-card account-card" aria-labelledby="legacy-review-events-title">
                <div class="settings-card-heading">
                    <div>
                        <h2 id="legacy-review-events-title">Histórico de decisões</h2>
                        <p>Eventos append-only registrados para esta revisão.</p>
                    </div>
                </div>
                <?php if ($eventosSelecionados === []) { ?>
                    <p class="student-history-empty">Nenhuma decisão registrada até o momento.</p>
                <?php } else { ?>
                    <ol class="legacy-review-event-list">
                        <?php foreach ($eventosSelecionados as $evento) { ?>
                            <li class="legacy-review-event">
                                <div class="legacy-review-event-heading">
                                    <strong><?= $evento['tipo_evento'] === 'RETIFICACAO_VIGENCIA' ? 'Retificação de vigência' : 'Decisão de revisão' ?></strong>
                                    <span><?= escapar(rotuloResultadoRevisaoLegado((string) $evento['resultado_novo'])) ?></span>
                                </div>
                                <small>
                                    <?= escapar($evento['responsavel'] ?? 'Funcionário não identificado') ?>
                                    · <?= escapar($evento['criado_em']) ?>
                                </small>
                                <?php if (!empty($evento['fonte_referencia'])) { ?>
                                    <p><strong>Fonte:</strong> <?= escapar($evento['fonte_referencia']) ?></p>
                                <?php } ?>
                                <?php if (
                                    $evento['tipo_evento'] === 'DECISAO'
                                    && $evento['resultado_novo'] === 'VINCULO_CONFIRMADO'
                                ) { ?>
                                    <p><strong>Intervalo comprovado na decisão:</strong>
                                        <?= escapar(formatarInstanteUtcNaAcademia($evento['inicio_novo'] ?? null)) ?>
                                        → <?= escapar(formatarInstanteUtcNaAcademia($evento['fim_exclusivo_novo'] ?? null)) ?>
                                    </p>
                                <?php } elseif ($evento['tipo_evento'] === 'RETIFICACAO_VIGENCIA') { ?>
                                    <p><strong>Intervalo anterior:</strong>
                                        <?= escapar(formatarInstanteUtcNaAcademia($evento['inicio_anterior'] ?? null)) ?>
                                        → <?= escapar(formatarInstanteUtcNaAcademia($evento['fim_exclusivo_anterior'] ?? null)) ?>
                                    </p>
                                    <p><strong>Novo intervalo:</strong>
                                        <?= escapar(formatarInstanteUtcNaAcademia($evento['inicio_novo'] ?? null)) ?>
                                        → <?= escapar(formatarInstanteUtcNaAcademia($evento['fim_exclusivo_novo'] ?? null)) ?>
                                    </p>
                                <?php } ?>
                                <?php if (!empty($evento['observacoes'])) { ?>
                                    <p><?= nl2br(escapar($evento['observacoes'])) ?></p>
                                <?php } ?>
                            </li>
                        <?php } ?>
                    </ol>
                <?php } ?>
            </section>
        <?php } ?>
    <?php } ?>

    <footer class="settings-footer">FitControl · Revisão de vínculos legados</footer>
</main>
</body>
</html>
