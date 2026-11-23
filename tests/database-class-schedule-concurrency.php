<?php
declare(strict_types=1);

use FitControl\Domain\Classes\ClassCatalogService;
use FitControl\Domain\Classes\ClassScheduleService;
use FitControl\Security\AuditIntegrityChain;

require_once dirname(__DIR__) . '/src/Domain/Classes/ClassCatalogService.php';
require_once dirname(__DIR__) . '/src/Domain/Classes/ClassScheduleService.php';
require_once dirname(__DIR__) . '/src/Security/AuditIntegrityChain.php';

function classScheduleConcurrencyConnection(): PDO
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
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database),
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function assertScheduleConcurrency(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function runScheduleConcurrencyWorker(string $mode, int $actorId, int $modalityId, int $instructorId): void
{
    if ($actorId < 1 || $modalityId < 1 || $instructorId < 1) {
        throw new InvalidArgumentException('Identificadores inválidos para o processo concorrente.');
    }

    $pdo = classScheduleConcurrencyConnection();

    if ($mode === 'writer') {
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare(
                'SELECT id FROM instrutores WHERE id = :id FOR UPDATE'
            );
            $lock->execute(['id' => $instructorId]);
            if ($lock->fetchColumn() === false) {
                throw new RuntimeException('Instrutor de teste não encontrado para bloqueio.');
            }

            fwrite(STDOUT, "LOCKED\n");
            fflush(STDOUT);
            usleep(1_500_000);

            $insert = $pdo->prepare(
                'INSERT INTO horarios_aula (
                    modalidade_id, instrutor_id, dia_semana, hora_inicio, hora_fim
                 ) VALUES (
                    :modality_id, :instructor_id, 1, \'09:00:00\', \'10:00:00\'
                 )'
            );
            $insert->execute([
                'modality_id' => $modalityId,
                'instructor_id' => $instructorId,
            ]);
            $scheduleId = (int) $pdo->lastInsertId();

            AuditIntegrityChain::append(
                $pdo,
                $actorId,
                'horario_aula_criado',
                'horarios_aula',
                $scheduleId
            );
            $pdo->commit();

            fwrite(STDOUT, "COMMITTED={$scheduleId}\n");
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
        fwrite(STDOUT, "ATTEMPTING\n");
        fflush(STDOUT);
        $startedAt = hrtime(true);

        try {
            $service = new ClassScheduleService($pdo);
            $service->create(
                $actorId,
                $modalityId,
                $instructorId,
                1,
                '09:30',
                '10:30'
            );
        } catch (DomainException $exception) {
            $waitedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;
            if (!str_contains($exception->getMessage(), 'conflita')) {
                throw $exception;
            }

            printf("WAITED_MS=%.2f;CONFLICT=1\n", $waitedMilliseconds);
            fflush(STDOUT);
            return;
        }

        throw new RuntimeException('O serviço concorrente aceitou uma aula sobreposta.');
    }

    throw new InvalidArgumentException('Modo de processo desconhecido.');
}

if (isset($argv[1]) && in_array($argv[1], ['writer', 'contender'], true)) {
    try {
        runScheduleConcurrencyWorker(
            $argv[1],
            (int) ($argv[2] ?? 0),
            (int) ($argv[3] ?? 0),
            (int) ($argv[4] ?? 0)
        );
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . "\n");
        exit(1);
    }
}

$pdo = classScheduleConcurrencyConnection();
$actorEmail = 'fitcontrol-class-concurrency-' . bin2hex(random_bytes(8)) . '@example.invalid';
$actor = $pdo->prepare(
    'INSERT INTO usuarios (nome, email, senha_hash, ativo)
     VALUES (:name, :email, :password_hash, 1)'
);
$actor->execute([
    'name' => 'Responsável concorrência de aulas',
    'email' => $actorEmail,
    'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
]);
$actorId = (int) $pdo->lastInsertId();

$suffix = bin2hex(random_bytes(5));
$catalog = new ClassCatalogService($pdo);
$modalityId = $catalog->createModality($actorId, 'Concorrência ' . $suffix);
$instructorId = $catalog->createInstructor(
    $actorId,
    'Instrutor concorrência ' . $suffix
);

$writerProcess = null;
$contenderProcess = null;
$writerPipes = [];
$contenderPipes = [];

try {
    $arguments = [(string) $actorId, (string) $modalityId, (string) $instructorId];
    $writerProcess = proc_open(
        [PHP_BINARY, __FILE__, 'writer', ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $writerPipes
    );
    assertScheduleConcurrency(is_resource($writerProcess), 'Não foi possível iniciar o processo que segura o instrutor.');
    fclose($writerPipes[0]);
    stream_set_timeout($writerPipes[1], 10);
    $writerSignal = fgets($writerPipes[1]);
    assertScheduleConcurrency(
        $writerSignal === "LOCKED\n",
        'O primeiro processo não confirmou o bloqueio do instrutor: ' . (string) $writerSignal
    );

    $contenderProcess = proc_open(
        [PHP_BINARY, __FILE__, 'contender', ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $contenderPipes
    );
    assertScheduleConcurrency(is_resource($contenderProcess), 'Não foi possível iniciar o processo concorrente.');
    fclose($contenderPipes[0]);
    stream_set_timeout($contenderPipes[1], 10);
    $contenderSignal = fgets($contenderPipes[1]);
    assertScheduleConcurrency(
        $contenderSignal === "ATTEMPTING\n",
        'O processo concorrente não confirmou o início da tentativa.'
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

    assertScheduleConcurrency(
        $writerExit === 0,
        'O processo inicial falhou: ' . trim($writerError) . ' ' . trim((string) $writerResult)
    );
    assertScheduleConcurrency(
        $contenderExit === 0,
        'O processo concorrente falhou: ' . trim($contenderError)
    );
    assertScheduleConcurrency(
        is_string($contenderResult)
            && preg_match('/^WAITED_MS=([0-9.]+);CONFLICT=1\s*$/', $contenderResult, $matches) === 1,
        'O processo concorrente não detectou o conflito: ' . (string) $contenderResult
    );

    $waitedMilliseconds = (float) $matches[1];
    assertScheduleConcurrency(
        $waitedMilliseconds >= 500,
        sprintf('O segundo processo não aguardou o lock do instrutor (%.2f ms).', $waitedMilliseconds)
    );

    $writerScheduleId = null;
    if (is_string($writerResult) && preg_match('/^COMMITTED=(\d+)\s*$/', $writerResult, $writerMatches) === 1) {
        $writerScheduleId = (int) $writerMatches[1];
    }
    assertScheduleConcurrency($writerScheduleId !== null, 'O primeiro processo não informou a aula criada.');

    (new ClassScheduleService($pdo))->deactivate($writerScheduleId, $actorId);
    $catalog->deactivateInstructor($instructorId, $actorId);
    $catalog->deactivateModality($modalityId, $actorId);

    echo sprintf(
        "Concorrência da grade: segunda conexão aguardou %.2f ms e rejeitou a sobreposição.\n",
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
}
