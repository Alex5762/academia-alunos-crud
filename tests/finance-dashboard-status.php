<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Domain/Finance/FinanceDashboardStatus.php';

use FitControl\Domain\Finance\FinanceDashboardStatus;

function assertFinanceDashboard(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$base = [
    'valor_total' => '300.00',
    'valor_pago' => '100.00',
    'data_vencimento' => '2026-12-01',
    'matricula_cancelada_em' => null,
    'cancelada_em_utc' => null,
];

assertFinanceDashboard(
    FinanceDashboardStatus::status($base, '2026-11-22')[0] === 'Parcial',
    'Cobrança ativa parcialmente paga deve continuar Parcial.'
);
assertFinanceDashboard(
    FinanceDashboardStatus::collectibleBalanceCents($base) === 20000,
    'Saldo de cobrança ativa deve permanecer cobrável.'
);
assertFinanceDashboard(
    FinanceDashboardStatus::reviewBalanceCents($base) === 0,
    'Cobrança ativa não deve aparecer como revisão.'
);

$cancelledEnrollment = $base;
$cancelledEnrollment['matricula_cancelada_em'] = '2026-11-22 10:00:00';
assertFinanceDashboard(
    FinanceDashboardStatus::status($cancelledEnrollment, '2026-11-22')[0] === 'Em revisão',
    'Matrícula cancelada com cobrança não cancelada deve ir para revisão.'
);
assertFinanceDashboard(
    FinanceDashboardStatus::collectibleBalanceCents($cancelledEnrollment) === 0,
    'Saldo de matrícula cancelada não pode ser tratado como cobrável.'
);
assertFinanceDashboard(
    FinanceDashboardStatus::reviewBalanceCents($cancelledEnrollment) === 20000,
    'Saldo pendente de matrícula cancelada deve ficar separado para revisão.'
);

$cancelledUnpaidEnrollment = $cancelledEnrollment;
$cancelledUnpaidEnrollment['valor_pago'] = '0.00';
assertFinanceDashboard(
    FinanceDashboardStatus::status($cancelledUnpaidEnrollment, '2026-11-22')[0] === 'Em revisão',
    'Matrícula cancelada sem pagamentos deve continuar classificada como Em revisão.'
);
assertFinanceDashboard(
    FinanceDashboardStatus::collectibleBalanceCents($cancelledUnpaidEnrollment) === 0,
    'Matrícula cancelada sem pagamentos não pode gerar saldo cobrável.'
);
assertFinanceDashboard(
    FinanceDashboardStatus::reviewBalanceCents($cancelledUnpaidEnrollment) === 30000,
    'Cobrança integralmente pendente de matrícula cancelada deve ter R$ 300,00 em revisão.'
);

// Saldo zero não encerra, por si só, a análise de um possível reembolso.
$cancelledPaidEnrollment = $cancelledEnrollment;
$cancelledPaidEnrollment['valor_pago'] = '300.00';
assertFinanceDashboard(
    FinanceDashboardStatus::status($cancelledPaidEnrollment, '2026-11-22')[0] === 'Em revisão',
    'Matrícula cancelada com cobrança quitada deve continuar identificada como Em revisão.'
);
assertFinanceDashboard(
    FinanceDashboardStatus::reviewBalanceCents($cancelledPaidEnrollment) === 0,
    'Cobrança quitada de matrícula cancelada deve ter saldo restante em revisão igual a zero.'
);
assertFinanceDashboard(
    FinanceDashboardStatus::collectibleBalanceCents($cancelledPaidEnrollment) === 0,
    'Cobrança quitada de matrícula cancelada não pode integrar o saldo cobrável.'
);

$cancelledCharge = $cancelledEnrollment;
$cancelledCharge['cancelada_em_utc'] = '2026-11-22 13:00:00';
assertFinanceDashboard(
    FinanceDashboardStatus::status($cancelledCharge, '2026-11-22')[0] === 'Cancelada',
    'Decisão financeira concluída deve prevalecer sobre a revisão da matrícula.'
);
assertFinanceDashboard(
    FinanceDashboardStatus::balanceCents($cancelledCharge) === 0,
    'Cobrança cancelada não deve manter saldo em aberto.'
);
assertFinanceDashboard(
    FinanceDashboardStatus::reviewBalanceCents($cancelledCharge) === 0,
    'Cobrança cancelada financeiramente não deve contribuir para o saldo em revisão.'
);

$paid = $base;
$paid['valor_pago'] = '300.00';
assertFinanceDashboard(
    FinanceDashboardStatus::status($paid, '2026-11-22')[0] === 'Quitada',
    'Cobrança integralmente paga deve ser Quitada.'
);

echo "Status financeiro: cobrança ativa, saldo cobrável, revisão e cancelamento: OK." . PHP_EOL;
