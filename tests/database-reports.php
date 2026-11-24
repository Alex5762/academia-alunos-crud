<?php
declare(strict_types=1);

use FitControl\Domain\Reports\EnrollmentReportQuery;
use FitControl\Domain\Reports\OperationalReportQuery;
use FitControl\Domain\Reports\ReportPeriod;

require_once dirname(__DIR__) . '/src/Domain/Reports/ReportPeriod.php';
require_once dirname(__DIR__) . '/src/Domain/Reports/OperationalReportQuery.php';
require_once dirname(__DIR__) . '/src/Domain/Reports/EnrollmentReportQuery.php';

function reportsTestConnection(): PDO
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

function assertReports(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertReportsThrows(string $class, callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        if ($exception instanceof $class) {
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

function reportsLocal(string $value): DateTimeImmutable
{
    return new DateTimeImmutable(
        $value,
        new DateTimeZone(ReportPeriod::ACADEMY_TIMEZONE)
    );
}

$pdo = reportsTestConnection();
$operational = new OperationalReportQuery($pdo);
$enrollmentReport = new EnrollmentReportQuery($pdo);
$suffix = bin2hex(random_bytes(5));
$studentIds = [];

$pdo->beginTransaction();

try {
    $beforeStudents = $operational->studentCounts();

    $insertStudent = $pdo->prepare(
        'INSERT INTO alunos (nome, telefone, email, plano, ativo)
         VALUES (:name, :phone, :email, :plan, :active)'
    );

    $studentFixtures = [
        ['Active A', 'Mensal', 1],
        ['Active B', 'Semestral', 1],
        ['Inactive C', 'Anual', 0],
    ];

    foreach ($studentFixtures as $index => [$name, $plan, $active]) {
        $insertStudent->execute([
            'name' => 'Relatório ' . $name . ' ' . $suffix,
            'phone' => '219' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'email' => 'reports-' . $suffix . '-' . $index . '@example.test',
            'plan' => $plan,
            'active' => $active,
        ]);
        $studentIds[] = (int) $pdo->lastInsertId();
    }

    $afterStudents = $operational->studentCounts();
    assertReports($afterStudents['total'] - $beforeStudents['total'] === 3, 'OP-01 deve contar os três cadastros fictícios.');
    assertReports($afterStudents['active'] - $beforeStudents['active'] === 2, 'OP-02 deve contar somente os dois alunos ativos.');
    assertReports($afterStudents['inactive'] - $beforeStudents['inactive'] === 1, 'OP-03 deve contar somente o aluno inativo.');

    $insertEnrollment = $pdo->prepare(
        'INSERT INTO matriculas
            (aluno_id, plano_nome_contratado, valor_contratado, inicio_em, fim_exclusivo_em, cancelada_em, origem)
         VALUES
            (:student_id, :plan_name, :contracted_value, :start_at, :end_at, :cancelled_at, :origin)'
    );

    $fixtures = [
        // AGENDADA e com início dentro de 7 dias.
        [$studentIds[0], 'Plano A', '100.00', '2026-11-24 12:00:00', '2026-12-24 12:00:00', null, 'SISTEMA'],
        // ATIVA e com término dentro de 7 dias; valor do snapshot desconhecido.
        [$studentIds[0], 'Plano A', null, '2026-11-01 12:00:00', '2026-11-25 15:00:00', null, 'SISTEMA'],
        // ATIVA e com término em mais de 7, mas até 30 dias.
        [$studentIds[1], 'Plano B', '200.00', '2026-11-02 12:00:00', '2026-12-03 15:00:00', null, 'LEGADO'],
        // VENCIDA antes de asOf; início fora do período selecionado.
        [$studentIds[1], 'Plano antigo', '50.00', '2026-10-01 12:00:00', '2026-11-22 14:59:59', null, 'LEGADO'],
        // CANCELADA antes do início: não entra em MT-01, mas seu cancelamento entra em MT-05.
        [$studentIds[2], 'Plano futuro cancelado', null, '2026-11-28 12:00:00', '2026-12-28 12:00:00', '2026-11-27 12:00:00', 'SISTEMA'],
        // CANCELADA depois do início: conta como início efetivo e como evento de cancelamento.
        [$studentIds[2], '', null, '2026-11-05 12:00:00', '2026-12-05 12:00:00', '2026-11-10 12:00:00', 'LEGADO'],
    ];

    foreach ($fixtures as [$studentId, $plan, $value, $start, $end, $cancelled, $origin]) {
        $insertEnrollment->execute([
            'student_id' => $studentId,
            'plan_name' => $plan,
            'contracted_value' => $value,
            'start_at' => $start,
            'end_at' => $end,
            'cancelled_at' => $cancelled,
            'origin' => $origin,
        ]);
    }

    $asOf = reportsLocal('2026-11-23 12:00:00');
    $snapshot = $operational->enrollmentSnapshot($asOf);

    assertReports($snapshot['status'] === [
        'scheduled' => 1,
        'active' => 2,
        'expired' => 1,
        'cancelled' => 2,
    ], 'OP-04 deve reproduzir as quatro situações canônicas.');
    assertReports($snapshot['upcoming_starts'] === ['days_7' => 1, 'days_30' => 1], 'OP-05 deve usar janelas cumulativas de 7/30 dias.');
    assertReports($snapshot['upcoming_ends'] === ['days_7' => 1, 'days_30' => 2], 'OP-06 deve contar somente matrículas ativas por janela.');

    $period = ReportPeriod::fromInput('2026-11-01', '2026-11-30', $asOf);
    $periodReport = $enrollmentReport->forPeriod($period);

    assertReports($periodReport['starts_total'] === 4, 'MT-01 deve excluir cancelamentos anteriores ou iguais ao início.');
    assertReports($periodReport['cancellations'] === 2, 'MT-05 deve incluir cancelamentos anteriores ou iguais ao início quando o evento está no período.');

    $plans = [];
    foreach ($periodReport['starts_by_plan'] as $group) {
        $plans[$group['name']] = $group;
    }

    assertReports($plans['Plano A']['count'] === 2, 'MT-02 deve agrupar os dois inícios do Plano A.');
    assertReports($plans['Plano A']['known_values_count'] === 1, 'MT-03 deve contar somente valores contratados conhecidos.');
    assertReports($plans['Plano A']['known_value_sum'] === '100.00', 'MT-03 deve preservar DECIMAL sem float.');
    assertReports($plans['Plano B']['known_value_sum'] === '200.00', 'MT-03 deve somar o valor conhecido do Plano B.');
    assertReports(
        $plans['Plano não informado no snapshot']['count'] === 1
        && $plans['Plano não informado no snapshot']['known_values_count'] === 0
        && $plans['Plano não informado no snapshot']['known_value_sum'] === null,
        'MT-02/03 devem agrupar snapshot vazio e preservar ausência de valor.'
    );

    $origins = [];
    foreach ($periodReport['starts_by_origin'] as $group) {
        $origins[$group['origin']] = $group['count'];
    }
    ksort($origins);
    assertReports($origins === ['LEGADO' => 2, 'SISTEMA' => 2], 'MT-04 deve reconciliar a mesma coorte de MT-01.');

    // Um único intervalo inválido deve interromper ambas as consultas, sem métricas parciais.
    $insertEnrollment->execute([
        'student_id' => $studentIds[0],
        'plan_name' => 'Intervalo inválido',
        'contracted_value' => '10.00',
        'start_at' => '2026-11-15 12:00:00',
        'end_at' => '2026-11-15 12:00:00',
        'cancelled_at' => null,
        'origin' => 'SISTEMA',
    ]);

    assertReportsThrows(
        DomainException::class,
        fn () => $operational->enrollmentSnapshot($asOf),
        'OP-04 a OP-06 devem falhar quando houver qualquer intervalo inválido.'
    );
    assertReportsThrows(
        DomainException::class,
        fn () => $enrollmentReport->forPeriod($period),
        'MT-01 a MT-05 devem falhar quando houver qualquer intervalo inválido.'
    );

    echo "Relatórios operacionais e de matrículas: OK (OP-01 a OP-06, MT-01 a MT-05, janelas e integridade temporal).\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
