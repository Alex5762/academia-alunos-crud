<?php
declare(strict_types=1);

/**
 * Prova que a decisão de revisão e a retificação concorrentes são serializadas
 * pela mesma linha-pai do aluno.
 *
 * O processo decisor segura os locks depois de os serviços os adquirirem
 * (relógio injetado). A retificação concorrente precisa aguardar e, após o
 * COMMIT da decisão para PENDENTE_REVISAO, deve rejeitar a operação.
 *
 * Usa tabelas descartáveis e dados de teste isolados; não altera migrations.
 */

use FitControl\Domain\Enrollment\EnrollmentPeriodCalculator;
use FitControl\Domain\Enrollment\LegacyEnrollmentRectificationService;
use FitControl\Domain\Enrollment\LegacyReviewDecisionService;

require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentPeriodCalculator.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentInterval.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/LegacyReviewDecisionService.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/LegacyEnrollmentRectificationService.php';

function openLegacyRaceConnection(): PDO
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

function legacyRaceLocalDate(string $value): DateTimeImmutable
{
    return new DateTimeImmutable(
        $value,
        new DateTimeZone(EnrollmentPeriodCalculator::ACADEMY_TIMEZONE)
    );
}

function legacyRaceUtc(string $value): string
{
    return legacyRaceLocalDate($value)
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');
}

function assertLegacyRace(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function runLegacyRaceWorker(
    string $mode,
    int $studentId,
    int $reviewId,
    int $enrollmentId,
    int $actorId
): void {
    $pdo = openLegacyRaceConnection();

    if ($mode === 'decision') {
        $service = new LegacyReviewDecisionService(
            $pdo,
            static function (): DateTimeImmutable {
                // O callback é executado pelo próprio serviço depois de bloquear
                // aluno, revisão e matrículas, imediatamente antes de persistir.
                fwrite(STDOUT, "DECISION_LOCKS_HELD\n");
                fflush(STDOUT);
                usleep(1_500_000);
                return legacyRaceLocalDate('2026-01-01 09:00:00');
            }
        );

        $service->decide(
            $studentId,
            $reviewId,
            $actorId,
            'PENDENTE_REVISAO',
            null,
            'Evidência contraditória exige nova revisão antes de qualquer retificação.'
        );

        fwrite(STDOUT, "DECISION_COMMITTED_PENDING\n");
        fflush(STDOUT);
        return;
    }

    if ($mode === 'rectification') {
        $service = new LegacyEnrollmentRectificationService($pdo);

        // O sinal é emitido antes de chamar o serviço e pedir o primeiro lock.
        fwrite(STDOUT, "RECTIFICATION_ATTEMPTING\n");
        fflush(STDOUT);

        $startedAt = hrtime(true);

        try {
            $service->rectify(
                $studentId,
                $reviewId,
                $enrollmentId,
                $actorId,
                'Evidência retificadora, documento 88',
                'A tentativa concorrente deve ser bloqueada pela decisão de revisão pendente.',
                legacyRaceLocalDate('2025-12-15 00:00:00'),
                legacyRaceLocalDate('2026-03-15 00:00:00')
            );
        } catch (DomainException $exception) {
            $waitedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

            if (!str_contains(
                $exception->getMessage(),
                'Somente um vínculo legado confirmado'
            )) {
                throw new RuntimeException(
                    'A retificação foi rejeitada por motivo diferente da revisão pendente: '
                    . $exception->getMessage(),
                    0,
                    $exception
                );
            }

            printf(
                "WAITED_MS=%.2f;RESULT=REJECTED_PENDING\n",
                $waitedMilliseconds
            );
            fflush(STDOUT);
            return;
        }

        throw new RuntimeException(
            'A retificação concorrente foi confirmada depois que a revisão deveria estar pendente.'
        );
    }

    throw new InvalidArgumentException('Modo de processo desconhecido.');
}

if (
    isset($argv[1])
    && in_array($argv[1], ['decision', 'rectification'], true)
) {
    try {
        runLegacyRaceWorker(
            $argv[1],
            (int) ($argv[2] ?? 0),
            (int) ($argv[3] ?? 0),
            (int) ($argv[4] ?? 0),
            (int) ($argv[5] ?? 0)
        );
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . "\n");
        exit(1);
    }
}

$pdo = openLegacyRaceConnection();
$temporaryTables = ['revisoes_legado_eventos', 'revisoes_legado', 'matriculas'];
$checkTable = $pdo->prepare(
    'SELECT COUNT(*)
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = :table_name'
);

foreach ($temporaryTables as $tableName) {
    $checkTable->execute(['table_name' => $tableName]);
    assertLegacyRace(
        (int) $checkTable->fetchColumn() === 0,
        'A tabela ' . $tableName . ' já existe; o teste não vai sobrescrevê-la.'
    );
}

$actorId = null;
$studentId = null;
$reviewId = null;
$enrollmentId = null;
$actorEmail = 'fitcontrol-legacy-race-' . bin2hex(random_bytes(5)) . '@example.invalid';
$decisionProcess = null;
$rectificationProcess = null;
$decisionPipes = [];
$rectificationPipes = [];

try {
    $pdo->exec(
        "CREATE TABLE matriculas (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            aluno_id INT UNSIGNED NOT NULL,
            plano_id INT UNSIGNED NULL,
            plano_nome_contratado VARCHAR(100) NULL,
            valor_contratado DECIMAL(10,2) NULL,
            duracao_contratada_valor SMALLINT UNSIGNED NULL,
            duracao_contratada_unidade ENUM('DIA','SEMANA','MES') NULL,
            inicio_em DATETIME NOT NULL,
            fim_exclusivo_em DATETIME NOT NULL,
            dia_referencia_calendario TINYINT UNSIGNED NULL,
            cancelada_em DATETIME NULL,
            origem ENUM('SISTEMA','LEGADO') NOT NULL DEFAULT 'SISTEMA',
            motivo_cancelamento VARCHAR(255) NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_legacy_race_student_period (aluno_id, inicio_em, fim_exclusivo_em),
            CONSTRAINT fk_legacy_race_student
                FOREIGN KEY (aluno_id) REFERENCES alunos (id)
                ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE revisoes_legado (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            aluno_id_origem INT UNSIGNED NOT NULL,
            plano_texto_original VARCHAR(40) NOT NULL,
            resultado_atual ENUM('VINCULO_CONFIRMADO','LEGADO_SEM_VIGENCIA','PENDENTE_REVISAO')
                NOT NULL DEFAULT 'PENDENTE_REVISAO',
            fonte_referencia VARCHAR(255) NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_legacy_race_student (aluno_id_origem),
            CONSTRAINT fk_legacy_race_review_student
                FOREIGN KEY (aluno_id_origem) REFERENCES alunos (id)
                ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE revisoes_legado_eventos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            revisao_legado_id BIGINT UNSIGNED NOT NULL,
            usuario_id INT UNSIGNED NOT NULL,
            tipo_evento ENUM('DECISAO','RETIFICACAO_VIGENCIA') NOT NULL,
            resultado_anterior ENUM('VINCULO_CONFIRMADO','LEGADO_SEM_VIGENCIA','PENDENTE_REVISAO') NOT NULL,
            resultado_novo ENUM('VINCULO_CONFIRMADO','LEGADO_SEM_VIGENCIA','PENDENTE_REVISAO') NOT NULL,
            matricula_id BIGINT UNSIGNED NULL,
            inicio_anterior DATETIME NULL,
            fim_exclusivo_anterior DATETIME NULL,
            inicio_novo DATETIME NULL,
            fim_exclusivo_novo DATETIME NULL,
            fonte_referencia VARCHAR(255) NULL,
            observacoes TEXT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_legacy_race_event_review (revisao_legado_id, criado_em),
            CONSTRAINT fk_legacy_race_event_review
                FOREIGN KEY (revisao_legado_id) REFERENCES revisoes_legado (id)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_legacy_race_event_user
                FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_legacy_race_event_enrollment
                FOREIGN KEY (matricula_id) REFERENCES matriculas (id)
                ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB"
    );

    $createUser = $pdo->prepare(
        'INSERT INTO usuarios (nome, email, senha_hash, ativo)
         VALUES (:nome, :email, :senha_hash, 1)'
    );
    $createUser->execute([
        'nome' => 'Funcionário teste de concorrência legada',
        'email' => $actorEmail,
        'senha_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
    ]);
    $actorId = (int) $pdo->lastInsertId();

    $createStudent = $pdo->prepare(
        'INSERT INTO alunos (nome, telefone, email, plano)
         VALUES (:nome, :telefone, :email, :plano)'
    );
    $createStudent->execute([
        'nome' => 'Aluno teste concorrência revisão e retificação',
        'telefone' => '21555559999',
        'email' => 'fitcontrol-legacy-race-student-' . bin2hex(random_bytes(5)) . '@example.invalid',
        'plano' => 'Plano legado original',
    ]);
    $studentId = (int) $pdo->lastInsertId();

    $createReview = $pdo->prepare(
        "INSERT INTO revisoes_legado
            (aluno_id_origem, plano_texto_original, resultado_atual, fonte_referencia)
         VALUES (:student_id, 'Plano legado original', 'VINCULO_CONFIRMADO', 'Fonte original')"
    );
    $createReview->execute(['student_id' => $studentId]);
    $reviewId = (int) $pdo->lastInsertId();

    $createEnrollment = $pdo->prepare(
        "INSERT INTO matriculas (
            aluno_id, plano_id, plano_nome_contratado, valor_contratado,
            duracao_contratada_valor, duracao_contratada_unidade,
            inicio_em, fim_exclusivo_em, dia_referencia_calendario,
            cancelada_em, origem
        ) VALUES (
            :student_id, NULL, 'Plano legado original', NULL,
            NULL, NULL, :start_at, :end_at, NULL, NULL, 'LEGADO'
        )"
    );
    $createEnrollment->execute([
        'student_id' => $studentId,
        'start_at' => legacyRaceUtc('2025-12-01 00:00:00'),
        'end_at' => legacyRaceUtc('2026-03-01 00:00:00'),
    ]);
    $enrollmentId = (int) $pdo->lastInsertId();

    $createInitialEvent = $pdo->prepare(
        "INSERT INTO revisoes_legado_eventos (
            revisao_legado_id, usuario_id, tipo_evento,
            resultado_anterior, resultado_novo, matricula_id,
            inicio_anterior, fim_exclusivo_anterior,
            inicio_novo, fim_exclusivo_novo,
            fonte_referencia, observacoes
        ) VALUES (
            :review_id, :user_id, 'DECISAO',
            'PENDENTE_REVISAO', 'VINCULO_CONFIRMADO', :enrollment_id,
            NULL, NULL, NULL, NULL, 'Fonte original', 'Vínculo confirmado no fixture.'
        )"
    );
    $createInitialEvent->execute([
        'review_id' => $reviewId,
        'user_id' => $actorId,
        'enrollment_id' => $enrollmentId,
    ]);

    // 1. A decisão começa primeiro e sinaliza quando já segura os locks do serviço.
    $decisionProcess = proc_open(
        [
            PHP_BINARY,
            __FILE__,
            'decision',
            (string) $studentId,
            (string) $reviewId,
            (string) $enrollmentId,
            (string) $actorId,
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $decisionPipes
    );
    assertLegacyRace(is_resource($decisionProcess), 'Não foi possível iniciar a decisão concorrente.');
    fclose($decisionPipes[0]);
    stream_set_timeout($decisionPipes[1], 10);

    $decisionSignal = fgets($decisionPipes[1]);
    assertLegacyRace(
        $decisionSignal === "DECISION_LOCKS_HELD\n",
        'O serviço de decisão não confirmou a aquisição dos locks: ' . (string) $decisionSignal
    );

    // 2. A retificação inicia enquanto a decisão ainda mantém a transação aberta.
    $rectificationProcess = proc_open(
        [
            PHP_BINARY,
            __FILE__,
            'rectification',
            (string) $studentId,
            (string) $reviewId,
            (string) $enrollmentId,
            (string) $actorId,
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $rectificationPipes
    );
    assertLegacyRace(is_resource($rectificationProcess), 'Não foi possível iniciar a retificação concorrente.');
    fclose($rectificationPipes[0]);
    stream_set_timeout($rectificationPipes[1], 10);

    $rectificationSignal = fgets($rectificationPipes[1]);
    assertLegacyRace(
        $rectificationSignal === "RECTIFICATION_ATTEMPTING\n",
        'O serviço de retificação não confirmou o início da tentativa.'
    );

    // A retificação fica bloqueada na linha do aluno até a decisão confirmar PENDENTE.
    $rectificationResult = fgets($rectificationPipes[1]);
    $decisionResult = fgets($decisionPipes[1]);

    $rectificationError = stream_get_contents($rectificationPipes[2]);
    $decisionError = stream_get_contents($decisionPipes[2]);

    fclose($rectificationPipes[1]);
    fclose($rectificationPipes[2]);
    fclose($decisionPipes[1]);
    fclose($decisionPipes[2]);

    $rectificationExit = proc_close($rectificationProcess);
    $rectificationProcess = null;
    $decisionExit = proc_close($decisionProcess);
    $decisionProcess = null;

    assertLegacyRace(
        $decisionExit === 0 && $decisionResult === "DECISION_COMMITTED_PENDING\n",
        'A decisão não confirmou o estado pendente: ' . trim($decisionError) . ' ' . (string) $decisionResult
    );
    assertLegacyRace(
        $rectificationExit === 0 && trim($rectificationError) === '',
        'A retificação concorrente falhou inesperadamente: ' . trim($rectificationError)
    );
    assertLegacyRace(
        is_string($rectificationResult)
            && preg_match('/^WAITED_MS=([0-9.]+);RESULT=REJECTED_PENDING\s*$/', $rectificationResult, $matches) === 1,
        'A retificação não foi rejeitada pelo estado pendente: ' . (string) $rectificationResult
    );

    $waitedMilliseconds = (float) $matches[1];
    assertLegacyRace(
        $waitedMilliseconds >= 500,
        sprintf('A retificação não aguardou pelo bloqueio do aluno (%.2f ms).', $waitedMilliseconds)
    );

    $reviewState = $pdo->query(
        'SELECT resultado_atual FROM revisoes_legado WHERE id = ' . $reviewId
    )->fetchColumn();
    assertLegacyRace(
        $reviewState === 'PENDENTE_REVISAO',
        'A decisão concorrente precisa deixar a revisão pendente.'
    );

    $enrollment = $pdo->query(
        'SELECT inicio_em, fim_exclusivo_em, origem
         FROM matriculas WHERE id = ' . $enrollmentId
    )->fetch();
    assertLegacyRace(
        $enrollment['inicio_em'] === legacyRaceUtc('2025-12-01 00:00:00')
        && $enrollment['fim_exclusivo_em'] === legacyRaceUtc('2026-03-01 00:00:00')
        && $enrollment['origem'] === 'LEGADO',
        'A retificação rejeitada não pode alterar o intervalo ou a origem da matrícula.'
    );

    $events = $pdo->prepare(
        'SELECT resultado_novo, tipo_evento, matricula_id
         FROM revisoes_legado_eventos
         WHERE revisao_legado_id = :review_id
         ORDER BY id'
    );
    $events->execute(['review_id' => $reviewId]);
    $eventRows = $events->fetchAll();

    assertLegacyRace(count($eventRows) === 2, 'A disputa deve produzir apenas a decisão original e a reabertura.');
    assertLegacyRace(
        $eventRows[0]['resultado_novo'] === 'VINCULO_CONFIRMADO'
        && (int) $eventRows[0]['matricula_id'] === $enrollmentId
        && $eventRows[1]['resultado_novo'] === 'PENDENTE_REVISAO'
        && $eventRows[1]['matricula_id'] === null,
        'O histórico deve preservar a confirmação original e registrar a reabertura sem matrícula nova.'
    );

    $audit = $pdo->prepare(
        "SELECT COUNT(*)
         FROM auditoria
         WHERE usuario_id = :actor_id
           AND acao = 'revisao_legado_decidida'
           AND entidade = 'revisoes_legado'
           AND entidade_id = :review_id"
    );
    $audit->execute([
        'actor_id' => $actorId,
        'review_id' => $reviewId,
    ]);
    assertLegacyRace(
        (int) $audit->fetchColumn() === 1,
        'A decisão precisa ter uma única auditoria; a retificação rejeitada não audita.'
    );

    $rectificationAudit = $pdo->prepare(
        "SELECT COUNT(*)
         FROM auditoria
         WHERE usuario_id = :actor_id
           AND acao = 'matricula_corrigida'
           AND entidade = 'matriculas'
           AND entidade_id = :enrollment_id"
    );
    $rectificationAudit->execute([
        'actor_id' => $actorId,
        'enrollment_id' => $enrollmentId,
    ]);
    assertLegacyRace(
        (int) $rectificationAudit->fetchColumn() === 0,
        'A retificação rejeitada não pode produzir auditoria.'
    );

    printf(
        "Concorrência decisão/retificação: OK; retificação aguardou %.2f ms, observou PENDENTE_REVISAO e não alterou matrícula nem histórico.\n",
        $waitedMilliseconds
    );
} finally {
    // Este usuário de fixture permanece no banco descartável: a auditoria é append-only
    // e sua FK usa ON DELETE RESTRICT. Não executar estes testes em banco de aplicação.
    foreach ([$rectificationPipes, $decisionPipes] as $pipes) {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }

    foreach ([$rectificationProcess, $decisionProcess] as $process) {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }

    if ($reviewId !== null) {
        $deleteEvents = $pdo->prepare(
            'DELETE FROM revisoes_legado_eventos WHERE revisao_legado_id = :review_id'
        );
        $deleteEvents->execute(['review_id' => $reviewId]);
    }

    if ($enrollmentId !== null) {
        $deleteEnrollment = $pdo->prepare('DELETE FROM matriculas WHERE id = :enrollment_id');
        $deleteEnrollment->execute(['enrollment_id' => $enrollmentId]);
    }

    if ($reviewId !== null) {
        $deleteReview = $pdo->prepare('DELETE FROM revisoes_legado WHERE id = :review_id');
        $deleteReview->execute(['review_id' => $reviewId]);
    }

    if ($studentId !== null) {
        $deleteStudent = $pdo->prepare('DELETE FROM alunos WHERE id = :student_id');
        $deleteStudent->execute(['student_id' => $studentId]);
    }


    $pdo->exec('DROP TABLE IF EXISTS revisoes_legado_eventos');
    $pdo->exec('DROP TABLE IF EXISTS revisoes_legado');
    $pdo->exec('DROP TABLE IF EXISTS matriculas');
}
