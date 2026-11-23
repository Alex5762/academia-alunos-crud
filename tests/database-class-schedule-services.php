<?php
declare(strict_types=1);

use FitControl\Domain\Classes\ClassCatalogService;
use FitControl\Domain\Classes\ClassScheduleService;

require_once dirname(__DIR__) . '/src/Domain/Classes/ClassCatalogService.php';
require_once dirname(__DIR__) . '/src/Domain/Classes/ClassScheduleService.php';

function classServicesConnection(): PDO
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

function assertClassService(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectClassDomainFailure(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (DomainException) {
        return;
    }

    throw new RuntimeException($message);
}

function expectClassInputFailure(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException($message);
}

function expectClassDatabaseFailure(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (PDOException) {
        return;
    }

    throw new RuntimeException($message);
}

$pdo = classServicesConnection();
$schema = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
assertClassService($schema !== '', 'O teste precisa de um banco selecionado.');

$actorEmail = 'fitcontrol-class-service-' . bin2hex(random_bytes(8)) . '@example.invalid';
$insertActor = $pdo->prepare(
    'INSERT INTO usuarios (nome, email, senha_hash, ativo)
     VALUES (:name, :email, :password_hash, 1)'
);
$insertActor->execute([
    'name' => 'Responsável de teste do Bloco 06',
    'email' => $actorEmail,
    'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
]);
$actorId = (int) $pdo->lastInsertId();

$catalog = new ClassCatalogService($pdo);
$schedules = new ClassScheduleService($pdo);
$suffix = bin2hex(random_bytes(5));

$modalityA = $catalog->createModality(
    $actorId,
    'Funcional ' . $suffix,
    'Treinamento funcional'
);
$modalityB = $catalog->createModality(
    $actorId,
    'Pilates ' . $suffix,
    null
);

$catalog->updateModality(
    $modalityA,
    $actorId,
    'Funcional atualizado ' . $suffix,
    'Descrição atualizada'
);

$instructorWithoutAccount = $catalog->createInstructor(
    $actorId,
    'Instrutor independente ' . $suffix,
    null,
    '(21) 99999-0000',
    null
);
$instructorWithAccount = $catalog->createInstructor(
    $actorId,
    'Instrutor com conta ' . $suffix,
    'instrutor-' . $suffix . '@example.invalid',
    null,
    $actorId
);

$catalog->updateInstructor(
    $instructorWithoutAccount,
    $actorId,
    'Instrutor independente atualizado ' . $suffix,
    'instrutor-sem-conta-' . $suffix . '@example.invalid',
    '(21) 98888-0000',
    null
);

expectClassDatabaseFailure(
    fn () => $catalog->createInstructor(
        $actorId,
        'Vínculo duplicado ' . $suffix,
        null,
        null,
        $actorId
    ),
    'Uma conta não pode ser vinculada a mais de um instrutor.'
);

$scheduleA = $schedules->create(
    $actorId,
    $modalityA,
    $instructorWithAccount,
    1,
    '09:00',
    '10:00'
);

// Aulas adjacentes são permitidas: o intervalo final não conflita com o próximo início.
$scheduleB = $schedules->create(
    $actorId,
    $modalityB,
    $instructorWithAccount,
    1,
    '10:00',
    '11:00'
);

$schedules->update(
    $scheduleB,
    $actorId,
    $modalityB,
    $instructorWithAccount,
    2,
    '10:00',
    '11:00'
);

expectClassDomainFailure(
    fn () => $schedules->create(
        $actorId,
        $modalityB,
        $instructorWithAccount,
        1,
        '09:30',
        '10:30'
    ),
    'O mesmo instrutor não pode ter aulas sobrepostas no mesmo dia.'
);

expectClassInputFailure(
    fn () => $schedules->create(
        $actorId,
        $modalityB,
        null,
        2,
        '10:00',
        '10:00'
    ),
    'Horário final igual ao inicial deve ser rejeitado.'
);

expectClassInputFailure(
    fn () => $schedules->create(
        $actorId,
        $modalityB,
        null,
        8,
        '10:00',
        '11:00'
    ),
    'Dia da semana fora do padrão ISO deve ser rejeitado.'
);

expectClassDomainFailure(
    fn () => $catalog->deactivateModality($modalityA, $actorId),
    'Uma modalidade com horário ativo não pode ser inativada.'
);

expectClassDomainFailure(
    fn () => $catalog->deactivateInstructor($instructorWithAccount, $actorId),
    'Um instrutor com horário ativo não pode ser inativado.'
);

// Alteração de horário também precisa respeitar conflitos com outras aulas.
expectClassDomainFailure(
    fn () => $schedules->update(
        $scheduleB,
        $actorId,
        $modalityB,
        $instructorWithAccount,
        1,
        '09:30',
        '10:30'
    ),
    'A edição não pode criar sobreposição de horários do instrutor.'
);

$schedules->deactivate($scheduleA, $actorId);
$schedules->deactivate($scheduleB, $actorId);
$catalog->deactivateModality($modalityA, $actorId);
$catalog->deactivateInstructor($instructorWithAccount, $actorId);

// A inativação preserva as linhas e seus vínculos para consulta histórica.
$preserved = $pdo->prepare(
    'SELECT COUNT(*) FROM horarios_aula
     WHERE id IN (:first_id, :second_id) AND ativo = 0'
);
$preserved->execute(['first_id' => $scheduleA, 'second_id' => $scheduleB]);
assertClassService(
    (int) $preserved->fetchColumn() === 2,
    'Os horários inativados devem permanecer preservados no banco.'
);

$modalityStatus = $pdo->prepare('SELECT ativo FROM modalidades WHERE id = :id');
$modalityStatus->execute(['id' => $modalityA]);
assertClassService((int) $modalityStatus->fetchColumn() === 0, 'A modalidade deveria ficar inativa.');

$instructorStatus = $pdo->prepare('SELECT ativo FROM instrutores WHERE id = :id');
$instructorStatus->execute(['id' => $instructorWithAccount]);
assertClassService((int) $instructorStatus->fetchColumn() === 0, 'O instrutor deveria ficar inativo.');

$instructorWithoutAccountQuery = $pdo->prepare(
    'SELECT usuario_id FROM instrutores WHERE id = :id'
);
$instructorWithoutAccountQuery->execute(['id' => $instructorWithoutAccount]);
assertClassService(
    $instructorWithoutAccountQuery->fetchColumn() === null,
    'O cadastro de instrutor independente deve aceitar ausência de conta.'
);

// Cada mutação confirmada precisa possuir seu correspondente elo de integridade.
$auditQuery = $pdo->prepare(
    'SELECT COUNT(*)
     FROM auditoria a
     INNER JOIN auditoria_integridade_eventos e ON e.evento_id = a.id
     WHERE a.usuario_id = :actor_id
       AND (
           (a.entidade = \'modalidades\' AND a.entidade_id IN (:modality_a, :modality_b))
           OR (a.entidade = \'instrutores\' AND a.entidade_id IN (:instructor_a, :instructor_b))
           OR (a.entidade = \'horarios_aula\' AND a.entidade_id IN (:schedule_a, :schedule_b))
       )'
);
$auditQuery->execute([
    'actor_id' => $actorId,
    'modality_a' => $modalityA,
    'modality_b' => $modalityB,
    'instructor_a' => $instructorWithoutAccount,
    'instructor_b' => $instructorWithAccount,
    'schedule_a' => $scheduleA,
    'schedule_b' => $scheduleB,
]);
assertClassService(
    (int) $auditQuery->fetchColumn() === 13,
    'As treze mutações confirmadas precisam estar encadeadas na auditoria.'
);

echo "Serviços do Bloco 06: catálogos, vínculo opcional, conflitos, inativação, preservação e auditoria aprovados.\n";
