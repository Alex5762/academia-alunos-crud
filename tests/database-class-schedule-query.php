<?php
declare(strict_types=1);

use FitControl\Domain\Classes\ClassCatalogService;
use FitControl\Domain\Classes\ClassScheduleQuery;
use FitControl\Domain\Classes\ClassScheduleService;

require_once dirname(__DIR__) . '/src/Domain/Classes/ClassCatalogService.php';
require_once dirname(__DIR__) . '/src/Domain/Classes/ClassScheduleService.php';
require_once dirname(__DIR__) . '/src/Domain/Classes/ClassScheduleQuery.php';

function classScheduleQueryConnection(): PDO
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

function assertClassScheduleQuery(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectClassScheduleQueryInputFailure(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException($message);
}

$pdo = classScheduleQueryConnection();
$suffix = bin2hex(random_bytes(5));
$actorEmail = 'fitcontrol-class-query-' . $suffix . '@example.invalid';
$actor = $pdo->prepare(
    'INSERT INTO usuarios (nome, email, senha_hash, ativo)
     VALUES (:name, :email, :password_hash, 1)'
);
$actor->execute([
    'name' => 'Responsável consulta grade',
    'email' => $actorEmail,
    'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
]);
$actorId = (int) $pdo->lastInsertId();

$catalog = new ClassCatalogService($pdo);
$schedules = new ClassScheduleService($pdo);
$query = new ClassScheduleQuery($pdo);

$modalityA = $catalog->createModality($actorId, 'Consulta funcional ' . $suffix);
$modalityB = $catalog->createModality($actorId, 'Consulta yoga ' . $suffix);
$instructor = $catalog->createInstructor($actorId, 'Instrutor consulta ' . $suffix);

$scheduleA = $schedules->create($actorId, $modalityA, $instructor, 1, '08:00', '09:00');
$scheduleB = $schedules->create($actorId, $modalityB, null, 2, '10:00', '11:00');

$all = $query->activeWeeklySchedule();
assertClassScheduleQuery(count($all) >= 2, 'A consulta geral deve retornar os horários ativos criados.');
$ownSchedules = array_values(array_filter(
    $all,
    static fn (array $row): bool => in_array((int) $row['id'], [$scheduleA, $scheduleB], true)
));
assertClassScheduleQuery(count($ownSchedules) === 2, 'A grade deve incluir os dois horários de teste.');

$managementRows = $query->allSchedulesForManagement();
$managedSchedule = array_values(array_filter(
    $managementRows,
    static fn (array $row): bool => (int) $row['id'] === $scheduleA
));
assertClassScheduleQuery(
    count($managedSchedule) === 1
    && (int) $managedSchedule[0]['ativo'] === 1
    && $managedSchedule[0]['modalidade_nome'] === 'Consulta funcional ' . $suffix
    && $managedSchedule[0]['instrutor_nome'] === 'Instrutor consulta ' . $suffix,
    'A consulta administrativa deve apresentar os dados completos do horário ativo.'
);

$monday = $query->activeWeeklySchedule(1);
assertClassScheduleQuery(
    count(array_filter($monday, static fn (array $row): bool => (int) $row['id'] === $scheduleA)) === 1,
    'O filtro por segunda-feira deve incluir o horário correspondente.'
);
assertClassScheduleQuery(
    count(array_filter($monday, static fn (array $row): bool => (int) $row['id'] === $scheduleB)) === 0,
    'O filtro por segunda-feira não deve incluir o horário de terça-feira.'
);

$byModality = $query->activeWeeklySchedule(null, $modalityA);
assertClassScheduleQuery(
    count($byModality) === 1 && (int) $byModality[0]['id'] === $scheduleA,
    'O filtro por modalidade deve retornar somente seus horários.'
);

$byInstructor = $query->activeWeeklySchedule(null, null, $instructor);
assertClassScheduleQuery(
    count($byInstructor) === 1 && (int) $byInstructor[0]['id'] === $scheduleA,
    'O filtro por instrutor deve retornar somente horários atribuídos a ele.'
);
assertClassScheduleQuery(
    $byInstructor[0]['instrutor_nome'] === 'Instrutor consulta ' . $suffix,
    'A consulta deve apresentar o nome do instrutor ativo.'
);

$withoutInstructor = array_values(array_filter(
    $all,
    static fn (array $row): bool => (int) $row['id'] === $scheduleB
));
assertClassScheduleQuery(
    count($withoutInstructor) === 1 && $withoutInstructor[0]['instrutor_nome'] === null,
    'Horários sem instrutor devem continuar visíveis.'
);

expectClassScheduleQueryInputFailure(
    fn () => $query->activeWeeklySchedule(8),
    'O filtro deve rejeitar dias fora do padrão ISO.'
);
expectClassScheduleQueryInputFailure(
    fn () => $query->activeWeeklySchedule(null, 0),
    'O filtro deve rejeitar identificadores não positivos.'
);

$schedules->deactivate($scheduleA, $actorId);
$afterDeactivation = $query->activeWeeklySchedule(null, $modalityA);
assertClassScheduleQuery(
    $afterDeactivation === [],
    'Horários inativados não devem aparecer na grade pública/interna.'
);
$managementAfterDeactivation = $query->allSchedulesForManagement();
$managedInactive = array_values(array_filter(
    $managementAfterDeactivation,
    static fn (array $row): bool => (int) $row['id'] === $scheduleA
));
assertClassScheduleQuery(
    count($managedInactive) === 1 && (int) $managedInactive[0]['ativo'] === 0,
    'A consulta administrativa deve preservar a visibilidade do horário inativado.'
);

$schedules->deactivate($scheduleB, $actorId);
$catalog->deactivateModality($modalityA, $actorId);
$catalog->deactivateModality($modalityB, $actorId);
$catalog->deactivateInstructor($instructor, $actorId);

$activeModalityIds = array_column($query->activeModalities(), 'id');
$activeInstructorIds = array_column($query->activeInstructors(), 'id');
assertClassScheduleQuery(
    !in_array($modalityA, array_map('intval', $activeModalityIds), true)
    && !in_array($modalityB, array_map('intval', $activeModalityIds), true),
    'Modalidades inativas não devem aparecer nos filtros.'
);
assertClassScheduleQuery(
    !in_array($instructor, array_map('intval', $activeInstructorIds), true),
    'Instrutores inativos não devem aparecer nos filtros.'
);

echo "Consulta da grade: filtros por dia, modalidade e instrutor, horários sem instrutor e estados ativos aprovados.\n";
