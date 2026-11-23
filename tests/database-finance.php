<?php
declare(strict_types=1);

use FitControl\Domain\Enrollment\EnrollmentCancellationService;
use FitControl\Domain\Enrollment\EnrollmentCreationService;
use FitControl\Domain\Enrollment\EnrollmentPeriodCalculator;
use FitControl\Domain\Finance\FinanceService;
use FitControl\Domain\Finance\FinanceDashboardStatus;
use FitControl\Domain\Finance\FinanceMoneyFormatter;
use FitControl\Domain\Finance\FinancePaymentHistoryQuery;
use FitControl\Domain\Reports\FinanceReportQuery;
use FitControl\Domain\Reports\ReportPeriod;

require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentPeriodCalculator.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentInterval.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentStatus.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentCreationService.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentCancellationService.php';
require_once dirname(__DIR__) . '/src/Domain/Finance/FinanceService.php';
require_once dirname(__DIR__) . '/src/Domain/Finance/FinanceDashboardStatus.php';
require_once dirname(__DIR__) . '/src/Domain/Finance/FinanceMoneyFormatter.php';
require_once dirname(__DIR__) . '/src/Domain/Finance/FinancePaymentHistoryQuery.php';
require_once dirname(__DIR__) . '/src/Domain/Reports/ReportPeriod.php';
require_once dirname(__DIR__) . '/src/Domain/Reports/FinanceReportQuery.php';

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

function assertFinanceInvalid(callable $operation, string $message): void
{
    try { $operation(); }
    catch (InvalidArgumentException) { return; }
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
    } catch (DomainException $exception) {
        fwrite(STDOUT, 'REJECTED=' . $exception->getMessage() . "\n");
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


/**
 * Disputa real de saldo: duas conexões independentes tentam pagar R$ 200,00
 * numa cobrança de R$ 300,00. Só um lançamento pode ser confirmado.
 */
function runFinanceBalanceRace(PDO $pdo, int $chargeId, int $actorId): int
{
    $requests = [
        ['200.00', 'PIX', '2026-11-22', '11111111111111111111111111111111', 'Corrida limite PIX'],
        ['200.00', 'DINHEIRO', '2026-11-22', '22222222222222222222222222222222', 'Corrida limite dinheiro'],
    ];
    $workers = [];
    $pdo->beginTransaction();

    try {
        $lock = $pdo->prepare('SELECT id FROM cobrancas WHERE id = :id FOR UPDATE');
        $lock->execute(['id' => $chargeId]);
        assertFinance((int) $lock->fetchColumn() === $chargeId, 'Cobrança do teste de limite não encontrada.');

        foreach ($requests as [$amount, $method, $paymentDate, $key, $observation]) {
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
                    $key,
                    $observation,
                ],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes
            );

            assertFinance(is_resource($process), 'Não foi possível iniciar worker do teste de limite financeiro.');
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 15);
            $workers[] = ['process' => $process, 'pipes' => $pipes];
        }

        foreach ($workers as $worker) {
            $signal = fgets($worker['pipes'][1]);
            $metadata = stream_get_meta_data($worker['pipes'][1]);
            assertFinance(
                $signal === "READY\n" && empty($metadata['timed_out']),
                'Worker do limite não confirmou prontidão antes da liberação do bloqueio.'
            );
        }

        $pdo->commit();

        $paymentId = null;
        $rejected = 0;
        foreach ($workers as $index => $worker) {
            $stdout = trim(stream_get_contents($worker['pipes'][1]));
            $stderr = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);

            $exitCode = proc_close($worker['process']);
            $workers[$index]['process'] = null;
            $workers[$index]['pipes'] = [];

            assertFinance($exitCode === 0, 'Worker do limite falhou: ' . trim($stderr));

            if (preg_match('/^PAYMENT_ID=(\d+)$/D', $stdout, $matches) === 1) {
                assertFinance($paymentId === null, 'A corrida confirmou mais de um pagamento.');
                $paymentId = (int) $matches[1];
                continue;
            }

            assertFinance(
                str_starts_with($stdout, 'REJECTED=')
                    && str_contains($stdout, 'excede o saldo em aberto'),
                'A segunda requisição deve ser recusada especificamente por exceder o saldo: ' . $stdout
            );
            $rejected++;
        }

        assertFinance($paymentId !== null, 'A corrida deve confirmar exatamente um pagamento.');
        assertFinance($rejected === 1, 'A corrida deve rejeitar exatamente uma requisição por saldo insuficiente.');

        return $paymentId;
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
    for ($i = 1; $i <= 4; $i++) {
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

    // Quarta matrícula isolada para a corrida de saldo: dois pagamentos de
    // R$ 200,00 competirão por uma cobrança de R$ 300,00.
    $balanceEnrollmentId = $enrollmentService->create(
        $studentIds[3], $planIds[0], $actorId, financeDate('2026-12-15 09:00:00')
    );
    $enrollmentIds[] = $balanceEnrollmentId;
    $balanceChargeQuery = $pdo->prepare('SELECT id FROM cobrancas WHERE matricula_id = :id');
    $balanceChargeQuery->execute(['id' => $balanceEnrollmentId]);
    $balanceChargeId = (int) $balanceChargeQuery->fetchColumn();
    assertFinance($balanceChargeId > 0, 'A matrícula da corrida de saldo precisa ter cobrança.');
    $chargeIds[] = $balanceChargeId;

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

    // F-06: chaves distintas, mesma cobrança e pagamentos que somados
    // ultrapassariam o contrato. O lock precisa permitir apenas um deles.
    $balanceRacePaymentId = runFinanceBalanceRace($pdo, $balanceChargeId, $actorId);
    $balanceRaceRows = $pdo->prepare(
        'SELECT COUNT(*) AS quantidade, COALESCE(SUM(valor), 0.00) AS total
         FROM pagamentos
         WHERE cobranca_id = :charge_id'
    );
    $balanceRaceRows->execute(['charge_id' => $balanceChargeId]);
    $balanceRace = $balanceRaceRows->fetch();
    assertFinance(
        (int) $balanceRace['quantidade'] === 1 && $balanceRace['total'] === '200.00',
        'A corrida de saldo deve preservar exatamente um pagamento de R$ 200,00.'
    );

    $balanceRaceAudit = $pdo->prepare(
        'SELECT COUNT(*) FROM auditoria
         WHERE usuario_id = :actor_id
           AND acao = :action
           AND entidade = :entity
           AND entidade_id = :entity_id'
    );
    $balanceRaceAudit->execute([
        'actor_id' => $actorId,
        'action' => 'pagamento_registrado',
        'entity' => 'pagamentos',
        'entity_id' => $balanceRacePaymentId,
    ]);
    assertFinance(
        (int) $balanceRaceAudit->fetchColumn() === 1,
        'A corrida de saldo deve produzir exatamente um evento de auditoria.'
    );

    // F-08: recebimento retroativo pode ser lançado, mas exige justificativa;
    // datas futuras são sempre rejeitadas no serviço.
    assertFinanceInvalid(
        fn () => $finance->registerPayment(
            $balanceChargeId, $actorId, '10.00', 'PIX', '2026-11-20',
            '33333333333333333333333333333333'
        ),
        'Pagamento retroativo sem observação deve ser recusado.'
    );
    assertFinanceInvalid(
        fn () => $finance->registerPayment(
            $balanceChargeId, $actorId, '10.00', 'PIX', '2026-11-23',
            '44444444444444444444444444444444', 'Tentativa de data futura'
        ),
        'Pagamento com data futura deve ser recusado mesmo com observação.'
    );
    $retroPaymentId = $finance->registerPayment(
        $balanceChargeId,
        $actorId,
        '10.00',
        'PIX',
        '2026-11-20',
        '55555555555555555555555555555555',
        'Recebimento em 20/11; lançado após conferência do comprovante.'
    );
    assertFinance($retroPaymentId > 0, 'Pagamento retroativo com justificativa deve ser aceito.');

    // Exercita as cinco formas permitidas no agrupamento FN-03. O valor
    // restante da cobrança continua positivo para validar FN-05.
    foreach ([
        ['TRANSFERENCIA', '66666666666666666666666666666666'],
        ['CARTAO', '77777777777777777777777777777777'],
        ['OUTRO', '88888888888888888888888888888888'],
    ] as [$method, $key]) {
        $finance->registerPayment(
            $balanceChargeId, $actorId, '1.00', $method, '2026-11-22', $key,
            'Validação de agrupamento por forma de pagamento.'
        );
    }

    $retroPaymentQuery = $pdo->prepare(
        'SELECT data_pagamento, observacao FROM pagamentos WHERE id = :id'
    );
    $retroPaymentQuery->execute(['id' => $retroPaymentId]);
    $retroPayment = $retroPaymentQuery->fetch();
    assertFinance(
        $retroPayment['data_pagamento'] === '2026-11-20'
            && $retroPayment['observacao'] === 'Recebimento em 20/11; lançado após conferência do comprovante.',
        'Data e justificativa do lançamento retroativo devem ser preservadas.'
    );

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

    // F-05: o próprio banco rejeita zero e negativos, mesmo fora dos serviços.
    foreach (['0.00', '-0.01'] as $invalidChargeAmount) {
        assertFinanceSqlRejected(
            $pdo,
            'UPDATE cobrancas SET valor_total = :amount WHERE id = :charge_id',
            ['amount' => $invalidChargeAmount, 'charge_id' => $firstChargeId],
            'A constraint deve rejeitar cobrança com valor zero ou negativo.',
            'chk_cobrancas_valor_total_positivo'
        );
    }

    // O guard do F-02 é habilitado somente para que o INSERT alcance a CHECK
    // constraint; a variável é sempre limpa, mesmo se a validação falhar.
    $pdo->exec('SET @fitcontrol_payment_write = 1');
    try {
        foreach (['0.00', '-0.01'] as $invalidPaymentAmount) {
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
                    'amount' => $invalidPaymentAmount,
                    'method' => 'PIX',
                    'payment_date' => '2026-11-22',
                    'actor_id' => $actorId,
                    'registered_at' => '2026-11-22 13:00:00',
                ],
                'A constraint deve rejeitar pagamento com valor zero ou negativo, após passar pelo guard.',
                'chk_pagamentos_valor_positivo'
            );
        }
    } finally {
        $pdo->exec('SET @fitcontrol_payment_write = NULL');
    }

    // Os casos positivos do próprio fluxo financeiro precisam continuar válidos.
    $positiveCharge = $pdo->prepare('SELECT valor_total FROM cobrancas WHERE id = :id');
    $positiveCharge->execute(['id' => $firstChargeId]);
    assertFinance(
        $positiveCharge->fetchColumn() === '300.00',
        'A constraint não pode rejeitar o valor positivo contratado.'
    );
    $positivePayments = $pdo->prepare(
        'SELECT COUNT(*) FROM pagamentos WHERE cobranca_id = :id AND valor > 0.00'
    );
    $positivePayments->execute(['id' => $firstChargeId]);
    assertFinance(
        (int) $positivePayments->fetchColumn() === 2,
        'A constraint não pode rejeitar pagamentos positivos válidos.'
    );

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

    // Relatório financeiro FN-01 a FN-06: período de pagamentos e vencimentos
    // não altera as fotografias atuais dos saldos.
    $financeReport = (new FinanceReportQuery($pdo))->forPeriod(
        ReportPeriod::fromInput('2026-11-22', '2026-11-22', financeDate('2026-11-23 10:00:00'))
    );
    assertFinance(
        $financeReport['fn01_received_decimal'] === '553.00',
        'FN-01 deve somar pagamentos pela data declarada, excluindo o retroativo de 20/11.'
    );
    assertFinance($financeReport['fn02_payment_count'] === 7, 'FN-02 deve contar cada lançamento do período uma vez.');
    assertFinance(
        count($financeReport['fn03_by_method']) === 5
            && array_column($financeReport['fn03_by_method'], 'method') === ['DINHEIRO', 'PIX', 'TRANSFERENCIA', 'CARTAO', 'OUTRO'],
        'FN-03 deve agrupar somente as cinco formas do ENUM, na ordem estável aprovada.'
    );
    assertFinance(
        array_sum(array_column($financeReport['fn03_by_method'], 'payment_count')) === 7
            && array_sum(array_map(
                static fn (array $group): int => FinanceMoneyFormatter::toCents($group['received_decimal']),
                $financeReport['fn03_by_method']
            )) === 55300,
        'FN-03 deve reconciliar contagens e valores com FN-01/FN-02.'
    );
    assertFinance(
        $financeReport['fn04_due_charges']['collectible']['charge_count'] === 1
            && $financeReport['fn04_due_charges']['collectible']['nominal_total_decimal'] === '300.00'
            && $financeReport['fn04_due_charges']['review']['charge_count'] === 2
            && $financeReport['fn04_due_charges']['review']['nominal_total_decimal'] === '600.00',
        'FN-04 deve separar cobranças não canceladas por situação atual da matrícula, sem multiplicar por pagamentos.'
    );
    assertFinance(
        $financeReport['fn05_collectible_balance_cents'] === 8700
            && $financeReport['fn06_review_balance_cents'] === 25000,
        'FN-05/FN-06 devem apresentar saldos atuais sem filtro de vencimento e respeitar matrículas canceladas.'
    );

    // FN-05/FN-06: o agregado SQL deve reproduzir as regras canônicas por cobrança.
    // Pagamentos são pré-agregados para impedir multiplicação de linhas no JOIN.
    $chargePlaceholders = implode(',', array_fill(0, count($chargeIds), '?'));
    $chargeRowsQuery = $pdo->prepare(
        'SELECT
            c.id,
            c.valor_total,
            c.cancelada_em_utc,
            m.cancelada_em AS matricula_cancelada_em,
            COALESCE(p.valor_pago, 0.00) AS valor_pago
         FROM cobrancas c
         INNER JOIN matriculas m ON m.id = c.matricula_id
         LEFT JOIN (
            SELECT cobranca_id, SUM(valor) AS valor_pago
            FROM pagamentos
            GROUP BY cobranca_id
         ) p ON p.cobranca_id = c.id
         WHERE c.id IN (' . $chargePlaceholders . ')'
    );
    $chargeRowsQuery->execute($chargeIds);
    $chargeRows = $chargeRowsQuery->fetchAll();

    $expectedCollectibleCents = 0;
    $expectedReviewCents = 0;
    foreach ($chargeRows as $chargeRow) {
        $expectedCollectibleCents += FinanceDashboardStatus::collectibleBalanceCents($chargeRow);
        $expectedReviewCents += FinanceDashboardStatus::reviewBalanceCents($chargeRow);
    }

    $financialSummaryQuery = $pdo->prepare(
        'SELECT
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
         ) p ON p.cobranca_id = c.id
         WHERE c.id IN (' . $chargePlaceholders . ')'
    );
    $financialSummaryQuery->execute($chargeIds);
    $financialSummary = $financialSummaryQuery->fetch();

    assertFinance(
        FinanceMoneyFormatter::toCents((string) $financialSummary['saldo_cobravel']) === $expectedCollectibleCents,
        'Agregado SQL do FN-05 deve corresponder à soma de FinanceDashboardStatus::collectibleBalanceCents().'
    );
    assertFinance(
        FinanceMoneyFormatter::toCents((string) $financialSummary['saldo_em_revisao']) === $expectedReviewCents,
        'Agregado SQL do FN-06 deve corresponder à soma de FinanceDashboardStatus::reviewBalanceCents().'
    );
    assertFinance(
        $expectedCollectibleCents === 8700 && $expectedReviewCents === 25000,
        'Fixtures devem preservar R$ 90,00 cobráveis e R$ 250,00 em revisão, sem misturar cobranças quitadas ou canceladas.'
    );

    $audit = $pdo->prepare(
        'SELECT COUNT(*) FROM auditoria
         WHERE usuario_id = :actor_id AND acao = :action AND entidade = :entity AND entidade_id = :entity_id'
    );
    foreach ([
        ['cobranca_criada', 'cobrancas', $firstChargeId],
        ['pagamento_registrado', 'pagamentos', $paymentOne],
        ['pagamento_registrado', 'pagamentos', $paymentTwo],
        ['pagamento_registrado', 'pagamentos', $balanceRacePaymentId],
        ['pagamento_registrado', 'pagamentos', $retroPaymentId],
        ['cobranca_criada', 'cobrancas', $balanceChargeId],
        ['cobranca_cancelada', 'cobrancas', $secondChargeId],
    ] as [$action, $entity, $entityId]) {
        $audit->execute(['actor_id' => $actorId, 'action' => $action, 'entity' => $entity, 'entity_id' => $entityId]);
        assertFinance((int) $audit->fetchColumn() === 1, 'Evento de auditoria ausente: ' . $action);
    }

    echo "Financeiro transacional: OK; idempotência, corrida de saldo em duas conexões, pagamentos parciais, constraints positivas, imutabilidade, revisão de matrícula, histórico e auditoria validados.\n";
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
