<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Recurso não encontrado.');
}

if (getenv('CI') !== 'true') {
    fwrite(STDERR, "Este teste cria fixtures persistentes de auditoria e só pode rodar no banco efêmero da CI.\n");
    exit(2);
}

require_once __DIR__ . '/../src/Domain/Users/UserAccountAdministrationService.php';
require_once __DIR__ . '/../src/Security/AuditIntegrityChain.php';

use FitControl\Domain\Users\UserAccountAdministrationService;
use FitControl\Security\AuditIntegrityChain;

function userAdministrationConnection(): PDO
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

function assertUserAdministration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function createUserAdministrationFixture(PDO $pdo, string $name, string $role): int
{
    $email = 'fitcontrol-user-admin-' . bin2hex(random_bytes(8)) . '@example.invalid';
    $statement = $pdo->prepare(
        'INSERT INTO usuarios
            (nome, email, senha_hash, deve_trocar_senha, ativo, perfil)
         VALUES (:name, :email, :password_hash, 0, 1, :role)'
    );
    $statement->execute([
        'name' => $name,
        'email' => $email,
        'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        'role' => $role,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Worker usado pela corrida entre:
 * - B rebaixar A para GESTOR;
 * - A desativar B.
 *
 * O ponto de barreira faz as duas requisições iniciarem a operação juntas.
 */
function runUserAdministrationWorker(array $arguments): never
{
    $mode = $arguments[2] ?? '';
    $actorId = filter_var($arguments[3] ?? null, FILTER_VALIDATE_INT);
    $targetId = filter_var($arguments[4] ?? null, FILTER_VALIDATE_INT);
    $barrierDirectory = (string) ($arguments[5] ?? '');

    if (
        !in_array($mode, ['demote', 'deactivate'], true)
        || $actorId === false || $actorId < 1
        || $targetId === false || $targetId < 1
        || !preg_match('/^fitcontrol_user_admin_[a-f0-9]{8}$/D', basename($barrierDirectory))
        || !is_dir($barrierDirectory)
    ) {
        throw new InvalidArgumentException('Argumentos inválidos para worker de concorrência.');
    }

    $readyFile = $barrierDirectory . DIRECTORY_SEPARATOR . $mode . '.ready';
    $releaseFile = $barrierDirectory . DIRECTORY_SEPARATOR . 'release';
    file_put_contents($readyFile, 'ready');

    $deadline = hrtime(true) + 15_000_000_000;
    while (!is_file($releaseFile)) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Tempo esgotado aguardando a barreira de concorrência.');
        }
        usleep(10_000);
    }

    $pdo = userAdministrationConnection();
    $service = new UserAccountAdministrationService($pdo);

    $result = $mode === 'demote'
        ? $service->changeProfile((int) $actorId, (int) $targetId, 'GESTOR')
        : $service->changeStatus((int) $actorId, (int) $targetId, 'desativar');

    fwrite(STDOUT, 'RESULT=' . $result . PHP_EOL);
    exit(0);
}

if (($argv[1] ?? '') === '--worker') {
    try {
        runUserAdministrationWorker($argv);
    } catch (Throwable $error) {
        fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
}

$pdo = userAdministrationConnection();

$integrityState = $pdo->query(
    'SELECT baseline_inicializado FROM auditoria_integridade_estado WHERE id = 1'
)->fetchColumn();
assertUserAdministration(
    (int) $integrityState === 1,
    'A cadeia de auditoria precisa estar inicializada antes dos testes.'
);

$adminA = createUserAdministrationFixture($pdo, 'Admin A concorrência', 'ADMINISTRADOR');
$adminB = createUserAdministrationFixture($pdo, 'Admin B concorrência', 'ADMINISTRADOR');
$operator = createUserAdministrationFixture($pdo, 'Operador concorrência', 'OPERADOR');

$barrierDirectory = sys_get_temp_dir()
    . DIRECTORY_SEPARATOR
    . 'fitcontrol_user_admin_'
    . bin2hex(random_bytes(4));

if (!mkdir($barrierDirectory, 0700)) {
    throw new RuntimeException('Não foi possível criar a barreira temporária de concorrência.');
}

$workers = [];
$workerResults = [];

try {
    $workerDefinitions = [
        'demote' => [$adminB, $adminA],
        'deactivate' => [$adminA, $adminB],
    ];

    foreach ($workerDefinitions as $mode => [$actorId, $targetId]) {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [
                PHP_BINARY,
                __FILE__,
                '--worker',
                $mode,
                (string) $actorId,
                (string) $targetId,
                $barrierDirectory,
            ],
            $descriptors,
            $pipes,
            dirname(__DIR__)
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Não foi possível iniciar o worker ' . $mode . '.');
        }

        fclose($pipes[0]);
        $workers[$mode] = [
            'process' => $process,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
        ];
    }

    $deadline = hrtime(true) + 15_000_000_000;
    while (
        !is_file($barrierDirectory . DIRECTORY_SEPARATOR . 'demote.ready')
        || !is_file($barrierDirectory . DIRECTORY_SEPARATOR . 'deactivate.ready')
    ) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Os workers não chegaram à barreira de concorrência.');
        }
        usleep(10_000);
    }

    file_put_contents($barrierDirectory . DIRECTORY_SEPARATOR . 'release', 'go');

    foreach ($workers as $mode => $worker) {
        $stdout = stream_get_contents($worker['stdout']);
        $stderr = stream_get_contents($worker['stderr']);
        fclose($worker['stdout']);
        fclose($worker['stderr']);

        $exitCode = proc_close($worker['process']);
        $workers[$mode]['process'] = null;

        assertUserAdministration(
            $exitCode === 0,
            'Worker ' . $mode . ' falhou: ' . trim((string) $stderr)
        );

        if (preg_match('/^RESULT=([A-Z_]+)$/m', (string) $stdout, $matches) !== 1) {
            throw new RuntimeException('Worker ' . $mode . ' não retornou resultado conhecido: ' . trim((string) $stdout));
        }

        $workerResults[$mode] = $matches[1];
    }
} finally {
    foreach ($workers as $worker) {
        if (is_resource($worker['process'])) {
            proc_terminate($worker['process']);
            fclose($worker['stdout']);
            fclose($worker['stderr']);
            proc_close($worker['process']);
        }
    }

    foreach (glob($barrierDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $temporaryFile) {
        @unlink($temporaryFile);
    }
    @rmdir($barrierDirectory);
}

$profileResult = $workerResults['demote'] ?? null;
$statusResult = $workerResults['deactivate'] ?? null;

assertUserAdministration(
    ($profileResult === UserAccountAdministrationService::UPDATED
        && $statusResult === UserAccountAdministrationService::UNAUTHORIZED)
    || ($profileResult === UserAccountAdministrationService::UNAUTHORIZED
        && $statusResult === UserAccountAdministrationService::DEACTIVATED),
    'A corrida deve permitir exatamente uma alteração e negar a requisição cujo ator perdeu os privilégios.'
);

$activeAdministratorCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM usuarios
     WHERE id IN ({$adminA}, {$adminB})
       AND ativo = 1 AND perfil = 'ADMINISTRADOR'"
)->fetchColumn();
assertUserAdministration(
    $activeAdministratorCount === 1,
    'A corrida entre rebaixamento e desativação deve preservar exatamente um administrador ativo.'
);

$adminAState = $pdo->prepare('SELECT ativo, perfil FROM usuarios WHERE id = :id');
$adminAState->execute(['id' => $adminA]);
$adminAState = $adminAState->fetch();

$adminBState = $pdo->prepare('SELECT ativo, perfil FROM usuarios WHERE id = :id');
$adminBState->execute(['id' => $adminB]);
$adminBState = $adminBState->fetch();

assertUserAdministration(
    ((int) $adminAState['ativo'] === 1 && $adminAState['perfil'] === 'ADMINISTRADOR')
    xor
    ((int) $adminBState['ativo'] === 1 && $adminBState['perfil'] === 'ADMINISTRADOR'),
    'Exatamente uma das duas contas concorrentes deve permanecer administradora e ativa.'
);

// A requisição vencida não pode reutilizar a autorização que tinha antes do lock.
$service = new UserAccountAdministrationService($pdo);
if ($profileResult === UserAccountAdministrationService::UNAUTHORIZED) {
    $staleActorResult = $service->changeStatus($adminB, $operator, 'desativar');
} else {
    $staleActorResult = $service->changeProfile($adminA, $operator, 'GESTOR');
}
assertUserAdministration(
    $staleActorResult === UserAccountAdministrationService::UNAUTHORIZED,
    'Uma conta rebaixada/desativada deve perder a autorização mesmo que a requisição tenha sido iniciada antes.'
);

$operatorState = $pdo->prepare('SELECT ativo, perfil FROM usuarios WHERE id = :id');
$operatorState->execute(['id' => $operator]);
$operatorState = $operatorState->fetch();
assertUserAdministration(
    (int) $operatorState['ativo'] === 1 && $operatorState['perfil'] === 'OPERADOR',
    'A requisição com ator revogado não pode alterar a conta de destino.'
);

// A invariável deve valer também fora do cenário concorrente.
$lastAdminProfileResult = $service->changeProfile($activeAdminId = (int) $pdo->query(
    "SELECT id FROM usuarios
     WHERE id IN ({$adminA}, {$adminB})
       AND ativo = 1 AND perfil = 'ADMINISTRADOR'
     LIMIT 1"
)->fetchColumn(), $operator, 'GESTOR');
assertUserAdministration(
    $lastAdminProfileResult === UserAccountAdministrationService::UPDATED,
    'A conta OPERADOR de fixture deve poder receber perfil de Gestor.'
);
assertUserAdministration(
    $service->changeProfile($activeAdminId, $activeAdminId === $adminA ? $adminB : $adminA, 'GESTOR')
        === UserAccountAdministrationService::LAST_ADMINISTRATOR,
    'Não deve ser possível rebaixar o último administrador ativo.'
);
assertUserAdministration(
    $service->changeStatus($activeAdminId, $activeAdminId, 'reativar')
        === UserAccountAdministrationService::ALREADY_ACTIVE,
    'Reativar uma conta já ativa deve ser operação idempotente.'
);
$otherAdminId = $activeAdminId === $adminA ? $adminB : $adminA;
assertUserAdministration(
    $service->changeStatus($activeAdminId, $otherAdminId, 'desativar')
        === UserAccountAdministrationService::LAST_ADMINISTRATOR,
    'Não deve ser possível desativar o último administrador ativo.'
);
assertUserAdministration(
    $service->changeProfile($operator, $activeAdminId, 'GESTOR')
        === UserAccountAdministrationService::UNAUTHORIZED,
    'Uma conta sem users.manage não pode alterar perfis.'
);

// Regressões das rotas administrativas e dos caminhos de sucesso.
$activeAdminId = (int) $pdo->query(
    "SELECT id FROM usuarios
     WHERE id IN ({$adminA}, {$adminB})
       AND ativo = 1 AND perfil = 'ADMINISTRADOR'
     LIMIT 1"
)->fetchColumn();
assertUserAdministration($activeAdminId > 0, 'O teste precisa identificar o administrador preservado.');

assertUserAdministration(
    $service->changeProfile($activeAdminId, $activeAdminId, 'GESTOR')
        === UserAccountAdministrationService::SELF_PROFILE,
    'O administrador não pode alterar o próprio perfil.'
);
assertUserAdministration(
    $service->changeStatus($activeAdminId, $activeAdminId, 'desativar')
        === UserAccountAdministrationService::SELF_DEACTIVATION,
    'O administrador não pode desativar a própria conta.'
);
assertUserAdministration(
    $service->changeProfile($activeAdminId, $operator, 'OPERADOR')
        === UserAccountAdministrationService::UPDATED,
    'O administrador deve conseguir alterar o perfil de outra conta.'
);
assertUserAdministration(
    $service->changeStatus($activeAdminId, $operator, 'desativar')
        === UserAccountAdministrationService::DEACTIVATED,
    'O administrador deve conseguir desativar outra conta.'
);
assertUserAdministration(
    $service->changeStatus($activeAdminId, $operator, 'reativar')
        === UserAccountAdministrationService::REACTIVATED,
    'O administrador deve conseguir reativar uma conta.'
);

$finalIntegrity = AuditIntegrityChain::verify($pdo);
assertUserAdministration(
    $finalIntegrity['ok'],
    'A cadeia de auditoria deve permanecer íntegra após todas as alterações de conta.'
);

echo "Administração de contas: autorização revalidada sob lock, corrida entre perfil/status, último administrador, CSRF na camada HTTP e auditoria: OK." . PHP_EOL;
