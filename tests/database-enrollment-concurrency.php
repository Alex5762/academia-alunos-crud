<?php
declare(strict_types=1);

/**
 * Testa a estratégia de serialização prevista para matrículas:
 * bloquear primeiro a linha do aluno e só então verificar sobreposição.
 *
 * Usa tabelas descartáveis e dois processos PHP com conexões PDO independentes.
 * Não cria nem modifica tabelas reais do FitControl.
 */

function openDatabaseConnection(): PDO
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

function assertConcurrency(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertProbePrefix(string $prefix): void
{
    if (!preg_match('/^fitcontrol_enrollment_lock_[a-f0-9]{8}$/D', $prefix)) {
        throw new InvalidArgumentException('Prefixo temporário inválido.');
    }
}

function runWorker(string $mode, string $prefix): void
{
    assertProbePrefix($prefix);
    $pdo = openDatabaseConnection();

    $studentsTable = $prefix . '_students';
    $enrollmentsTable = $prefix . '_enrollments';

    if ($mode === 'writer') {
        $pdo->beginTransaction();

        try {
            $lockStudent = $pdo->prepare(
                "SELECT id FROM {$studentsTable} WHERE id = :id FOR UPDATE"
            );
            $lockStudent->execute(['id' => 1]);

            if ($lockStudent->fetchColumn() === false) {
                throw new RuntimeException('Aluno de teste não encontrado para bloqueio.');
            }

            // O processo principal só inicia o concorrente depois deste sinal.
            fwrite(STDOUT, "LOCKED\n");
            fflush(STDOUT);

            usleep(2_000_000);

            // DATETIME é preenchido com valores UTC literais, conforme a convenção do domínio.
            $insert = $pdo->prepare(
                "INSERT INTO {$enrollmentsTable}
                    (student_id, start_at_utc, end_exclusive_at_utc)
                 VALUES (:student_id, :start_at, :end_at)"
            );
            $insert->execute([
                'student_id' => 1,
                'start_at' => '2026-01-10 12:00:00',
                'end_at' => '2026-01-10 13:00:00',
            ]);

            $pdo->commit();
            fwrite(STDOUT, "COMMITTED\n");
            fflush(STDOUT);
            return;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    if ($mode === 'contender') {
        $pdo->beginTransaction();

        try {
            // Confirma que o concorrente iniciou a transação antes de solicitar o lock.
            fwrite(STDOUT, "ATTEMPTING\n");
            fflush(STDOUT);

            $startedAt = hrtime(true);
            $lockStudent = $pdo->prepare(
                "SELECT id FROM {$studentsTable} WHERE id = :id FOR UPDATE"
            );
            $lockStudent->execute(['id' => 1]);

            if ($lockStudent->fetchColumn() === false) {
                throw new RuntimeException('Aluno de teste não encontrado pelo concorrente.');
            }

            $waitedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

            // Tentativa: 09:30–10:30 UTC. Deve detectar a matrícula 09:00–10:00 UTC
            // que o primeiro processo confirmou enquanto o segundo aguardava o lock.
            $findOverlap = $pdo->prepare(
                "SELECT id
                 FROM {$enrollmentsTable}
                 WHERE student_id = :student_id
                   AND start_at_utc < :candidate_end
                   AND end_exclusive_at_utc > :candidate_start
                 FOR UPDATE"
            );
            $findOverlap->execute([
                'student_id' => 1,
                'candidate_start' => '2026-01-10 12:30:00',
                'candidate_end' => '2026-01-10 13:30:00',
            ]);
            $conflicts = $findOverlap->fetchAll(PDO::FETCH_COLUMN);

            $pdo->rollBack();

            printf(
                "WAITED_MS=%.2f;CONFLICTS=%d\n",
                $waitedMilliseconds,
                count($conflicts)
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

if (isset($argv[1]) && in_array($argv[1], ['writer', 'contender'], true)) {
    try {
        runWorker($argv[1], (string) ($argv[2] ?? ''));
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . "\n");
        exit(1);
    }
}

$pdo = openDatabaseConnection();
$prefix = 'fitcontrol_enrollment_lock_' . bin2hex(random_bytes(4));
assertProbePrefix($prefix);

$studentsTable = $prefix . '_students';
$enrollmentsTable = $prefix . '_enrollments';

$writerProcess = null;
$contenderProcess = null;
$writerPipes = [];
$contenderPipes = [];

try {
    $pdo->exec(
        "CREATE TABLE {$studentsTable} (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE {$enrollmentsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_id BIGINT UNSIGNED NOT NULL,
            start_at_utc DATETIME NOT NULL,
            end_exclusive_at_utc DATETIME NOT NULL,
            INDEX idx_student_period (student_id, start_at_utc, end_exclusive_at_utc),
            CONSTRAINT fk_probe_enrollment_student
                FOREIGN KEY (student_id) REFERENCES {$studentsTable} (id)
                ON DELETE RESTRICT
        ) ENGINE=InnoDB"
    );

    $pdo->exec("INSERT INTO {$studentsTable} (id) VALUES (1)");

    $writerProcess = proc_open(
        [PHP_BINARY, __FILE__, 'writer', $prefix],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $writerPipes
    );

    assertConcurrency(is_resource($writerProcess), 'Não foi possível iniciar o processo que segura o bloqueio.');
    fclose($writerPipes[0]);

    stream_set_timeout($writerPipes[1], 10);
    $writerSignal = fgets($writerPipes[1]);

    assertConcurrency(
        $writerSignal === "LOCKED\n",
        'O processo inicial não confirmou a aquisição do bloqueio: ' . (string) $writerSignal
    );

    $contenderProcess = proc_open(
        [PHP_BINARY, __FILE__, 'contender', $prefix],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $contenderPipes
    );

    assertConcurrency(is_resource($contenderProcess), 'Não foi possível iniciar o processo concorrente.');
    fclose($contenderPipes[0]);

    stream_set_timeout($contenderPipes[1], 10);
    $contenderSignal = fgets($contenderPipes[1]);

    assertConcurrency(
        $contenderSignal === "ATTEMPTING\n",
        'O segundo processo não confirmou o início da transação: ' . (string) $contenderSignal
    );

    $contenderResult = fgets($contenderPipes[1]);
    $contenderError = stream_get_contents($contenderPipes[2]);
    $writerResult = stream_get_contents($writerPipes[1]);
    $writerError = stream_get_contents($writerPipes[2]);

    fclose($contenderPipes[1]);
    fclose($contenderPipes[2]);
    fclose($writerPipes[1]);
    fclose($writerPipes[2]);

    $contenderExit = proc_close($contenderProcess);
    $contenderProcess = null;
    $writerExit = proc_close($writerProcess);
    $writerProcess = null;

    assertConcurrency(
        $writerExit === 0,
        'O processo que grava a matrícula falhou: ' . trim($writerError) . ' ' . trim($writerResult)
    );
    assertConcurrency(
        $contenderExit === 0,
        'O processo concorrente falhou: ' . trim($contenderError)
    );
    assertConcurrency(
        is_string($contenderResult) && preg_match('/^WAITED_MS=([0-9.]+);CONFLICTS=(\d+)\s*$/', $contenderResult, $matches) === 1,
        'O resultado do concorrente está em formato inesperado: ' . (string) $contenderResult
    );

    $waitedMilliseconds = (float) $matches[1];
    $conflicts = (int) $matches[2];

    assertConcurrency(
        $waitedMilliseconds >= 500,
        sprintf(
            'O segundo processo não aguardou pelo bloqueio da linha do aluno (%.2f ms).',
            $waitedMilliseconds
        )
    );
    assertConcurrency(
        $conflicts === 1,
        sprintf(
            'O segundo processo deveria detectar exatamente uma sobreposição; encontrou %d.',
            $conflicts
        )
    );

    $storedRows = (int) $pdo->query(
        "SELECT COUNT(*) FROM {$enrollmentsTable}"
    )->fetchColumn();

    assertConcurrency(
        $storedRows === 1,
        sprintf('A transação concorrente não deve inserir uma segunda matrícula; linhas encontradas: %d.', $storedRows)
    );

    printf(
        "Concorrência de matrículas: OK; bloqueio aguardado %.2f ms; conflito detectado; uma gravação persistida.\n",
        $waitedMilliseconds
    );
} finally {
    foreach ([$contenderPipes, $writerPipes] as $pipes) {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }

    foreach ([$contenderProcess, $writerProcess] as $process) {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }

    $pdo->exec("DROP TABLE IF EXISTS {$enrollmentsTable}");
    $pdo->exec("DROP TABLE IF EXISTS {$studentsTable}");
}
