<?php
declare(strict_types=1);

use FitControl\Domain\Enrollment\EnrollmentCreationService;
use FitControl\Domain\Enrollment\EnrollmentPeriodCalculator;

require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentPeriodCalculator.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentInterval.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentCreationService.php';

function creationTestConnection(): PDO
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

function assertCreation(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function creationLocalDate(string $value): DateTimeImmutable
{
    return new DateTimeImmutable(
        $value,
        new DateTimeZone(EnrollmentPeriodCalculator::ACADEMY_TIMEZONE)
    );
}

function creationUtc(string $localValue): string
{
    return creationLocalDate($localValue)
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');
}

function assertCreationRejected(
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

$pdo = creationTestConnection();
$existingTables = $pdo->prepare(
    'SELECT COUNT(*)
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME IN (\'planos\', \'matriculas\', \'revisoes_legado\', \'cobrancas\')'
);
$existingTables->execute();

assertCreation(
    (int) $existingTables->fetchColumn() === 0,
    'Uma das tabelas de teste já existe; o teste não vai sobrescrevê-la.'
);

$actorId = null;
$studentIds = [];
$planIds = [];
$enrollmentIds = [];
$actorEmail = 'fitcontrol-create-' . bin2hex(random_bytes(5)) . '@example.invalid';

try {
    $pdo->exec(
        "CREATE TABLE planos (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(100) NOT NULL,
            duracao_valor SMALLINT UNSIGNED NOT NULL,
            duracao_unidade ENUM('DIA','SEMANA','MES') NOT NULL,
            valor_atual DECIMAL(10,2) NOT NULL,
            situacao ENUM('RASCUNHO','ATIVO','INATIVO') NOT NULL DEFAULT 'RASCUNHO',
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_planos_situacao_nome (situacao, nome)
        ) ENGINE=InnoDB"
    );

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
            KEY idx_matriculas_aluno_inicio (aluno_id, inicio_em, fim_exclusivo_em),
            KEY idx_matriculas_plano (plano_id),
            CONSTRAINT fk_create_test_student
                FOREIGN KEY (aluno_id) REFERENCES alunos (id)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_create_test_plan
                FOREIGN KEY (plano_id) REFERENCES planos (id)
                ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE cobrancas (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            matricula_id BIGINT UNSIGNED NOT NULL,
            valor_total DECIMAL(10,2) NOT NULL,
            data_vencimento DATE NOT NULL,
            criado_por INT UNSIGNED NOT NULL,
            criado_em_utc DATETIME NOT NULL,
            cancelada_em_utc DATETIME NULL,
            cancelada_por INT UNSIGNED NULL,
            motivo_cancelamento VARCHAR(255) NULL,
            UNIQUE KEY uq_create_test_charge_enrollment (matricula_id),
            CONSTRAINT fk_create_test_charge_enrollment
                FOREIGN KEY (matricula_id) REFERENCES matriculas (id)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_create_test_charge_actor
                FOREIGN KEY (criado_por) REFERENCES usuarios (id)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_create_test_charge_cancel_actor
                FOREIGN KEY (cancelada_por) REFERENCES usuarios (id)
                ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE revisoes_legado (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            aluno_id_origem INT UNSIGNED NOT NULL,
            resultado_atual ENUM('VINCULO_CONFIRMADO','LEGADO_SEM_VIGENCIA','PENDENTE_REVISAO')
                NOT NULL DEFAULT 'PENDENTE_REVISAO',
            UNIQUE KEY uq_create_test_legacy_student (aluno_id_origem),
            CONSTRAINT fk_create_test_legacy_student
                FOREIGN KEY (aluno_id_origem) REFERENCES alunos (id)
                ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB"
    );

    $createUser = $pdo->prepare(
        'INSERT INTO usuarios (nome, email, senha_hash, ativo)
         VALUES (:nome, :email, :senha_hash, 1)'
    );
    $createUser->execute([
        'nome' => 'Teste de criação de matrícula',
        'email' => $actorEmail,
        'senha_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
    ]);
    $actorId = (int) $pdo->lastInsertId();

    $createStudent = $pdo->prepare(
        'INSERT INTO alunos (nome, telefone, email, plano, ativo)
         VALUES (:nome, :telefone, :email, :plano, :ativo)'
    );

    for ($index = 1; $index <= 9; $index++) {
        $createStudent->execute([
            'nome' => 'Aluno teste criação ' . $index,
            'telefone' => '2188888' . str_pad((string) $index, 4, '0', STR_PAD_LEFT),
            'email' => 'fitcontrol-create-student-' . bin2hex(random_bytes(5)) . '@example.invalid',
            'plano' => 'Legado não revisado',
            'ativo' => $index === 2 ? 0 : 1,
        ]);
        $studentIds[] = (int) $pdo->lastInsertId();
    }

    $createPlan = $pdo->prepare(
        'INSERT INTO planos (nome, duracao_valor, duracao_unidade, valor_atual, situacao)
         VALUES (:nome, :duration, :unit, :price, :situation)'
    );

    $planDefinitions = [
        ['Plano mensal', 1, 'MES', '149.90', 'ATIVO'],
        ['Plano inativo', 1, 'MES', '99.90', 'INATIVO'],
        ['Plano diário', 1, 'DIA', '25.00', 'ATIVO'],
        ['Plano rascunho', 1, 'SEMANA', '50.00', 'RASCUNHO'],
    ];

    foreach ($planDefinitions as [$name, $duration, $unit, $price, $situation]) {
        $createPlan->execute([
            'nome' => $name,
            'duration' => $duration,
            'unit' => $unit,
            'price' => $price,
            'situation' => $situation,
        ]);
        $planIds[] = (int) $pdo->lastInsertId();
    }

    $createReview = $pdo->prepare(
        'INSERT INTO revisoes_legado (aluno_id_origem, resultado_atual)
         VALUES (:student_id, :result)'
    );

    // Aluno 3: legado pendente, bloqueia.
    $createReview->execute(['student_id' => $studentIds[2], 'result' => 'PENDENTE_REVISAO']);
    // Aluno 4: vínculo confirmado sem matrícula LEGADO materializada, bloqueia por integridade.
    $createReview->execute(['student_id' => $studentIds[3], 'result' => 'VINCULO_CONFIRMADO']);
    // Aluno 5: legado sem vigência, libera.
    $createReview->execute(['student_id' => $studentIds[4], 'result' => 'LEGADO_SEM_VIGENCIA']);
    // Alunos 6 e 7 têm vínculos legados comprovados com períodos concretos.
    $createReview->execute(['student_id' => $studentIds[5], 'result' => 'VINCULO_CONFIRMADO']);
    $createReview->execute(['student_id' => $studentIds[6], 'result' => 'VINCULO_CONFIRMADO']);

    $insertLegacy = $pdo->prepare(
        "INSERT INTO matriculas (
            aluno_id, plano_id, plano_nome_contratado, valor_contratado,
            duracao_contratada_valor, duracao_contratada_unidade,
            inicio_em, fim_exclusivo_em, dia_referencia_calendario,
            cancelada_em, origem
        ) VALUES (
            :student_id, NULL, 'Plano legado sem catálogo', NULL,
            NULL, NULL, :start_at, :end_at, NULL, :cancelled_at, 'LEGADO'
        )"
    );

    // Aluno 6: vínculo efetivamente utilizado; matrícula seguinte pode ser adjacente.
    $insertLegacy->execute([
        'student_id' => $studentIds[5],
        'start_at' => creationUtc('2026-01-10 09:00:00'),
        'end_at' => creationUtc('2026-01-11 09:00:00'),
        'cancelled_at' => null,
    ]);
    $enrollmentIds[] = (int) $pdo->lastInsertId();

    // Aluno 7: cancelado antes do início; o intervalo efetivo é vazio.
    $insertLegacy->execute([
        'student_id' => $studentIds[6],
        'start_at' => creationUtc('2026-01-10 09:00:00'),
        'end_at' => creationUtc('2026-02-10 09:00:00'),
        'cancelled_at' => creationUtc('2026-01-10 08:00:00'),
    ]);
    $enrollmentIds[] = (int) $pdo->lastInsertId();

    // Aluno 8: contrato utilizado até 14h após cancelamento intradiário.
    $insertLegacy->execute([
        'student_id' => $studentIds[7],
        'start_at' => creationUtc('2026-01-10 09:00:00'),
        'end_at' => creationUtc('2026-02-10 09:00:00'),
        'cancelled_at' => creationUtc('2026-01-10 14:00:00'),
    ]);
    $enrollmentIds[] = (int) $pdo->lastInsertId();

    $clock = creationLocalDate('2026-01-10 08:00:00');
    $service = new EnrollmentCreationService(
        $pdo,
        static fn (): DateTimeImmutable => $clock
    );

    // Nova matrícula mensal: snapshot e conversão explícita UTC.
    $monthlyId = $service->create(
        $studentIds[0],
        $planIds[0],
        $actorId,
        creationLocalDate('2026-01-10 15:00:00')
    );
    $enrollmentIds[] = $monthlyId;

    // Hoje sem horário: o serviço resolve o instante depois dos bloqueios,
    // evitando que a latência da transação torne o horário padrão retroativo.
    $clockAfterLocks = creationLocalDate('2026-01-10 08:00:05');
    $nowService = new EnrollmentCreationService(
        $pdo,
        static fn (): DateTimeImmutable => $clockAfterLocks
    );
    $nowStartId = $nowService->create(
        $studentIds[8],
        $planIds[0],
        $actorId,
        creationLocalDate('2026-01-10 08:00:00'),
        true
    );
    $enrollmentIds[] = $nowStartId;
    $nowStartStored = $pdo->query(
        'SELECT inicio_em FROM matriculas WHERE id = ' . $nowStartId
    )->fetchColumn();
    assertCreation(
        $nowStartStored === creationUtc('2026-01-10 08:00:05'),
        'Início de hoje sem horário deve usar o instante confirmado depois dos bloqueios.'
    );

    $monthly = $pdo->query(
        'SELECT plano_nome_contratado, valor_contratado,
                duracao_contratada_valor, duracao_contratada_unidade,
                inicio_em, fim_exclusivo_em, dia_referencia_calendario, origem
         FROM matriculas WHERE id = ' . $monthlyId
    )->fetch();

    assertCreation($monthly['plano_nome_contratado'] === 'Plano mensal', 'Snapshot deve preservar nome do plano.');
    assertCreation($monthly['valor_contratado'] === '149.90', 'Snapshot deve preservar valor decimal exato.');
    assertCreation((int) $monthly['duracao_contratada_valor'] === 1, 'Snapshot deve preservar duração.');
    assertCreation($monthly['duracao_contratada_unidade'] === 'MES', 'Snapshot deve preservar unidade.');
    assertCreation($monthly['inicio_em'] === creationUtc('2026-01-10 15:00:00'), 'Início deve ser gravado em UTC.');
    assertCreation($monthly['fim_exclusivo_em'] === creationUtc('2026-02-11 00:00:00'), 'Fim mensal deve incluir o último dia e gravar UTC.');
    assertCreation((int) $monthly['dia_referencia_calendario'] === 10, 'Referência mensal deve preservar o dia inicial.');
    assertCreation($monthly['origem'] === 'SISTEMA', 'Matrícula operacional deve ter origem SISTEMA.');

    $audit = $pdo->prepare(
        "SELECT COUNT(*) FROM auditoria
         WHERE usuario_id = :user_id
           AND acao = 'matricula_criada'
           AND entidade = 'matriculas'
           AND entidade_id = :enrollment_id"
    );
    $audit->execute(['user_id' => $actorId, 'enrollment_id' => $monthlyId]);
    assertCreation((int) $audit->fetchColumn() === 1, 'Criação precisa gerar exatamente uma auditoria.');

    $chargeQuery = $pdo->prepare(
        'SELECT id, valor_total, data_vencimento, criado_por, criado_em_utc
         FROM cobrancas WHERE matricula_id = :matricula_id'
    );
    $chargeQuery->execute(['matricula_id' => $monthlyId]);
    $monthlyCharge = $chargeQuery->fetch();
    assertCreation(is_array($monthlyCharge), 'Matrícula nova precisa gerar cobrança.');
    assertCreation($monthlyCharge['valor_total'] === '149.90', 'Cobrança deve copiar o valor total do plano.');
    assertCreation($monthlyCharge['data_vencimento'] === '2026-01-10', 'Vencimento padrão deve ser a data local de início.');
    assertCreation((int) $monthlyCharge['criado_por'] === $actorId, 'Cobrança deve registrar o funcionário responsável.');
    assertCreation($monthlyCharge['criado_em_utc'] === creationUtc('2026-01-10 08:00:00'), 'Criação da cobrança deve ser persistida em UTC.');
    $chargeCount = $pdo->prepare('SELECT COUNT(*) FROM cobrancas WHERE matricula_id = :matricula_id');
    $chargeCount->execute(['matricula_id' => $monthlyId]);
    assertCreation((int) $chargeCount->fetchColumn() === 1, 'Deve existir uma cobrança por matrícula.');
    $chargeAudit = $pdo->prepare(
        "SELECT COUNT(*) FROM auditoria
         WHERE usuario_id = :user_id AND acao = 'cobranca_criada'
           AND entidade = 'cobrancas' AND entidade_id = :charge_id"
    );
    $chargeAudit->execute(['user_id' => $actorId, 'charge_id' => $monthlyCharge['id']]);
    assertCreation((int) $chargeAudit->fetchColumn() === 1, 'Criação da cobrança precisa ser auditada.');

    // Plano inativo/rascunho e aluno inativo não permitem contratação.
    assertCreationRejected(
        fn () => $service->create($studentIds[0], $planIds[1], $actorId, creationLocalDate('2026-03-10 00:00:00')),
        'Plano inativo deve ser rejeitado'
    );
    assertCreationRejected(
        fn () => $service->create($studentIds[0], $planIds[3], $actorId, creationLocalDate('2026-03-10 00:00:00')),
        'Plano em rascunho deve ser rejeitado'
    );
    assertCreationRejected(
        fn () => $service->create($studentIds[1], $planIds[0], $actorId, creationLocalDate('2026-03-10 00:00:00')),
        'Aluno inativo deve ser rejeitado'
    );

    // Revisão pendente ou confirmação sem matrícula histórica concreta bloqueiam.
    assertCreationRejected(
        fn () => $service->create($studentIds[2], $planIds[0], $actorId, creationLocalDate('2026-03-10 00:00:00')),
        'Legado pendente deve bloquear nova contratação'
    );
    assertCreationRejected(
        fn () => $service->create($studentIds[3], $planIds[0], $actorId, creationLocalDate('2026-03-10 00:00:00')),
        'Legado confirmado sem intervalo registrado deve bloquear nova contratação'
    );

    // LEGADO_SEM_VIGENCIA libera a contratação.
    $noLegacyId = $service->create(
        $studentIds[4],
        $planIds[2],
        $actorId,
        creationLocalDate('2026-01-10 15:00:00')
    );
    $enrollmentIds[] = $noLegacyId;

    // VINCULO_CONFIRMADO usa o intervalo LEGADO existente para verificar adjacência.
    $adjacentId = $service->create(
        $studentIds[5],
        $planIds[2],
        $actorId,
        creationLocalDate('2026-01-11 09:00:00')
    );
    $enrollmentIds[] = $adjacentId;

    // Cancelamento anterior ao início gera intervalo vazio e não bloqueia.
    $afterEmptyLegacyId = $service->create(
        $studentIds[6],
        $planIds[2],
        $actorId,
        creationLocalDate('2026-01-10 09:00:00')
    );
    $enrollmentIds[] = $afterEmptyLegacyId;

    // Cancelamento intradiário permite nova matrícula exatamente no instante do corte.
    $atCancellationId = $service->create(
        $studentIds[7],
        $planIds[2],
        $actorId,
        creationLocalDate('2026-01-10 14:00:00')
    );
    $enrollmentIds[] = $atCancellationId;

    assertCreationRejected(
        fn () => $service->create(
            $studentIds[7],
            $planIds[2],
            $actorId,
            creationLocalDate('2026-01-10 13:59:59')
        ),
        'Início antes do cancelamento efetivo deve ser rejeitado por sobreposição'
    );

    // Um início anterior ao instante atual deve ser rejeitado.
    assertCreationRejected(
        fn () => $service->create(
            $studentIds[0],
            $planIds[2],
            $actorId,
            creationLocalDate('2026-01-10 07:59:59')
        ),
        'Início passado deve ser rejeitado'
    );

    // Falha de auditoria após o INSERT precisa reverter a matrícula recém-criada.
    $countBeforeFailure = (int) $pdo->query(
        'SELECT COUNT(*) FROM matriculas WHERE aluno_id = ' . $studentIds[8]
    )->fetchColumn();
    $chargesBeforeFailure = (int) $pdo->query(
        'SELECT COUNT(*) FROM cobrancas c INNER JOIN matriculas m ON m.id = c.matricula_id WHERE m.aluno_id = ' . $studentIds[8]
    )->fetchColumn();

    assertCreationRejected(
        fn () => $service->create(
            $studentIds[8],
            $planIds[2],
            4_000_000_000,
            creationLocalDate('2026-04-10 09:00:00')
        ),
        'Falha na FK da auditoria deve provocar rollback',
        PDOException::class
    );

    $countAfterFailure = (int) $pdo->query(
        'SELECT COUNT(*) FROM matriculas WHERE aluno_id = ' . $studentIds[8]
    )->fetchColumn();

    assertCreation($countAfterFailure === $countBeforeFailure, 'Rollback precisa desfazer a nova matrícula.');
    $chargesAfterFailure = (int) $pdo->query(
        'SELECT COUNT(*) FROM cobrancas c INNER JOIN matriculas m ON m.id = c.matricula_id WHERE m.aluno_id = ' . $studentIds[8]
    )->fetchColumn();
    assertCreation($chargesAfterFailure === $chargesBeforeFailure, 'Rollback precisa desfazer também a cobrança.');

    printf(
        "Criação transacional de matrícula e cobrança: OK; snapshot, vencimento, auditoria e rollback validados.\n"
    );
} finally {
    if ($enrollmentIds !== []) {
        $placeholders = implode(',', array_fill(0, count($enrollmentIds), '?'));
$deleteCharges = $pdo->prepare(
            "DELETE FROM cobrancas WHERE matricula_id IN ({$placeholders})"
        );
        $deleteCharges->execute($enrollmentIds);
    $deleteEnrollments = $pdo->prepare(
            "DELETE FROM matriculas WHERE id IN ({$placeholders})"
        );
        $deleteEnrollments->execute($enrollmentIds);
    }

    if ($studentIds !== []) {
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $deleteReviews = $pdo->prepare(
            "DELETE FROM revisoes_legado WHERE aluno_id_origem IN ({$placeholders})"
        );
        $deleteReviews->execute($studentIds);

        $deleteStudents = $pdo->prepare(
            "DELETE FROM alunos WHERE id IN ({$placeholders})"
        );
        $deleteStudents->execute($studentIds);
    }

    if ($actorId !== null) {
}

    $pdo->exec('DROP TABLE IF EXISTS cobrancas');
    $pdo->exec('DROP TABLE IF EXISTS revisoes_legado');
    $pdo->exec('DROP TABLE IF EXISTS matriculas');
    $pdo->exec('DROP TABLE IF EXISTS planos');
}
