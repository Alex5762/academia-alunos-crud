<?php
declare(strict_types=1);

/**
 * Verifica as garantias mínimas de banco e fuso exigidas pelo Bloco 03.
 *
 * Este teste não implementa regras de matrícula nem substitui a validação
 * da versão efetivamente instalada no XAMPP.
 */

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$database = getenv('DB_NAME') ?: 'academia_alunos';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD');

if ($password === false) {
    $password = '';
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $host,
    $port,
    $database
);

$pdo = new PDO(
    $dsn,
    $username,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            sprintf(
                "%s\nEsperado: %s\nObtido: %s",
                $message,
                var_export($expected, true),
                var_export($actual, true)
            )
        );
    }
}

$server = $pdo->query(
    'SELECT VERSION() AS version, @@version_comment AS distribution'
)->fetch();

printf(
    "Banco detectado: %s (%s)\n",
    $server['version'],
    $server['distribution']
);

$studentsEngine = $pdo->query(
    "SELECT ENGINE
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'alunos'"
)->fetchColumn();

assertSameValue(
    'InnoDB',
    is_string($studentsEngine) ? $studentsEngine : null,
    'A tabela alunos precisa usar InnoDB para suportar os bloqueios transacionais previstos.'
);

echo "Engine da tabela alunos: InnoDB\n";

$academyTimezone = new DateTimeZone('America/Sao_Paulo');
$utcTimezone = new DateTimeZone('UTC');

$localInstant = new DateTimeImmutable(
    '2026-01-10 15:00:00',
    $academyTimezone
);
$utcInstant = $localInstant->setTimezone($utcTimezone);

$table = 'fitcontrol_temporal_compatibility_probe';
$pdo->exec("DROP TABLE IF EXISTS {$table}");
$pdo->exec(
    "CREATE TABLE {$table} (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        instante_utc DATETIME NOT NULL
    ) ENGINE=InnoDB"
);

try {
    $probeEngine = $pdo->query(
        "SELECT ENGINE
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = '{$table}'"
    )->fetchColumn();

    assertSameValue(
        'InnoDB',
        is_string($probeEngine) ? $probeEngine : null,
        'O mecanismo InnoDB precisa estar disponível para tabelas transacionais.'
    );

    // O valor gravado em DATETIME é uma representação UTC definida pela aplicação.
    // A mudança do fuso da sessão não deve reinterpretar nem converter esse valor.
    $pdo->exec("SET time_zone = '+03:00'");

    $insert = $pdo->prepare(
        "INSERT INTO {$table} (id, instante_utc) VALUES (:id, :instante)"
    );
    $insert->execute([
        ':id' => 1,
        ':instante' => $utcInstant->format('Y-m-d H:i:s'),
    ]);

    $pdo->exec("SET time_zone = '-05:00'");

    $storedUtc = $pdo->query(
        "SELECT instante_utc FROM {$table} WHERE id = 1"
    )->fetchColumn();

    assertSameValue(
        $utcInstant->format('Y-m-d H:i:s'),
        is_string($storedUtc) ? $storedUtc : null,
        'DATETIME deve preservar o literal UTC mesmo após a mudança do fuso da sessão.'
    );

    $restoredLocal = (new DateTimeImmutable($storedUtc, $utcTimezone))
        ->setTimezone($academyTimezone);

    assertSameValue(
        $localInstant->format('Y-m-d H:i:s'),
        $restoredLocal->format('Y-m-d H:i:s'),
        'A conversão UTC para America/Sao_Paulo deve recuperar o horário local original.'
    );

    echo "Round-trip America/Sao_Paulo -> UTC -> America/Sao_Paulo: OK\n";
    echo "DATETIME permanece estável entre fusos de sessão: OK\n";

    // Confirma que a tabela de referência aceita rollback transacional.
    $pdo->beginTransaction();
    $insert->execute([
        ':id' => 2,
        ':instante' => $utcInstant->format('Y-m-d H:i:s'),
    ]);
    $pdo->rollBack();

    $rolledBackRows = (int) $pdo->query(
        "SELECT COUNT(*) FROM {$table} WHERE id = 2"
    )->fetchColumn();

    assertSameValue(
        0,
        $rolledBackRows,
        'O InnoDB deve desfazer integralmente uma gravação após rollback.'
    );

    echo "Rollback transacional InnoDB: OK\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $pdo->exec("DROP TABLE IF EXISTS {$table}");
}

echo "Compatibilidade temporal e transacional: OK\n";
