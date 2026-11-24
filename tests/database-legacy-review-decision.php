<?php
declare(strict_types=1);

use FitControl\Domain\Enrollment\LegacyReviewDecisionService;
use FitControl\Domain\Enrollment\EnrollmentPeriodCalculator;

require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentPeriodCalculator.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentInterval.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/LegacyReviewDecisionService.php';

function legacyReviewTestConnection(): PDO
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

function assertLegacyReview(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function legacyLocalDate(string $value): DateTimeImmutable
{
    return new DateTimeImmutable(
        $value,
        new DateTimeZone(EnrollmentPeriodCalculator::ACADEMY_TIMEZONE)
    );
}

function legacyUtc(string $value): string
{
    return legacyLocalDate($value)
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');
}

function assertLegacyReviewRejected(
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

$pdo = legacyReviewTestConnection();

$temporaryTables = ['revisoes_legado_eventos', 'revisoes_legado', 'matriculas', 'planos'];
$check = $pdo->prepare(
    'SELECT COUNT(*)
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = :table_name'
);

foreach ($temporaryTables as $tableName) {
    $check->execute(['table_name' => $tableName]);
    assertLegacyReview(
        (int) $check->fetchColumn() === 0,
        'A tabela ' . $tableName . ' já existe; o teste não vai sobrescrevê-la.'
    );
}

$actorId = null;
$studentIds = [];
$reviewIds = [];
$legacyEnrollmentIds = [];
$actorEmail = 'fitcontrol-review-' . bin2hex(random_bytes(5)) . '@example.invalid';

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
            KEY idx_legacy_review_student_period (aluno_id, inicio_em, fim_exclusivo_em),
            CONSTRAINT fk_legacy_review_student
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
            UNIQUE KEY uq_legacy_review_student (aluno_id_origem),
            CONSTRAINT fk_legacy_review_student_source
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
            KEY idx_legacy_review_event_timeline (revisao_legado_id, criado_em),
            KEY idx_legacy_review_event_enrollment (matricula_id, criado_em),
            CONSTRAINT fk_legacy_review_event_review
                FOREIGN KEY (revisao_legado_id) REFERENCES revisoes_legado (id)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_legacy_review_event_user
                FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_legacy_review_event_enrollment
                FOREIGN KEY (matricula_id) REFERENCES matriculas (id)
                ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB"
    );

    $createUser = $pdo->prepare(
        'INSERT INTO usuarios (nome, email, senha_hash, ativo)
         VALUES (:name, :email, :hash, 1)'
    );
    $createUser->execute([
        'name' => 'Funcionário teste de revisão',
        'email' => $actorEmail,
        'hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
    ]);
    $actorId = (int) $pdo->lastInsertId();

    $createStudent = $pdo->prepare(
        'INSERT INTO alunos (nome, telefone, email, plano)
         VALUES (:name, :phone, :email, :plan_text)'
    );

    $originalPlanTexts = ['Mensal antigo', 'Plano sem vigência', 'Sem informação', 'Plano repetido', 'Legado com conflito'];
    foreach ($originalPlanTexts as $index => $planText) {
        $createStudent->execute([
            'name' => 'Aluno de revisão legada ' . ($index + 1),
            'phone' => '2177777' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
            'email' => 'fitcontrol-review-student-' . bin2hex(random_bytes(5)) . '@example.invalid',
            'plan_text' => $planText,
        ]);
        $studentIds[] = (int) $pdo->lastInsertId();
    }

    $createReview = $pdo->prepare(
        "INSERT INTO revisoes_legado
            (aluno_id_origem, plano_texto_original, resultado_atual)
         VALUES (:student_id, :plan_text, 'PENDENTE_REVISAO')"
    );
    foreach ($studentIds as $index => $studentId) {
        $createReview->execute([
            'student_id' => $studentId,
            'plan_text' => $originalPlanTexts[$index],
        ]);
        $reviewIds[] = (int) $pdo->lastInsertId();
    }

    // Matrícula SISTEMA para produzir sobreposição no quinto aluno.
    $createExistingEnrollment = $pdo->prepare(
        "INSERT INTO matriculas (
            aluno_id, plano_id, plano_nome_contratado, valor_contratado,
            duracao_contratada_valor, duracao_contratada_unidade,
            inicio_em, fim_exclusivo_em, dia_referencia_calendario,
            cancelada_em, origem
        ) VALUES (
            :student_id, NULL, 'Contrato existente', '100.00',
            1, 'MES', :start_at, :end_at, 10, NULL, 'SISTEMA'
        )"
    );
    $createExistingEnrollment->execute([
        'student_id' => $studentIds[4],
        'start_at' => legacyUtc('2026-01-10 09:00:00'),
        'end_at' => legacyUtc('2026-02-10 09:00:00'),
    ]);

    $clock = legacyLocalDate('2026-01-01 09:00:00');
    $service = new LegacyReviewDecisionService(
        $pdo,
        static fn (): DateTimeImmutable => $clock
    );

    assertLegacyReviewRejected(
        fn () => $service->decide(
            $studentIds[0],
            $reviewIds[0],
            $actorId,
            'VINCULO_CONFIRMADO',
            'Contrato físico, pasta 2025/12, folha 18',
            str_repeat('x', 65536),
            legacyLocalDate('2025-12-01 00:00:00'),
            legacyLocalDate('2026-01-01 00:00:00')
        ),
        'Observações acima do limite TEXT devem ser rejeitadas antes da transação.',
        InvalidArgumentException::class
    );

    assertLegacyReviewRejected(
        fn () => $service->decide(
            $studentIds[0],
            $reviewIds[0],
            $actorId,
            'VINCULO_CONFIRMADO',
            'Contrato físico, pasta 2025/12, folha 18',
            chr(0xB1),
            legacyLocalDate('2025-12-01 00:00:00'),
            legacyLocalDate('2026-01-01 00:00:00')
        ),
        'Observações com UTF-8 inválido devem ser rejeitadas antes da transação.',
        InvalidArgumentException::class
    );

    // Confirmação: intervalos exatos, texto original preservado, sem valores inferidos.
    $legacyEnrollmentId = $service->decide(
        $studentIds[0],
        $reviewIds[0],
        $actorId,
        'VINCULO_CONFIRMADO',
        'Contrato físico, pasta 2025/12, folha 18',
        'Vigência conferida no documento original.',
        legacyLocalDate('2025-12-01 00:00:00'),
        legacyLocalDate('2026-01-01 00:00:00')
    );
    assertLegacyReview(is_int($legacyEnrollmentId) && $legacyEnrollmentId > 0, 'Confirmação deve criar matrícula LEGADO.');

    $legacyRow = $pdo->query(
        'SELECT plano_id, plano_nome_contratado, valor_contratado,
                duracao_contratada_valor, duracao_contratada_unidade,
                inicio_em, fim_exclusivo_em, origem
         FROM matriculas WHERE id = ' . $legacyEnrollmentId
    )->fetch();

    assertLegacyReview($legacyRow['plano_id'] === null, 'Plano não deve ser associado sem correspondência segura.');
    assertLegacyReview($legacyRow['plano_nome_contratado'] === $originalPlanTexts[0], 'Texto original deve ser preservado na matrícula.');
    assertLegacyReview($legacyRow['valor_contratado'] === null, 'Preço desconhecido não pode ser inventado.');
    assertLegacyReview($legacyRow['duracao_contratada_valor'] === null, 'Duração desconhecida não pode ser inventada.');
    assertLegacyReview($legacyRow['duracao_contratada_unidade'] === null, 'Unidade desconhecida não pode ser inventada.');
    assertLegacyReview($legacyRow['inicio_em'] === legacyUtc('2025-12-01 00:00:00'), 'Início legado precisa ser armazenado em UTC.');
    assertLegacyReview($legacyRow['fim_exclusivo_em'] === legacyUtc('2026-01-01 00:00:00'), 'Fim legado precisa ser armazenado em UTC.');
    assertLegacyReview($legacyRow['origem'] === 'LEGADO', 'A origem da matrícula precisa ser LEGADO.');

    $reviewState = $pdo->query(
        'SELECT resultado_atual, fonte_referencia
         FROM revisoes_legado WHERE id = ' . $reviewIds[0]
    )->fetch();
    assertLegacyReview($reviewState['resultado_atual'] === 'VINCULO_CONFIRMADO', 'Resultado atual deve ser atualizado.');
    assertLegacyReview($reviewState['fonte_referencia'] === 'Contrato físico, pasta 2025/12, folha 18', 'Fonte deve ser preservada na revisão.');

    $eventCount = $pdo->query(
        'SELECT COUNT(*) FROM revisoes_legado_eventos WHERE revisao_legado_id = ' . $reviewIds[0]
    )->fetchColumn();
    assertLegacyReview((int) $eventCount === 1, 'Confirmação deve criar exatamente um evento especializado.');

    $decisionEvent = $pdo->query(
        'SELECT tipo_evento, inicio_novo, fim_exclusivo_novo
         FROM revisoes_legado_eventos
         WHERE revisao_legado_id = ' . $reviewIds[0] . '
         ORDER BY id DESC
         LIMIT 1'
    )->fetch();
    assertLegacyReview($decisionEvent['tipo_evento'] === 'DECISAO', 'O primeiro evento deve ser uma decisão humana.');
    assertLegacyReview(
        $decisionEvent['inicio_novo'] === legacyUtc('2025-12-01 00:00:00')
        && $decisionEvent['fim_exclusivo_novo'] === legacyUtc('2026-01-01 00:00:00'),
        'O evento de confirmação deve preservar o intervalo original, independentemente de retificações futuras.'
    );

    $auditCount = $pdo->query(
        "SELECT COUNT(*) FROM auditoria
         WHERE usuario_id = {$actorId}
           AND acao = 'revisao_legado_decidida'
           AND entidade = 'revisoes_legado'
           AND entidade_id = {$reviewIds[0]}"
    )->fetchColumn();
    assertLegacyReview((int) $auditCount === 1, 'Confirmação deve criar uma auditoria geral.');

    // Repetição idêntica é idempotente: nenhum evento ou auditoria duplicados.
    $repeatedLegacyId = $service->decide(
        $studentIds[0],
        $reviewIds[0],
        $actorId,
        'VINCULO_CONFIRMADO',
        'Contrato físico, pasta 2025/12, folha 18',
        'Vigência conferida no documento original.',
        legacyLocalDate('2025-12-01 00:00:00'),
        legacyLocalDate('2026-01-01 00:00:00')
    );
    assertLegacyReview($repeatedLegacyId === $legacyEnrollmentId, 'Repetição idêntica deve retornar a matrícula já criada.');
    assertLegacyReview(
        (int) $pdo->query('SELECT COUNT(*) FROM revisoes_legado_eventos WHERE revisao_legado_id = ' . $reviewIds[0])->fetchColumn() === 1,
        'Repetição não pode duplicar evento.'
    );

    // Uma repetição que tenta introduzir preço comprovado divergente não é idêntica.
    assertLegacyReviewRejected(
        fn () => $service->decide(
            $studentIds[0],
            $reviewIds[0],
            $actorId,
            'VINCULO_CONFIRMADO',
            'Contrato físico, pasta 2025/12, folha 18',
            'Vigência conferida no documento original.',
            legacyLocalDate('2025-12-01 00:00:00'),
            legacyLocalDate('2026-01-01 00:00:00'),
            '99.90'
        ),
        'Repetição não pode introduzir preço diferente no snapshot histórico'
    );

    // Se uma evidência posterior gerar dúvida, a revisão volta a PENDENTE sem
    // apagar/cancelar a matrícula provisória; uma confirmação posterior reutiliza
    // o mesmo intervalo e o mesmo registro, sem duplicar a matrícula LEGADO.
    $service->decide(
        $studentIds[0],
        $reviewIds[0],
        $actorId,
        'PENDENTE_REVISAO',
        null,
        'Nova evidência contraditória exige reavaliação do vínculo.'
    );
    assertLegacyReview(
        (string) $pdo->query('SELECT resultado_atual FROM revisoes_legado WHERE id = ' . $reviewIds[0])->fetchColumn() === 'PENDENTE_REVISAO',
        'Nova dúvida deve reabrir a revisão.'
    );
    assertLegacyReview(
        (int) $pdo->query('SELECT COUNT(*) FROM matriculas WHERE aluno_id = ' . $studentIds[0] . " AND origem = 'LEGADO'")->fetchColumn() === 1,
        'Reabrir a revisão não pode apagar nem duplicar o vínculo legado.'
    );

    // A reconfirmação também não pode acrescentar silenciosamente um snapshot
    // divergente à matrícula LEGADO já materializada.
    assertLegacyReviewRejected(
        fn () => $service->decide(
            $studentIds[0],
            $reviewIds[0],
            $actorId,
            'VINCULO_CONFIRMADO',
            'Contrato físico revalidado, pasta 2025/12, folha 18',
            'Intervalo original confirmado novamente após revisão da nova evidência.',
            legacyLocalDate('2025-12-01 00:00:00'),
            legacyLocalDate('2026-01-01 00:00:00'),
            '99.90'
        ),
        'Reconfirmação não pode alterar snapshot histórico opcional'
    );

    $reconfirmedLegacyId = $service->decide(
        $studentIds[0],
        $reviewIds[0],
        $actorId,
        'VINCULO_CONFIRMADO',
        'Contrato físico revalidado, pasta 2025/12, folha 18',
        'Intervalo original confirmado novamente após revisão da nova evidência.',
        legacyLocalDate('2025-12-01 00:00:00'),
        legacyLocalDate('2026-01-01 00:00:00')
    );
    assertLegacyReview($reconfirmedLegacyId === $legacyEnrollmentId, 'Nova confirmação deve reutilizar a matrícula LEGADO existente.');
    assertLegacyReview(
        (int) $pdo->query('SELECT COUNT(*) FROM matriculas WHERE aluno_id = ' . $studentIds[0] . " AND origem = 'LEGADO'")->fetchColumn() === 1,
        'Nova confirmação não pode duplicar a matrícula LEGADO.'
    );

    // Ausência de vigência exige fonte e não cria matrícula artificial.
    $noVigencyResult = $service->decide(
        $studentIds[1],
        $reviewIds[1],
        $actorId,
        'LEGADO_SEM_VIGENCIA',
        'Declaração assinada, protocolo 142',
        'Documento confirma ausência de contrato vigente.'
    );
    assertLegacyReview($noVigencyResult === null, 'LEGADO_SEM_VIGENCIA não cria matrícula.');
    assertLegacyReview(
        (string) $pdo->query('SELECT resultado_atual FROM revisoes_legado WHERE id = ' . $reviewIds[1])->fetchColumn() === 'LEGADO_SEM_VIGENCIA',
        'Resultado sem vigência deve atualizar a revisão.'
    );
    assertLegacyReview(
        (int) $pdo->query('SELECT COUNT(*) FROM matriculas WHERE aluno_id = ' . $studentIds[1])->fetchColumn() === 0,
        'Ausência de vigência não deve gerar matrícula fictícia.'
    );

    // Evidência insuficiente exige observação e mantém o bloqueio.
    $pendingResult = $service->decide(
        $studentIds[2],
        $reviewIds[2],
        $actorId,
        'PENDENTE_REVISAO',
        null,
        'Documento ilegível; falta confirmar o período exato.'
    );
    assertLegacyReview($pendingResult === null, 'Pendente não cria matrícula.');
    assertLegacyReview(
        (string) $pdo->query('SELECT resultado_atual FROM revisoes_legado WHERE id = ' . $reviewIds[2])->fetchColumn() === 'PENDENTE_REVISAO',
        'Revisão insuficiente permanece pendente.'
    );

    // O vínculo confirmado não pode se sobrepor a uma matrícula existente.
    assertLegacyReviewRejected(
        fn () => $service->decide(
            $studentIds[4],
            $reviewIds[4],
            $actorId,
            'VINCULO_CONFIRMADO',
            'Contrato comprovado, pasta 2025/12',
            'Intervalo conflita com matrícula operacional.',
            legacyLocalDate('2026-01-20 00:00:00'),
            legacyLocalDate('2026-02-20 00:00:00')
        ),
        'Confirmação com sobreposição deve ser rejeitada'
    );
    assertLegacyReview(
        (string) $pdo->query('SELECT resultado_atual FROM revisoes_legado WHERE id = ' . $reviewIds[4])->fetchColumn() === 'PENDENTE_REVISAO',
        'Falha de sobreposição não pode alterar a revisão.'
    );
    assertLegacyReview(
        (int) $pdo->query('SELECT COUNT(*) FROM revisoes_legado_eventos WHERE revisao_legado_id = ' . $reviewIds[4])->fetchColumn() === 0,
        'Falha de sobreposição não pode criar evento.'
    );

    // Falha de auditoria após criar matrícula e evento reverte as três alterações.
    assertLegacyReviewRejected(
        fn () => $service->decide(
            $studentIds[3],
            $reviewIds[3],
            4_000_000_000,
            'VINCULO_CONFIRMADO',
            'Documento comprovado, protocolo 77',
            'Falha simulada na auditoria.',
            legacyLocalDate('2025-10-01 00:00:00'),
            legacyLocalDate('2025-11-01 00:00:00')
        ),
        'Falha da FK de auditoria deve provocar rollback',
        PDOException::class
    );

    assertLegacyReview(
        (string) $pdo->query('SELECT resultado_atual FROM revisoes_legado WHERE id = ' . $reviewIds[3])->fetchColumn() === 'PENDENTE_REVISAO',
        'Rollback deve restaurar o resultado anterior da revisão.'
    );
    assertLegacyReview(
        (int) $pdo->query('SELECT COUNT(*) FROM revisoes_legado_eventos WHERE revisao_legado_id = ' . $reviewIds[3])->fetchColumn() === 0,
        'Rollback deve remover o evento especializado inserido.'
    );
    assertLegacyReview(
        (int) $pdo->query('SELECT COUNT(*) FROM matriculas WHERE aluno_id = ' . $studentIds[3])->fetchColumn() === 0,
        'Rollback deve remover a matrícula LEGADO inserida.'
    );

    // Campos obrigatórios e fusos inválidos são rejeitados antes da transação.
    assertLegacyReviewRejected(
        fn () => $service->decide(
            $studentIds[2],
            $reviewIds[2],
            $actorId,
            'VINCULO_CONFIRMADO',
            null,
            'Sem referência de prova.',
            legacyLocalDate('2025-01-01 00:00:00'),
            legacyLocalDate('2025-02-01 00:00:00')
        ),
        'Vínculo confirmado exige referência de fonte',
        InvalidArgumentException::class
    );
    assertLegacyReviewRejected(
        fn () => $service->decide(
            $studentIds[2],
            $reviewIds[2],
            $actorId,
            'VINCULO_CONFIRMADO',
            'Fonte',
            'Intervalo com fuso incompatível.',
            new DateTimeImmutable('2025-01-01 00:00:00', new DateTimeZone('UTC')),
            legacyLocalDate('2025-02-01 00:00:00')
        ),
        'Intervalo legado em UTC deve ser rejeitado',
        InvalidArgumentException::class
    );
    assertLegacyReviewRejected(
        fn () => $service->decide(
            $studentIds[2],
            $reviewIds[2],
            $actorId,
            'PENDENTE_REVISAO',
            null,
            null
        ),
        'Revisão pendente exige observações',
        InvalidArgumentException::class
    );

    printf(
        "Revisão legada transacional: OK; três resultados, materialização comprovada, idempotência, sobreposição e rollback validados.\n"
    );
} finally {
    if ($reviewIds !== []) {
        $placeholders = implode(',', array_fill(0, count($reviewIds), '?'));
        $deleteEvents = $pdo->prepare(
            "DELETE FROM revisoes_legado_eventos WHERE revisao_legado_id IN ({$placeholders})"
        );
        $deleteEvents->execute($reviewIds);
    }

    if ($legacyEnrollmentIds !== []) {
        $placeholders = implode(',', array_fill(0, count($legacyEnrollmentIds), '?'));
}

    if ($studentIds !== []) {
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
$deleteEnrollments = $pdo->prepare(
            "DELETE FROM matriculas WHERE aluno_id IN ({$placeholders})"
        );
        $deleteEnrollments->execute($studentIds);

        $deleteReviews = $pdo->prepare(
            "DELETE FROM revisoes_legado WHERE id IN ({$placeholders})"
        );
        $deleteReviews->execute($reviewIds);

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
