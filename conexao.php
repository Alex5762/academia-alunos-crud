<?php
require_once __DIR__ . '/src/Infrastructure/DatabaseConfig.php';

$variaveisAmbiente = [];
foreach (['APP_ENV', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $chave) {
    $valor = getenv($chave);
    if ($valor !== false) {
        $variaveisAmbiente[$chave] = $valor;
    }
}

$ambienteDeclarado = $variaveisAmbiente['APP_ENV'] ?? null;
$carregarConfiguracaoLocal = $ambienteDeclarado === null
    || (is_string($ambienteDeclarado) && strtolower(trim($ambienteDeclarado)) === 'local');

$configuracaoLocal = null;

try {
    $arquivoConfiguracaoLocal = __DIR__ . '/config/database.local.php';

    if ($carregarConfiguracaoLocal && is_file($arquivoConfiguracaoLocal)) {
        $configuracaoCarregada = require $arquivoConfiguracaoLocal;

        if (!is_array($configuracaoCarregada)) {
            throw new RuntimeException('config/database.local.php deve retornar um array.');
        }

        $configuracaoLocal = $configuracaoCarregada;
    }

    $configuracaoBanco = \FitControl\Infrastructure\DatabaseConfig::fromEnvironment(
        $variaveisAmbiente,
        $configuracaoLocal
    );
} catch (Throwable $erro) {
    error_log('FitControl database configuration: ' . $erro->getMessage());
    http_response_code(500);
    exit('Configuração do banco incompleta ou inválida. Consulte README.md.');
}

try {
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $configuracaoBanco['host'],
            $configuracaoBanco['port'],
            $configuracaoBanco['database']
        ),
        $configuracaoBanco['username'],
        $configuracaoBanco['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $erro) {
    error_log($erro->getMessage());
    http_response_code(500);
    exit('Não foi possível conectar ao banco de dados. Confira o MySQL e a configuração de conexão.');
}
