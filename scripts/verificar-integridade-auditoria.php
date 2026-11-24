<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Recurso não encontrado.');
}

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/../src/Security/AuditIntegrityChain.php';

$result = \FitControl\Security\AuditIntegrityChain::verify($pdo);
if (!$result['ok']) {
    fwrite(STDERR, 'FALHA DE INTEGRIDADE: ' . $result['message'] . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, $result['message'] . PHP_EOL);
fwrite(STDOUT, 'Marco histórico: ' . $result['baseline_count'] . ' evento(s).' . PHP_EOL);
fwrite(STDOUT, 'Eventos encadeados: ' . $result['chained_count'] . PHP_EOL);
