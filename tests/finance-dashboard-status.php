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

$paid = $base;
$paid['valor_pago'] = '300.00';
assertFinanceDashboard(
    FinanceDashboardStatus::status($paid, '2026-11-22')[0] === 'Quitada',
    'Cobrança integralmente paga deve ser Quitada.'
);

echo "Status financeiro: cobrança ativa, saldo cobrável, revisão e cancelamento: OK." . PHP_EOL;
