<?php
require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/src/Domain/Enrollment/EnrollmentPeriodCalculator.php';
require_once __DIR__ . '/src/Domain/Finance/FinanceService.php';
require_once __DIR__ . '/src/Domain/Finance/FinanceDashboardStatus.php';
require_once __DIR__ . '/src/Domain/Finance/FinancePaymentHistoryQuery.php';

use FitControl\Domain\Enrollment\EnrollmentPeriodCalculator;
use FitControl\Domain\Finance\FinanceService;
use FitControl\Domain\Finance\FinanceDashboardStatus;
use FitControl\Domain\Finance\FinancePaymentHistoryQuery;

$usuario = exigirAutenticacao($pdo);
exigirPermissao($usuario, 'finance.view');

$fusoAcademia = new DateTimeZone(EnrollmentPeriodCalculator::ACADEMY_TIMEZONE);
$agoraLocal = new DateTimeImmutable('now', $fusoAcademia);
$dataHoje = $agoraLocal->format('Y-m-d');
$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
$erro = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    validarToken();
    $acao = $_POST['acao'] ?? '';
    $chargeId = validarId($_POST['cobranca_id'] ?? '');

    if ($chargeId === false) {
        http_response_code(400);
        exit('Identificador de cobrança inválido.');
    }

    $service = new FinanceService(
        $pdo,
        static fn (): DateTimeImmutable => new DateTimeImmutable(
            'now',
            new DateTimeZone(EnrollmentPeriodCalculator::ACADEMY_TIMEZONE)
        )
    );

    try {
        if ($acao === 'registrar_pagamento') {
            exigirPermissao($usuario, 'finance.register_payment');
            $amountReceived = $_POST['valor'] ?? '';
            $method = $_POST['forma_pagamento'] ?? '';
            $paymentDate = $_POST['data_pagamento'] ?? '';
            $observation = $_POST['observacao'] ?? '';
            $idempotencyKey = $_POST['chave_idempotencia'] ?? '';

            if (!is_string($amountReceived) || !is_string($method) || !is_string($paymentDate) ||
                !is_string($observation) || !is_string($idempotencyKey)) {
                throw new InvalidArgumentException('Os dados do pagamento são inválidos.');
            }

            $amountReceived = trim(str_replace(',', '.', $amountReceived));
            if (preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $amountReceived) !== 1) {
                throw new InvalidArgumentException('Informe um valor monetário válido.');
            }
            if (!str_contains($amountReceived, '.')) {
                $amountReceived .= '.00';
            } else {
                [$integer, $fraction] = explode('.', $amountReceived, 2);
                $amountReceived = $integer . '.' . str_pad($fraction, 2, '0');
            }

            $paymentId = $service->registerPayment(
                (int) $chargeId,
                (int) $usuario['id'],
                $amountReceived,
                $method,
                $paymentDate,
                $idempotencyKey,
                $observation
            );
            $_SESSION['mensagem'] = 'Pagamento #' . $paymentId . ' confirmado com sucesso.';
        } elseif ($acao === 'cancelar_cobranca') {
            exigirPermissao($usuario, 'finance.cancel_charge');
            $reason = $_POST['motivo_cancelamento'] ?? '';
            if (!is_string($reason)) {
                throw new InvalidArgumentException('O motivo do cancelamento é inválido.');
            }
            $service->cancelUnpaidCharge(
                (int) $chargeId,
                (int) $usuario['id'],
                $reason
            );
            $_SESSION['mensagem'] = 'Cobrança cancelada. O histórico contratual e financeiro foi preservado.';
        } else {
            http_response_code(400);
            exit('Ação financeira inválida.');
        }

        header('Location: financeiro.php', true, 303);
        exit;
    } catch (DomainException | InvalidArgumentException $exception) {
        $erro = $exception->getMessage();
        http_response_code(422);
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        http_response_code(500);
        $erro = 'Não foi possível concluir a operação financeira. Consulte o log do servidor.';
    }
}

$structureCheck = $pdo->query(
    "SELECT COUNT(*)
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME IN ('alunos', 'matriculas', 'cobrancas', 'pagamentos')"
);
$financialStructureAvailable = (int) $structureCheck->fetchColumn() === 4;

$charges = [];
$summary = [
    'total_cobrado' => '0.00',
    'total_pago' => '0.00',
    'total_cobrancas' => 0,
];

if ($financialStructureAvailable) {
    $charges = $pdo->query(
        'SELECT
            c.id,
            c.valor_total,
            c.data_vencimento,
            c.cancelada_em_utc,
            c.motivo_cancelamento,
            m.id AS matricula_id,
            m.aluno_id,
            m.plano_nome_contratado,
            m.cancelada_em AS matricula_cancelada_em,
            a.nome AS aluno_nome,
            COALESCE(SUM(p.valor), 0.00) AS valor_pago,
            COUNT(p.id) AS quantidade_pagamentos
         FROM cobrancas c
         INNER JOIN matriculas m ON m.id = c.matricula_id
         INNER JOIN alunos a ON a.id = m.aluno_id
         LEFT JOIN pagamentos p ON p.cobranca_id = c.id
         GROUP BY
            c.id, c.valor_total, c.data_vencimento, c.cancelada_em_utc,
            c.motivo_cancelamento, m.id, m.aluno_id, m.plano_nome_contratado,
            m.cancelada_em, a.nome
         ORDER BY c.data_vencimento DESC, c.id DESC
         LIMIT 200'
    )->fetchAll(PDO::FETCH_ASSOC);

    $summary = $pdo->query(
        'SELECT
            COALESCE(SUM(c.valor_total), 0.00) AS total_cobrado,
            COALESCE(SUM(COALESCE(p.valor_pago, 0.00)), 0.00) AS total_pago,
            COUNT(c.id) AS total_cobrancas,
            COALESCE(SUM(
                CASE
                    WHEN c.cancelada_em_utc IS NULL AND m.cancelada_em IS NULL
                    THEN GREATEST(c.valor_total - COALESCE(p.valor_pago, 0.00), 0.00)
                    ELSE 0.00
                END
            ), 0.00) AS saldo_cobravel,
            COALESCE(SUM(
                CASE
                    WHEN c.cancelada_em_utc IS NULL AND m.cancelada_em IS NOT NULL
                    THEN GREATEST(c.valor_total - COALESCE(p.valor_pago, 0.00), 0.00)
                    ELSE 0.00
                END
            ), 0.00) AS saldo_em_revisao
         FROM cobrancas c
         INNER JOIN matriculas m ON m.id = c.matricula_id
         LEFT JOIN (
            SELECT cobranca_id, SUM(valor) AS valor_pago
            FROM pagamentos
            GROUP BY cobranca_id
         ) p ON p.cobranca_id = c.id'
    )->fetch(PDO::FETCH_ASSOC);

    $historyChargeIds = array_map(
        static fn (array $charge): int => (int) $charge['id'],
        $charges
    );
    $paymentHistory = FinancePaymentHistoryQuery::forCharges($pdo, $historyChargeIds);
}

function financeiroMoeda(string|int|float|null $value): string
{
    return 'R$ ' . number_format((float) ($value ?? 0), 2, ',', '.');
}

function financeiroCentavos(string|int|float|null $value): int
{
    $normalized = (string) ($value ?? '0.00');
    if (preg_match('/^(\\d{1,8})\\.(\\d{2})$/D', $normalized, $parts) !== 1) {
        throw new UnexpectedValueException('Valor monetário inválido retornado pelo banco.');
    }
    return ((int) $parts[1] * 100) + (int) $parts[2];
}

$totalSaldoCobravelCents = financeiroCentavos($summary['saldo_cobravel'] ?? '0.00');
$totalSaldoRevisaoCents = financeiroCentavos($summary['saldo_em_revisao'] ?? '0.00');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Cobranças e pagamentos registrados no FitControl.">
    <title>Financeiro | FitControl</title>
    <link rel="stylesheet" href="style.css">
    <?= renderizarMonitorSessao() ?>
    <script src="theme.js"></script>
</head>
<body class="settings-body">
<main class="settings-page users-page">
    <a class="settings-back" href="index.php">← Voltar ao painel</a>
    <span class="eyebrow">CONTROLE FINANCEIRO</span>
    <h1>Financeiro</h1>
    <p class="settings-intro">Acompanhe as cobranças geradas pelas matrículas e registre pagamentos com histórico.</p>

    <?php if ($mensagem !== '') { ?>
        <div class="flash-message" role="status"><span class="flash-check" aria-hidden="true">✓</span><?= escapar($mensagem) ?></div>
    <?php } ?>
    <?php if ($erro !== '') { ?>
        <div class="flash-message enrollment-error" role="alert"><?= escapar($erro) ?></div>
    <?php } ?>

    <section class="plan-summary-grid" aria-label="Resumo financeiro">
        <article class="plan-summary-card">
            <span>Valor contratado (histórico)</span>
            <strong><?= escapar(financeiroMoeda($summary['total_cobrado'] ?? 0)) ?></strong>
        </article>
        <article class="plan-summary-card">
            <span>Recebido</span>
            <strong><?= escapar(financeiroMoeda($summary['total_pago'] ?? 0)) ?></strong>
        </article>
        <article class="plan-summary-card">
            <span>Saldo cobrável</span>
            <strong><?= escapar(financeiroMoeda($totalSaldoCobravelCents / 100)) ?></strong>
        </article>
        <article class="plan-summary-card">
            <span>Em revisão</span>
            <strong><?= escapar(financeiroMoeda($totalSaldoRevisaoCents / 100)) ?></strong>
        </article>
    </section>

    <section class="settings-card account-card" aria-labelledby="charges-title">
        <div class="settings-card-heading account-list-heading">
            <div>
                <h2 id="charges-title">Cobranças</h2>
                <p><?= (int) ($summary['total_cobrancas'] ?? 0) ?> cobrança(s) · exibindo as 200 mais recentes</p>
            </div>
        </div>

        <?php if (!$financialStructureAvailable) { ?>
            <div class="compact-empty">
                <strong>Financeiro ainda não instalado</strong>
                <span>Depois de backup e validação das migrations 005–010, esta tela exibirá cobranças e pagamentos. Nenhuma alteração foi feita no banco.</span>
            </div>
        <?php } elseif ($charges === []) { ?>
            <div class="compact-empty">
                <strong>Nenhuma cobrança registrada</strong>
                <span>Uma cobrança será criada automaticamente ao confirmar uma nova matrícula.</span>
            </div>
        <?php } else { ?>
            <div class="table-scroll" tabindex="0" aria-label="Tabela de cobranças; role horizontalmente para ver todas as colunas">
                <table class="students-table accounts-table">
                    <thead>
                        <tr>
                            <th scope="col">ALUNO / PLANO</th>
                            <th scope="col">VENCIMENTO</th>
                            <th scope="col">VALOR</th>
                            <th scope="col">RECEBIDO</th>
                            <th scope="col">SALDO</th>
                            <th scope="col">STATUS</th>
                            <th scope="col">AÇÕES</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($charges as $charge) {
                        [$statusLabel, $statusClass] = FinanceDashboardStatus::status($charge, $dataHoje);
                        $paidCents = financeiroCentavos($charge['valor_pago']);
                        $balanceCents = FinanceDashboardStatus::balanceCents($charge);
                        $cancelledEnrollment = $charge['matricula_cancelada_em'] !== null;
                        $chargeCancelled = $charge['cancelada_em_utc'] !== null;
                    ?>
                        <tr>
                            <td>
                                <div class="account-person">
                                    <strong><?= escapar($charge['aluno_nome']) ?></strong>
                                    <small><?= escapar($charge['plano_nome_contratado'] ?? 'Plano sem descrição') ?> · Matrícula #<?= (int) $charge['matricula_id'] ?></small>
                                </div>
                            </td>
                            <td><?= escapar(DateTimeImmutable::createFromFormat('!Y-m-d', (string) $charge['data_vencimento'], $fusoAcademia)->format('d/m/Y')) ?></td>
                            <td><?= escapar(financeiroMoeda($charge['valor_total'])) ?></td>
                            <td><?= escapar(financeiroMoeda($charge['valor_pago'])) ?></td>
                            <td><?= escapar(financeiroMoeda($balanceCents / 100)) ?></td>
                            <td><span class="account-status <?= escapar($statusClass) ?>"><?= escapar($statusLabel) ?></span></td>
                            <td>
                                <div class="plan-actions">
                                <?php if (
                                    !$chargeCancelled
                                    && !$cancelledEnrollment
                                    && $balanceCents > 0
                                    && usuarioTemPermissao($usuario, 'finance.register_payment')
                                ) { ?>
                                    <form action="financeiro.php" method="post" class="finance-inline-form">
                                        <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
                                        <input type="hidden" name="acao" value="registrar_pagamento">
                                        <input type="hidden" name="cobranca_id" value="<?= (int) $charge['id'] ?>">
                                        <input type="hidden" name="chave_idempotencia" value="<?= escapar(bin2hex(random_bytes(16))) ?>">
                                        <label class="sr-only" for="pagamento-valor-<?= (int) $charge['id'] ?>">Valor do pagamento</label>
                                        <input id="pagamento-valor-<?= (int) $charge['id'] ?>" type="number" name="valor"
                                               min="0.01" max="<?= escapar(number_format($balanceCents / 100, 2, '.', '')) ?>"
                                               step="0.01" inputmode="decimal" placeholder="Valor R$" required>
                                        <label class="sr-only" for="pagamento-forma-<?= (int) $charge['id'] ?>">Forma de pagamento</label>
                                        <select id="pagamento-forma-<?= (int) $charge['id'] ?>" name="forma_pagamento" required>
                                            <option value="PIX">Pix</option>
                                            <option value="DINHEIRO">Dinheiro</option>
                                            <option value="TRANSFERENCIA">Transferência</option>
                                            <option value="CARTAO">Cartão</option>
                                            <option value="OUTRO">Outro</option>
                                        </select>
                                        <input type="hidden" name="data_pagamento" value="<?= escapar($dataHoje) ?>">
                                        <button class="button button-primary" type="submit">Registrar</button>
                                    </form>
                                <?php } elseif ($cancelledEnrollment && !$chargeCancelled && (int) $charge['quantidade_pagamentos'] === 0 && usuarioTemPermissao($usuario, 'finance.cancel_charge')) { ?>
                                    <form action="financeiro.php" method="post" class="finance-inline-form">
                                        <input type="hidden" name="token" value="<?= escapar($_SESSION['token']) ?>">
                                        <input type="hidden" name="acao" value="cancelar_cobranca">
                                        <input type="hidden" name="cobranca_id" value="<?= (int) $charge['id'] ?>">
                                        <label class="sr-only" for="motivo-cobranca-<?= (int) $charge['id'] ?>">Motivo do cancelamento</label>
                                        <input id="motivo-cobranca-<?= (int) $charge['id'] ?>" type="text" name="motivo_cancelamento"
                                               maxlength="255" placeholder="Motivo obrigatório" required>
                                        <button class="button button-danger" type="submit">Cancelar cobrança</button>
                                    </form>
                                <?php } elseif ($cancelledEnrollment && (int) $charge['quantidade_pagamentos'] > 0 && !$chargeCancelled) { ?>
                                    <span class="field-hint">Pagamento preservado; estorno exige revisão própria.</span>
                                <?php } else { ?>
                                    <span class="field-hint">Sem ações disponíveis</span>
                                <?php } ?>
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
        Situação calculada pelo valor recebido e vencimento. Matrículas antigas não recebem cobranças retroativas.
        O cancelamento de uma matrícula não apaga pagamentos nem cancela automaticamente a cobrança.
        Cobranças com pagamentos exigem um fluxo específico de estorno, ainda fora desta versão.
    </p>
    <footer class="settings-footer">FitControl · Financeiro</footer>
</main>
</body>
</html>
