<?php
declare(strict_types=1);

use FitControl\Domain\Enrollment\EnrollmentCancellationService;
use FitControl\Domain\Enrollment\EnrollmentPeriodCalculator;
use FitControl\Domain\Enrollment\EnrollmentStatus;

require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentPeriodCalculator.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentStatus.php';
require_once dirname(__DIR__) . '/src/Domain/Enrollment/EnrollmentCancellationService.php';

function cancellationTestConnection(): PDO
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

function assertCancellation(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function academyDate(string $value): DateTimeImmutable
{
    return new DateTimeImmutable(
        $value,
        new DateTimeZone(EnrollmentPeriodCalculator::ACADEMY_TIMEZONE)
    );
}

function utcLiteralForAcademyDate(string $value): string
{
    return academyDate($value)
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');
}

function countCancellationAudits(PDO $pdo, int $userId, int $enrollmentId): int
{
    $query = $pdo->prepare(
        "SELECT COUNT(*)
         FROM auditoria
         WHERE usuario_id = :user_id
           AND acao = 'matricula_cancelada'
           AND entidade = 'matriculas'
           AND entidade_id = :enrollment_id"
    );
    $query->execute([
        'user_id' => $userId,
        'enrollment_id' => $enrollmentId,
    ]);

    return (int) $query->fetchColumn();
}

$pdo = cancellationTestConnection();
$existingTable = $pdo->prepare(
    'SELECT COUNT(*)
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = :table_name'
);
$existingTable->execute(['table_name' => 'matriculas']);

assertCancellation(
    (int) $existingTable->fetchColumn() === 0,
    'A tabela matriculas já existe; o teste descartável não vai sobrescrevê-la.'
);

$actorEmail = 'fitcontrol-cancel-test-' . bin2hex(random_bytes(5)) . '@example.invalid';
$studentEmails = [];
$studentIds = [];
$enrollmentIds = [];
$actorId = null;

try {
    $pdo->exec(
        'CREATE TABLE matriculas (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            aluno_id INT UNSIGNED NOT NULL,
            inicio_em DATETIME NOT NULL,
            fim_exclusivo_em DATETIME NOT NULL,
            cancelada_em DATETIME NULL,
            motivo_cancelamento VARCHAR(255) NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_matriculas_aluno_inicio (aluno_id, inicio_em),
            CONSTRAINT fk_cancel_test_student
                FOREIGN KEY (aluno_id) REFERENCES alunos (id)
                ON UPDATE CASCADE
                ON DELETE RESTRICT
        ) ENGINE=InnoDB'
    );

    $createUser = $pdo->prepare(
        'INSERT INTO usuarios (nome, email, senha_hash, ativo)
         VALUES (:nome, :email, :senha_hash, 1)'
    );
    $createUser->execute([
        'nome' => 'Teste de cancelamento',
        'email' => $actorEmail,
        'senha_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
    ]);
    $actorId = (int) $pdo->lastInsertId();

    $createStudent = $pdo->prepare(
        'INSERT INTO alunos (nome, telefone, email, plano)
         VALUES (:nome, :telefone, :email, :plano)'
    );

    for ($index = 1; $index <= 5; $index++) {
        $email = 'fitcontrol-cancel-student-' . bin2hex(random_bytes(5)) . '@example.invalid';
        $studentEmails[] = $email;
        $createStudent->execute([
            'nome' => 'Aluno de teste de cancelamento ' . $index,
            'telefone' => '2199999' . str_pad((string) $index, 4, '0', STR_PAD_LEFT),
            'email' => $email,
            'plano' => 'Teste',
        ]);
        $studentIds[] = (int) $pdo->lastInsertId();
    }

    $createEnrollment = $pdo->prepare(
        'INSERT INTO matriculas
            (aluno_id, inicio_em, fim_exclusivo_em, cancelada_em, motivo_cancelamento)
         VALUES (:aluno_id, :inicio_em, :fim_exclusivo_em, :cancelada_em, NULL)'
    );

    $insertEnrollment = static function (
        int $studentId,
        string $startLocal,
        string $endLocal,
        ?string $cancelledLocal = null
    ) use ($createEnrollment, $pdo, &$enrollmentIds): int {
        $createEnrollment->execute([
            'aluno_id' => $studentId,
            'inicio_em' => utcLiteralForAcademyDate($startLocal),
            'fim_exclusivo_em' => utcLiteralForAcademyDate($endLocal),
            'cancelada_em' => $cancelledLocal === null
                ? null
                : utcLiteralForAcademyDate($cancelledLocal),
        ]);
        $id = (int) $pdo->lastInsertId();
        $enrollmentIds[] = $id;
        return $id;
    };

    $scheduledId = $insertEnrollment(
        $studentIds[0],
        '2026-01-10 09:00:00',
        '2026-02-10 09:00:00'
    );
    $activeId = $insertEnrollment(
        $studentIds[1],
        '2026-01-10 09:00:00',
        '2026-02-10 09:00:00'
    );
    $expiredId = $insertEnrollment(
        $studentIds[2],
        '2026-01-10 09:00:00',
        '2026-01-11 09:00:00'
    );
    $alreadyCancelledId = $insertEnrollment(
        $studentIds[3],
        '2026-01-10 09:00:00',
        '2026-02-10 09:00:00',
        '2026-01-10 08:00:00'
    );
    $auditFailureId = $insertEnrollment(
        $studentIds[4],
        '2026-01-10 09:00:00',
        '2026-02-10 09:00:00'
    );

    $fixedClock = academyDate('2026-01-10 08:30:00');
    $service = new EnrollmentCancellationService(
        $pdo,
        static fn (): DateTimeImmutable => $fixedClock
    );

    // Cancelamento agendado antes do início, com motivo vazio normalizado para NULL.
    $service->cancel($studentIds[0], $scheduledId, $actorId, '   ');

    $scheduledRow = $pdo->query(
        'SELECT cancelada_em, motivo_cancelamento
         FROM matriculas
         WHERE id = ' . $scheduledId
    )->fetch();

    assertCancellation(
        $scheduledRow['cancelada_em'] === utcLiteralForAcademyDate('2026-01-10 08:30:00'),
        'O cancelamento agendado precisa armazenar o instante confirmado em UTC.'
    );
    assertCancellation(
        $scheduledRow['motivo_cancelamento'] === null,
        'Motivo em branco deve ser normalizado para NULL.'
    );
    assertCancellation(
        countCancellationAudits($pdo, $actorId, $scheduledId) === 1,
        'O cancelamento agendado precisa gerar exatamente um evento de auditoria.'
    );
    assertCancellation(
        EnrollmentStatus::derive(
            academyDate('2026-01-10 09:00:00'),
            academyDate('2026-02-10 09:00:00'),
            academyDate('2026-01-10 08:30:00'),
            academyDate('2026-01-10 08:30:00')
        ) === EnrollmentStatus::CANCELLED,
        'O cancelamento anterior ao início deve derivar CANCELADA.'
    );

    // Instante atual injetado no serviço é avaliado somente após os bloqueios.
    $activeClock = academyDate('2026-01-10 10:15:00');
    $activeService = new EnrollmentCancellationService(
        $pdo,
        static fn (): DateTimeImmutable => $activeClock
    );
    $activeService->cancel($studentIds[1], $activeId, $actorId, 'Solicitação do aluno');

    $activeRow = $pdo->query(
        'SELECT cancelada_em, motivo_cancelamento
         FROM matriculas
         WHERE id = ' . $activeId
    )->fetch();

    assertCancellation(
        $activeRow['cancelada_em'] === utcLiteralForAcademyDate('2026-01-10 10:15:00'),
        'O cancelamento ativo deve persistir o instante confirmado em UTC.'
    );
    assertCancellation(
        $activeRow['motivo_cancelamento'] === 'Solicitação do aluno',
        'O motivo informado deve ser preservado.'
    );
    assertCancellation(
        countCancellationAudits($pdo, $actorId, $activeId) === 1,
        'O cancelamento ativo precisa gerar exatamente um evento de auditoria.'
    );

    // O domínio também protege o limite do schema, mesmo quando chamado fora do endpoint.
    $invalidReasonRejected = false;
    try {
        $service->cancel(
            $studentIds[2],
            $expiredId,
            $actorId,
            str_repeat('x', 256)
        );
    } catch (InvalidArgumentException) {
        $invalidReasonRejected = true;
    }
    assertCancellation(
        $invalidReasonRejected,
        'Motivo acima de 255 caracteres deve ser rejeitado antes da transação.'
    );
    assertCancellation(
        $pdo->query('SELECT cancelada_em FROM matriculas WHERE id = ' . $expiredId)->fetchColumn() === null,
        'Motivo inválido não pode alterar a matrícula.'
    );

    // Matrícula vencida não pode ser cancelada nem receber evento.
    $expiredService = new EnrollmentCancellationService(
        $pdo,
        static fn (): DateTimeImmutable => academyDate('2026-01-11 09:00:00')
    );

    try {
        $expiredService->cancel($studentIds[2], $expiredId, $actorId);
        throw new RuntimeException('Matrícula vencida não deveria aceitar cancelamento.');
    } catch (DomainException) {
        // Resultado esperado: a transação deve ter sido revertida.
    }

    $expiredValue = $pdo->query(
        'SELECT cancelada_em FROM matriculas WHERE id = ' . $expiredId
    )->fetchColumn();

    assertCancellation($expiredValue === null, 'Matrícula vencida não pode receber cancelada_em.');
    assertCancellation(
        countCancellationAudits($pdo, $actorId, $expiredId) === 0,
        'Tentativa rejeitada de cancelar matrícula vencida não pode gerar auditoria.'
    );

    // Uma matrícula já cancelada não aceita evento duplicado.
    try {
        $service->cancel($studentIds[3], $alreadyCancelledId, $actorId);
        throw new RuntimeException('Matrícula já cancelada não deveria aceitar novo cancelamento.');
    } catch (DomainException) {
        // Resultado esperado.
    }

    assertCancellation(
        countCancellationAudits($pdo, $actorId, $alreadyCancelledId) === 0,
        'Tentativa de cancelamento repetido não pode gerar auditoria duplicada.'
    );

    // ID inexistente em usuarios viola a FK da auditoria depois do UPDATE.
    // O serviço deve desfazer também a alteração em matriculas.
    $auditFailureService = new EnrollmentCancellationService(
        $pdo,
        static fn (): DateTimeImmutable => academyDate('2026-01-10 10:00:00')
    );
    $auditFailed = false;

    try {
        $auditFailureService->cancel(
            $studentIds[4],
            $auditFailureId,
            4_000_000_000
        );
    } catch (PDOException) {
        $auditFailed = true;
    }

    assertCancellation($auditFailed, 'O cenário de auditoria inválida deveria falhar pela FK.');
    $afterAuditFailure = $pdo->query(
        'SELECT cancelada_em FROM matriculas WHERE id = ' . $auditFailureId
    )->fetchColumn();

    assertCancellation(
        $afterAuditFailure === null,
        'A falha na auditoria precisa reverter cancelada_em.'
    );
    assertCancellation(
        countCancellationAudits($pdo, $actorId, $auditFailureId) === 0,
        'A falha na auditoria não pode deixar evento parcial.'
    );

    printf(
        "Cancelamento transacional: OK; cancelamento agendado/ativo, vencida/repetida rejeitadas e rollback de auditoria confirmado.\n"
    );
} finally {
if (isset($enrollmentIds) && $enrollmentIds !== []) {
        $placeholders = implode(',', array_fill(0, count($enrollmentIds), '?'));
        $deleteMatriculas = $pdo->prepare(
            "DELETE FROM matriculas WHERE id IN ({$placeholders})"
        );
        $deleteMatriculas->execute($enrollmentIds);
    }

    if (isset($studentIds) && $studentIds !== []) {
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $deleteStudents = $pdo->prepare(
            "DELETE FROM alunos WHERE id IN ({$placeholders})"
        );
        $deleteStudents->execute($studentIds);
    }

    if ($actorId !== null) {
}

    $pdo->exec('DROP TABLE IF EXISTS matriculas');
}
