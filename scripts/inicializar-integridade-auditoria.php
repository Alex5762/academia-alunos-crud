<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Recurso não encontrado.');
}

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/../src/Security/AuditIntegrityChain.php';

try {
    $result = \FitControl\Security\AuditIntegrityChain::initializeBaseline($pdo);
    if ($result['initialized']) {
        fwrite(STDOUT, 'Marco inicial criado até o evento #' . $result['baseline_id'] . PHP_EOL);
    } else {
        fwrite(STDOUT, 'Marco já inicializado até o evento #' . $result['baseline_id'] . '; nenhuma alteração realizada.' . PHP_EOL);
    }

    $verification = \FitControl\Security\AuditIntegrityChain::verify($pdo);
    if (!$verification['ok']) {
        fwrite(STDERR, 'FALHA: ' . $verification['message'] . PHP_EOL);
        exit(1);
    }

    fwrite(
        STDOUT,
        'Integridade confirmada: ' . $verification['baseline_count']
        . ' evento(s) no marco histórico.' . PHP_EOL
    );
} catch (Throwable $error) {
    error_log($error->getMessage());
    fwrite(STDERR, 'Não foi possível inicializar a integridade da auditoria: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
