<?php
declare(strict_types=1);

/**
 * Prova a ordem inversa à disputa anterior:
 * retificação LEGADO adquire os locks primeiro; decisão de revisão aguarda,
 * observa o intervalo já retificado e então reabre a revisão para PENDENTE.
 *
 * Um trigger temporário atrasa a auditoria da retificação depois de todos os
 * locks e gravações operacionais terem sido realizados, mantendo a transação
 * aberta de forma determinística durante o início do processo concorrente.
 */

use FitControl\Domain\Enrollment\EnrollmentPeriodCalculator;
use FitControl\Domain\Enrollment\LegacyEnrollmentRectificationService;
use FitControl\Domain\Enrollment\LegacyReviewDecisionService;

require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentPeriodCalculator.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentInterval.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/LegacyReviewDecisionService.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/LegacyEnrollmentRectificationService.php';

function openReverseLegacyRaceConnection(): PDO
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

function reverseLegacyLocalDate(string $value): DateTimeImmutable
{
    return new DateTimeImmutable(
        $value,
        new DateTimeZone(EnrollmentPeriodCalculator::ACADEMY_TIMEZONE)
    );
}

function reverseLegacyUtc(string $value): string
{
    return reverseLegacyLocalDate($value)
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');
}

function assertReverseLegacyRace(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function runReverseLegacyRaceWorker(
    string $mode,
    int $studentId,
    int $reviewId,
    int $enrollmentId,
    int $actorId
): void {
    $pdo = openReverseLegacyRaceConnection();

    if ($mode === 'rectification') {
        $service = new LegacyEnrollmentRectificationService($pdo);

        fwrite(STDOUT, "RECTIFICATION_ATTEMPTING\n");
        fflush(STDOUT);
        $startedAt = hrtime(true);

        $service->rectify(
            $studentId,
            $reviewId,
            $enrollmentId,
            $actorId,
            'Contrato retificado, documento 91',
            'A correção de vigência foi confirmada antes da reabertura da revisão.',
            reverseLegacyLocalDate('2025-12-15 00:00:00'),
            reverseLegacyLocalDate('2026-03-15 00:00:00')
        );

        $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;
        printf("RECTIFICATION_COMMITTED;ELAPSED_MS=%.2f\n", $elapsedMilliseconds);
        fflush(STDOUT);
        return;
    }

    if ($mode === 'decision') {
        fwrite(STDOUT, "DECISION_ATTEMPTING\n");
        fflush(STDOUT);
        $startedAt = hrtime(true);

        $service = new LegacyReviewDecisionService(
            $pdo,
            static function (): DateTimeImmutable {
                // Só é chamado depois de o serviço adquirir aluno, revisão
                // e matrículas; a mensagem comprova que o lock foi obtido.
                fwrite(STDOUT, "DECISION_LOCKS_ACQUIRED\n");
                fflush(STDOUT);
                return reverseLegacyLocalDate('2026-01-01 09:00:00');
            }
        );

        $service->decide(
            $studentId,
            $reviewId,
            $actorId,
            'PENDENTE_REVISAO',
            null,
            'Nova evidência exige reabrir a revisão após a retificação concluída.'
        );

        $waitedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;
        printf("DECISION_COMMITTED_PENDING;WAITED_MS=%.2f\n", $waitedMilliseconds);
        fflush(STDOUT);
        return;
    }

    throw new InvalidArgumentException('Modo de processo desconhecido.');
}

if (
    isset($argv[1])
    && in_array($argv[1], ['rectification', 'decision'], true)
) {
    try {
        runReverseLegacyRaceWorker(
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

$pdo = openReverseLegacyRaceConnection();
$temporaryTables = ['revisoes_legado_eventos', 'revisoes_legado', 'matriculas'];
$checkTable = $pdo->prepare(
    'SELECT COUNT(*)
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = :table_name'
);

foreach ($temporaryTables as $tableName) {
    $checkTable->execute(['table_name' => $tableName]);
    assertReverseLegacyRace(
        (int) $checkTable->fetchColumn() === 0,
        'A tabela ' . $tableName . ' já existe; o teste não vai sobrescrevê-la.'
    );
}

$actorId = null;
$studentId = null;
$reviewId = null;
$enrollmentId = null;
$actorEmail = 'fitcontrol-reverse-race-' . bin2hex(random_bytes(5)) . '@example.invalid';
$triggerName = 'fitcontrol_delay_rectification_' . bin2hex(random_bytes(4));
$triggerCreated = false;
$rectificationProcess = null;
$decisionProcess = null;
$rectificationPipes = [];
$decisionPipes = [];

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
            KEY idx_reverse_race_student_period (aluno_id, inicio_em, fim_exclusivo_em),
            CONSTRAINT fk_reverse_race_student
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
            UNIQUE KEY uq_reverse_race_student (aluno_id_origem),
            CONSTRAINT fk_reverse_race_review_student
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
            KEY idx_reverse_race_event_review (revisao_legado_id, criado_em),
            CONSTRAINT fk_reverse_race_event_review
                FOREIGN KEY (revisao_legado_id) REFERENCES revisoes_legado (id)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_reverse_race_event_user
                FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_reverse_race_event_enrollment
                FOREIGN KEY (matricula_id) REFERENCES matriculas (id)
                ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB"
    );

    $createUser = $pdo->prepare(
        'INSERT INTO usuarios (nome, email, senha_hash, ativo)
         VALUES (:nome, :email, :senha_hash, 1)'
    );
    $createUser->execute([
        'nome' => 'Funcionário teste concorrência inversa',
        'email' => $actorEmail,
        'senha_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
    ]);
    $actorId = (int) $pdo->lastInsertId();

    $createStudent = $pdo->prepare(
        'INSERT INTO alunos (nome, telefone, email, plano)
         VALUES (:nome, :telefone, :email, :plano)'
    );
    $createStudent->execute([
        'nome' => 'Aluno teste concorrência inversa revisão e retificação',
        'telefone' => '21444449999',
        'email' => 'fitcontrol-reverse-race-student-' . bin2hex(random_bytes(5)) . '@example.invalid',
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
        'start_at' => reverseLegacyUtc('2025-12-01 00:00:00'),
        'end_at' => reverseLegacyUtc('2026-03-01 00:00:00'),
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

    // O trigger segura a transação da retificação durante a gravação de auditoria.
    // Nesse ponto aluno, revisão e matrícula já estão bloqueados e as alterações
    // operacionais/históricas estão prontas, mas ainda não houve COMMIT.
    $pdo->exec(
        "CREATE TRIGGER {$triggerName}
         BEFORE INSERT ON auditoria
         FOR EACH ROW
         BEGIN
             IF NEW.acao = 'matricula_corrigida'
                AND NEW.entidade = 'matriculas'
                AND NEW.entidade_id = {$enrollmentId} THEN
                 SET @fitcontrol_reverse_delay = SLEEP(2);
             END IF;
         END"
    );
    $triggerCreated = true;

    // 1. A retificação começa primeiro.
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
    assertReverseLegacyRace(is_resource($rectificationProcess), 'Não foi possível iniciar a retificação.');
    fclose($rectificationPipes[0]);
    stream_set_timeout($rectificationPipes[1], 10);

    $rectificationSignal = fgets($rectificationPipes[1]);
    assertReverseLegacyRace(
        $rectificationSignal === "RECTIFICATION_ATTEMPTING\n",
        'A retificação não confirmou o início.'
    );

    // Dá tempo para a retificação adquirir os locks e alcançar o trigger de auditoria.
    usleep(500_000);

    // 2. A decisão começa enquanto a retificação ainda mantém a transação aberta.
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
    assertReverseLegacyRace(is_resource($decisionProcess), 'Não foi possível iniciar a decisão concorrente.');
    fclose($decisionPipes[0]);
    stream_set_timeout($decisionPipes[1], 10);

    $decisionSignal = fgets($decisionPipes[1]);
    assertReverseLegacyRace(
        $decisionSignal === "DECISION_ATTEMPTING\n",
        'A decisão não confirmou o início da tentativa.'
    );

    $rectificationResult = fgets($rectificationPipes[1]);
    $decisionLockSignal = fgets($decisionPipes[1]);
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

    assertReverseLegacyRace(
        $rectificationExit === 0 && trim($rectificationError) === '',
        'A retificação inicial falhou: ' . trim($rectificationError) . ' ' . (string) $rectificationResult
    );
    assertReverseLegacyRace(
        is_string($rectificationResult)
            && preg_match('/^RECTIFICATION_COMMITTED;ELAPSED_MS=([0-9.]+)\s*$/', $rectificationResult, $rectificationMatches) === 1,
        'A retificação não confirmou COMMIT: ' . (string) $rectificationResult
    );
    assertReverseLegacyRace(
        $decisionLockSignal === "DECISION_LOCKS_ACQUIRED\n",
        'A decisão não confirmou a aquisição dos bloqueios após a retificação.'
    );
    assertReverseLegacyRace(
        $decisionExit === 0 && trim($decisionError) === '',
        'A decisão subsequente falhou: ' . trim($decisionError) . ' ' . (string) $decisionResult
    );
    assertReverseLegacyRace(
        is_string($decisionResult)
            && preg_match('/^DECISION_COMMITTED_PENDING;WAITED_MS=([0-9.]+)\s*$/', $decisionResult, $decisionMatches) === 1,
        'A decisão não confirmou a transição para PENDENTE_REVISAO: ' . (string) $decisionResult
    );

    $decisionWaitMilliseconds = (float) $decisionMatches[1];
    assertReverseLegacyRace(
        $decisionWaitMilliseconds >= 500,
        sprintf(
            'A decisão não aguardou pelo lock da retificação (%.2f ms).',
            $decisionWaitMilliseconds
        )
    );

    $reviewState = $pdo->query(
        'SELECT resultado_atual FROM revisoes_legado WHERE id = ' . $reviewId
    )->fetchColumn();
    assertReverseLegacyRace(
        $reviewState === 'PENDENTE_REVISAO',
        'A decisão posterior deve deixar a revisão pendente.'
    );

    $enrollment = $pdo->query(
        'SELECT inicio_em, fim_exclusivo_em, cancelada_em, origem
         FROM matriculas WHERE id = ' . $enrollmentId
    )->fetch();

    assertReverseLegacyRace(
        $enrollment['inicio_em'] === reverseLegacyUtc('2025-12-15 00:00:00')
        && $enrollment['fim_exclusivo_em'] === reverseLegacyUtc('2026-03-15 00:00:00')
        && $enrollment['cancelada_em'] === null
        && $enrollment['origem'] === 'LEGADO',
        'A decisão posterior não pode apagar nem alterar o intervalo retificado.'
    );

    $eventQuery = $pdo->prepare(
        'SELECT tipo_evento, resultado_anterior, resultado_novo, matricula_id,
                inicio_anterior, fim_exclusivo_anterior,
                inicio_novo, fim_exclusivo_novo
         FROM revisoes_legado_eventos
         WHERE revisao_legado_id = :review_id
         ORDER BY id'
    );
    $eventQuery->execute(['review_id' => $reviewId]);
    $events = $eventQuery->fetchAll();

    assertReverseLegacyRace(count($events) === 3, 'A ordem concorrente deve produzir exatamente três eventos.');
    assertReverseLegacyRace(
        $events[1]['tipo_evento'] === 'RETIFICACAO_VIGENCIA'
        && $events[1]['resultado_novo'] === 'VINCULO_CONFIRMADO'
        && (int) $events[1]['matricula_id'] === $enrollmentId
        && $events[1]['inicio_anterior'] === reverseLegacyUtc('2025-12-01 00:00:00')
        && $events[1]['fim_exclusivo_anterior'] === reverseLegacyUtc('2026-03-01 00:00:00')
        && $events[1]['inicio_novo'] === reverseLegacyUtc('2025-12-15 00:00:00')
        && $events[1]['fim_exclusivo_novo'] === reverseLegacyUtc('2026-03-15 00:00:00'),
        'O evento de retificação deve preservar o antes/depois.'
    );
    assertReverseLegacyRace(
        $events[2]['tipo_evento'] === 'DECISAO'
        && $events[2]['resultado_anterior'] === 'VINCULO_CONFIRMADO'
        && $events[2]['resultado_novo'] === 'PENDENTE_REVISAO'
        && $events[2]['matricula_id'] === null,
        'A decisão deve vir depois da retificação sem apagar o evento anterior.'
    );

    $auditCounts = $pdo->query(
        "SELECT
            SUM(CASE WHEN acao = 'matricula_corrigida' THEN 1 ELSE 0 END) AS rectifications,
            SUM(CASE WHEN acao = 'revisao_legado_decidida' THEN 1 ELSE 0 END) AS decisions
         FROM auditoria
         WHERE usuario_id = {$actorId}"
    )->fetch();

    assertReverseLegacyRace(
        (int) $auditCounts['rectifications'] === 1
        && (int) $auditCounts['decisions'] === 1,
        'Retificação e decisão devem gerar uma auditoria cada.'
    );

    printf(
        "Concorrência inversa retificação/decisão: OK; decisão aguardou %.2f ms, preservou intervalo retificado e registrou PENDENTE_REVISAO depois.\n",
        $decisionWaitMilliseconds
    );
} finally {
    foreach ([$decisionPipes, $rectificationPipes] as $pipes) {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }

    foreach ([$decisionProcess, $rectificationProcess] as $process) {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }

    if ($triggerCreated) {
        $pdo->exec("DROP TRIGGER IF EXISTS {$triggerName}");
    }

    // A trilha é append-only; os eventos e usuários de teste permanecem no banco descartável.

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
