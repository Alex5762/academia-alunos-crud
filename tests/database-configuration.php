<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Infrastructure/DatabaseConfig.php';

$passed = 0;
$failed = 0;

$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if (!$condition) {
        $failed++;
        fwrite(STDERR, "FALHA: {$message}" . PHP_EOL);
        return;
    }

    $passed++;
};

$assertThrows = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
        $assert(false, $message);
    } catch (\InvalidArgumentException) {
        $assert(true, $message);
    }
};

$productionEnvironment = [
    'APP_ENV' => 'production',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_NAME' => 'academia_alunos',
    'DB_USER' => 'fitcontrol_app',
    'DB_PASSWORD' => 'ci-only-password'
];

$production = \FitControl\Infrastructure\DatabaseConfig::fromEnvironment($productionEnvironment);
$assert($production['username'] === 'fitcontrol_app', 'Production deve usar o usuário informado no ambiente.');
$assert($production['password'] === 'ci-only-password', 'Production deve usar a senha informada no ambiente.');
$assert($production['port'] === 3306, 'A porta deve ser convertida para inteiro.');

$productionMissingPassword = $productionEnvironment;
unset($productionMissingPassword['DB_PASSWORD']);
$assertThrows(
    static fn() => \FitControl\Infrastructure\DatabaseConfig::fromEnvironment($productionMissingPassword),
    'Production deve rejeitar DB_PASSWORD ausente.'
);

$productionEmptyPassword = $productionEnvironment;
$productionEmptyPassword['DB_PASSWORD'] = '';
$assertThrows(
    static fn() => \FitControl\Infrastructure\DatabaseConfig::fromEnvironment($productionEmptyPassword),
    'Production deve rejeitar senha vazia.'
);

$productionMissingUser = $productionEnvironment;
unset($productionMissingUser['DB_USER']);
$assertThrows(
    static fn() => \FitControl\Infrastructure\DatabaseConfig::fromEnvironment($productionMissingUser),
    'Production deve rejeitar DB_USER ausente.'
);

$localConfig = [
    'host' => '127.0.0.1',
    'port' => '3306',
    'database' => 'academia_alunos',
    'username' => 'root',
    'password' => ''
];

$local = \FitControl\Infrastructure\DatabaseConfig::fromEnvironment([], $localConfig);
$assert($local['username'] === 'root', 'Configuração local deve preservar o usuário XAMPP informado.');
$assert($local['password'] === '', 'Senha vazia deve ser permitida somente no modo local explícito.');
$assert($local['host'] === '127.0.0.1', 'Configuração local deve aceitar host padrão do XAMPP.');

$productionOverridesLocal = \FitControl\Infrastructure\DatabaseConfig::fromEnvironment($productionEnvironment, $localConfig);
$assert(
    $productionOverridesLocal['username'] === 'fitcontrol_app',
    'Production deve ignorar credenciais do arquivo local.'
);

$localOverrides = \FitControl\Infrastructure\DatabaseConfig::fromEnvironment([
    'APP_ENV' => 'local',
    'DB_USER' => 'fitcontrol_local',
    'DB_PASSWORD' => 'senha-local'
], $localConfig);
$assert($localOverrides['username'] === 'fitcontrol_local', 'Variáveis de ambiente devem sobrescrever a configuração local.');
$assert($localOverrides['password'] === 'senha-local', 'Senha do ambiente deve sobrescrever a senha local.');

$localWithoutPassword = $localConfig;
unset($localWithoutPassword['password']);
$assertThrows(
    static fn() => \FitControl\Infrastructure\DatabaseConfig::fromEnvironment([], $localWithoutPassword),
    'Modo local deve rejeitar DB_PASSWORD não declarado.'
);

$localEnvironmentOnly = \FitControl\Infrastructure\DatabaseConfig::fromEnvironment([
    'APP_ENV' => 'local',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_NAME' => 'academia_alunos',
    'DB_USER' => 'root',
    'DB_PASSWORD' => ''
]);
$assert($localEnvironmentOnly['password'] === '', 'Modo local deve aceitar senha vazia explicitamente definida no ambiente.');

$assertThrows(
    static fn() => \FitControl\Infrastructure\DatabaseConfig::fromEnvironment([]),
    'Sem variáveis ou arquivo local, a aplicação deve assumir production e falhar de forma segura.'
);

$assertThrows(
    static fn() => \FitControl\Infrastructure\DatabaseConfig::fromEnvironment(['APP_ENV' => 'staging'], $localConfig),
    'Ambientes desconhecidos devem ser rejeitados.'
);

$assertThrows(
    static fn() => \FitControl\Infrastructure\DatabaseConfig::fromEnvironment([
        'APP_ENV' => 'production',
        'DB_HOST' => 'db.internal',
        'DB_PORT' => '0',
        'DB_NAME' => 'academia_alunos',
        'DB_USER' => 'fitcontrol_app',
        'DB_PASSWORD' => 'senha-segura'
    ]),
    'Porta fora do intervalo válido deve ser rejeitada.'
);

$assertThrows(
    static fn() => \FitControl\Infrastructure\DatabaseConfig::fromEnvironment([
        'APP_ENV' => '',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306',
        'DB_NAME' => 'academia_alunos',
        'DB_USER' => 'root',
        'DB_PASSWORD' => ''
    ], $localConfig),
    'APP_ENV vazio deve ser rejeitado em vez de ativar modo local implicitamente.'
);

echo "Configuração de banco: {$passed} verificações aprovadas; {$failed} falhas." . PHP_EOL;

exit($failed === 0 ? 0 : 1);
