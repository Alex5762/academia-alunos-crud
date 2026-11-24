<?php
declare(strict_types=1);

use FitControl\Domain\Enrollment\EnrollmentPeriodCalculator;
use FitControl\Domain\Enrollment\LegacyEnrollmentRectificationService;
use FitControl\Domain\Enrollment\LegacyReviewDecisionService;

require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentPeriodCalculator.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentInterval.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/LegacyReviewDecisionService.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/LegacyEnrollmentRectificationService.php';

function rectificationTestConnection(): PDO
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

function assertRectification(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rectificationLocalDate(string $value): DateTimeImmutable
{
    return new DateTimeImmutable(
        $value,
        new DateTimeZone(EnrollmentPeriodCalculator::ACADEMY_TIMEZONE)
    );
}

function rectificationUtc(string $value): string
{
    return rectificationLocalDate($value)
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');
}

function assertRectificationRejected(
    callable $operation,
    string $message,
    string $exceptionClass = DomainException::class
): void {
    try {
        $operation();
    } catch (Throwable $exception) {
        if ($exception instanceof $exceptionClass) {
            return;
        }

        throw new RuntimeException(
            $message . ': exceção inesperada ' . get_class($exception),
            0,
            $exception
        );
    }

    throw new RuntimeException($message . ': nenhuma exceção foi lançada.');
}

$pdo = rectificationTestConnection();
$temporaryTables = ['revisoes_legado_eventos', 'revisoes_legado', 'matriculas'];

$checkTable = $pdo->prepare(
    'SELECT COUNT(*)
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = :table_name'
);

foreach ($temporaryTables as $tableName) {
    $checkTable->execute(['table_name' => $tableName]);
    assertRectification(
        (int) $checkTable->fetchColumn() === 0,
        'A tabela ' . $tableName . ' já existe; o teste não vai sobrescrevê-la.'
    );
}

$actorId = null;
$studentIds = [];
$reviewIds = [];
$enrollmentIds = [];
$actorEmail = 'fitcontrol-rectify-' . bin2hex(random_bytes(5)) . '@example.invalid';
$triggerCreated = false;

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
            KEY idx_rectify_student_period (aluno_id, inicio_em, fim_exclusivo_em),
            CONSTRAINT fk_rectify_student
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
            UNIQUE KEY uq_rectify_student (aluno_id_origem),
            CONSTRAINT fk_rectify_review_student
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
            KEY idx_rectify_event_review (revisao_legado_id, criado_em),
            KEY idx_rectify_event_enrollment (matricula_id, criado_em),
            CONSTRAINT fk_rectify_event_review
                FOREIGN KEY (revisao_legado_id) REFERENCES revisoes_legado (id)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_rectify_event_user
                FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_rectify_event_enrollment
                FOREIGN KEY (matricula_id) REFERENCES matriculas (id)
                ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB"
    );

    $createUser = $pdo->prepare(
        'INSERT INTO usuarios (nome, email, senha_hash, ativo)
         VALUES (:nome, :email, :senha_hash, 1)'
    );
    $createUser->execute([
        'nome' => 'Funcionário de retificação',
        'email' => $actorEmail,
        'senha_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
    ]);
    $actorId = (int) $pdo->lastInsertId();

    $createStudent = $pdo->prepare(
        'INSERT INTO alunos (nome, telefone, email, plano)
         VALUES (:nome, :telefone, :email, :plano)'
    );

    for ($index = 1; $index <= 4; $index++) {
        $createStudent->execute([
            'nome' => 'Aluno retificação ' . $index,
            'telefone' => '2166666' . str_pad((string) $index, 4, '0', STR_PAD_LEFT),
            'email' => 'fitcontrol-rectify-student-' . bin2hex(random_bytes(5)) . '@example.invalid',
            'plano' => 'Plano legado original',
        ]);
        $studentIds[] = (int) $pdo->lastInsertId();
    }

    $createReview = $pdo->prepare(
        "INSERT INTO revisoes_legado
            (aluno_id_origem, plano_texto_original, resultado_atual, fonte_referencia)
         VALUES (:student_id, 'Plano legado original', 'VINCULO_CONFIRMADO', 'Fonte inicial')"
    );
    foreach ($studentIds as $studentId) {
        $createReview->execute(['student_id' => $studentId]);
        $reviewIds[] = (int) $pdo->lastInsertId();
    }

    $createEnrollment = $pdo->prepare(
        'INSERT INTO matriculas (
            aluno_id, plano_id, plano_nome_contratado, valor_contratado,
            duracao_contratada_valor, duracao_contratada_unidade,
            inicio_em, fim_exclusivo_em, dia_referencia_calendario,
            cancelada_em, origem
        ) VALUES (
            :student_id, NULL, :plan_name, NULL, NULL, NULL,
            :start_at, :end_at, NULL, :cancelled_at, :origin
        )'
    );

    $legacyStart = '2025-12-01 00:00:00';
    $legacyEnd = '2026-02-01 00:00:00';

    for ($index = 0; $index < 4; $index++) {
        $createEnrollment->execute([
            'student_id' => $studentIds[$index],
            'plan_name' => 'Plano legado original',
            'start_at' => rectificationUtc($legacyStart),
            'end_at' => rectificationUtc($legacyEnd),
            'cancelled_at' => $index === 3 ? rectificationUtc('2026-01-10 12:00:00') : null,
            'origin' => $index === 2 ? 'SISTEMA' : 'LEGADO',
        ]);
        $enrollmentIds[] = (int) $pdo->lastInsertId();
    }

    // A revisão precisa apontar para a matrícula legada específica no último evento.
    $insertDecision = $pdo->prepare(
        "INSERT INTO revisoes_legado_eventos (
            revisao_legado_id, usuario_id, tipo_evento,
            resultado_anterior, resultado_novo, matricula_id,
            inicio_novo, fim_exclusivo_novo, fonte_referencia, observacoes
        ) VALUES (
            :review_id, :user_id, 'DECISAO',
            'PENDENTE_REVISAO', 'VINCULO_CONFIRMADO', :enrollment_id,
            :start_at, :end_at, 'Fonte inicial', 'Intervalo conferido na documentação original.'
        )"
    );

    foreach ($reviewIds as $index => $reviewId) {
        $insertDecision->execute([
            'review_id' => $reviewId,
            'user_id' => $actorId,
            'enrollment_id' => $enrollmentIds[$index],
            'start_at' => rectificationUtc($legacyStart),
            'end_at' => rectificationUtc($legacyEnd),
        ]);
    }

    // Matrícula SISTEMA adicional que conflita com a retificação do aluno 2.
    $createEnrollment->execute([
        'student_id' => $studentIds[1],
        'plan_name' => 'Contrato operacional',
        'start_at' => rectificationUtc('2026-01-15 00:00:00'),
        'end_at' => rectificationUtc('2026-02-15 00:00:00'),
        'cancelled_at' => null,
        'origin' => 'SISTEMA',
    ]);
    $enrollmentIds[] = (int) $pdo->lastInsertId();

    // Para o aluno 4, uma matrícula SISTEMA começa exatamente no instante do
    // cancelamento do vínculo LEGADO. O intervalo efetivo antigo termina nesse
    // instante, então a retificação contratual posterior deve continuar adjacente.
    $createEnrollment->execute([
        'student_id' => $studentIds[3],
        'plan_name' => 'Contrato após cancelamento',
        'start_at' => rectificationUtc('2026-01-10 12:00:00'),
        'end_at' => rectificationUtc('2026-02-10 12:00:00'),
        'cancelled_at' => null,
        'origin' => 'SISTEMA',
    ]);
    $enrollmentIds[] = (int) $pdo->lastInsertId();

    $service = new LegacyEnrollmentRectificationService($pdo);

    // 1. Retificação válida grava UTC antigo/novo, fonte, justificativa e auditoria.
    $service->rectify(
        $studentIds[0],
        $reviewIds[0],
        $enrollmentIds[0],
        $actorId,
        'Contrato digital assinado, referência 2025-12, documento 18',
        'A data inicial foi registrada incorretamente; evidência confirma 15/12.',
        rectificationLocalDate('2025-12-15 00:00:00'),
        rectificationLocalDate('2026-03-01 00:00:00')
    );

    $rectified = $pdo->query(
        'SELECT inicio_em, fim_exclusivo_em, cancelada_em, origem
         FROM matriculas WHERE id = ' . $enrollmentIds[0]
    )->fetch();

    assertRectification($rectified['inicio_em'] === rectificationUtc('2025-12-15 00:00:00'), 'Novo início deve ser persistido em UTC.');
    assertRectification($rectified['fim_exclusivo_em'] === rectificationUtc('2026-03-01 00:00:00'), 'Novo fim exclusivo deve ser persistido em UTC.');
    assertRectification($rectified['cancelada_em'] === null, 'Retificação não pode criar cancelamento.');
    assertRectification($rectified['origem'] === 'LEGADO', 'Origem do registro deve permanecer LEGADO.');

    $event = $pdo->query(
        'SELECT tipo_evento, resultado_anterior, resultado_novo, matricula_id,
                inicio_anterior, fim_exclusivo_anterior,
                inicio_novo, fim_exclusivo_novo, fonte_referencia, observacoes
         FROM revisoes_legado_eventos
         WHERE revisao_legado_id = ' . $reviewIds[0] . '
         ORDER BY id DESC LIMIT 1'
    )->fetch();

    assertRectification($event['tipo_evento'] === 'RETIFICACAO_VIGENCIA', 'Evento especializado deve ser RETIFICACAO_VIGENCIA.');
    assertRectification($event['resultado_anterior'] === 'VINCULO_CONFIRMADO', 'Resultado anterior deve permanecer confirmado.');
    assertRectification($event['resultado_novo'] === 'VINCULO_CONFIRMADO', 'Resultado novo deve permanecer confirmado.');
    assertRectification((int) $event['matricula_id'] === $enrollmentIds[0], 'Evento precisa referenciar matrícula corrigida.');
    assertRectification($event['inicio_anterior'] === rectificationUtc($legacyStart), 'Início anterior deve ser preservado em UTC.');
    assertRectification($event['fim_exclusivo_anterior'] === rectificationUtc($legacyEnd), 'Fim anterior deve ser preservado em UTC.');
    assertRectification($event['inicio_novo'] === rectificationUtc('2025-12-15 00:00:00'), 'Início novo deve ser preservado no evento.');
    assertRectification($event['fim_exclusivo_novo'] === rectificationUtc('2026-03-01 00:00:00'), 'Fim novo deve ser preservado no evento.');
    assertRectification($event['fonte_referencia'] === 'Contrato digital assinado, referência 2025-12, documento 18', 'Evento deve armazenar fonte.');
    assertRectification($event['observacoes'] === 'A data inicial foi registrada incorretamente; evidência confirma 15/12.', 'Evento deve armazenar justificativa.');

    $initialDecision = $pdo->query(
        "SELECT inicio_novo, fim_exclusivo_novo
         FROM revisoes_legado_eventos
         WHERE revisao_legado_id = {$reviewIds[0]}
           AND tipo_evento = 'DECISAO'
         ORDER BY id ASC
         LIMIT 1"
    )->fetch();
    assertRectification(
        $initialDecision['inicio_novo'] === rectificationUtc($legacyStart)
        && $initialDecision['fim_exclusivo_novo'] === rectificationUtc($legacyEnd),
        'A retificação não pode alterar o snapshot temporal da decisão original.'
    );

    $auditCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM auditoria
         WHERE usuario_id = {$actorId}
           AND acao = 'matricula_corrigida'
           AND entidade = 'matriculas'
           AND entidade_id = {$enrollmentIds[0]}"
    )->fetchColumn();
    assertRectification($auditCount === 1, 'Retificação precisa gerar uma auditoria geral.');

    // 2. Repetir a mesma retificação não deve duplicar evento nem auditoria.
    $service->rectify(
        $studentIds[0],
        $reviewIds[0],
        $enrollmentIds[0],
        $actorId,
        'Contrato digital assinado, referência 2025-12, documento 18',
        'A data inicial foi registrada incorretamente; evidência confirma 15/12.',
        rectificationLocalDate('2025-12-15 00:00:00'),
        rectificationLocalDate('2026-03-01 00:00:00')
    );
    assertRectification(
        (int) $pdo->query('SELECT COUNT(*) FROM revisoes_legado_eventos WHERE revisao_legado_id = ' . $reviewIds[0])->fetchColumn() === 2,
        'Repetição idêntica não pode duplicar evento.'
    );
    assertRectification(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM auditoria
             WHERE usuario_id = {$actorId}
               AND acao = 'matricula_corrigida'
               AND entidade = 'matriculas'
               AND entidade_id = {$enrollmentIds[0]}"
        )->fetchColumn() === 1,
        'Repetição idêntica não pode duplicar auditoria.'
    );

    // 2b. Reabrir a revisão após uma retificação não apaga o histórico nem a matrícula.
    // A reconfirmação do mesmo intervalo reutiliza o mesmo ID, e a retificação
    // seguinte continua vinculada à matrícula LEGADO original.
    $reviewDecisionService = new LegacyReviewDecisionService(
        $pdo,
        static fn (): DateTimeImmutable => rectificationLocalDate('2026-01-01 09:00:00')
    );

    $reviewDecisionService->decide(
        $studentIds[0],
        $reviewIds[0],
        $actorId,
        'PENDENTE_REVISAO',
        null,
        'Evidência complementar exige nova conferência do mesmo vínculo.'
    );

    assertRectification(
        (string) $pdo->query(
            'SELECT resultado_atual FROM revisoes_legado WHERE id = ' . $reviewIds[0]
        )->fetchColumn() === 'PENDENTE_REVISAO',
        'Reabertura deve manter revisão pendente.'
    );
    assertRectification(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM matriculas
             WHERE aluno_id = {$studentIds[0]} AND origem = 'LEGADO'"
        )->fetchColumn() === 1,
        'Reabertura não pode apagar nem duplicar a matrícula LEGADO.'
    );

    $reconfirmedId = $reviewDecisionService->decide(
        $studentIds[0],
        $reviewIds[0],
        $actorId,
        'VINCULO_CONFIRMADO',
        'Contrato revalidado, documento 18 e adendo 2',
        'Intervalo já materializado foi reconfirmado com nova evidência.',
        rectificationLocalDate('2025-12-15 00:00:00'),
        rectificationLocalDate('2026-03-01 00:00:00')
    );

    assertRectification(
        $reconfirmedId === $enrollmentIds[0],
        'Reconfirmação deve reutilizar o ID da matrícula LEGADO existente.'
    );
    assertRectification(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM matriculas
             WHERE aluno_id = {$studentIds[0]} AND origem = 'LEGADO'"
        )->fetchColumn() === 1,
        'Reconfirmação não pode criar uma matrícula LEGADO duplicada.'
    );

    $service->rectify(
        $studentIds[0],
        $reviewIds[0],
        $enrollmentIds[0],
        $actorId,
        'Adendo contratual, documento 18-A',
        'Segunda correção após reabertura e reconfirmação do vínculo.',
        rectificationLocalDate('2025-12-20 00:00:00'),
        rectificationLocalDate('2026-03-15 00:00:00')
    );

    $secondRectificationEvent = $pdo->query(
        'SELECT tipo_evento, matricula_id, inicio_anterior, fim_exclusivo_anterior,
                inicio_novo, fim_exclusivo_novo
         FROM revisoes_legado_eventos
         WHERE revisao_legado_id = ' . $reviewIds[0] . '
         ORDER BY id DESC LIMIT 1'
    )->fetch();

    assertRectification(
        $secondRectificationEvent['tipo_evento'] === 'RETIFICACAO_VIGENCIA',
        'Retificação após reconfirmação deve criar evento especializado.'
    );
    assertRectification(
        (int) $secondRectificationEvent['matricula_id'] === $enrollmentIds[0],
        'Novo evento deve continuar vinculado à matrícula original.'
    );
    assertRectification(
        $secondRectificationEvent['inicio_anterior'] === rectificationUtc('2025-12-15 00:00:00')
        && $secondRectificationEvent['fim_exclusivo_anterior'] === rectificationUtc('2026-03-01 00:00:00'),
        'Segunda retificação deve preservar o intervalo corrigido anterior.'
    );
    assertRectification(
        $secondRectificationEvent['inicio_novo'] === rectificationUtc('2025-12-20 00:00:00')
        && $secondRectificationEvent['fim_exclusivo_novo'] === rectificationUtc('2026-03-15 00:00:00'),
        'Segunda retificação deve registrar o novo intervalo.'
    );

    $service->rectify(
        $studentIds[0],
        $reviewIds[0],
        $enrollmentIds[0],
        $actorId,
        'Adendo contratual, documento 18-A',
        'Segunda correção após reabertura e reconfirmação do vínculo.',
        rectificationLocalDate('2025-12-20 00:00:00'),
        rectificationLocalDate('2026-03-15 00:00:00')
    );

    assertRectification(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM revisoes_legado_eventos WHERE revisao_legado_id = ' . $reviewIds[0]
        )->fetchColumn() === 5,
        'Repetir retificação após reconfirmação não deve duplicar evento.'
    );

    // 3. Sobreposição com matrícula SISTEMA deve abortar sem alterar estado/histórico.
    assertRectificationRejected(
        fn () => $service->rectify(
            $studentIds[1],
            $reviewIds[1],
            $enrollmentIds[1],
            $actorId,
            'Evidência de intervalo corrigido, documento 22',
            'Nova data conflita com contrato operacional existente.',
            rectificationLocalDate('2026-01-20 00:00:00'),
            rectificationLocalDate('2026-02-20 00:00:00')
        ),
        'Retificação sobreposta deve ser rejeitada'
    );
    $overlapStart = $pdo->query(
        'SELECT inicio_em FROM matriculas WHERE id = ' . $enrollmentIds[1]
    )->fetchColumn();
    assertRectification(
        $overlapStart === rectificationUtc($legacyStart),
        'Falha de sobreposição não pode atualizar o início.'
    );
    assertRectification(
        (int) $pdo->query('SELECT COUNT(*) FROM revisoes_legado_eventos WHERE revisao_legado_id = ' . $reviewIds[1])->fetchColumn() === 1,
        'Falha de sobreposição não pode inserir evento.'
    );

    // 4. Uma matrícula de origem SISTEMA não pode usar o serviço de legado.
    assertRectificationRejected(
        fn () => $service->rectify(
            $studentIds[2],
            $reviewIds[2],
            $enrollmentIds[2],
            $actorId,
            'Fonte válida, documento 33',
            'Tentativa de corrigir matrícula de origem SISTEMA.',
            rectificationLocalDate('2025-12-10 00:00:00'),
            rectificationLocalDate('2026-03-10 00:00:00')
        ),
        'Origem SISTEMA deve ser rejeitada'
    );

    // 5. Cancelamento histórico não é alterado pela correção de datas.
    $service->rectify(
        $studentIds[3],
        $reviewIds[3],
        $enrollmentIds[3],
        $actorId,
        'Documento de arquivo, referência 44',
        'Correção apenas das datas contratuais; cancelamento anterior permanece.',
        rectificationLocalDate('2025-12-10 00:00:00'),
        rectificationLocalDate('2026-03-10 00:00:00')
    );
    $cancelledAfterRectification = $pdo->query(
        'SELECT cancelada_em FROM matriculas WHERE id = ' . $enrollmentIds[3]
    )->fetchColumn();
    assertRectification(
        $cancelledAfterRectification === rectificationUtc('2026-01-10 12:00:00'),
        'Retificação precisa preservar cancelada_em.'
    );
    assertRectification(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM matriculas
             WHERE aluno_id = {$studentIds[3]} AND origem = 'SISTEMA'"
        )->fetchColumn() === 1,
        'A matrícula SISTEMA adjacente deve permanecer registrada.'
    );

    // 6. Falha da auditoria depois de atualizar matrícula/revisão e inserir evento
    // deve reverter as três gravações na mesma transação.
    $pdo->exec(
        "CREATE TRIGGER fitcontrol_fail_rectification_audit
         BEFORE INSERT ON auditoria
         FOR EACH ROW
         BEGIN
             IF NEW.acao = 'matricula_corrigida' THEN
                 SIGNAL SQLSTATE '45000'
                 SET MESSAGE_TEXT = 'Falha simulada da auditoria de retificação';
             END IF;
         END"
    );
    $triggerCreated = true;

    $beforeFailure = $pdo->query(
        'SELECT inicio_em, fim_exclusivo_em
         FROM matriculas WHERE id = ' . $enrollmentIds[1]
    )->fetch();
    $reviewSourceBeforeFailure = $pdo->query(
        'SELECT fonte_referencia FROM revisoes_legado WHERE id = ' . $reviewIds[1]
    )->fetchColumn();
    $eventsBeforeFailure = (int) $pdo->query(
        'SELECT COUNT(*) FROM revisoes_legado_eventos WHERE revisao_legado_id = ' . $reviewIds[1]
    )->fetchColumn();

    $auditFailed = false;
    try {
        $service->rectify(
            $studentIds[1],
            $reviewIds[1],
            $enrollmentIds[1],
            $actorId,
            'Fonte corrigida, referência 99',
            'O intervalo foi atualizado antes da falha de auditoria simulada.',
            rectificationLocalDate('2025-10-01 00:00:00'),
            rectificationLocalDate('2025-11-01 00:00:00')
        );
    } catch (PDOException) {
        $auditFailed = true;
    }

    assertRectification($auditFailed, 'Falha simulada na auditoria precisa interromper a transação.');
    $afterFailure = $pdo->query(
        'SELECT inicio_em, fim_exclusivo_em
         FROM matriculas WHERE id = ' . $enrollmentIds[1]
    )->fetch();
    $reviewSourceAfterFailure = $pdo->query(
        'SELECT fonte_referencia FROM revisoes_legado WHERE id = ' . $reviewIds[1]
    )->fetchColumn();
    $eventsAfterFailure = (int) $pdo->query(
        'SELECT COUNT(*) FROM revisoes_legado_eventos WHERE revisao_legado_id = ' . $reviewIds[1]
    )->fetchColumn();

    assertRectification($afterFailure === $beforeFailure, 'Rollback precisa restaurar as datas anteriores.');
    assertRectification($reviewSourceAfterFailure === $reviewSourceBeforeFailure, 'Rollback precisa restaurar a fonte da revisão.');
    assertRectification($eventsAfterFailure === $eventsBeforeFailure, 'Rollback precisa remover o evento especializado.');

    printf(
        "Retificação de vigência legada: OK; histórico anterior/novo, sobreposição, idempotência, cancelamento preservado e rollback confirmados.\n"
    );
} finally {
    if ($triggerCreated) {
        $pdo->exec('DROP TRIGGER IF EXISTS fitcontrol_fail_rectification_audit');
    }

    // O usuário é exclusivo deste fixture. Remover toda a auditoria vinculada
    // antes de apagar o usuário, cuja FK em auditoria usa ON DELETE RESTRICT.
    if ($actorId !== null) {
}

    if ($reviewIds !== []) {
        $placeholders = implode(',', array_fill(0, count($reviewIds), '?'));
        $deleteEvents = $pdo->prepare(
            "DELETE FROM revisoes_legado_eventos WHERE revisao_legado_id IN ({$placeholders})"
        );
        $deleteEvents->execute($reviewIds);
}

    if ($enrollmentIds !== []) {
        $placeholders = implode(',', array_fill(0, count($enrollmentIds), '?'));
        $deleteEnrollments = $pdo->prepare(
            "DELETE FROM matriculas WHERE id IN ({$placeholders})"
        );
        $deleteEnrollments->execute($enrollmentIds);
    }

    if ($reviewIds !== []) {
        $placeholders = implode(',', array_fill(0, count($reviewIds), '?'));
        $deleteReviews = $pdo->prepare(
            "DELETE FROM revisoes_legado WHERE id IN ({$placeholders})"
        );
        $deleteReviews->execute($reviewIds);
    }

    if ($studentIds !== []) {
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $deleteStudents = $pdo->prepare(
            "DELETE FROM alunos WHERE id IN ({$placeholders})"
        );
        $deleteStudents->execute($studentIds);
    }

    if ($actorId !== null) {
}

    $pdo->exec('DROP TABLE IF EXISTS revisoes_legado_eventos');
    $pdo->exec('DROP TABLE IF EXISTS revisoes_legado');
    $pdo->exec('DROP TABLE IF EXISTS matriculas');
}
