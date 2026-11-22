<?php
declare(strict_types=1);

/**
 * Valida a estrutura da migration 014 em MySQL 8.0 e MariaDB 10.11.
 * O banco usado pela CI é efêmero; os registros de fixture são removidos ao final.
 */

function classScheduleTestConnection(): PDO
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

function assertClassSchedule(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectClassSchedulePdoFailure(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (PDOException) {
        return;
    }

    throw new RuntimeException($message);
}

$pdo = classScheduleTestConnection();
$schema = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
assertClassSchedule($schema !== '', 'O teste precisa de um banco selecionado.');

$tableQuery = $pdo->prepare(
    'SELECT TABLE_NAME, ENGINE
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = :schema
       AND TABLE_NAME IN (\'modalidades\', \'instrutores\', \'horarios_aula\')'
);
$tableQuery->execute(['schema' => $schema]);
$tables = $tableQuery->fetchAll(PDO::FETCH_KEY_PAIR);
assertClassSchedule(count($tables) === 3, 'A migration 014 precisa criar as três tabelas do Bloco 06.');

foreach (['modalidades', 'instrutores', 'horarios_aula'] as $table) {
    assertClassSchedule(($tables[$table] ?? null) === 'InnoDB', $table . ' precisa usar InnoDB.');
}

$columnQuery = $pdo->prepare(
    'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = :schema
       AND (
           (TABLE_NAME = \'instrutores\' AND COLUMN_NAME = \'usuario_id\')
           OR (TABLE_NAME = \'horarios_aula\' AND COLUMN_NAME = \'instrutor_id\')
       )'
);
$columnQuery->execute(['schema' => $schema]);
$columns = [];
foreach ($columnQuery->fetchAll() as $column) {
    $columns[$column['TABLE_NAME']][$column['COLUMN_NAME']] = $column;
}

assertClassSchedule(
    ($columns['instrutores']['usuario_id']['COLUMN_TYPE'] ?? null) === 'int unsigned',
    'instrutores.usuario_id precisa corresponder exatamente a usuarios.id (INT UNSIGNED).'
);
assertClassSchedule(
    ($columns['instrutores']['usuario_id']['IS_NULLABLE'] ?? null) === 'YES',
    'O vínculo do instrutor com uma conta deve ser opcional.'
);
assertClassSchedule(
    ($columns['horarios_aula']['instrutor_id']['IS_NULLABLE'] ?? null) === 'YES',
    'Um horário fixo pode existir sem instrutor atribuído.'
);

$actorEmail = 'fitcontrol-class-schedule-' . bin2hex(random_bytes(8)) . '@example.invalid';
$actorId = null;
$modalityIds = [];
$instructorIds = [];
$scheduleIds = [];

try {
    $insertActor = $pdo->prepare(
        'INSERT INTO usuarios (nome, email, senha_hash, ativo)
         VALUES (:name, :email, :password_hash, 1)'
    );
    $insertActor->execute([
        'name' => 'Funcionário de teste da grade',
        'email' => $actorEmail,
        'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
    ]);
    $actorId = (int) $pdo->lastInsertId();

    $insertModality = $pdo->prepare(
        'INSERT INTO modalidades (nome, descricao)
         VALUES (:name, :description)'
    );
    $insertModality->execute([
        'name' => 'Modalidade de teste ' . bin2hex(random_bytes(4)),
        'description' => 'Fixture da migration 014',
    ]);
    $modalityIds[] = (int) $pdo->lastInsertId();

    $insertSecondModality = $pdo->prepare(
        'INSERT INTO modalidades (nome)
         VALUES (:name)'
    );
    $insertSecondModality->execute([
        'name' => 'Segunda modalidade de teste ' . bin2hex(random_bytes(4)),
    ]);
    $modalityIds[] = (int) $pdo->lastInsertId();

    $insertInstructor = $pdo->prepare(
        'INSERT INTO instrutores (nome, email, usuario_id)
         VALUES (:name, :email, :user_id)'
    );
    $insertInstructor->execute([
        'name' => 'Instrutor sem conta',
        'email' => 'instrutor-sem-conta-' . bin2hex(random_bytes(4)) . '@example.invalid',
        'user_id' => null,
    ]);
    $instructorIds[] = (int) $pdo->lastInsertId();

    $insertInstructor->execute([
        'name' => 'Instrutor vinculado',
        'email' => 'instrutor-vinculado-' . bin2hex(random_bytes(4)) . '@example.invalid',
        'user_id' => $actorId,
    ]);
    $instructorIds[] = (int) $pdo->lastInsertId();

    expectClassSchedulePdoFailure(
        fn () => $insertInstructor->execute([
            'name' => 'Vínculo duplicado',
            'email' => 'instrutor-duplicado-' . bin2hex(random_bytes(4)) . '@example.invalid',
            'user_id' => $actorId,
        ]),
        'Uma conta não pode estar vinculada a dois instrutores.'
    );

    $insertSchedule = $pdo->prepare(
        'INSERT INTO horarios_aula (
            modalidade_id, instrutor_id, dia_semana, hora_inicio, hora_fim
         ) VALUES (
            :modality_id, :instructor_id, :weekday, :starts_at, :ends_at
         )'
    );
    $insertSchedule->execute([
        'modality_id' => $modalityIds[0],
        'instructor_id' => $instructorIds[0],
        'weekday' => 1,
        'starts_at' => '07:00:00',
        'ends_at' => '08:00:00',
    ]);
    $scheduleIds[] = (int) $pdo->lastInsertId();

    // Instrutor é opcional; cada ocorrência semanal é uma linha independente.
    $insertSchedule->execute([
        'modality_id' => $modalityIds[1],
        'instructor_id' => null,
        'weekday' => 3,
        'starts_at' => '18:30:00',
        'ends_at' => '19:15:00',
    ]);
    $scheduleIds[] = (int) $pdo->lastInsertId();

    expectClassSchedulePdoFailure(
        fn () => $insertSchedule->execute([
            'modality_id' => $modalityIds[0],
            'instructor_id' => null,
            'weekday' => 1,
            'starts_at' => '07:00:00',
            'ends_at' => '08:30:00',
        ]),
        'A mesma modalidade não pode ser cadastrada duas vezes no mesmo dia e horário de início.'
    );

    expectClassSchedulePdoFailure(
        fn () => $insertSchedule->execute([
            'modality_id' => $modalityIds[0],
            'instructor_id' => null,
            'weekday' => 8,
            'starts_at' => '09:00:00',
            'ends_at' => '10:00:00',
        ]),
        'Dia da semana fora do intervalo ISO 1–7 deve ser rejeitado.'
    );

    expectClassSchedulePdoFailure(
        fn () => $insertSchedule->execute([
            'modality_id' => $modalityIds[0],
            'instructor_id' => null,
            'weekday' => 2,
            'starts_at' => '10:00:00',
            'ends_at' => '10:00:00',
        ]),
        'Horário com duração zero deve ser rejeitado.'
    );

    expectClassSchedulePdoFailure(
        fn () => $insertSchedule->execute([
            'modality_id' => $modalityIds[0],
            'instructor_id' => null,
            'weekday' => 2,
            'starts_at' => '23:30:00',
            'ends_at' => '24:00:00',
        ]),
        'A grade não aceita aulas atravessando a meia-noite.'
    );

    expectClassSchedulePdoFailure(
        fn () => $pdo->prepare('DELETE FROM modalidades WHERE id = ?')
            ->execute([$modalityIds[0]]),
        'Modalidade referenciada por horário precisa ser protegida contra exclusão.'
    );
    expectClassSchedulePdoFailure(
        fn () => $pdo->prepare('DELETE FROM instrutores WHERE id = ?')
            ->execute([$instructorIds[0]]),
        'Instrutor referenciado por horário precisa ser protegido contra exclusão.'
    );

    $scheduleIndexes = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = :schema
           AND TABLE_NAME = \'horarios_aula\'
           AND INDEX_NAME = \'idx_horarios_aula_grade\''
    );
    $scheduleIndexes->execute(['schema' => $schema]);
    assertClassSchedule((int) $scheduleIndexes->fetchColumn() === 4, 'A grade precisa de índice para consulta por dia e hora.');

    echo "Migration 014: tabelas, FKs, vínculo opcional, grade semanal, unicidade e checks aprovados.\n";
} finally {
    foreach ($scheduleIds as $id) {
        $delete = $pdo->prepare('DELETE FROM horarios_aula WHERE id = :id');
        $delete->execute(['id' => $id]);
    }

    foreach ($instructorIds as $id) {
        $delete = $pdo->prepare('DELETE FROM instrutores WHERE id = :id');
        $delete->execute(['id' => $id]);
    }

    foreach ($modalityIds as $id) {
        $delete = $pdo->prepare('DELETE FROM modalidades WHERE id = :id');
        $delete->execute(['id' => $id]);
    }

    if ($actorId !== null) {
        $delete = $pdo->prepare('DELETE FROM usuarios WHERE id = :id');
        $delete->execute(['id' => $actorId]);
    }
}
