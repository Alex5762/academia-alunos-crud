<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Recurso não encontrado.');
}

if (getenv('GITHUB_ACTIONS') !== 'true') {
    fwrite(STDERR, "Este teste cria fixtures persistentes e só pode rodar no banco efêmero do GitHub Actions.\n");
    exit(2);
}

require_once __DIR__ . '/../src/Domain/Users/UserAccountAdministrationService.php';
require_once __DIR__ . '/../src/Security/AuditIntegrityChain.php';

use FitControl\Domain\Users\UserAccountAdministrationService;
use FitControl\Security\AuditIntegrityChain;

function accountCreationConnection(): PDO
{
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $database = getenv('DB_NAME') ?: 'academia_alunos';
    $username = getenv('DB_USER') ?: 'root';
    $password = getenv('DB_PASSWORD') ?: '';

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

function assertAccountCreation(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function createAccountCreationFixture(PDO $pdo, string $name): int
{
    $email = 'fitcontrol-account-create-' . bin2hex(random_bytes(8)) . '@example.invalid';
    $insert = $pdo->prepare(
        'INSERT INTO usuarios (nome, email, senha_hash, deve_trocar_senha, ativo, perfil)
         VALUES (:name, :email, :password_hash, 0, 1, \'ADMINISTRADOR\')'
    );
    $insert->execute([
        'name' => $name,
        'email' => $email,
        'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
    ]);

    return (int) $pdo->lastInsertId();
}

function runAccountCreationWorker(array $arguments): never
{
    $actorId = filter_var($arguments[2] ?? null, FILTER_VALIDATE_INT);
    $email = (string) ($arguments[3] ?? '');
    $barrierDirectory = (string) ($arguments[4] ?? '');

    if (
        $actorId === false || $actorId < 1
        || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        || !preg_match('/^fitcontrol_account_create_[a-f0-9]{8}$/D', basename($barrierDirectory))
        || !is_dir($barrierDirectory)
    ) {
        throw new InvalidArgumentException('Argumentos inválidos para worker de criação.');
    }

    $pdo = accountCreationConnection();
    $preflight = $pdo->prepare(
        'SELECT ativo, perfil, deve_trocar_senha FROM usuarios WHERE id = :id'
    );
    $preflight->execute(['id' => $actorId]);
    $actor = $preflight->fetch();

    if (
        !$actor
        || (int) $actor['ativo'] !== 1
        || (int) $actor['deve_trocar_senha'] !== 0
        || !\FitControl\Security\AccessControl::allows((string) $actor['perfil'], 'users.manage')
    ) {
        throw new RuntimeException('A pré-autorização do worker deveria estar válida.');
    }

    file_put_contents($barrierDirectory . DIRECTORY_SEPARATOR . 'preauthorized.ready', 'ready');
    $release = $barrierDirectory . DIRECTORY_SEPARATOR . 'release';
    $deadline = hrtime(true) + 15_000_000_000;

    while (!is_file($release)) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Tempo esgotado aguardando revogação concorrente.');
        }
        usleep(10_000);
    }

    $service = new UserAccountAdministrationService($pdo);
    $result = $service->createAccount(
        (int) $actorId,
        'Conta criada por worker',
        $email,
        password_hash('Temporary-Password-2026!', PASSWORD_DEFAULT)
    );

    fwrite(STDOUT, 'RESULT=' . $result . PHP_EOL);
    exit(0);
}

if (($argv[1] ?? '') === '--worker') {
    try {
        runAccountCreationWorker($argv);
    } catch (Throwable $error) {
        fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
}

$pdo = accountCreationConnection();
$baseline = $pdo->query(
    'SELECT baseline_inicializado FROM auditoria_integridade_estado WHERE id = 1'
)->fetchColumn();
assertAccountCreation((int) $baseline === 1, 'A cadeia de auditoria precisa estar inicializada.');

$actorId = createAccountCreationFixture($pdo, 'Administrador sujeito à revogação');
$revokerId = createAccountCreationFixture($pdo, 'Administrador revogador');
$email = 'fitcontrol-race-target-' . bin2hex(random_bytes(8)) . '@example.invalid';
$barrierDirectory = sys_get_temp_dir()
    . DIRECTORY_SEPARATOR
    . 'fitcontrol_account_create_'
    . bin2hex(random_bytes(4));

assertAccountCreation(mkdir($barrierDirectory, 0700), 'Não foi possível criar a barreira temporária.');

$process = null;
$stdout = null;
$stderr = null;

try {
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        [PHP_BINARY, __FILE__, '--worker', (string) $actorId, $email, $barrierDirectory],
        $descriptors,
        $pipes,
        dirname(__DIR__)
    );
    assertAccountCreation(is_resource($process), 'Não foi possível iniciar o worker de criação.');
    fclose($pipes[0]);
    $stdout = $pipes[1];
    $stderr = $pipes[2];

    $ready = $barrierDirectory . DIRECTORY_SEPARATOR . 'preauthorized.ready';
    $deadline = hrtime(true) + 15_000_000_000;
    while (!is_file($ready)) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Worker não confirmou pré-autorização.');
        }
        usleep(10_000);
    }

    $revoker = new UserAccountAdministrationService($pdo);
    $revocation = $revoker->changeProfile($revokerId, $actorId, 'GESTOR');
    assertAccountCreation(
        $revocation === UserAccountAdministrationService::UPDATED,
        'O administrador concorrente deveria rebaixar o ator antes da criação.'
    );

    file_put_contents($barrierDirectory . DIRECTORY_SEPARATOR . 'release', 'go');
    $workerOutput = stream_get_contents($stdout);
    $workerError = stream_get_contents($stderr);
    fclose($stdout);
    fclose($stderr);
    $stdout = null;
    $stderr = null;
    $exitCode = proc_close($process);
    $process = null;

    assertAccountCreation($exitCode === 0, 'Worker de criação falhou: ' . trim((string) $workerError));
    assertAccountCreation(
        preg_match('/^RESULT=UNAUTHORIZED$/m', (string) $workerOutput) === 1,
        'A criação deve ser negada após a revogação concorrente, mesmo com pré-autorização anterior.'
    );

    $findTarget = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE email = :email');
    $findTarget->execute(['email' => $email]);
    assertAccountCreation(
        (int) $findTarget->fetchColumn() === 0,
        'A requisição com autorização revogada não pode persistir uma nova conta.'
    );

    $authorizedEmail = 'fitcontrol-authorized-create-' . bin2hex(random_bytes(8)) . '@example.invalid';
    $service = new UserAccountAdministrationService($pdo);
    $createdId = $service->createAccount(
        $revokerId,
        'Conta criada com autorização válida',
        $authorizedEmail,
        password_hash('Temporary-Password-2026!', PASSWORD_DEFAULT)
    );
    assertAccountCreation(is_int($createdId) && $createdId > 0, 'Administrador ativo deve conseguir criar conta.');

    $audit = $pdo->prepare(
        "SELECT COUNT(*) FROM auditoria
         WHERE usuario_id = :actor_id AND acao = 'usuario_criado'
           AND entidade = 'usuarios' AND entidade_id = :target_id"
    );
    $audit->execute(['actor_id' => $revokerId, 'target_id' => $createdId]);
    assertAccountCreation((int) $audit->fetchColumn() === 1, 'A criação autorizada deve gerar exatamente um evento de auditoria.');

    assertAccountCreation(
        AuditIntegrityChain::verify($pdo)['ok'],
        'A cadeia de auditoria deve permanecer íntegra após o cenário de revogação/criação.'
    );

    echo "Criação de contas: autorização revalidada sob lock, revogação concorrente nega criação, caminho autorizado e auditoria: OK." . PHP_EOL;
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        if (is_resource($stdout)) fclose($stdout);
        if (is_resource($stderr)) fclose($stderr);
        proc_close($process);
    }

    foreach (glob($barrierDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($barrierDirectory);
}
