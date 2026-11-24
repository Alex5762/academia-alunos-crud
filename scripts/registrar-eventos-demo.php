<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Recurso não encontrado.');
}

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/../src/Security/AuditIntegrityChain.php';

$usuarioQuery = $pdo->prepare('SELECT id FROM usuarios WHERE email = :email LIMIT 1');
$usuarioQuery->execute(['email' => 'provision-ci@example.test']);
$usuarioId = (int) $usuarioQuery->fetchColumn();

$alunoQuery = $pdo->prepare('SELECT id FROM alunos WHERE email = :email LIMIT 1');
$alunoQuery->execute(['email' => 'henrique.rocha@example.test']);
$alunoId = (int) $alunoQuery->fetchColumn();

if ($usuarioId < 1 || $alunoId < 1) {
    fwrite(STDERR, 'Não foi possível localizar os registros fictícios para as capturas.' . PHP_EOL);
    exit(1);
}

$eventos = [
    ['aluno_criado', 4],
    ['aluno_editado', 3],
    ['aluno_inativado', 2],
    ['aluno_reativado', 1],
];

foreach ($eventos as [$acao, $diasAtras]) {
    $criadoEm = (new DateTimeImmutable('-' . $diasAtras . ' days', new DateTimeZone('UTC')))
        ->format('Y-m-d H:i:s');
    \FitControl\Security\AuditIntegrityChain::append(
        $pdo,
        $usuarioId,
        $acao,
        'alunos',
        $alunoId,
        $criadoEm
    );
}

$verification = \FitControl\Security\AuditIntegrityChain::verify($pdo);
if (!$verification['ok']) {
    fwrite(STDERR, $verification['message'] . PHP_EOL);
    exit(1);
}

echo "Eventos visuais criados e encadeados: OK." . PHP_EOL;
