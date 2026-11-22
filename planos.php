<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/src/Domain/Enrollment/EnrollmentPeriodCalculator.php';
require_once __DIR__ . '/src/Domain/Enrollment/EnrollmentInterval.php';
require_once __DIR__ . '/src/Domain/Enrollment/EnrollmentCreationService.php';

use FitControl\Domain\Enrollment\EnrollmentCreationService;
use FitControl\Domain\Enrollment\EnrollmentPeriodCalculator;

$usuario = exigirAutenticacao($pdo);
exigirPermissao($usuario, 'plans.view');

function planosTabelaDisponivel(PDO $pdo): bool {
    $consulta = $pdo->query(
        "SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN ('planos', 'matriculas')"
    );

    return (int) $consulta->fetchColumn() === 2;
}

$estruturaDisponivel = planosTabelaDisponivel($pdo);
$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
$mensagemErroMatricula = '';
$novaMatriculaSolicitada = ($_GET['nova_matricula'] ?? '') === '1';
$alunoMatriculaSelecionado = null;
$planoMatriculaSelecionado = null;
$dataInicioMatricula = (new DateTimeImmutable(
    'now',
    new DateTimeZone(EnrollmentPeriodCalculator::ACADEMY_TIMEZONE)
))->format('Y-m-d');
$horaInicioMatricula = '';
$estruturaMatriculasDisponivel = false;
$alunosAtivosMatricula = [];
$planosAtivosMatricula = [];

$colunasMatriculaObrigatorias = [
    'planos' => ['id', 'nome', 'duracao_valor', 'duracao_unidade', 'valor_atual', 'situacao'],
    'matriculas' => [
        'id', 'aluno_id', 'plano_id', 'plano_nome_contratado', 'valor_contratado',
        'duracao_contratada_valor', 'duracao_contratada_unidade', 'inicio_em',
        'fim_exclusivo_em', 'dia_referencia_calendario', 'cancelada_em',
        'origem', 'motivo_cancelamento', 'criado_em',
    ],
    'revisoes_legado' => ['aluno_id_origem', 'resultado_atual'],
];

if ($estruturaDisponivel) {
    $estruturaMatriculasDisponivel = true;

    foreach ($colunasMatriculaObrigatorias as $tabela => $colunas) {
        $placeholders = [];
        $parametros = ['tabela' => $tabela];

        foreach ($colunas as $indice => $coluna) {
            $placeholder = 'coluna_' . $indice;
            $placeholders[] = ':' . $placeholder;
            $parametros[$placeholder] = $coluna;
        }

        $consultaColunas = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :tabela
               AND COLUMN_NAME IN (' . implode(', ', $placeholders) . ')'
        );
        $consultaColunas->execute($parametros);

        if ((int) $consultaColunas->fetchColumn() !== count($colunas)) {
            $estruturaMatriculasDisponivel = false;
            break;
        }
    }
}

if ($estruturaMatriculasDisponivel) {
    $alunosAtivosMatricula = $pdo->query(
        'SELECT id, nome FROM alunos WHERE ativo = 1 ORDER BY nome ASC, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $planosAtivosMatricula = $pdo->query(
        "SELECT id, nome, duracao_valor, duracao_unidade, valor_atual
         FROM planos
         WHERE situacao = 'ATIVO'
           AND valor_atual > 0
           AND duracao_valor > 0
           AND duracao_unidade IN ('DIA', 'SEMANA', 'MES')
         ORDER BY nome ASC, id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['acao'] ?? '') === 'criar_matricula') {
    validarToken();
    $novaMatriculaSolicitada = true;

    $alunoRecebido = $_POST['aluno_id'] ?? '';
    $alunoValidado = validarId($alunoRecebido);
    $planoRecebido = $_POST['plano_id'] ?? '';
    $planoValidado = validarId($planoRecebido);

    $alunoMatriculaSelecionado = $alunoValidado === false ? null : (int) $alunoValidado;
    $planoMatriculaSelecionado = $planoValidado === false ? null : (int) $planoValidado;
    $dataRecebida = $_POST['data_inicio'] ?? '';
    $horaRecebida = $_POST['hora_inicio'] ?? '';
    $dadosDataHoraValidos = is_string($dataRecebida) && is_string($horaRecebida);
    $dataInicioMatricula = is_string($dataRecebida) ? $dataRecebida : '';
    $horaInicioMatricula = is_string($horaRecebida) ? $horaRecebida : '';

    try {
        if (!$dadosDataHoraValidos) {
            throw new InvalidArgumentException('Informe uma data e um horário válidos.');
        }
        if (!$estruturaMatriculasDisponivel) {
            throw new DomainException('A estrutura de matrículas ainda não está instalada.');
        }
        if ($alunoMatriculaSelecionado === null) {
            throw new InvalidArgumentException('Selecione um aluno ativo.');
        }
        if ($planoMatriculaSelecionado === null) {
            throw new InvalidArgumentException('Selecione um plano válido.');
        }
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $dataInicioMatricula) !== 1) {
            throw new InvalidArgumentException('Informe uma data de início válida.');
        }

        $fusoAcademia = new DateTimeZone(EnrollmentPeriodCalculator::ACADEMY_TIMEZONE);
        $agoraLocal = new DateTimeImmutable('now', $fusoAcademia);
        $diaInicio = DateTimeImmutable::createFromFormat('!Y-m-d', $dataInicioMatricula, $fusoAcademia);
        $errosData = DateTimeImmutable::getLastErrors();

        if (
            !$diaInicio instanceof DateTimeImmutable
            || $diaInicio->format('Y-m-d') !== $dataInicioMatricula
            || (is_array($errosData) && ($errosData['warning_count'] > 0 || $errosData['error_count'] > 0))
        ) {
            throw new InvalidArgumentException('Informe uma data de início válida.');
        }
        if ($diaInicio->format('Y-m-d') < $agoraLocal->format('Y-m-d')) {
            throw new DomainException('A data de início não pode estar no passado.');
        }

        if ($horaInicioMatricula === '') {
            $inicioLocal = $diaInicio->format('Y-m-d') === $agoraLocal->format('Y-m-d')
                ? $agoraLocal
                : $diaInicio;
        } else {
            if (preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/D', $horaInicioMatricula) !== 1) {
                throw new InvalidArgumentException('Informe um horário válido.');
            }

            $inicioLocal = DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i',
                $dataInicioMatricula . ' ' . $horaInicioMatricula,
                $fusoAcademia
            );
            $errosHora = DateTimeImmutable::getLastErrors();

            if (
                !$inicioLocal instanceof DateTimeImmutable
                || $inicioLocal->format('Y-m-d H:i') !== $dataInicioMatricula . ' ' . $horaInicioMatricula
                || (is_array($errosHora) && ($errosHora['warning_count'] > 0 || $errosHora['error_count'] > 0))
            ) {
                throw new InvalidArgumentException('Informe um horário válido.');
            }
        }

        $service = new EnrollmentCreationService(
            $pdo,
            static fn (): DateTimeImmutable => new DateTimeImmutable(
                'now',
                new DateTimeZone(EnrollmentPeriodCalculator::ACADEMY_TIMEZONE)
            )
        );
        $matriculaId = $service->create(
            $alunoMatriculaSelecionado,
            $planoMatriculaSelecionado,
            (int) $usuario['id'],
            $inicioLocal,
            $horaInicioMatricula === ''
                && $dataInicioMatricula === $agoraLocal->format('Y-m-d')
        );

        $_SESSION['mensagem'] = 'Matrícula #' . $matriculaId . ' criada com sucesso.';
        header('Location: detalhes-aluno.php?id=' . $alunoMatriculaSelecionado, true, 303);
        exit;
    } catch (DomainException | InvalidArgumentException $exception) {
        $mensagemErroMatricula = $exception->getMessage();
        http_response_code(422);
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        http_response_code(500);
        $mensagemErroMatricula = 'Não foi possível criar a matrícula. Consulte o log do servidor.';
    }
}

if ($novaMatriculaSolicitada && isset($_GET['aluno_id'])) {
    $alunoGet = validarId($_GET['aluno_id']);
    if ($alunoGet === false) {
        http_response_code(400);
        exit('Identificador de aluno inválido.');
    }
    $alunoMatriculaSelecionado = (int) $alunoGet;
}

$planoEdicao = null;
$erroConsulta = '';

if ($estruturaDisponivel) {
    $editarId = $_GET['editar'] ?? null;

    if ($editarId !== null) {
        $editarId = validarId($editarId);
        if ($editarId === false) {
            http_response_code(400);
            exit('Identificador de plano inválido.');
        }

        $consultaPlano = $pdo->prepare(
            'SELECT id, nome, descricao, duracao_valor, duracao_unidade,
                    valor_atual, situacao
             FROM planos
             WHERE id = :id
             LIMIT 1'
        );
        $consultaPlano->execute(['id' => $editarId]);
        $planoEdicao = $consultaPlano->fetch();

        if (!$planoEdicao) {
            http_response_code(404);
            exit('Plano não encontrado.');
        }
    }

    $planos = $pdo->query(
        "SELECT
            p.id,
            p.nome,
            p.descricao,
            p.duracao_valor,
            p.duracao_unidade,
            p.valor_atual,
            p.situacao,
            (SELECT COUNT(*) FROM matriculas m WHERE m.plano_id = p.id) AS total_matriculas
         FROM planos p
         ORDER BY
            CASE p.situacao
                WHEN 'ATIVO' THEN 1
                WHEN 'RASCUNHO' THEN 2
                ELSE 3
            END,
            p.nome ASC,
            p.id ASC"
    )->fetchAll();
} else {
    $planos = [];
}

$totalAtivos = 0;
$totalRascunhos = 0;
$totalInativos = 0;
foreach ($planos as $plano) {
    if ($plano['situacao'] === 'ATIVO') {
        $totalAtivos++;
    } elseif ($plano['situacao'] === 'RASCUNHO') {
        $totalRascunhos++;
    } else {
        $totalInativos++;
    }
}

function rotuloDuracaoPlano(array $plano): string {
    if ($plano['duracao_valor'] === null || $plano['duracao_unidade'] === null) {
        return 'Não definida';
    }

    $unidades = [
        'DIA' => ((int) $plano['duracao_valor'] === 1 ? 'dia' : 'dias'),
        'SEMANA' => ((int) $plano['duracao_valor'] === 1 ? 'semana' : 'semanas'),
        'MES' => ((int) $plano['duracao_valor'] === 1 ? 'mês' : 'meses'),
    ];

    return (int) $plano['duracao_valor'] . ' ' . ($unidades[$plano['duracao_unidade']] ?? 'unidades');
}

function rotuloPrecoPlano(?string $valor): string {
    if ($valor === null) {
        return 'Não definido';
    }

    return 'R$ ' . number_format((float) $valor, 2, ',', '.');
}

function classeSituacaoPlano(string $situacao): string {
    return match ($situacao) {
        'ATIVO' => 'is-active',
        'INATIVO' => 'is-inactive',
        default => 'is-draft',
    };
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Gerencie o catálogo de planos do FitControl.">
    <title>Planos | FitControl</title>
    <link rel="stylesheet" href="style.css">
    <?= renderizarMonitorSessao() ?>
    <script src="theme.js"></script>
</head>
<body class="settings-body">
<main class="settings-page users-page plans-page">
    <a class="settings-back" href="index.php">← Voltar ao painel</a>
    <span class="eyebrow">CATÁLOGO COMERCIAL</span>
    <h1>Planos</h1>
    <p class="settings-intro">Organize as opções disponíveis para novas matrículas sem alterar o histórico das contratações existentes.</p>
    <?php if ($estruturaMatriculasDisponivel) { ?>
        <a class="button button-primary enrollment-create-cta" href="planos.php?nova_matricula=1">Registrar matrícula</a>
    <?php } ?>

    <?php if ($mensagem !== '') { ?>
        <div class="flash-message" role="status">
            <span class="flash-check" aria-hidden="true">✓</span>
            <?= escapar($mensagem) ?>
        </div>
    <?php } ?>

    <?php if (!$estruturaDisponivel) { ?>
        <section class="settings-card account-card" aria-labelledby="plans-not-ready">
            <div class="settings-card-heading">
                <span class="settings-icon" aria-hidden="true">◷</span>
                <div>
                    <h2 id="plans-not-ready">Catálogo ainda não instalado</h2>
                    <p>A estrutura de planos e matrículas será habilitada pelas migrations do Bloco 03.</p>
                </div>
            </div>
            <p class="plan-setup-note">
                Nenhum dado foi alterado. Depois da instalação e validação das migrations 005 e 006, esta tela permitirá criar, editar, ativar, inativar e consultar os planos.
            </p>
        </section>
    <?php } else { ?>
        <?php if ($novaMatriculaSolicitada) { ?>
            <section class="settings-card account-card" aria-labelledby="enrollment-form-title">
                <div class="settings-card-heading">
                    <span class="settings-icon" aria-hidden="true">＋</span>
                    <div>
                        <h2 id="enrollment-form-title">Nova matrícula</h2>
                        <p>O preço, o nome e a duração do plano serão registrados como snapshot da contratação.</p>
                    </div>
                </div>

                <?php if ($mensagemErroMatricula !== '') { ?>
                    <div class="flash-message enrollment-error" role="alert"><?= escapar($mensagemErroMatricula) ?></div>
                <?php } ?>

                <?php if (!$estruturaMatriculasDisponivel) { ?>
                    <p class="plan-setup-note">O fluxo de contratação será habilitado após a instalação e validação das migrations do Bloco 03.</p>
                <?php } elseif ($alunosAtivosMatricula === [] || $planosAtivosMatricula === []) { ?>
                    <p class="plan-setup-note">
                        <?php if ($alunosAtivosMatricula === []) { ?>Não há alunos ativos disponíveis.<?php } ?>
                        <?php if ($planosAtivosMatricula === []) { ?>Não há planos ativos com preço e duração válidos.<?php } ?>
                    </p>
                <?php } else { ?>
                    <form action="planos.php?nova_matricula=1" method="post" class="account-create-form enrollment-create-form">
                        <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
                        <input type="hidden" name="acao" value="criar_matricula">

                        <div>
                            <label for="matricula-aluno">Aluno</label>
                            <select id="matricula-aluno" name="aluno_id" required>
                                <option value="">Selecione um aluno</option>
                                <?php foreach ($alunosAtivosMatricula as $alunoMatricula) { ?>
                                    <option value="<?= (int) $alunoMatricula['id'] ?>"
                                        <?= $alunoMatriculaSelecionado === (int) $alunoMatricula['id'] ? 'selected' : '' ?>>
                                        <?= escapar($alunoMatricula['nome']) ?> · #<?= (int) $alunoMatricula['id'] ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>

                        <div>
                            <label for="matricula-plano">Plano</label>
                            <select id="matricula-plano" name="plano_id" required>
                                <option value="">Selecione um plano ativo</option>
                                <?php foreach ($planosAtivosMatricula as $planoMatricula) {
                                    $unidadesMatricula = [
                                        'DIA' => ((int) $planoMatricula['duracao_valor'] === 1 ? 'dia' : 'dias'),
                                        'SEMANA' => ((int) $planoMatricula['duracao_valor'] === 1 ? 'semana' : 'semanas'),
                                        'MES' => ((int) $planoMatricula['duracao_valor'] === 1 ? 'mês' : 'meses'),
                                    ];
                                    $duracaoMatricula = (int) $planoMatricula['duracao_valor'] . ' '
                                        . ($unidadesMatricula[$planoMatricula['duracao_unidade']] ?? 'unidades');
                                ?>
                                    <option value="<?= (int) $planoMatricula['id'] ?>"
                                        <?= $planoMatriculaSelecionado === (int) $planoMatricula['id'] ? 'selected' : '' ?>>
                                        <?= escapar($planoMatricula['nome']) ?>
                                        · R$ <?= escapar(number_format((float) $planoMatricula['valor_atual'], 2, ',', '.')) ?>
                                        · <?= escapar($duracaoMatricula) ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>

                        <div>
                            <label for="matricula-data-inicio">Data de início</label>
                            <input type="date" id="matricula-data-inicio" name="data_inicio"
                                   min="<?= escapar((new DateTimeImmutable('now', new DateTimeZone(EnrollmentPeriodCalculator::ACADEMY_TIMEZONE)))->format('Y-m-d')) ?>"
                                   value="<?= escapar($dataInicioMatricula) ?>" required>
                        </div>

                        <div>
                            <label for="matricula-hora-inicio">Horário <span class="optional-label">opcional</span></label>
                            <input type="time" id="matricula-hora-inicio" name="hora_inicio"
                                   value="<?= escapar($horaInicioMatricula) ?>">
                            <small class="field-hint">Hoje sem horário usa o instante atual; uma data futura começa às 00:00 no fuso da academia.</small>
                        </div>

                        <div class="enrollment-create-actions">
                            <button class="button button-primary" type="submit">Confirmar matrícula</button>
                            <a class="button button-search" href="planos.php">Cancelar</a>
                        </div>
                    </form>
                <?php } ?>
            </section>
        <?php } ?>

        <section class="plan-summary-grid" aria-label="Resumo do catálogo">
            <article class="plan-summary-card">
                <span>Ativos</span>
                <strong><?= $totalAtivos ?></strong>
            </article>
            <article class="plan-summary-card">
                <span>Rascunhos</span>
                <strong><?= $totalRascunhos ?></strong>
            </article>
            <article class="plan-summary-card">
                <span>Inativos</span>
                <strong><?= $totalInativos ?></strong>
            </article>
        </section>

        <section class="settings-card account-card" aria-labelledby="plan-form-title">
            <div class="settings-card-heading">
                <span class="settings-icon" aria-hidden="true"><?= $planoEdicao ? '✎' : '＋' ?></span>
                <div>
                    <h2 id="plan-form-title"><?= $planoEdicao ? 'Editar plano' : 'Adicionar plano' ?></h2>
                    <p>Planos incompletos podem ser salvos como rascunho e ativados depois.</p>
                </div>
            </div>

            <form action="salvar-plano.php" method="post" class="account-create-form plan-form">
                <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
                <?php if ($planoEdicao) { ?>
                    <input type="hidden" name="id" value="<?= (int) $planoEdicao['id'] ?>">
                <?php } ?>

                <div>
                    <label for="nome-plano">Nome do plano</label>
                    <input type="text" id="nome-plano" name="nome" maxlength="100"
                           value="<?= escapar($planoEdicao['nome'] ?? '') ?>"
                           autocomplete="off" required>
                </div>

                <div>
                    <label for="descricao-plano">Descrição <span class="optional-label">opcional</span></label>
                    <input type="text" id="descricao-plano" name="descricao" maxlength="255"
                           value="<?= escapar($planoEdicao['descricao'] ?? '') ?>">
                </div>

                <div>
                    <label for="duracao-valor">Duração</label>
                    <input type="number" id="duracao-valor" name="duracao_valor"
                           min="1" max="65535" step="1" inputmode="numeric"
                           value="<?= escapar($planoEdicao['duracao_valor'] ?? '') ?>"
                           placeholder="Ex.: 1">
                </div>

                <div>
                    <label for="duracao-unidade">Unidade</label>
                    <select id="duracao-unidade" name="duracao_unidade">
                        <option value="">Selecione</option>
                        <?php foreach (['DIA' => 'Dias', 'SEMANA' => 'Semanas', 'MES' => 'Meses'] as $valor => $rotulo) { ?>
                            <option value="<?= escapar($valor) ?>"
                                <?= ($planoEdicao['duracao_unidade'] ?? '') === $valor ? 'selected' : '' ?>>
                                <?= escapar($rotulo) ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>

                <div>
                    <label for="valor-plano">Preço (R$)</label>
                    <input type="text" id="valor-plano" name="valor_atual"
                           inputmode="decimal" maxlength="11"
                           value="<?= escapar($planoEdicao['valor_atual'] ?? '') ?>"
                           placeholder="Ex.: 149,90">
                    <small class="field-hint">Use vírgula ou ponto nos centavos. Preço zero não permite ativação.</small>
                </div>

                <div class="plan-form-actions">
                    <button class="button button-primary" type="submit">
                        <?= $planoEdicao ? 'Salvar alterações' : 'Salvar rascunho' ?>
                    </button>
                    <?php if ($planoEdicao) { ?>
                        <a class="button button-search" href="planos.php">Cancelar edição</a>
                    <?php } ?>
                </div>
            </form>
        </section>

        <section class="settings-card account-card" aria-labelledby="plan-list-title">
            <div class="settings-card-heading account-list-heading">
                <div>
                    <h2 id="plan-list-title">Catálogo cadastrado</h2>
                    <p><?= count($planos) ?> plano(s) · valores e durações preservados nas matrículas antigas</p>
                </div>
            </div>

            <?php if ($planos === []) { ?>
                <div class="compact-empty">
                    <strong>Nenhum plano cadastrado</strong>
                    <span>Crie o primeiro rascunho usando o formulário acima.</span>
                </div>
            <?php } else { ?>
                <div class="table-scroll" tabindex="0" aria-label="Tabela de planos; role horizontalmente para ver todas as colunas">
                    <table class="students-table accounts-table plans-table">
                        <thead>
                            <tr>
                                <th scope="col">PLANO</th>
                                <th scope="col">DURAÇÃO</th>
                                <th scope="col">PREÇO ATUAL</th>
                                <th scope="col">MATRÍCULAS</th>
                                <th scope="col">STATUS</th>
                                <th scope="col">AÇÕES</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($planos as $plano) {
                                $situacao = (string) $plano['situacao'];
                                $totalVinculos = (int) $plano['total_matriculas'];
                            ?>
                                <tr>
                                    <td>
                                        <div class="account-person plan-name-cell">
                                            <strong><?= escapar($plano['nome']) ?></strong>
                                            <?php if ($plano['descricao'] !== null && $plano['descricao'] !== '') { ?>
                                                <small><?= escapar($plano['descricao']) ?></small>
                                            <?php } ?>
                                        </div>
                                    </td>
                                    <td><?= escapar(rotuloDuracaoPlano($plano)) ?></td>
                                    <td><?= escapar(rotuloPrecoPlano($plano['valor_atual'] === null ? null : (string) $plano['valor_atual'])) ?></td>
                                    <td><?= $totalVinculos ?></td>
                                    <td>
                                        <span class="account-status <?= escapar(classeSituacaoPlano($situacao)) ?>">
                                            <?= escapar($situacao === 'ATIVO' ? 'Ativo' : ($situacao === 'INATIVO' ? 'Inativo' : 'Rascunho')) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="plan-actions">
                                            <a class="button button-search" href="planos.php?editar=<?= (int) $plano['id'] ?>">Editar</a>

                                            <?php if ($situacao === 'ATIVO') { ?>
                                                <form action="gerenciar-plano.php" method="post">
                                                    <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
                                                    <input type="hidden" name="id" value="<?= (int) $plano['id'] ?>">
                                                    <input type="hidden" name="acao" value="inativar">
                                                    <button class="button button-search" type="submit">Inativar</button>
                                                </form>
                                            <?php } else { ?>
                                                <form action="gerenciar-plano.php" method="post">
                                                    <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
                                                    <input type="hidden" name="id" value="<?= (int) $plano['id'] ?>">
                                                    <input type="hidden" name="acao" value="ativar">
                                                    <button class="button button-primary" type="submit">Ativar</button>
                                                </form>
                                            <?php } ?>

                                            <form action="gerenciar-plano.php" method="post">
                                                <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
                                                <input type="hidden" name="id" value="<?= (int) $plano['id'] ?>">
                                                <input type="hidden" name="acao" value="excluir">
                                                <button class="button button-danger" type="submit">Excluir</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } ?>
        </section>

        <p class="account-policy">
            Um plano só pode ser ativado com duração válida e preço maior que zero.
            Planos com matrículas vinculadas não podem ser excluídos: inative-os para impedir novas contratações, mantendo o histórico.
            Alterações no catálogo não modificam nome, preço ou duração já contratados.
        </p>
    <?php } ?>

    <footer class="settings-footer">FitControl · Catálogo de planos</footer>
</main>
</body>
</html>
