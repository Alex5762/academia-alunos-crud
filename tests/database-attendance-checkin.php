<?php
declare(strict_types=1);

use FitControl\Domain\Attendance\AttendanceCheckinService;

require_once dirname(__DIR__) . '/src/Domain/Attendance/AttendanceCheckinService.php';
require_once dirname(__DIR__) . '/src/Security/AuditIntegrityChain.php';

function attendanceTestConnection(): PDO
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

function assertAttendance(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertAttendanceRejected(
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

function attendanceUtc(string $localDateTime): string
{
    $local = new DateTimeImmutable(
        $localDateTime,
        new DateTimeZone('America/Sao_Paulo')
    );

    return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

$pdo = attendanceTestConnection();
$schema = $pdo->query('SELECT DATABASE()')->fetchColumn();
assertAttendance(is_string($schema) && $schema !== '', 'O teste precisa de um banco selecionado.');

$requiredTables = $pdo->prepare(
    'SELECT COUNT(*)
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME IN (\'alunos\', \'usuarios\', \'matriculas\', \'checkins\')'
);
$requiredTables->execute();
assertAttendance((int) $requiredTables->fetchColumn() === 4, 'As migrations 005 e 009 precisam estar aplicadas.');

$actorEmail = 'fitcontrol-attendance-' . bin2hex(random_bytes(8)) . '@example.invalid';
$studentIds = [];
$checkinIds = [];

$createActor = $pdo->prepare(
    'INSERT INTO usuarios (nome, email, senha_hash, ativo)
     VALUES (:name, :email, :password_hash, 1)'
);
$createActor->execute([
    'name' => 'Funcionário de teste de frequência',
    'email' => $actorEmail,
    'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
]);
$actorId = (int) $pdo->lastInsertId();

$createStudent = $pdo->prepare(
    'INSERT INTO alunos (nome, telefone, email, plano, ativo)
     VALUES (:name, :phone, :email, :plan, :active)'
);

$definitions = [
    ['Aluno apto a check-in', 1],
    ['Aluno inativo', 0],
    ['Aluno sem matrícula', 1],
    ['Aluno com matrícula futura', 1],
    ['Aluno com matrícula vencida', 1],
    ['Aluno com matrícula cancelada', 1],
    ['Aluno para teste de rollback', 1],
    ['Aluno na virada do dia local', 1],
    ['Aluno com múltiplas matrículas vigentes', 1],
];

foreach ($definitions as $index => [$name, $active]) {
    $suffix = bin2hex(random_bytes(5));
    $createStudent->execute([
        'name' => $name,
        'phone' => '2177' . str_pad((string) ($index + 1), 7, '0', STR_PAD_LEFT),
        'email' => 'fitcontrol-attendance-student-' . $suffix . '@example.invalid',
        'plan' => 'Plano de teste',
        'active' => $active,
    ]);
    $studentIds[] = (int) $pdo->lastInsertId();
}

$insertEnrollment = $pdo->prepare(
    "INSERT INTO matriculas (
        aluno_id,
        plano_id,
        plano_nome_contratado,
        valor_contratado,
        duracao_contratada_valor,
        duracao_contratada_unidade,
        inicio_em,
        fim_exclusivo_em,
        dia_referencia_calendario,
        cancelada_em,
        origem
    ) VALUES (
        :student_id, NULL, 'Plano legado de teste', NULL,
        NULL, NULL, :starts_at, :ends_at, NULL, :cancelled_at, 'LEGADO'
    )"
);

$enrollmentFixtures = [
    [0, '2026-11-01 00:00:00', '2026-12-01 00:00:00', null],
    [1, '2026-11-01 00:00:00', '2026-12-01 00:00:00', null],
    [3, '2026-11-23 00:00:00', '2026-12-23 00:00:00', null],
    [4, '2026-10-01 00:00:00', '2026-11-22 09:00:00', null],
    [5, '2026-11-01 00:00:00', '2026-12-01 00:00:00', '2026-11-20 12:00:00'],
    [6, '2026-11-01 00:00:00', '2026-12-01 00:00:00', null],
    [7, '2026-11-01 00:00:00', '2026-12-01 00:00:00', null],
    [8, '2026-11-01 00:00:00', '2026-12-01 00:00:00', null],
    [8, '2026-11-10 00:00:00', '2026-12-10 00:00:00', null],
];

foreach ($enrollmentFixtures as [$studentIndex, $startsAt, $endsAt, $cancelledAt]) {
    $insertEnrollment->execute([
        'student_id' => $studentIds[$studentIndex],
        'starts_at' => attendanceUtc($startsAt),
        'ends_at' => attendanceUtc($endsAt),
        'cancelled_at' => $cancelledAt === null ? null : attendanceUtc($cancelledAt),
    ]);
}

$now = new DateTimeImmutable(
    '2026-11-22 10:00:00',
    new DateTimeZone('America/Sao_Paulo')
);
$service = new AttendanceCheckinService($pdo, static fn (): DateTimeImmutable => $now);

try {
    // Matrícula ativa e aluno ativo: deve registrar o instante UTC e o dia local.
    $firstCheckinId = $service->register($studentIds[0], $actorId);
    $checkinIds[] = $firstCheckinId;

    $first = $pdo->prepare(
        'SELECT aluno_id, matricula_id, usuario_id, data_local, registrado_em_utc
         FROM checkins
         WHERE id = :id'
    );
    $first->execute(['id' => $firstCheckinId]);
    $record = $first->fetch();

    assertAttendance($record !== false, 'O check-in registrado precisa existir.');
    assertAttendance((int) $record['aluno_id'] === $studentIds[0], 'O check-in precisa apontar para o aluno correto.');
    assertAttendance((int) $record['usuario_id'] === $actorId, 'O check-in precisa identificar o funcionário autenticado.');
    assertAttendance($record['data_local'] === '2026-11-22', 'A data civil precisa usar o fuso da academia.');
    assertAttendance($record['registrado_em_utc'] === '2026-11-22 13:00:00', 'O instante precisa ser persistido em UTC.');

    assertAttendanceRejected(
        fn () => $service->register($studentIds[0], $actorId),
        'A segunda entrada do mesmo aluno no mesmo dia deve ser bloqueada.'
    );

    $nextDay = new DateTimeImmutable(
        '2026-11-23 08:15:00',
        new DateTimeZone('America/Sao_Paulo')
    );
    $nextDayService = new AttendanceCheckinService(
        $pdo,
        static fn (): DateTimeImmutable => $nextDay
    );
    $secondCheckinId = $nextDayService->register($studentIds[0], $actorId);
    $checkinIds[] = $secondCheckinId;

    assertAttendance(
        $secondCheckinId !== $firstCheckinId,
        'O mesmo aluno pode registrar nova presença em outro dia.'
    );

    assertAttendanceRejected(
        fn () => $service->register($studentIds[1], $actorId),
        'Aluno inativo não pode registrar presença.'
    );
    assertAttendanceRejected(
        fn () => $service->register($studentIds[2], $actorId),
        'Aluno sem matrícula vigente não pode registrar presença.'
    );
    assertAttendanceRejected(
        fn () => $service->register($studentIds[3], $actorId),
        'Matrícula futura não pode habilitar check-in antecipado.'
    );
    assertAttendanceRejected(
        fn () => $service->register($studentIds[4], $actorId),
        'Matrícula vencida não pode habilitar check-in.'
    );
    assertAttendanceRejected(
        fn () => $service->register($studentIds[5], $actorId),
        'Matrícula cancelada não pode habilitar check-in.'
    );
    assertAttendanceRejected(
        fn () => $service->register(4294967295, $actorId),
        'Aluno inexistente não pode registrar presença.'
    );
    assertAttendanceRejected(
        fn () => $service->register($studentIds[8], $actorId),
        'Mais de uma matrícula vigente deve ser tratada como estado ambíguo.'
    );

    // Simula uma falha na cadeia depois do INSERT do check-in. O serviço
    // precisa reverter a presença e preservar o estado original da cadeia.
    $previousChainHash = (string) $pdo->query(
        'SELECT ultimo_hash FROM auditoria_integridade_estado WHERE id = 1'
    )->fetchColumn();
    $pdo->exec('SET @fitcontrol_audit_chain_write = 1');
    $pdo->exec(
        "UPDATE auditoria_integridade_estado SET ultimo_hash = 'hash-invalido' WHERE id = 1"
    );
    $pdo->exec('SET @fitcontrol_audit_chain_write = NULL');

    try {
        $service->register($studentIds[6], $actorId);
        throw new RuntimeException('A cadeia inválida deveria fazer a transação falhar.');
    } catch (RuntimeException $exception) {
        assertAttendance(
            str_contains($exception->getMessage(), 'Hash de integridade inválido.'),
            'A falha esperada deve vir da validação do hash da cadeia.'
        );
    } finally {
        $pdo->exec('SET @fitcontrol_audit_chain_write = 1');
        $restoreChain = $pdo->prepare(
            'UPDATE auditoria_integridade_estado SET ultimo_hash = :previous_hash WHERE id = 1'
        );
        $restoreChain->execute(['previous_hash' => $previousChainHash]);
        $pdo->exec('SET @fitcontrol_audit_chain_write = NULL');
    }

    $rollbackCount = $pdo->prepare(
        'SELECT COUNT(*) FROM checkins WHERE aluno_id = :student_id'
    );
    $rollbackCount->execute(['student_id' => $studentIds[6]]);
    assertAttendance((int) $rollbackCount->fetchColumn() === 0, 'Falha de auditoria precisa reverter o check-in.');

    // 23:30 em São Paulo já corresponde ao dia seguinte em UTC; a data civil
    // do check-in precisa continuar sendo a data local da academia.
    $lateNight = new DateTimeImmutable(
        '2026-11-22 23:30:00',
        new DateTimeZone('America/Sao_Paulo')
    );
    $lateNightService = new AttendanceCheckinService(
        $pdo,
        static fn (): DateTimeImmutable => $lateNight
    );
    $lateNightId = $lateNightService->register($studentIds[7], $actorId);
    $lateNightRecord = $pdo->prepare(
        'SELECT data_local, registrado_em_utc FROM checkins WHERE id = :id'
    );
    $lateNightRecord->execute(['id' => $lateNightId]);
    $lateNightValues = $lateNightRecord->fetch(PDO::FETCH_ASSOC);
    assertAttendance(
        $lateNightValues['data_local'] === '2026-11-22',
        'A virada de data UTC não pode alterar o dia civil local do check-in.'
    );
    assertAttendance(
        $lateNightValues['registrado_em_utc'] === '2026-11-23 02:30:00',
        'O instante de check-in noturno precisa ser convertido corretamente para UTC.'
    );

    $auditCount = $pdo->prepare(
        'SELECT COUNT(*)
         FROM auditoria
         WHERE usuario_id = :actor_id
           AND acao = :action
           AND entidade = :entity'
    );
    $auditCount->execute([
        'actor_id' => $actorId,
        'action' => 'checkin_registrado',
        'entity' => 'checkins',
    ]);
    assertAttendance((int) $auditCount->fetchColumn() === 3, 'Cada check-in precisa gerar exatamente um evento de auditoria.');

    $chainCount = $pdo->prepare(
        'SELECT COUNT(*)
         FROM auditoria_integridade_eventos c
         INNER JOIN auditoria a ON a.id = c.evento_id
         WHERE a.usuario_id = :actor_id
           AND a.acao = :action
           AND a.entidade = :entity'
    );
    $chainCount->execute([
        'actor_id' => $actorId,
        'action' => 'checkin_registrado',
        'entity' => 'checkins',
    ]);
    assertAttendance((int) $chainCount->fetchColumn() === 3, 'Cada evento de check-in precisa estar encadeado.');

    $uniqueIndex = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND INDEX_NAME = :index_name
           AND NON_UNIQUE = 0'
    );
    $uniqueIndex->execute([
        'table_name' => 'checkins',
        'index_name' => 'uq_checkins_aluno_data_local',
    ]);
    assertAttendance((int) $uniqueIndex->fetchColumn() === 2, 'A proteção de duplicidade deve usar chave única por aluno/dia.');

    $integrity = \FitControl\Security\AuditIntegrityChain::verify($pdo);
    assertAttendance($integrity['ok'], 'A cadeia geral de auditoria precisa permanecer íntegra.');

    echo "Check-in: elegibilidade, UTC, unicidade diária e auditoria aprovados.\n";
} finally {
    // Os eventos de auditoria são append-only e permanecem no banco efêmero da CI.
    // O banco do job é descartado ao final da execução.
}
