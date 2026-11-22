<?php
declare(strict_types=1);

use FitControl\Domain\Enrollment\EnrollmentCancellationService;
use FitControl\Domain\Enrollment\EnrollmentCreationService;
use FitControl\Domain\Enrollment\EnrollmentPeriodCalculator;
use FitControl\Domain\Finance\FinanceService;

require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentPeriodCalculator.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentInterval.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentStatus.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentCreationService.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentCancellationService.php';
require_once dirname(__DIR__) . '/src/Domain/Finance/FinanceService.php';

function financeConnection(): PDO
{
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $database = getenv('DB_NAME') ?: 'academia_alunos';
    $username = getenv('DB_USER') ?: 'root';
    $password = getenv('DB_PASSWORD');
    if ($password === false) { $password = ''; }
    return new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database),
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
}

function assertFinance(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function financeDate(string $value): DateTimeImmutable
{
    return new DateTimeImmutable($value, new DateTimeZone(EnrollmentPeriodCalculator::ACADEMY_TIMEZONE));
}

function assertFinanceRejected(callable $operation, string $message): void
{
    try { $operation(); }
    catch (DomainException) { return; }
    throw new RuntimeException($message);
}

$pdo = financeConnection();
$nonce = bin2hex(random_bytes(6));
$actorId = null;
$studentIds = [];
$planIds = [];
$enrollmentIds = [];
$chargeIds = [];

try {
    $createUser = $pdo->prepare(
        'INSERT INTO usuarios (nome, email, senha_hash, ativo)
         VALUES (:nome, :email, :senha_hash, 1)'
    );
    $createUser->execute([
        'nome' => 'Teste financeiro FitControl',
        'email' => 'fitcontrol-finance-' . $nonce . '@example.invalid',
        'senha_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
    ]);
    $actorId = (int) $pdo->lastInsertId();

    $createStudent = $pdo->prepare(
        'INSERT INTO alunos (nome, telefone, email, plano, ativo)
         VALUES (:nome, :telefone, :email, :plano, 1)'
    );
    for ($i = 1; $i <= 2; $i++) {
        $createStudent->execute([
            'nome' => 'Aluno teste financeiro ' . $i,
            'telefone' => '217777' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
            'email' => 'fitcontrol-finance-student-' . $nonce . '-' . $i . '@example.invalid',
            'plano' => 'Plano financeiro',
        ]);
        $studentIds[] = (int) $pdo->lastInsertId();
    }

    $createPlan = $pdo->prepare(
        "INSERT INTO planos (nome, duracao_valor, duracao_unidade, valor_atual, situacao)
         VALUES ('Trimestral Financeiro', 3, 'MES', '300.00', 'ATIVO')"
    );
    $createPlan->execute();
    $planIds[] = (int) $pdo->lastInsertId();

    $clock = static fn (): DateTimeImmutable => financeDate('2026-11-22 10:00:00');
    $enrollmentService = new EnrollmentCreationService($pdo, $clock);
    $firstEnrollmentId = $enrollmentService->create(
        $studentIds[0], $planIds[0], $actorId, financeDate('2026-12-01 09:00:00')
    );
    $enrollmentIds[] = $firstEnrollmentId;

    $chargeQuery = $pdo->prepare(
        'SELECT id, valor_total, data_vencimento
         FROM cobrancas WHERE matricula_id = :matricula_id'
    );
    $chargeQuery->execute(['matricula_id' => $firstEnrollmentId]);
    $charge = $chargeQuery->fetch();
    assertFinance(is_array($charge), 'Matrícula nova deve criar cobrança automaticamente.');
    $firstChargeId = (int) $charge['id'];
    $chargeIds[] = $firstChargeId;
    assertFinance($charge['valor_total'] === '300.00', 'Plano trimestral deve gerar uma cobrança total de R$ 300,00.');
    assertFinance($charge['data_vencimento'] === '2026-12-01', 'Vencimento padrão deve corresponder à data de início.');

    $finance = new FinanceService($pdo, $clock);
    $paymentOne = $finance->registerPayment(
        $firstChargeId, $actorId, '100.00', 'PIX', '2026-11-22', 'idem00000000000000000000000000001', 'Pagamento parcial 1'
    );
    $paymentTwo = $finance->registerPayment(
        $firstChargeId, $actorId, '200.00', 'DINHEIRO', '2026-11-22', 'idem00000000000000000000000000002', 'Quitação do saldo'
    );
    assertFinance($paymentOne > 0 && $paymentTwo > 0, 'O serviço deve retornar IDs de pagamentos válidos.');

    $replayedPayment = $finance->registerPayment(
        $firstChargeId, $actorId, '100.00', 'PIX', '2026-11-22',
        'idem00000000000000000000000000001', 'Pagamento parcial 1'
    );
    assertFinance($replayedPayment === $paymentOne, 'Reenvio idêntico deve retornar o ID do pagamento original.');

    assertFinanceRejected(
        fn () => $finance->registerPayment(
            $firstChargeId, $actorId, '101.00', 'PIX', '2026-11-22',
            'idem00000000000000000000000000001', 'Payload alterado'
        ),
        'A mesma chave não pode ser reutilizada com dados diferentes.'
    );
    $paidSum = $pdo->prepare('SELECT SUM(valor) FROM pagamentos WHERE cobranca_id = :charge_id');
    $paidSum->execute(['charge_id' => $firstChargeId]);
    assertFinance($paidSum->fetchColumn() === '300.00', 'Pagamentos parciais devem somar exatamente o valor contratado.');

    assertFinanceRejected(
        fn () => $finance->registerPayment($firstChargeId, $actorId, '0.01', 'PIX', '2026-11-22', 'idem00000000000000000000000000003'),
        'O sistema não deve aceitar pagamento acima do saldo.'
    );
    $paymentCount = $pdo->prepare('SELECT COUNT(*) FROM pagamentos WHERE cobranca_id = :charge_id');
    $paymentCount->execute(['charge_id' => $firstChargeId]);
    assertFinance((int) $paymentCount->fetchColumn() === 2, 'Reenvios e tentativas inválidas não devem criar pagamentos adicionais.');

    try {
        $duplicate = $pdo->prepare(
            'INSERT INTO cobrancas (matricula_id, valor_total, data_vencimento, criado_por, criado_em_utc)
             VALUES (:matricula_id, 300.00, :due_date, :actor_id, :created_at)'
        );
        $duplicate->execute([
            'matricula_id' => $firstEnrollmentId,
            'due_date' => '2026-12-01',
            'actor_id' => $actorId,
            'created_at' => '2026-11-22 13:00:00',
        ]);
        throw new RuntimeException('Índice único permitiu uma segunda cobrança para a mesma matrícula.');
    } catch (PDOException) {
        // Restrição UNIQUE esperada.
    }

    $secondEnrollmentId = $enrollmentService->create(
        $studentIds[1], $planIds[0], $actorId, financeDate('2026-12-15 09:00:00')
    );
    $enrollmentIds[] = $secondEnrollmentId;
    $secondChargeQuery = $pdo->prepare('SELECT id FROM cobrancas WHERE matricula_id = :id');
    $secondChargeQuery->execute(['id' => $secondEnrollmentId]);
    $secondChargeId = (int) $secondChargeQuery->fetchColumn();
    $chargeIds[] = $secondChargeId;

    assertFinanceRejected(
        fn () => $finance->cancelUnpaidCharge($secondChargeId, $actorId, 'Cancelamento administrativo'),
        'Não deve cancelar cobrança enquanto a matrícula está ativa/agendada.'
    );

    $cancellation = new EnrollmentCancellationService($pdo, $clock);
    $cancellation->cancel($studentIds[1], $secondEnrollmentId, $actorId, 'Desistência antes do início');
    $finance->cancelUnpaidCharge($secondChargeId, $actorId, 'Contrato cancelado antes do início');

    $cancelledAt = $pdo->prepare(
        'SELECT cancelada_em_utc, motivo_cancelamento FROM cobrancas WHERE id = :id'
    );
    $cancelledAt->execute(['id' => $secondChargeId]);
    $cancelledRow = $cancelledAt->fetch();
    assertFinance($cancelledRow['cancelada_em_utc'] !== null, 'Cancelamento financeiro deve persistir instante UTC.');
    assertFinance(
        $cancelledRow['motivo_cancelamento'] === 'Contrato cancelado antes do início',
        'Motivo do cancelamento financeiro deve ser preservado.'
    );
    assertFinanceRejected(
        fn () => $finance->registerPayment($secondChargeId, $actorId, '10.00', 'PIX', '2026-11-22', 'idem00000000000000000000000000004'),
        'Cobrança cancelada não deve aceitar pagamentos.'
    );

    $cancellation->cancel($studentIds[0], $firstEnrollmentId, $actorId, 'Cancelamento após pagamentos');
    assertFinanceRejected(
        fn () => $finance->cancelUnpaidCharge($firstChargeId, $actorId, 'Não deve apagar valores pagos'),
        'Cobrança com pagamentos não pode ser cancelada como se nada tivesse sido recebido.'
    );

    $audit = $pdo->prepare(
        'SELECT COUNT(*) FROM auditoria
         WHERE usuario_id = :actor_id AND acao = :action AND entidade = :entity AND entidade_id = :entity_id'
    );
    foreach ([
        ['cobranca_criada', 'cobrancas', $firstChargeId],
        ['pagamento_registrado', 'pagamentos', $paymentOne],
        ['pagamento_registrado', 'pagamentos', $paymentTwo],
        ['cobranca_cancelada', 'cobrancas', $secondChargeId],
    ] as [$action, $entity, $entityId]) {
        $audit->execute(['actor_id' => $actorId, 'action' => $action, 'entity' => $entity, 'entity_id' => $entityId]);
        assertFinance((int) $audit->fetchColumn() === 1, 'Evento de auditoria ausente: ' . $action);
    }

    echo "Financeiro transacional: OK; cobrança única, pagamentos parciais, limite de saldo, cancelamento condicionado e auditoria validados.\n";
} finally {
    if ($chargeIds !== []) {
        $placeholders = implode(',', array_fill(0, count($chargeIds), '?'));
        $deletePayments = $pdo->prepare("DELETE FROM pagamentos WHERE cobranca_id IN ({$placeholders})");
        $deletePayments->execute($chargeIds);
        $deleteCharges = $pdo->prepare("DELETE FROM cobrancas WHERE id IN ({$placeholders})");
        $deleteCharges->execute($chargeIds);
    }
    if ($enrollmentIds !== []) {
        $placeholders = implode(',', array_fill(0, count($enrollmentIds), '?'));
        $deleteEnrollments = $pdo->prepare("DELETE FROM matriculas WHERE id IN ({$placeholders})");
        $deleteEnrollments->execute($enrollmentIds);
    }
    if ($studentIds !== []) {
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $deleteStudents = $pdo->prepare("DELETE FROM alunos WHERE id IN ({$placeholders})");
        $deleteStudents->execute($studentIds);
    }
    if ($planIds !== []) {
        $placeholders = implode(',', array_fill(0, count($planIds), '?'));
        $deletePlans = $pdo->prepare("DELETE FROM planos WHERE id IN ({$placeholders})");
        $deletePlans->execute($planIds);
    }
}
