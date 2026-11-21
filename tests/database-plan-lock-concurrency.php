<?php
declare(strict_types=1);

/**
 * Valida a concorrência entre criação de matrícula e exclusão de plano.
 *
 * Usa tabelas temporárias descartáveis e dois processos PHP com conexões PDO
 * independentes. Também prova rollback integral quando a gravação da auditoria falha.
 */

function openPlanConcurrencyConnection(): PDO
{
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $database = getenv('DB_NAME') ?: 'academia_alunos';
    $username = getenv('DB_USER') ?: 'root';
    $password = getenv('DB_PASSWORD');

    if ($password === false) {
        $password = '';
    }

    return new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $database
        ),
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function assertPlanConcurrency(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertPlanPrefix(string $prefix): void
{
    if (!preg_match('/^fitcontrol_plan_lock_[a-f0-9]{8}$/D', $prefix)) {
        throw new InvalidArgumentException('Prefixo temporário inválido.');
    }
}

function runPlanWorker(string $mode, string $prefix): void
{
    assertPlanPrefix($prefix);
    $pdo = openPlanConcurrencyConnection();

    $studentsTable = $prefix . '_students';
    $plansTable = $prefix . '_plans';
    $enrollmentsTable = $prefix . '_enrollments';

    if ($mode === 'create-enrollment') {
        $pdo->beginTransaction();

        try {
            // Ordem aprovada para criação: primeiro aluno, depois plano.
            $studentLock = $pdo->prepare(
                "SELECT id FROM {$studentsTable} WHERE id = :id FOR UPDATE"
            );
            $studentLock->execute(['id' => 1]);

            if ($studentLock->fetchColumn() === false) {
                throw new RuntimeException('Aluno não encontrado para bloqueio.');
            }

            $planLock = $pdo->prepare(
                "SELECT id, active FROM {$plansTable} WHERE id = :id FOR UPDATE"
            );
            $planLock->execute(['id' => 1]);
            $plan = $planLock->fetch();

            if (!$plan || (int) $plan['active'] !== 1) {
                $pdo->rollBack();
                fwrite(STDOUT, "REJECTED_INACTIVE_PLAN\n");
                fflush(STDOUT);
                return;
            }

            // Ambos os locks foram obtidos. O processo de exclusão começa agora.
            fwrite(STDOUT, "LOCKED_STUDENT_AND_PLAN\n");
            fflush(STDOUT);

            usleep(1_500_000);

            $insert = $pdo->prepare(
                "INSERT INTO {$enrollmentsTable}
                    (student_id, plan_id, start_at_utc, end_exclusive_at_utc)
                 VALUES (:student_id, :plan_id, :start_at, :end_at)"
            );
            $insert->execute([
                'student_id' => 1,
                'plan_id' => 1,
                'start_at' => '2026-02-10 12:00:00',
                'end_at' => '2026-03-10 12:00:00',
            ]);

            $pdo->commit();
            fwrite(STDOUT, "ENROLLMENT_COMMITTED\n");
            fflush(STDOUT);
            return;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    if ($mode === 'delete-plan') {
        $pdo->beginTransaction();

        try {
            fwrite(STDOUT, "ATTEMPTING_PLAN_LOCK\n");
            fflush(STDOUT);

            $startedAt = hrtime(true);
            $planLock = $pdo->prepare(
                "SELECT id FROM {$plansTable} WHERE id = :id FOR UPDATE"
            );
            $planLock->execute(['id' => 1]);
            $planId = $planLock->fetchColumn();

            if ($planId === false) {
                throw new RuntimeException('Plano não encontrado para bloqueio.');
            }

            $waitedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

            // A leitura com bloqueio vê vínculos confirmados enquanto este processo aguardava.
            $findEnrollment = $pdo->prepare(
                "SELECT id
                 FROM {$enrollmentsTable}
                 WHERE plan_id = :plan_id
                 LIMIT 1
                 FOR UPDATE"
            );
            $findEnrollment->execute(['plan_id' => 1]);
            $enrollmentId = $findEnrollment->fetchColumn();

            if ($enrollmentId !== false) {
                $pdo->rollBack();
                printf(
                    "WAITED_MS=%.2f;LINKS=1;ACTION=BLOCKED\n",
                    $waitedMilliseconds
                );
                fflush(STDOUT);
                return;
            }

            $delete = $pdo->prepare(
                "DELETE FROM {$plansTable} WHERE id = :id"
            );
            $delete->execute(['id' => 1]);
            $pdo->commit();

            printf(
                "WAITED_MS=%.2f;LINKS=0;ACTION=DELETED\n",
                $waitedMilliseconds
            );
            fflush(STDOUT);
            return;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    throw new InvalidArgumentException('Modo de processo desconhecido.');
}

if (isset($argv[1]) && in_array($argv[1], ['create-enrollment', 'delete-plan'], true)) {
    try {
        runPlanWorker($argv[1], (string) ($argv[2] ?? ''));
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . "\n");
        exit(1);
    }
}

$pdo = openPlanConcurrencyConnection();
$prefix = 'fitcontrol_plan_lock_' . bin2hex(random_bytes(4));
assertPlanPrefix($prefix);

$usersTable = $prefix . '_users';
$studentsTable = $prefix . '_students';
$plansTable = $prefix . '_plans';
$enrollmentsTable = $prefix . '_enrollments';
$auditTable = $prefix . '_audit_events';

$creatorProcess = null;
$deleteProcess = null;
$creatorPipes = [];
$deletePipes = [];

try {
    $pdo->exec(
        "CREATE TABLE {$usersTable} (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE {$studentsTable} (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE {$plansTable} (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            active TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE {$enrollmentsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_id BIGINT UNSIGNED NOT NULL,
            plan_id BIGINT UNSIGNED NOT NULL,
            start_at_utc DATETIME NOT NULL,
            end_exclusive_at_utc DATETIME NOT NULL,
            INDEX idx_plan_period (plan_id, start_at_utc, end_exclusive_at_utc),
            CONSTRAINT fk_plan_probe_student
                FOREIGN KEY (student_id) REFERENCES {$studentsTable} (id)
                ON DELETE RESTRICT,
            CONSTRAINT fk_plan_probe_plan
                FOREIGN KEY (plan_id) REFERENCES {$plansTable} (id)
                ON DELETE RESTRICT
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE {$auditTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            action_name VARCHAR(80) NOT NULL,
            CONSTRAINT fk_plan_probe_audit_user
                FOREIGN KEY (user_id) REFERENCES {$usersTable} (id)
                ON DELETE RESTRICT
        ) ENGINE=InnoDB"
    );

    $pdo->exec("INSERT INTO {$usersTable} (id) VALUES (1)");
    $pdo->exec("INSERT INTO {$studentsTable} (id) VALUES (1), (2)");
    $pdo->exec("INSERT INTO {$plansTable} (id, active) VALUES (1, 1)");

    $creatorProcess = proc_open(
        [PHP_BINARY, __FILE__, 'create-enrollment', $prefix],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $creatorPipes
    );

    assertPlanConcurrency(is_resource($creatorProcess), 'Não foi possível iniciar a criação da matrícula.');
    fclose($creatorPipes[0]);

    stream_set_timeout($creatorPipes[1], 10);
    $creatorSignal = fgets($creatorPipes[1]);

    assertPlanConcurrency(
        $creatorSignal === "LOCKED_STUDENT_AND_PLAN\n",
        'O criador não confirmou os bloqueios do aluno e do plano: ' . (string) $creatorSignal
    );

    $deleteProcess = proc_open(
        [PHP_BINARY, __FILE__, 'delete-plan', $prefix],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $deletePipes
    );

    assertPlanConcurrency(is_resource($deleteProcess), 'Não foi possível iniciar a exclusão concorrente do plano.');
    fclose($deletePipes[0]);

    stream_set_timeout($deletePipes[1], 10);
    $deleteSignal = fgets($deletePipes[1]);

    assertPlanConcurrency(
        $deleteSignal === "ATTEMPTING_PLAN_LOCK\n",
        'O processo de exclusão não confirmou a tentativa de bloqueio.'
    );

    $deleteResult = fgets($deletePipes[1]);
    $creatorResult = fgets($creatorPipes[1]);
    $deleteError = stream_get_contents($deletePipes[2]);
    $creatorError = stream_get_contents($creatorPipes[2]);

    fclose($deletePipes[1]);
    fclose($deletePipes[2]);
    fclose($creatorPipes[1]);
    fclose($creatorPipes[2]);

    $deleteExit = proc_close($deleteProcess);
    $deleteProcess = null;
    $creatorExit = proc_close($creatorProcess);
    $creatorProcess = null;

    assertPlanConcurrency(
        $creatorExit === 0,
        'A criação da matrícula falhou: ' . trim($creatorError) . ' ' . (string) $creatorResult
    );
    assertPlanConcurrency(
        $deleteExit === 0,
        'A operação concorrente do plano falhou: ' . trim($deleteError)
    );
    assertPlanConcurrency(
        $creatorResult === "ENROLLMENT_COMMITTED\n",
        'A criação não confirmou a matrícula: ' . (string) $creatorResult
    );
    assertPlanConcurrency(
        is_string($deleteResult)
            && preg_match('/^WAITED_MS=([0-9.]+);LINKS=(\d+);ACTION=(\w+)\s*$/', $deleteResult, $matches) === 1,
        'Resultado inesperado da disputa pelo plano: ' . (string) $deleteResult
    );

    $waitedMilliseconds = (float) $matches[1];
    $links = (int) $matches[2];
    $action = $matches[3];

    assertPlanConcurrency(
        $waitedMilliseconds >= 500,
        sprintf('A exclusão não aguardou pelo lock do plano (%.2f ms).', $waitedMilliseconds)
    );
    assertPlanConcurrency(
        $links === 1 && $action === 'BLOCKED',
        'A exclusão deveria encontrar o vínculo recém-confirmado e ser bloqueada.'
    );

    $planCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM {$plansTable} WHERE id = 1"
    )->fetchColumn();
    $enrollmentCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM {$enrollmentsTable}"
    )->fetchColumn();

    assertPlanConcurrency($planCount === 1, 'O plano com histórico não pode ser excluído.');
    assertPlanConcurrency($enrollmentCount === 1, 'A matrícula confirmada deve permanecer persistida.');

    // Simula falha de auditoria depois da gravação operacional.
    // A FK inválida deve abortar a transação e desfazer também a matrícula.
    $pdo->beginTransaction();

    try {
        $insertEnrollment = $pdo->prepare(
            "INSERT INTO {$enrollmentsTable}
                (student_id, plan_id, start_at_utc, end_exclusive_at_utc)
             VALUES (:student_id, :plan_id, :start_at, :end_at)"
        );
        $insertEnrollment->execute([
            'student_id' => 2,
            'plan_id' => 1,
            'start_at' => '2026-04-10 12:00:00',
            'end_at' => '2026-05-10 12:00:00',
        ]);

        $insertAudit = $pdo->prepare(
            "INSERT INTO {$auditTable} (user_id, action_name)
             VALUES (:user_id, :action_name)"
        );
        $insertAudit->execute([
            'user_id' => 999,
            'action_name' => 'matricula_criada',
        ]);

        $pdo->commit();
        throw new RuntimeException('A FK de auditoria deveria impedir o COMMIT do cenário de falha.');
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $afterRollbackCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM {$enrollmentsTable}"
    )->fetchColumn();
    $auditCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM {$auditTable}"
    )->fetchColumn();

    assertPlanConcurrency(
        $afterRollbackCount === 1,
        'A falha da auditoria deve desfazer a matrícula inserida na mesma transação.'
    );
    assertPlanConcurrency(
        $auditCount === 0,
        'Nenhum evento de auditoria parcial pode permanecer após o rollback.'
    );

    printf(
        "Concorrência plano/matrícula: OK; lock aguardado %.2f ms; exclusão bloqueada com histórico; rollback integral confirmado.\n",
        $waitedMilliseconds
    );
} finally {
    foreach ([$deletePipes, $creatorPipes] as $pipes) {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }

    foreach ([$deleteProcess, $creatorProcess] as $process) {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }

    $pdo->exec("DROP TABLE IF EXISTS {$auditTable}");
    $pdo->exec("DROP TABLE IF EXISTS {$enrollmentsTable}");
    $pdo->exec("DROP TABLE IF EXISTS {$plansTable}");
    $pdo->exec("DROP TABLE IF EXISTS {$studentsTable}");
    $pdo->exec("DROP TABLE IF EXISTS {$usersTable}");
}
