<?php
declare(strict_types=1);

use FitControl\Domain\Enrollment\EnrollmentCancellationService;
use FitControl\Domain\Enrollment\EnrollmentCreationService;
use FitControl\Domain\Enrollment\EnrollmentPeriodCalculator;
use FitControl\Domain\Finance\FinanceService;
use FitControl\Domain\Finance\FinanceDashboardStatus;
use FitControl\Domain\Finance\FinancePaymentHistoryQuery;

require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentPeriodCalculator.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentInterval.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentStatus.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentCreationService.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentCancellationService.php';
require_once dirname(__DIR__) . '/src/Domain/Finance/FinanceService.php';
require_once dirname(__DIR__) . '/src/Domain/Finance/FinanceDashboardStatus.php';
require_once dirname(__DIR__) . '/src/Domain/Finance/FinancePaymentHistoryQuery.php';

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

function assertFinanceSqlRejected(
    PDO $pdo,
    string $sql,
    array $parameters,
    string $message,
    ?string $expectedConstraint = null
): void {
    try {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
    } catch (PDOException $exception) {
        if (
            $expectedConstraint !== null
            && !str_contains($exception->getMessage(), $expectedConstraint)
        ) {
            throw new RuntimeException(
                $message . ' A rejeição ocorreu por outro motivo: ' . $exception->getMessage(),
                previous: $exception
            );
        }

        return;
    }

    throw new RuntimeException($message);
}

/**
 * Worker isolado: cada processo cria sua própria conexão PDO e chama o serviço.
 */
function runFinancePaymentWorker(array $arguments): never
{
    if (count($arguments) !== 9) {
        throw new InvalidArgumentException('Argumentos incompletos no worker financeiro.');
    }

    $chargeId = filter_var($arguments[2], FILTER_VALIDATE_INT);
    $actorId = filter_var($arguments[3], FILTER_VALIDATE_INT);
    if (!is_int($chargeId) || $chargeId < 1 || !is_int($actorId) || $actorId < 1) {
        throw new InvalidArgumentException('Identificadores inválidos no worker financeiro.');
    }

    $pdo = financeConnection();
    $clock = static fn (): DateTimeImmutable => financeDate('2026-11-22 10:00:00');
    $service = new FinanceService($pdo, $clock);

    // O processo pai mantém a cobrança bloqueada até receber READY dos dois workers.
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);

    try {
        $paymentId = $service->registerPayment(
            $chargeId,
            $actorId,
            (string) $arguments[4],
            (string) $arguments[5],
            (string) $arguments[6],
            (string) $arguments[7],
            (string) $arguments[8]
        );
        fwrite(STDOUT, 'PAYMENT_ID=' . $paymentId . "\n");
        fflush(STDOUT);
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . "\n");
        exit(1);
    }
}

/**
 * Mantém a linha da cobrança bloqueada enquanto duas conexões independentes
 * chegam ao serviço com a mesma chave e exatamente o mesmo conteúdo.
 */
function runFinanceIdempotencyRace(
    PDO $pdo,
    int $chargeId,
    int $actorId,
    string $amount,
    string $method,
    string $paymentDate,
    string $idempotencyKey,
    string $observation
): int {
    $workers = [];
    $pdo->beginTransaction();

    try {
        $lock = $pdo->prepare('SELECT id FROM cobrancas WHERE id = :id FOR UPDATE');
        $lock->execute(['id' => $chargeId]);
        assertFinance((int) $lock->fetchColumn() === $chargeId, 'Cobrança de teste concorrente não encontrada.');

        for ($index = 0; $index < 2; $index++) {
            $pipes = [];
            $process = proc_open(
                [
                    PHP_BINARY,
                    __FILE__,
                    '--idempotency-worker',
                    (string) $chargeId,
                    (string) $actorId,
                    $amount,
                    $method,
                    $paymentDate,
                    $idempotencyKey,
                    $observation,
                ],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes
            );

            assertFinance(is_resource($process), 'Não foi possível iniciar worker financeiro concorrente.');
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 15);
            $workers[] = ['process' => $process, 'pipes' => $pipes];
        }

        foreach ($workers as $worker) {
            $signal = fgets($worker['pipes'][1]);
            $metadata = stream_get_meta_data($worker['pipes'][1]);
            assertFinance(
                $signal === "READY\n" && empty($metadata['timed_out']),
                'Worker financeiro não confirmou prontidão antes da liberação do bloqueio.'
            );
        }

        $pdo->commit();

        $paymentIds = [];
        foreach ($workers as $index => $worker) {
            $stdout = stream_get_contents($worker['pipes'][1]);
            $stderr = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);

            $exitCode = proc_close($worker['process']);
            $workers[$index]['process'] = null;
            $workers[$index]['pipes'] = [];

            assertFinance($exitCode === 0, 'Worker financeiro falhou: ' . trim($stderr));
            assertFinance(
                preg_match('/^PAYMENT_ID=(\\d+)\\s*$/D', trim($stdout), $matches) === 1,
                'Worker não retornou um ID de pagamento válido: ' . trim($stdout)
            );
            $paymentIds[] = (int) $matches[1];
        }

        assertFinance(
            $paymentIds[0] === $paymentIds[1],
            'Requisições concorrentes da mesma operação devem retornar o mesmo pagamento.'
        );

        return $paymentIds[0];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    } finally {
        foreach ($workers as $worker) {
            foreach ($worker['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (isset($worker['process']) && is_resource($worker['process'])) {
                proc_terminate($worker['process']);
                proc_close($worker['process']);
            }
        }
    }
}

if (($argv[1] ?? '') === '--idempotency-worker') {
    try {
        runFinancePaymentWorker($argv);
    } catch (Throwable $exception) {
        fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . "\n");
        exit(1);
    }
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
    for ($i = 1; $i <= 3; $i++) {
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

    // Terceira matrícula isolada para a corrida, sem interferir nos cenários sequenciais.
    $raceEnrollmentId = $enrollmentService->create(
        $studentIds[2], $planIds[0], $actorId, financeDate('2026-12-08 09:00:00')
    );
    $enrollmentIds[] = $raceEnrollmentId;
    $raceChargeQuery = $pdo->prepare('SELECT id FROM cobrancas WHERE matricula_id = :id');
    $raceChargeQuery->execute(['id' => $raceEnrollmentId]);
    $raceChargeId = (int) $raceChargeQuery->fetchColumn();
    assertFinance($raceChargeId > 0, 'A matrícula concorrente precisa ter uma cobrança própria.');
    $chargeIds[] = $raceChargeId;

    $raceKey = bin2hex(random_bytes(16));
    $raceObservation = 'Pagamento simultâneo com a mesma chave';
    $racePaymentId = runFinanceIdempotencyRace(
        $pdo, $raceChargeId, $actorId, '50.00', 'PIX', '2026-11-22', $raceKey, $raceObservation
    );

    $raceCount = $pdo->prepare(
        'SELECT COUNT(*) FROM pagamentos
         WHERE cobranca_id = :charge_id AND chave_idempotencia = :idempotency_key'
    );
    $raceCount->execute(['charge_id' => $raceChargeId, 'idempotency_key' => $raceKey]);
    assertFinance((int) $raceCount->fetchColumn() === 1, 'A corrida deve persistir somente um pagamento.');

    $raceAuditCount = $pdo->prepare(
        'SELECT COUNT(*) FROM auditoria
         WHERE usuario_id = :actor_id
           AND acao = :action
           AND entidade = :entity
           AND entidade_id = :entity_id'
    );
    $raceAuditCount->execute([
        'actor_id' => $actorId,
        'action' => 'pagamento_registrado',
        'entity' => 'pagamentos',
        'entity_id' => $racePaymentId,
    ]);
    assertFinance((int) $raceAuditCount->fetchColumn() === 1, 'A corrida deve gerar exatamente um evento de auditoria.');

    // Matrícula cancelada com recebimento parcial permanece em revisão,
    // não deve ser apresentada como saldo normalmente cobrável.
    $cancellation = new EnrollmentCancellationService($pdo, $clock);
    $cancellation->cancel($studentIds[2], $raceEnrollmentId, $actorId, 'Cancelamento com pagamento parcial');
    $reviewQuery = $pdo->prepare(
        'SELECT
            c.valor_total,
            c.cancelada_em_utc,
            m.cancelada_em AS matricula_cancelada_em,
            COALESCE(SUM(p.valor), 0.00) AS valor_pago,
            c.data_vencimento
         FROM cobrancas c
         INNER JOIN matriculas m ON m.id = c.matricula_id
         LEFT JOIN pagamentos p ON p.cobranca_id = c.id
         WHERE c.id = :charge_id
         GROUP BY c.id, c.valor_total, c.cancelada_em_utc, m.cancelada_em, c.data_vencimento'
    );
    $reviewQuery->execute(['charge_id' => $raceChargeId]);
    $reviewCharge = $reviewQuery->fetch();
    assertFinance(
        FinanceDashboardStatus::status($reviewCharge, '2026-11-22')[0] === 'Em revisão',
        'Matrícula cancelada com cobrança não cancelada deve aparecer Em revisão.'
    );
    assertFinance(
        FinanceDashboardStatus::collectibleBalanceCents($reviewCharge) === 0,
        'Saldo da matrícula cancelada não pode permanecer no saldo cobrável.'
    );
    assertFinance(
        FinanceDashboardStatus::reviewBalanceCents($reviewCharge) === 25000,
        'Saldo restante da matrícula cancelada deve permanecer em revisão.'
    );

    $finance = new FinanceService($pdo, $clock);
    $paymentOne = $finance->registerPayment(
        $firstChargeId, $actorId, '100.00', 'PIX', '2026-11-22', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'Pagamento parcial 1'
    );
    $paymentTwo = $finance->registerPayment(
        $firstChargeId, $actorId, '200.00', 'DINHEIRO', '2026-11-22', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'Quitação do saldo'
    );
    assertFinance($paymentOne > 0 && $paymentTwo > 0, 'O serviço deve retornar IDs de pagamentos válidos.');

    $history = FinancePaymentHistoryQuery::forCharges($pdo, [$firstChargeId, $firstChargeId]);
    assertFinance(
        count($history[$firstChargeId] ?? []) === 2,
        'O histórico deve listar os dois pagamentos da cobrança sem duplicar IDs de cobrança.'
    );
    assertFinance(
        (int) $history[$firstChargeId][0]['id'] === $paymentTwo
            && (int) $history[$firstChargeId][1]['id'] === $paymentOne,
        'O histórico deve apresentar os lançamentos do mais recente para o mais antigo.'
    );
    assertFinance(
        $history[$firstChargeId][0]['forma_pagamento'] === 'DINHEIRO'
            && $history[$firstChargeId][0]['observacao'] === 'Quitação do saldo',
        'O histórico deve preservar forma de pagamento e observação.'
    );
    assertFinance(
        (int) $history[$firstChargeId][0]['registrado_por'] === $actorId
            && $history[$firstChargeId][0]['responsavel_nome'] !== '',
        'O histórico deve identificar o usuário responsável pelo lançamento.'
    );
    assertFinance(
        FinancePaymentHistoryQuery::forCharges($pdo, []) === [],
        'Lista vazia de cobranças deve retornar histórico vazio sem consultar registros.'
    );

    $replayedPayment = $finance->registerPayment(
        $firstChargeId, $actorId, '100.00', 'PIX', '2026-11-22',
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'Pagamento parcial 1'
    );
    assertFinance($replayedPayment === $paymentOne, 'Reenvio idêntico deve retornar o ID do pagamento original.');

    assertFinanceRejected(
        fn () => $finance->registerPayment(
            $firstChargeId, $actorId, '101.00', 'PIX', '2026-11-22',
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'Payload alterado'
        ),
        'A mesma chave não pode ser reutilizada com dados diferentes.'
    );
    $paidSum = $pdo->prepare('SELECT SUM(valor) FROM pagamentos WHERE cobranca_id = :charge_id');
    $paidSum->execute(['charge_id' => $firstChargeId]);
    assertFinance($paidSum->fetchColumn() === '300.00', 'Pagamentos parciais devem somar exatamente o valor contratado.');

    assertFinanceRejected(
        fn () => $finance->registerPayment($firstChargeId, $actorId, '0.01', 'PIX', '2026-11-22', 'cccccccccccccccccccccccccccccccc'),
        'O sistema não deve aceitar pagamento acima do saldo.'
    );
    $paymentCount = $pdo->prepare('SELECT COUNT(*) FROM pagamentos WHERE cobranca_id = :charge_id');
    $paymentCount->execute(['charge_id' => $firstChargeId]);
    assertFinance((int) $paymentCount->fetchColumn() === 2, 'Reenvios e tentativas inválidas não devem criar pagamentos adicionais.');

    // F-05: o próprio banco rejeita valores nulos ou negativos, mesmo fora do serviço.
    assertFinanceSqlRejected(
        $pdo,
        'UPDATE cobrancas SET valor_total = :amount WHERE id = :charge_id',
        ['amount' => '0.00', 'charge_id' => $firstChargeId],
        'A constraint deve rejeitar cobrança com valor total zero.',
        'chk_cobrancas_valor_total_positivo'
    );
    $pdo->exec('SET @fitcontrol_payment_write = 1');
    try {
        assertFinanceSqlRejected(
            $pdo,
            'INSERT INTO pagamentos (
                cobranca_id, valor, forma_pagamento, data_pagamento,
                registrado_por, registrado_em_utc
             ) VALUES (
                :charge_id, :amount, :method, :payment_date,
                :actor_id, :registered_at
             )',
            [
                'charge_id' => $firstChargeId,
                'amount' => '-0.01',
                'method' => 'PIX',
                'payment_date' => '2026-11-22',
                'actor_id' => $actorId,
                'registered_at' => '2026-11-22 13:00:00',
            ],
            'A constraint deve rejeitar pagamento negativo mesmo com a permissão de escrita do serviço.',
            'chk_pagamentos_valor_positivo'
        );
    } finally {
        $pdo->exec('SET @fitcontrol_payment_write = NULL');
    }

    // A tabela deve impedir escrita direta, edição e exclusão fora do serviço.
    assertFinanceSqlRejected(
        $pdo,
        'INSERT INTO pagamentos (
            cobranca_id, valor, forma_pagamento, data_pagamento,
            registrado_por, registrado_em_utc, chave_idempotencia, hash_requisicao
         ) VALUES (
            :charge_id, 1.00, \'PIX\', \'2026-11-22\',
            :actor_id, \'2026-11-22 13:00:00\', :idempotency_key, :request_hash
         )',
        [
            'charge_id' => $firstChargeId,
            'actor_id' => $actorId,
            'idempotency_key' => str_repeat('e', 32),
            'request_hash' => str_repeat('f', 64),
        ],
        'INSERT direto não deve contornar o serviço financeiro.'
    );
    assertFinanceSqlRejected(
        $pdo,
        'UPDATE pagamentos SET valor = \'101.00\' WHERE id = :payment_id',
        ['payment_id' => $paymentOne],
        'Pagamento confirmado não pode ser editado.'
    );
    assertFinanceSqlRejected(
        $pdo,
        'DELETE FROM pagamentos WHERE id = :payment_id',
        ['payment_id' => $paymentOne],
        'Pagamento confirmado não pode ser excluído.'
    );
    $immutableValue = $pdo->prepare('SELECT valor FROM pagamentos WHERE id = :id');
    $immutableValue->execute(['id' => $paymentOne]);
    assertFinance($immutableValue->fetchColumn() === '100.00', 'Tentativa de edição não pode alterar o valor original.');

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
        fn () => $finance->registerPayment($secondChargeId, $actorId, '10.00', 'PIX', '2026-11-22', 'dddddddddddddddddddddddddddddddd'),
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
        $pdo->exec('SET @fitcontrol_finance_test_cleanup = 1');
        try {
            $placeholders = implode(',', array_fill(0, count($chargeIds), '?'));
            $deletePayments = $pdo->prepare("DELETE FROM pagamentos WHERE cobranca_id IN ({$placeholders})");
            $deletePayments->execute($chargeIds);
            $deleteCharges = $pdo->prepare("DELETE FROM cobrancas WHERE id IN ({$placeholders})");
            $deleteCharges->execute($chargeIds);
        } finally {
            $pdo->exec('SET @fitcontrol_finance_test_cleanup = NULL');
        }
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
