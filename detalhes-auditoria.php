<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/src/Security/AccessControl.php';

$usuario = exigirAutenticacao($pdo);
exigirPermissao($usuario, 'audit.view');

$entidadeRecebida = $_GET['entidade'] ?? '';
$idRecebido = $_GET['id'] ?? '';
$entidadesPermitidas = ['alunos', 'usuarios', 'planos', 'matriculas', 'revisoes_legado'];

if (
    !is_string($entidadeRecebida)
    || !in_array($entidadeRecebida, $entidadesPermitidas, true)
    || !is_string($idRecebido)
    || ($registroId = validarId($idRecebido)) === false
) {
    http_response_code(400);
    exit('Registro de auditoria inválido.');
}

$registroId = (int) $registroId;
$registro = null;
$tituloRegistro = '';
$descricaoRegistro = '';
$eventos = [];

switch ($entidadeRecebida) {
    case 'alunos':
        $consultaRegistro = $pdo->prepare('SELECT id, nome FROM alunos WHERE id = :id LIMIT 1');
        $consultaRegistro->execute(['id' => $registroId]);
        $registro = $consultaRegistro->fetch();
        $tituloRegistro = 'Aluno';
        if ($registro) {
            $descricaoRegistro = $registro['nome'];
        }
        break;

    case 'usuarios':
        $consultaRegistro = $pdo->prepare('SELECT id, nome, perfil, ativo FROM usuarios WHERE id = :id LIMIT 1');
        $consultaRegistro->execute(['id' => $registroId]);
        $registro = $consultaRegistro->fetch();
        $tituloRegistro = 'Funcionário';
        if ($registro) {
            $descricaoRegistro = $registro['nome'] . ' · ' . \FitControl\Security\AccessControl::label($registro['perfil']);
        }
        break;

    case 'planos':
        $consultaRegistro = $pdo->prepare(
            'SELECT id, nome, situacao, valor_atual FROM planos WHERE id = :id LIMIT 1'
        );
        $consultaRegistro->execute(['id' => $registroId]);
        $registro = $consultaRegistro->fetch();
        $tituloRegistro = 'Plano';
        if ($registro) {
            $descricaoRegistro = $registro['nome'] . ' · ' . $registro['situacao'];
        }
        break;

    case 'matriculas':
        $consultaRegistro = $pdo->prepare(
            'SELECT m.id, m.aluno_id, m.plano_nome_contratado, m.valor_contratado,
                    m.inicio_em, m.fim_exclusivo_em, m.cancelada_em, m.motivo_cancelamento,
                    a.nome AS aluno_nome
             FROM matriculas m
             INNER JOIN alunos a ON a.id = m.aluno_id
             WHERE m.id = :id
             LIMIT 1'
        );
        $consultaRegistro->execute(['id' => $registroId]);
        $registro = $consultaRegistro->fetch();
        $tituloRegistro = 'Matrícula';
        if ($registro) {
            $descricaoRegistro = 'Aluno: ' . $registro['aluno_nome']
                . ' · ' . ($registro['plano_nome_contratado'] ?: 'Plano legado');
        }
        break;

    case 'revisoes_legado':
        $consultaRegistro = $pdo->prepare(
            'SELECT r.id, r.aluno_id_origem, r.plano_texto_original,
                    r.resultado_atual, a.nome AS aluno_nome
             FROM revisoes_legado r
             INNER JOIN alunos a ON a.id = r.aluno_id_origem
             WHERE r.id = :id
             LIMIT 1'
        );
        $consultaRegistro->execute(['id' => $registroId]);
        $registro = $consultaRegistro->fetch();
        $tituloRegistro = 'Revisão de vínculo legado';
        if ($registro) {
            $descricaoRegistro = $registro['aluno_nome']
                . ' · ' . $registro['resultado_atual'];
        }
        break;
}

if (!$registro) {
    http_response_code(404);
    exit('Registro não encontrado ou indisponível.');
}

// Trilha geral: a consulta é sempre limitada à entidade e ao identificador validados.
$consultaAuditoria = $pdo->prepare(
    'SELECT a.id, a.usuario_id, a.acao, a.entidade, a.entidade_id, a.criado_em,
            u.nome AS responsavel_nome
     FROM auditoria a
     LEFT JOIN usuarios u ON u.id = a.usuario_id
     WHERE a.entidade = :entidade
       AND a.entidade_id = :registro_id
       AND a.acao <> "usuario_perfil_alterado"
     ORDER BY a.criado_em DESC, a.id DESC'
);
$consultaAuditoria->execute([
    'entidade' => $entidadeRecebida,
    'registro_id' => $registroId,
]);

foreach ($consultaAuditoria->fetchAll() as $evento) {
    $eventos[] = [
        'ordem' => 'A-' . str_pad((string) $evento['id'], 20, '0', STR_PAD_LEFT),
        'criado_em' => $evento['criado_em'],
        'responsavel' => $evento['responsavel_nome'] ?? ('Conta #' . ($evento['usuario_id'] ?? 'indisponível')),
        'tipo' => 'Ação administrativa',
        'descricao' => $evento['acao'],
        'detalhes' => 'Entidade: ' . $evento['entidade'] . ' · Registro #' . $evento['entidade_id'],
    ];
}

// Histórico especializado de perfis: somente alterações recebidas pelo funcionário consultado.
if ($entidadeRecebida === 'usuarios') {
    $consultaPerfis = $pdo->prepare(
        'SELECT e.id, e.responsavel_id, e.perfil_anterior, e.perfil_novo, e.criado_em,
                u.nome AS responsavel_nome
         FROM usuarios_perfis_eventos e
         LEFT JOIN usuarios u ON u.id = e.responsavel_id
         WHERE e.usuario_id = :usuario_id
         ORDER BY e.criado_em DESC, e.id DESC'
    );
    $consultaPerfis->execute(['usuario_id' => $registroId]);

    foreach ($consultaPerfis->fetchAll() as $evento) {
        $eventos[] = [
            'ordem' => 'P-' . str_pad((string) $evento['id'], 20, '0', STR_PAD_LEFT),
            'criado_em' => $evento['criado_em'],
            'responsavel' => $evento['responsavel_nome'] ?? ('Conta #' . $evento['responsavel_id']),
            'tipo' => 'Alteração de perfil',
            'descricao' => $evento['perfil_anterior'] . ' → ' . $evento['perfil_novo'],
            'detalhes' => 'Perfil atribuído ao funcionário #' . $registroId,
        ];
    }
}

// Decisões e retificações: preserva responsável, evidência e intervalos históricos.
if ($entidadeRecebida === 'alunos' || $entidadeRecebida === 'revisoes_legado' || $entidadeRecebida === 'matriculas') {
    $condicao = match ($entidadeRecebida) {
        'alunos' => 'r.aluno_id_origem = :registro_id',
        'revisoes_legado' => 'r.id = :registro_id',
        'matriculas' => 'e.matricula_id = :registro_id',
    };

    $consultaLegado = $pdo->prepare(
        'SELECT e.id, e.usuario_id, e.tipo_evento, e.resultado_anterior, e.resultado_novo,
                e.matricula_id, e.inicio_anterior, e.fim_exclusivo_anterior,
                e.inicio_novo, e.fim_exclusivo_novo, e.fonte_referencia,
                e.observacoes, e.criado_em, r.id AS revisao_id,
                u.nome AS responsavel_nome
         FROM revisoes_legado_eventos e
         INNER JOIN revisoes_legado r ON r.id = e.revisao_legado_id
         LEFT JOIN usuarios u ON u.id = e.usuario_id
         WHERE ' . $condicao . '
         ORDER BY e.criado_em DESC, e.id DESC'
    );
    $parametrosLegado = ['registro_id' => $registroId];
    $consultaLegado->execute($parametrosLegado);

    foreach ($consultaLegado->fetchAll() as $evento) {
        $detalhes = [
            'Revisão #' . $evento['revisao_id'],
            'Resultado: ' . $evento['resultado_anterior'] . ' → ' . $evento['resultado_novo'],
        ];

        if ($evento['matricula_id'] !== null) {
            $detalhes[] = 'Matrícula #' . $evento['matricula_id'];
        }
        if ($evento['inicio_anterior'] !== null || $evento['fim_exclusivo_anterior'] !== null) {
            $detalhes[] = 'Intervalo anterior: '
                . formatarInstanteUtcNaAcademia($evento['inicio_anterior'])
                . ' → ' . formatarInstanteUtcNaAcademia($evento['fim_exclusivo_anterior']);
        }
        if ($evento['inicio_novo'] !== null || $evento['fim_exclusivo_novo'] !== null) {
            $detalhes[] = 'Intervalo registrado: '
                . formatarInstanteUtcNaAcademia($evento['inicio_novo'])
                . ' → ' . formatarInstanteUtcNaAcademia($evento['fim_exclusivo_novo']);
        }
        if ($evento['fonte_referencia'] !== null && $evento['fonte_referencia'] !== '') {
            $detalhes[] = 'Evidência: ' . $evento['fonte_referencia'];
        }
        if ($evento['observacoes'] !== null && $evento['observacoes'] !== '') {
            $detalhes[] = 'Justificativa: ' . $evento['observacoes'];
        }

        $eventos[] = [
            'ordem' => 'L-' . str_pad((string) $evento['id'], 20, '0', STR_PAD_LEFT),
            'criado_em' => $evento['criado_em'],
            'responsavel' => $evento['responsavel_nome'] ?? ('Conta #' . $evento['usuario_id']),
            'tipo' => $evento['tipo_evento'] === 'RETIFICACAO_VIGENCIA'
                ? 'Retificação de vigência'
                : 'Decisão de revisão',
            'descricao' => $evento['tipo_evento'] === 'RETIFICACAO_VIGENCIA'
                ? 'Vigência corrigida'
                : $evento['resultado_novo'],
            'detalhes' => implode("\n", $detalhes),
        ];
    }
}

usort($eventos, static function (array $a, array $b): int {
    $data = strcmp($b['criado_em'], $a['criado_em']);
    return $data !== 0 ? $data : strcmp($b['ordem'], $a['ordem']);
});

$valorRegistro = $entidadeRecebida === 'planos' && $registro['valor_atual'] !== null
    ? ' · R$ ' . number_format((float) $registro['valor_atual'], 2, ',', '.')
    : '';
$inicioMatricula = $entidadeRecebida === 'matriculas'
    ? formatarInstanteUtcNaAcademia($registro['inicio_em'])
    : null;
$fimMatricula = $entidadeRecebida === 'matriculas'
    ? formatarInstanteUtcNaAcademia($registro['fim_exclusivo_em'])
    : null;

function urlVoltarAuditoria(): string
{
    return 'auditoria.php';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Histórico detalhado de um registro no FitControl.">
    <title>Detalhes da auditoria | FitControl</title>
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
            <a class="nav-link" href="auditoria.php">Central de auditoria</a>
            <a class="nav-link" href="index.php">Visão geral</a>
            <?php if (usuarioTemPermissao($usuario, 'users.manage')) { ?><a class="nav-link" href="usuarios.php">Funcionários</a><?php } ?>
        </nav>
        <div class="sidebar-bottom"><a class="nav-link" href="logout.php">Sair</a></div>
    </aside>
    <main class="app-main">
        <header class="topbar">
            <div><span class="eyebrow">SEGURANÇA E GOVERNANÇA</span><h1>Detalhes da auditoria</h1></div>
            <span class="account-current"><?= escapar($usuario['nome']) ?> · <?= escapar(\FitControl\Security\AccessControl::label($usuario['perfil'])) ?></span>
        </header>
        <div class="page-content">
            <section class="page-heading">
                <div>
                    <p class="eyebrow"><?= escapar($tituloRegistro) ?> #<?= $registroId ?></p>
                    <h2><?= escapar($descricaoRegistro) ?><?= escapar($valorRegistro) ?></h2>
                    <p>Histórico consolidado do registro. Os eventos são somente para consulta.</p>
                </div>
                <a class="button button-search" href="<?= escapar(urlVoltarAuditoria()) ?>">Voltar à central</a>
            </section>

            <?php if ($entidadeRecebida === 'matriculas') { ?>
                <section class="settings-card account-card" aria-labelledby="enrollment-summary-title">
                    <div class="settings-card-heading"><div><h2 id="enrollment-summary-title">Intervalo contratual</h2><p>Valores históricos da matrícula</p></div></div>
                    <dl class="legacy-review-original">
                        <div><dt>Início</dt><dd><?= escapar($inicioMatricula) ?></dd></div>
                        <div><dt>Fim exclusivo</dt><dd><?= escapar($fimMatricula) ?></dd></div>
                        <div><dt>Preço registrado</dt><dd><?= $registro['valor_contratado'] === null ? 'Não informado' : 'R$ ' . number_format((float) $registro['valor_contratado'], 2, ',', '.') ?></dd></div>
                        <?php if ($registro['cancelada_em'] !== null) { ?>
                            <div><dt>Cancelada em</dt><dd><?= escapar(formatarInstanteUtcNaAcademia($registro['cancelada_em'])) ?></dd></div>
                            <div><dt>Motivo</dt><dd><?= escapar($registro['motivo_cancelamento'] ?: 'Não informado') ?></dd></div>
                        <?php } ?>
                    </dl>
                </section>
            <?php } ?>

            <section class="settings-card account-card" aria-labelledby="record-events-title">
                <div class="settings-card-heading account-list-heading">
                    <div><h2 id="record-events-title">Linha do tempo</h2><p><?= count($eventos) ?> evento(s) encontrado(s), do mais recente para o mais antigo.</p></div>
                </div>
                <?php if ($eventos === []) { ?>
                    <div class="compact-empty"><strong>Nenhum evento registrado</strong><span>Este registro ainda não possui eventos na trilha de auditoria.</span></div>
                <?php } else { ?>
                    <ol class="audit-timeline">
                        <?php foreach ($eventos as $evento) { ?>
                            <li class="audit-timeline-item">
                                <div class="audit-timeline-marker" aria-hidden="true"></div>
                                <div class="audit-timeline-content">
                                    <div class="audit-timeline-meta">
                                        <strong><?= escapar($evento['tipo']) ?></strong>
                                        <time><?= escapar(formatarInstanteUtcNaAcademia($evento['criado_em'])) ?></time>
                                    </div>
                                    <p class="audit-timeline-action"><?= escapar($evento['descricao']) ?></p>
                                    <p class="audit-timeline-actor">Responsável: <?= escapar($evento['responsavel']) ?></p>
                                    <div class="audit-timeline-details"><?= nl2br(escapar($evento['detalhes'])) ?></div>
                                </div>
                            </li>
                        <?php } ?>
                    </ol>
                <?php } ?>
            </section>
            <footer class="page-footer"><span>FitControl · Histórico de auditoria</span><span>Projeto acadêmico de Desenvolvimento Web</span></footer>
        </div>
    </main>
</div>
</body>
</html>
