<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/src/Domain/Enrollment/EnrollmentPeriodCalculator.php';
require_once __DIR__ . '/src/Domain/Enrollment/EnrollmentStatus.php';

$usuario = exigirAutenticacao($pdo);
exigirPermissao($usuario, 'students.view');
$contextoLista = obterContextoListaAlunos($_GET);
$id = validarId($_GET['id'] ?? null);

if ($id === false) {
    http_response_code(400);
    exit('ID inválido.');
}

$consulta = $pdo->prepare(
    'SELECT id, nome, telefone, email, plano, ativo
     FROM alunos
     WHERE id = :id
     LIMIT 1'
);
$consulta->execute(['id' => $id]);
$aluno = $consulta->fetch(PDO::FETCH_ASSOC);

if (!$aluno) {
    http_response_code(404);
    exit('Aluno não encontrado.');
}

$historico = $pdo->prepare(
    'SELECT a.acao, a.criado_em, u.nome AS responsavel
     FROM auditoria a
     LEFT JOIN usuarios u ON u.id = a.usuario_id
     WHERE a.entidade = :entidade AND a.entidade_id = :id
       AND a.acao IN (:criado, :editado, :inativado, :reativado)
     ORDER BY a.criado_em DESC, a.id DESC
     LIMIT 50'
);
$historico->execute([
    'entidade' => 'alunos',
    'id' => $id,
    'criado' => 'aluno_criado',
    'editado' => 'aluno_editado',
    'inativado' => 'aluno_inativado',
    'reativado' => 'aluno_reativado'
]);
$eventos = $historico->fetchAll(PDO::FETCH_ASSOC);

// A consulta do histórico operacional só é habilitada quando a estrutura inteira
// necessária estiver instalada. Isso protege a tela durante migrations parciais.
$colunasHistoricoEsperadas = [
    'matriculas' => [
        'id',
        'aluno_id',
        'plano_nome_contratado',
        'valor_contratado',
        'duracao_contratada_valor',
        'duracao_contratada_unidade',
        'inicio_em',
        'fim_exclusivo_em',
        'cancelada_em',
        'origem',
        'motivo_cancelamento',
        'criado_em',
    ],
    'revisoes_legado' => [
        'aluno_id_origem',
        'resultado_atual',
        'fonte_referencia',
        'atualizado_em',
    ],
    'revisoes_legado_eventos' => [
        'id',
        'revisao_legado_id',
        'usuario_id',
        'tipo_evento',
        'resultado_anterior',
        'resultado_novo',
        'matricula_id',
        'inicio_anterior',
        'fim_exclusivo_anterior',
        'inicio_novo',
        'fim_exclusivo_novo',
        'fonte_referencia',
        'observacoes',
        'criado_em',
    ],
];

$condicoesColunas = [];
$parametrosColunas = [];
$indiceTabela = 0;

foreach ($colunasHistoricoEsperadas as $tabela => $colunas) {
    $parametroTabela = 'tabela_' . $indiceTabela;
    $parametrosColunas[$parametroTabela] = $tabela;
    $placeholders = [];

    foreach ($colunas as $indice => $coluna) {
        $parametroColuna = 'coluna_' . $indiceTabela . '_' . $indice;
        $placeholders[] = ':' . $parametroColuna;
        $parametrosColunas[$parametroColuna] = $coluna;
    }

    $condicoesColunas[] = '(TABLE_NAME = :' . $parametroTabela
        . ' AND COLUMN_NAME IN (' . implode(', ', $placeholders) . '))';
    $indiceTabela++;
}

$consultaEstrutura = $pdo->prepare(
    'SELECT COUNT(*)
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND (' . implode(' OR ', $condicoesColunas) . ')'
);
$consultaEstrutura->execute($parametrosColunas);
$totalColunasEsperadas = array_sum(array_map('count', $colunasHistoricoEsperadas));
$historicoMatriculasDisponivel = (int) $consultaEstrutura->fetchColumn() === $totalColunasEsperadas;

$matriculas = [];
$revisaoLegado = null;
$eventosRevisaoLegado = [];

if ($historicoMatriculasDisponivel) {
    $consultaMatriculas = $pdo->prepare(
        'SELECT
            matriculas.id,
            matriculas.plano_nome_contratado,
            matriculas.valor_contratado,
            matriculas.duracao_contratada_valor,
            matriculas.duracao_contratada_unidade,
            matriculas.inicio_em,
            matriculas.fim_exclusivo_em,
            matriculas.cancelada_em,
            matriculas.origem,
            matriculas.motivo_cancelamento,
            matriculas.criado_em,
            (
                SELECT u.nome
                FROM auditoria a
                LEFT JOIN usuarios u ON u.id = a.usuario_id
                WHERE a.acao = :acao_cancelamento
                  AND a.entidade = :entidade_cancelamento
                  AND a.entidade_id = matriculas.id
                ORDER BY a.id DESC
                LIMIT 1
            ) AS responsavel_cancelamento
         FROM matriculas
         WHERE aluno_id = :aluno_id
         ORDER BY inicio_em DESC, id DESC'
    );
    $consultaMatriculas->execute([
        'acao_cancelamento' => 'matricula_cancelada',
        'entidade_cancelamento' => 'matriculas',
        'aluno_id' => $id,
    ]);

    $fusoUtc = new DateTimeZone('UTC');
    $fusoAcademia = new DateTimeZone(
        FitControl\Domain\Enrollment\EnrollmentPeriodCalculator::ACADEMY_TIMEZONE
    );
    $instanteConsulta = new DateTimeImmutable('now', $fusoAcademia);

    foreach ($consultaMatriculas->fetchAll(PDO::FETCH_ASSOC) as $matricula) {
        $inicioLocal = (new DateTimeImmutable((string) $matricula['inicio_em'], $fusoUtc))
            ->setTimezone($fusoAcademia);
        $fimExclusivoLocal = (new DateTimeImmutable((string) $matricula['fim_exclusivo_em'], $fusoUtc))
            ->setTimezone($fusoAcademia);
        $cancelamentoLocal = $matricula['cancelada_em'] === null
            ? null
            : (new DateTimeImmutable((string) $matricula['cancelada_em'], $fusoUtc))
                ->setTimezone($fusoAcademia);

        $matricula['situacao_calculada'] = FitControl\Domain\Enrollment\EnrollmentStatus::derive(
            $inicioLocal,
            $fimExclusivoLocal,
            $cancelamentoLocal,
            $instanteConsulta
        );
        $matricula['inicio_local'] = $inicioLocal;
        $matricula['fim_exclusivo_local'] = $fimExclusivoLocal;
        $matricula['cancelamento_local'] = $cancelamentoLocal;
        $matriculas[] = $matricula;
    }

    $consultaRevisao = $pdo->prepare(
        'SELECT id, resultado_atual, fonte_referencia, atualizado_em
         FROM revisoes_legado
         WHERE aluno_id_origem = :aluno_id
         LIMIT 1'
    );
    $consultaRevisao->execute(['aluno_id' => $id]);
    $revisaoLegado = $consultaRevisao->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($revisaoLegado !== null) {
        $consultaEventosRevisao = $pdo->prepare(
            'SELECT
                e.tipo_evento,
                e.resultado_anterior,
                e.resultado_novo,
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
             LIMIT 10'
        );
        $consultaEventosRevisao->execute(['review_id' => $revisaoLegado['id']]);
        $eventosRevisaoLegado = $consultaEventosRevisao->fetchAll(PDO::FETCH_ASSOC);
    }
}

$rotulosEventos = [
    'aluno_criado' => 'Cadastro criado',
    'aluno_editado' => 'Dados cadastrais atualizados',
    'aluno_inativado' => 'Aluno inativado',
    'aluno_reativado' => 'Aluno reativado'
];
$ativo = (int) $aluno['ativo'] === 1;
$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Dados cadastrais e histórico de atividade do aluno no FitControl.">
    <title><?= escapar($aluno['nome']) ?> | FitControl</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="auth.css">
    <?= renderizarMonitorSessao() ?>
    <script src="theme.js"></script>
</head>
<body class="settings-body">
<main class="settings-page student-detail-page">
    <a class="settings-back" href="<?= escapar(urlListaAlunos($contextoLista)) ?>">← Voltar à listagem</a>
    <span class="eyebrow">PERFIL DO ALUNO · #<?= (int) $aluno['id'] ?></span>
    <div class="student-detail-heading">
        <div>
            <h1><?= escapar($aluno['nome']) ?></h1>
            <p class="settings-intro">Dados cadastrais e histórico de atividade.</p>
        </div>
        <span class="student-status <?= $ativo ? 'is-active' : 'is-inactive' ?>">
            <?= $ativo ? 'Ativo' : 'Inativo' ?>
        </span>
    </div>

    <?php if ($mensagem !== '') { ?>
        <div class="flash-message" role="status">
            <span class="flash-check" aria-hidden="true">✓</span>
            <?= escapar($mensagem) ?>
        </div>
    <?php } ?>

    <section class="settings-card detail-card" aria-labelledby="student-data-title">
        <div class="settings-card-heading">
            <span class="settings-icon" aria-hidden="true">◎</span>
            <div>
                <h2 id="student-data-title">Informações cadastrais</h2>
                <p>Dados atuais registrados no sistema.</p>
            </div>
        </div>
        <dl class="student-detail-grid">
            <div><dt>Nome completo</dt><dd><?= escapar($aluno['nome']) ?></dd></div>
            <div><dt>E-mail</dt><dd><a href="mailto:<?= escapar($aluno['email']) ?>"><?= escapar($aluno['email']) ?></a></dd></div>
            <div><dt>Telefone</dt><dd><a href="tel:<?= escapar(preg_replace('/[^0-9+]/', '', $aluno['telefone'])) ?>"><?= escapar($aluno['telefone']) ?></a></dd></div>
            <div><dt>Plano</dt><dd><?= escapar($aluno['plano']) ?></dd></div>
            <div><dt>Situação</dt><dd><?= $ativo ? 'Ativo' : 'Inativo' ?></dd></div>
        </dl>
        <div class="student-detail-actions">
            <a class="button button-primary" href="<?= escapar(urlCadastroAluno((int) $aluno['id'], $contextoLista)) ?>">Editar cadastro</a>
            <?php if (
                $historicoMatriculasDisponivel
                && $ativo
                && usuarioTemPermissao($usuario, 'enrollments.create')
                && ($revisaoLegado === null || $revisaoLegado['resultado_atual'] !== 'PENDENTE_REVISAO')
            ) { ?>
                <a class="button button-primary" href="planos.php?nova_matricula=1&amp;aluno_id=<?= (int) $aluno['id'] ?>">
                    Registrar matrícula
                </a>
            <?php } ?>
            <?php if (usuarioTemPermissao($usuario, 'students.status')) { ?>
            <form action="alterar-status-aluno.php" method="post">
                <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
                <input type="hidden" name="id" value="<?= (int) $aluno['id'] ?>">
                <input type="hidden" name="busca" value="<?= escapar($contextoLista['busca']) ?>">
                <input type="hidden" name="status" value="<?= escapar($contextoLista['status']) ?>">
                <input type="hidden" name="retorno" value="detalhes">
                <input type="hidden" name="acao" value="<?= $ativo ? 'inativar' : 'reativar' ?>">
                <button class="button <?= $ativo ? 'button-search' : 'button-primary' ?>" type="submit">
                    <?= $ativo ? 'Inativar aluno' : 'Reativar aluno' ?>
                </button>
            </form>
            <?php } ?>
        </div>
    </section>

    <section class="settings-card detail-card" aria-labelledby="enrollment-history-title">
        <div class="settings-card-heading">
            <span class="settings-icon" aria-hidden="true">▤</span>
            <div>
                <h2 id="enrollment-history-title">Histórico de matrículas</h2>
                <p>Contratos e condições registrados para este aluno.</p>
            </div>
        </div>

        <?php if (!$historicoMatriculasDisponivel) { ?>
            <p class="student-history-empty">
                O histórico operacional será habilitado após a instalação e validação das migrations do Bloco 03.
                Os dados cadastrais e o histórico de atividade continuam disponíveis.
            </p>
        <?php } else { ?>
            <?php if ($revisaoLegado !== null) {
                $situacaoRevisao = (string) $revisaoLegado['resultado_atual'];
                $rotulosRevisao = [
                    'VINCULO_CONFIRMADO' => 'Vínculo legado confirmado',
                    'LEGADO_SEM_VIGENCIA' => 'Sem vigência legada confirmada',
                    'PENDENTE_REVISAO' => 'Revisão do vínculo pendente',
                ];
                $classesRevisao = [
                    'VINCULO_CONFIRMADO' => 'is-active',
                    'LEGADO_SEM_VIGENCIA' => 'is-inactive',
                    'PENDENTE_REVISAO' => 'is-pending',
                ];
            ?>
                <div class="legacy-review-summary">
                    <div>
                        <strong>Revisão do vínculo antigo</strong>
                        <span class="account-status <?= escapar($classesRevisao[$situacaoRevisao] ?? 'is-pending') ?>">
                            <?= escapar($rotulosRevisao[$situacaoRevisao] ?? 'Situação não reconhecida') ?>
                        </span>
                    </div>
                    <?php if (!empty($revisaoLegado['fonte_referencia'])) { ?>
                        <small>Fonte registrada: <?= escapar($revisaoLegado['fonte_referencia']) ?></small>
                    <?php } ?>
                    <?php if ($situacaoRevisao === 'PENDENTE_REVISAO') { ?>
                        <p>Novas matrículas permanecem bloqueadas até uma decisão baseada em evidências.</p>
                    <?php } elseif ($situacaoRevisao === 'LEGADO_SEM_VIGENCIA') { ?>
                        <p>Não há vínculo antigo vigente confirmado. Uma nova contratação pode seguir o fluxo normal.</p>
                    <?php } else { ?>
                        <p>O vínculo anterior foi confirmado. Consulte abaixo o intervalo comprovado e seu histórico.</p>
                    <?php } ?>
                    <a class="button button-search legacy-review-profile-link"
                       href="revisoes-legado.php?filtro=todos&amp;review_id=<?= (int) $revisaoLegado['id'] ?>">
                        <?= $situacaoRevisao === 'PENDENTE_REVISAO' ? 'Analisar revisão' : 'Consultar revisão' ?>
                    </a>
                    <?php if ($eventosRevisaoLegado !== []) { ?>
                        <div class="legacy-review-profile-events">
                            <strong>Últimas decisões registradas</strong>
                            <ol>
                                <?php foreach ($eventosRevisaoLegado as $eventoRevisao) { ?>
                                    <li>
                                        <span><?= escapar($eventoRevisao['tipo_evento'] === 'RETIFICACAO_VIGENCIA' ? 'Retificação' : 'Decisão') ?> ·
                                            <?= escapar($rotulosRevisao[$eventoRevisao['resultado_novo']] ?? 'Resultado não reconhecido') ?></span>
                                        <small>
                                            <?= escapar($eventoRevisao['responsavel'] ?? 'Funcionário não identificado') ?>
                                            · <?= escapar($eventoRevisao['criado_em']) ?>
                                        </small>
                                        <?php if (
                                            $eventoRevisao['tipo_evento'] === 'DECISAO'
                                            && $eventoRevisao['resultado_novo'] === 'VINCULO_CONFIRMADO'
                                        ) { ?>
                                            <small>Vigência comprovada:
                                                <?= escapar(formatarInstanteUtcNaAcademia($eventoRevisao['inicio_novo'] ?? null)) ?>
                                                → <?= escapar(formatarInstanteUtcNaAcademia($eventoRevisao['fim_exclusivo_novo'] ?? null)) ?>
                                            </small>
                                        <?php } elseif ($eventoRevisao['tipo_evento'] === 'RETIFICACAO_VIGENCIA') { ?>
                                            <small>Intervalo anterior:
                                                <?= escapar(formatarInstanteUtcNaAcademia($eventoRevisao['inicio_anterior'] ?? null)) ?>
                                                → <?= escapar(formatarInstanteUtcNaAcademia($eventoRevisao['fim_exclusivo_anterior'] ?? null)) ?>
                                            </small>
                                            <small>Novo intervalo:
                                                <?= escapar($eventoRevisao['inicio_novo'] ?? 'Não informado') ?>
                                                → <?= escapar(formatarInstanteUtcNaAcademia($eventoRevisao['fim_exclusivo_novo'] ?? null)) ?>
                                            </small>
                                        <?php } ?>
                                        <?php if (!empty($eventoRevisao['fonte_referencia'])) { ?>
                                            <small>Fonte: <?= escapar($eventoRevisao['fonte_referencia']) ?></small>
                                        <?php } ?>
                                        <?php if (!empty($eventoRevisao['observacoes'])) { ?>
                                            <p><?= nl2br(escapar($eventoRevisao['observacoes'])) ?></p>
                                        <?php } ?>
                                    </li>
                                <?php } ?>
                            </ol>
                        </div>
                    <?php } ?>
                </div>
            <?php } ?>

            <?php if ($matriculas === []) { ?>
                <p class="student-history-empty">Nenhuma matrícula operacional registrada para este aluno.</p>
            <?php } else { ?>
                <ol class="enrollment-history-list">
                    <?php foreach ($matriculas as $matricula) {
                        $situacao = $matricula['situacao_calculada'];
                        $rotulosSituacao = [
                            'AGENDADA' => 'Agendada',
                            'ATIVA' => 'Ativa',
                            'VENCIDA' => 'Vencida',
                            'CANCELADA' => 'Cancelada',
                        ];
                        $classesSituacao = [
                            'AGENDADA' => 'is-scheduled',
                            'ATIVA' => 'is-active',
                            'VENCIDA' => 'is-expired',
                            'CANCELADA' => 'is-cancelled',
                        ];
                        $unidadesDuracao = [
                            'DIA' => ((int) $matricula['duracao_contratada_valor'] === 1 ? 'dia' : 'dias'),
                            'SEMANA' => ((int) $matricula['duracao_contratada_valor'] === 1 ? 'semana' : 'semanas'),
                            'MES' => ((int) $matricula['duracao_contratada_valor'] === 1 ? 'mês' : 'meses'),
                        ];
                        $duracaoContratada = $matricula['duracao_contratada_valor'] === null
                            || $matricula['duracao_contratada_unidade'] === null
                            ? 'Não informada'
                            : (int) $matricula['duracao_contratada_valor'] . ' '
                                . ($unidadesDuracao[$matricula['duracao_contratada_unidade']] ?? 'unidades');
                        $precoContratado = $matricula['valor_contratado'] === null
                            ? 'Não informado'
                            : 'R$ ' . number_format((float) $matricula['valor_contratado'], 2, ',', '.');
                    ?>
                        <li class="enrollment-history-item">
                            <div class="enrollment-history-heading">
                                <div>
                                    <strong><?= escapar($matricula['plano_nome_contratado'] ?? 'Plano legado sem identificação') ?></strong>
                                    <small><?= $matricula['origem'] === 'LEGADO' ? 'Registro legado' : 'Contratação pelo sistema' ?></small>
                                </div>
                                <span class="enrollment-status <?= escapar($classesSituacao[$situacao] ?? 'is-expired') ?>">
                                    <?= escapar($rotulosSituacao[$situacao] ?? $situacao) ?>
                                </span>
                            </div>
                            <dl class="enrollment-detail-grid">
                                <div>
                                    <dt>Início</dt>
                                    <dd><?= escapar($matricula['inicio_local']->format('d/m/Y H:i')) ?></dd>
                                </div>
                                <div>
                                    <dt>Fim exclusivo</dt>
                                    <dd><?= escapar($matricula['fim_exclusivo_local']->format('d/m/Y H:i')) ?></dd>
                                </div>
                                <div><dt>Preço contratado</dt><dd><?= escapar($precoContratado) ?></dd></div>
                                <div><dt>Duração contratada</dt><dd><?= escapar($duracaoContratada) ?></dd></div>
                            </dl>
                            <?php if ($matricula['cancelamento_local'] !== null) { ?>
                                <p class="enrollment-cancellation-note">
                                    Cancelada em <?= escapar($matricula['cancelamento_local']->format('d/m/Y H:i')) ?>
                                    <?php if (!empty($matricula['responsavel_cancelamento'])) { ?>
                                        · por <?= escapar($matricula['responsavel_cancelamento']) ?>
                                    <?php } ?>
                                    <?php if (!empty($matricula['motivo_cancelamento'])) { ?>
                                        · <?= escapar($matricula['motivo_cancelamento']) ?>
                                    <?php } ?>
                                </p>
                            <?php } elseif (
                                in_array($situacao, ['AGENDADA', 'ATIVA'], true)
                                && usuarioTemPermissao($usuario, 'enrollments.cancel')
                            ) { ?>
                                <form action="cancelar-matricula.php" method="post" class="enrollment-cancel-form">
                                    <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
                                    <input type="hidden" name="student_id" value="<?= (int) $aluno['id'] ?>">
                                    <input type="hidden" name="enrollment_id" value="<?= (int) $matricula['id'] ?>">
                                    <label for="motivo-cancelamento-<?= (int) $matricula['id'] ?>">
                                        Motivo do cancelamento <span class="optional-label">opcional</span>
                                    </label>
                                    <input type="text"
                                           id="motivo-cancelamento-<?= (int) $matricula['id'] ?>"
                                           name="motivo_cancelamento"
                                           maxlength="255"
                                           placeholder="Ex.: solicitação do aluno">
                                    <button class="button button-search" type="submit">Cancelar matrícula</button>
                                    <small class="field-hint">
                                        O cancelamento é imediato e ficará registrado no histórico com o funcionário responsável.
                                    </small>
                                </form>
                            <?php } ?>
                        </li>
                    <?php } ?>
                </ol>
            <?php } ?>
        <?php } ?>
    </section>

    <section class="settings-card detail-card" aria-labelledby="student-history-title">
        <div class="settings-card-heading">
            <span class="settings-icon" aria-hidden="true">↻</span>
            <div>
                <h2 id="student-history-title">Histórico de atividade</h2>
                <p>Últimos <?= count($eventos) ?> eventos registrados para este aluno.</p>
            </div>
        </div>
        <?php if (count($eventos) === 0) { ?>
            <p class="student-history-empty">Nenhum evento de auditoria disponível para este cadastro.</p>
        <?php } else { ?>
            <ol class="student-timeline">
                <?php foreach ($eventos as $evento) { ?>
                    <li>
                        <span class="timeline-marker" aria-hidden="true"></span>
                        <div class="timeline-event">
                            <strong><?= escapar($rotulosEventos[$evento['acao']] ?? 'Atividade registrada') ?></strong>
                            <time datetime="<?= escapar(date(DATE_ATOM, strtotime($evento['criado_em']))) ?>">
                                <?= escapar(date('d/m/Y H:i:s', strtotime($evento['criado_em']))) ?>
                            </time>
                            <small><?= $evento['responsavel'] !== null ? 'Por ' . escapar($evento['responsavel']) : 'Responsável não identificado' ?></small>
                        </div>
                    </li>
                <?php } ?>
            </ol>
        <?php } ?>
    </section>
</main>
</body>
</html>
