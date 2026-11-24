<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/funcoes.php';

function assertUtcAcademyFormat(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

assertUtcAcademyFormat(
    formatarInstanteUtcNaAcademia('2026-11-22 12:30:00') === '22/11/2026 09:30:00',
    'O histórico UTC precisa ser convertido para America/Sao_Paulo.'
);

assertUtcAcademyFormat(
    formatarInstanteUtcNaAcademia('2026-11-22 02:15:00') === '21/11/2026 23:15:00',
    'A conversão precisa atravessar corretamente a meia-noite local.'
);

assertUtcAcademyFormat(
    formatarInstanteUtcNaAcademia(null) === 'Não informado',
    'Valores nulos precisam ter uma representação explícita.'
);

assertUtcAcademyFormat(
    formatarInstanteUtcNaAcademia('2026-02-30 12:00:00') === 'Data inválida',
    'Datas impossíveis não podem ser normalizadas silenciosamente.'
);

assertUtcAcademyFormat(
    formatarInstanteUtcNaAcademia('2026-11-22 12:30') === 'Data inválida',
    'O formato DATETIME incompleto precisa ser rejeitado.'
);

echo "Formatação de auditoria UTC/local: OK.\\n";
