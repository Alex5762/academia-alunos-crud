<?php
require_once __DIR__ . '/funcoes.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Use o formulário para salvar um aluno.');
}

validarToken();
require_once __DIR__ . '/conexao.php';

// Aceita somente textos nos campos esperados.
$dados = [];
foreach (['id', 'nome', 'telefone', 'email', 'plano'] as $campo) {
    $valor = $_POST[$campo] ?? '';
    if (!is_string($valor)) {
        http_response_code(400);
        exit('Dados inválidos. Volte à lista.');
    }
    $dados[$campo] = trim($valor);
}

$id = $dados['id'] === '' ? null : validarId($dados['id']);
if ($id === false) {
    http_response_code(400);
    exit('ID inválido. Volte à lista.');
}

if ($id !== null) {
    buscarAluno($pdo, $id); // Confere se o aluno ainda existe.
}

$erros = [];
$tamanhoNome = preg_match_all('/./us', $dados['nome']);
if ($tamanhoNome === false || $tamanhoNome < 1 || $tamanhoNome > 100) {
    $erros[] = 'Informe um nome com 1 a 100 caracteres.';
}

$tamanhoTelefone = preg_match_all('/./us', $dados['telefone']);
if ($tamanhoTelefone === false || $tamanhoTelefone < 1 || $tamanhoTelefone > 20) {
    $erros[] = 'Informe um telefone com até 20 caracteres.';
}

if (!filter_var($dados['email'], FILTER_VALIDATE_EMAIL) || strlen($dados['email']) > 150) {
    $erros[] = 'Informe um e-mail válido com até 150 caracteres.';
}

$planosPermitidos = ['Mensal', 'Trimestral', 'Semestral', 'Anual'];
if (!in_array($dados['plano'], $planosPermitidos, true)) {
    $erros[] = 'Selecione um plano válido.';
}

if (count($erros) > 0) {
    $_SESSION['erros'] = $erros;
    $_SESSION['dados'] = $dados;
    $destino = $id === null ? 'cadastro.php' : 'cadastro.php?id=' . $id;
    redirecionar($destino);
}

try {
    $parametros = [
        'nome' => $dados['nome'],
        'telefone' => $dados['telefone'],
        'email' => $dados['email'],
        'plano' => $dados['plano']
    ];

    if ($id === null) {
        $sql = 'INSERT INTO alunos (nome, telefone, email, plano)
                VALUES (:nome, :telefone, :email, :plano)';
        $mensagem = 'Aluno cadastrado com sucesso!';
    } else {
        $sql = 'UPDATE alunos SET nome = :nome, telefone = :telefone,
                email = :email, plano = :plano WHERE id = :id';
        $parametros['id'] = $id;
        $mensagem = 'Aluno atualizado com sucesso!';
    }

    $comando = $pdo->prepare($sql);
    $comando->execute($parametros);
} catch (PDOException $erro) {
    error_log($erro->getMessage());
    http_response_code(500);
    exit('Não foi possível salvar. Consulte o registro de erros do Apache.');
}

unset($_SESSION['erros'], $_SESSION['dados']);
$_SESSION['mensagem'] = $mensagem;
// Volta à lista: atualizar a página não repete o INSERT ou o UPDATE.
redirecionar('index.php');
