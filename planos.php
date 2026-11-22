<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';

$usuario = exigirAutenticacao($pdo);

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
