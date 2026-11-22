<?php
declare(strict_types=1);

use FitControl\Domain\Enrollment\PlanManagementService;

require_once dirname(__DIR__) . '/src/Domain/Enrollment/PlanManagementService.php';

function planManagementTestConnection(): PDO
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

function assertPlanManagement(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertPlanManagementThrows(
    string $exceptionClass,
    callable $operation,
    string $message
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

$pdo = planManagementTestConnection();

$existing = $pdo->prepare(
    'SELECT COUNT(*)
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME IN (\'planos\', \'matriculas\')'
);
$existing->execute();
assertPlanManagement(
    (int) $existing->fetchColumn() === 0,
    'As tabelas do fixture já existem; o teste não vai sobrescrevê-las.'
);

$actorId = null;
$actorEmail = 'fitcontrol-plan-management-' . bin2hex(random_bytes(5)) . '@example.invalid';
$planIds = [];

try {
    $pdo->exec(
        "CREATE TABLE planos (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(100) NOT NULL,
            descricao VARCHAR(255) NULL,
            duracao_valor SMALLINT UNSIGNED NULL,
            duracao_unidade ENUM('DIA','SEMANA','MES') NULL,
            valor_atual DECIMAL(10,2) NULL,
            situacao ENUM('RASCUNHO','ATIVO','INATIVO') NOT NULL DEFAULT 'RASCUNHO',
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_plan_management_status_name (situacao, nome)
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE matriculas (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            plano_id INT UNSIGNED NULL,
            plano_nome_contratado VARCHAR(100) NULL,
            valor_contratado DECIMAL(10,2) NULL,
            KEY idx_plan_management_plan (plano_id),
            CONSTRAINT fk_plan_management_plan
                FOREIGN KEY (plano_id) REFERENCES planos (id)
                ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB"
    );

    $user = $pdo->prepare(
        'INSERT INTO usuarios (nome, email, senha_hash, ativo)
         VALUES (:name, :email, :password_hash, 1)'
    );
    $user->execute([
        'name' => 'Funcionário teste de planos',
        'email' => $actorEmail,
        'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
    ]);
    $actorId = (int) $pdo->lastInsertId();

    $service = new PlanManagementService($pdo);

    // 1. Rascunho incompleto é permitido; ativação sem preço/duração é bloqueada.
    $draftId = $service->createDraft(
        $actorId,
        'Plano em preparação',
        'Descrição preliminar'
    );
    $planIds[] = $draftId;

    $draft = $pdo->query('SELECT situacao, valor_atual, duracao_valor FROM planos WHERE id = ' . $draftId)->fetch();
    assertPlanManagement($draft['situacao'] === 'RASCUNHO', 'Novo plano deve nascer como RASCUNHO.');
    assertPlanManagement($draft['valor_atual'] === null && $draft['duracao_valor'] === null, 'Rascunho pode manter preço e duração nulos.');

    assertPlanManagementThrows(
        DomainException::class,
        fn () => $service->activate($draftId, $actorId),
        'Rascunho incompleto não pode ser ativado'
    );

    // Preço zero pode ser guardado durante preparação, mas nunca publicado.
    $service->updateDetails($draftId, $actorId, 'Plano em preparação', null, 1, 'MES', '0.00');
    assertPlanManagementThrows(
        DomainException::class,
        fn () => $service->activate($draftId, $actorId),
        'Plano com preço zero não pode ser ativado'
    );

    // Completar dados permite ativar.
    $service->updateDetails($draftId, $actorId, 'Plano Mensal', 'Acesso mensal', 1, 'MES', '149.90');
    $service->activate($draftId, $actorId);

    $active = $pdo->query('SELECT situacao, valor_atual FROM planos WHERE id = ' . $draftId)->fetch();
    assertPlanManagement($active['situacao'] === 'ATIVO', 'Plano completo deve ficar ATIVO.');
    assertPlanManagement($active['valor_atual'] === '149.90', 'Preço DECIMAL deve ser preservado exatamente.');

    // Edição de plano ativo com valor válido atualiza somente catálogo, não snapshots.
    $insertHistorical = $pdo->prepare(
        'INSERT INTO matriculas (plano_id, plano_nome_contratado, valor_contratado)
         VALUES (:plan_id, :name, :price)'
    );
    $insertHistorical->execute([
        'plan_id' => $draftId,
        'name' => 'Plano Mensal',
        'price' => '149.90',
    ]);

    $service->updateDetails($draftId, $actorId, 'Plano Mensal Plus', 'Nova versão comercial', 1, 'MES', '179.90');
    $snapshot = $pdo->query(
        'SELECT plano_nome_contratado, valor_contratado FROM matriculas WHERE plano_id = ' . $draftId
    )->fetch();

    assertPlanManagement($snapshot['plano_nome_contratado'] === 'Plano Mensal', 'Editar catálogo não pode alterar nome contratado histórico.');
    assertPlanManagement($snapshot['valor_contratado'] === '149.90', 'Editar catálogo não pode alterar preço contratado histórico.');

    // Chamada idêntica não gera auditoria duplicada.
    $auditBeforeNoop = (int) $pdo->query(
        "SELECT COUNT(*) FROM auditoria
         WHERE usuario_id = {$actorId} AND acao = 'plano_editado' AND entidade_id = {$draftId}"
    )->fetchColumn();

    $service->updateDetails($draftId, $actorId, 'Plano Mensal Plus', 'Nova versão comercial', 1, 'MES', '179.90');

    $auditAfterNoop = (int) $pdo->query(
        "SELECT COUNT(*) FROM auditoria
         WHERE usuario_id = {$actorId} AND acao = 'plano_editado' AND entidade_id = {$draftId}"
    )->fetchColumn();

    assertPlanManagement($auditAfterNoop === $auditBeforeNoop, 'Edição sem alteração não deve gerar auditoria duplicada.');

    // Plano com matrícula não pode ser excluído, mas pode ser desativado e reativado.
    assertPlanManagementThrows(
        DomainException::class,
        fn () => $service->deleteIfUnused($draftId, $actorId),
        'Plano com histórico vinculado não pode ser excluído'
    );

    $service->deactivate($draftId, $actorId);
    $situation = $pdo->query('SELECT situacao FROM planos WHERE id = ' . $draftId)->fetchColumn();
    assertPlanManagement($situation === 'INATIVO', 'Plano ATIVO deve poder ser desativado.');

    $service->activate($draftId, $actorId);
    $situation = $pdo->query('SELECT situacao FROM planos WHERE id = ' . $draftId)->fetchColumn();
    assertPlanManagement($situation === 'ATIVO', 'Plano INATIVO válido deve poder ser reativado.');

    // Plano sem vínculos pode ser excluído, com evento de auditoria preservado.
    $unusedId = $service->createDraft($actorId, 'Rascunho descartável');
    $planIds[] = $unusedId;

    $service->deleteIfUnused($unusedId, $actorId);

    assertPlanManagement(
        (int) $pdo->query('SELECT COUNT(*) FROM planos WHERE id = ' . $unusedId)->fetchColumn() === 0,
        'Plano sem vínculos deve ser excluído fisicamente.'
    );
    assertPlanManagement(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM auditoria
             WHERE usuario_id = {$actorId}
               AND acao = 'plano_excluido'
               AND entidade = 'planos'
               AND entidade_id = {$unusedId}"
        )->fetchColumn() === 1,
        'Exclusão deve registrar auditoria antes do COMMIT.'
    );

    // Auditoria inválida após INSERT deve desfazer a criação do plano.
    $beforeInvalidActor = (int) $pdo->query('SELECT COUNT(*) FROM planos')->fetchColumn();
    assertPlanManagementThrows(
        PDOException::class,
        fn () => $service->createDraft(4_000_000_000, 'Plano sem auditoria'),
        'Falha de auditoria deve provocar rollback'
    );
    $afterInvalidActor = (int) $pdo->query('SELECT COUNT(*) FROM planos')->fetchColumn();

    assertPlanManagement($afterInvalidActor === $beforeInvalidActor, 'Falha da auditoria não pode deixar plano parcial.');

    // Validação de preço e pareamento da duração também ocorre antes de gravar.
    assertPlanManagementThrows(
        InvalidArgumentException::class,
        fn () => $service->createDraft($actorId, 'Plano inválido', null, 1, null, '20.00'),
        'Duração e unidade precisam ser informadas em conjunto'
    );
    assertPlanManagementThrows(
        InvalidArgumentException::class,
        fn () => $service->createDraft($actorId, 'Plano inválido', null, null, null, '-10.00'),
        'Preço negativo deve ser rejeitado'
    );

    $auditCounts = $pdo->query(
        "SELECT acao, COUNT(*) AS total
         FROM auditoria
         WHERE usuario_id = {$actorId}
           AND entidade = 'planos'
         GROUP BY acao
         ORDER BY acao"
    )->fetchAll();

    printf(
        "Gerenciamento de planos: OK; rascunho, edição, ativação, inativação, reativação, exclusão protegida, snapshots e auditoria validados.\n"
    );
} finally {
    if ($actorId !== null) {
        $deleteAudits = $pdo->prepare(
            "DELETE FROM auditoria
             WHERE usuario_id = :user_id
               AND entidade = 'planos'"
        );
        $deleteAudits->execute(['user_id' => $actorId]);
    }

    if ($planIds !== []) {
        $placeholders = implode(',', array_fill(0, count($planIds), '?'));
        $deleteSnapshots = $pdo->prepare(
            "DELETE FROM matriculas WHERE plano_id IN ({$placeholders})"
        );
        $deleteSnapshots->execute($planIds);

        $deletePlans = $pdo->prepare(
            "DELETE FROM planos WHERE id IN ({$placeholders})"
        );
        $deletePlans->execute($planIds);
    }

    if ($actorId !== null) {
        $deleteUser = $pdo->prepare('DELETE FROM usuarios WHERE id = :id');
        $deleteUser->execute(['id' => $actorId]);
    }

    $pdo->exec('DROP TABLE IF EXISTS matriculas');
    $pdo->exec('DROP TABLE IF EXISTS planos');
}
