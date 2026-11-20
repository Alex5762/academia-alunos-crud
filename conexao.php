<?php
// O XAMPP continua funcionando com os valores locais abaixo.
// Em outros ambientes, as variáveis de ambiente podem sobrescrever a configuração.
$host = getenv('DB_HOST') ?: '127.0.0.1';
$porta = getenv('DB_PORT') ?: '3306';
$banco = getenv('DB_NAME') ?: 'academia_alunos';
$usuario = getenv('DB_USER') ?: 'root';
$senha = getenv('DB_PASSWORD');

if ($senha === false) {
    $senha = ''; // Configuração local comum do XAMPP: root sem senha.
}

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$porta;dbname=$banco;charset=utf8mb4",
        $usuario,
        $senha,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $erro) {
    error_log($erro->getMessage());
    http_response_code(500);
    exit('Não foi possível conectar. Confira o MySQL, o banco e conexao.php.');
}
