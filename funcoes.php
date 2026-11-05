<?php
// A sessão mantém o token e as mensagens entre as páginas.
session_start();

if (!isset($_SESSION['token'])) {
    $_SESSION['token'] = bin2hex(random_bytes(32));
}

// Use ao exibir textos no HTML, inclusive em atributos value.
function escapar($texto) {
    return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
}

function redirecionar($pagina) {
    header('Location: ' . $pagina);
    exit;
}

function validarToken() {
    $token = $_POST['token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['token'], $token)) {
        http_response_code(403);
        exit('Formulário inválido. Volte à lista e abra o formulário novamente.');
    }
}

function validarId($valor) {
    return filter_var($valor, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 4294967295]
    ]);
}

function buscarAluno($pdo, $id) {
    $consulta = $pdo->prepare('SELECT * FROM alunos WHERE id = :id');
    $consulta->execute(['id' => $id]);
    $aluno = $consulta->fetch();

    if (!$aluno) {
        http_response_code(404);
        exit('Aluno não encontrado. Volte à lista.');
    }

    return $aluno;
}
