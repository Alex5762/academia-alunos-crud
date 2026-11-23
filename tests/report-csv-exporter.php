<?php
declare(strict_types=1);

use FitControl\Domain\Reports\ReportCsvExporter;

require_once __DIR__ . '/../src/Domain/Reports/ReportCsvExporter.php';

function csvAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$malicious = [
    '=1+1',
    '+SUM(A1:A2)',
    '-2+3',
    '@SUM(A1)',
    "\t=1+1",
    "\r=1+1",
    "\n=1+1",
    '  =1+1',
    "\u{00A0}=1+1",
    '＝1+1',
    '＋1+1',
    '－1+1',
    '＠SUM(A1)',
];

foreach ($malicious as $value) {
    csvAssert(
        str_starts_with(ReportCsvExporter::neutralizeSpreadsheetFormula($value), "\t"),
        'O iniciador de fórmula não foi neutralizado: ' . bin2hex($value)
    );
}

$ordinary = [
    'Plano Mensal',
    'São João',
    'texto com, vírgula',
    'texto com "aspas"',
    'texto normal - sem fórmula',
];
foreach ($ordinary as $value) {
    csvAssert(
        ReportCsvExporter::neutralizeSpreadsheetFormula($value) === $value,
        'Um texto comum foi alterado indevidamente.'
    );
}

$stream = fopen('php://temp', 'w+b');
if ($stream === false) {
    throw new RuntimeException('Não foi possível abrir o fluxo temporário.');
}
ReportCsvExporter::writeRows($stream, [
    ['Plano', 'Quantidade', 'Observação'],
    ['=HYPERLINK("https://example.test")', 2, 'São João, "Centro"'],
]);
rewind($stream);
$header = fgetcsv($stream, null, ',', '"', '');
$row = fgetcsv($stream, null, ',', '"', '');
fclose($stream);

csvAssert($header === ['Plano', 'Quantidade', 'Observação'], 'O cabeçalho CSV não foi preservado.');
csvAssert(is_array($row) && count($row) === 3, 'A estrutura CSV perdeu colunas.');
csvAssert($row[0] === "\t=HYPERLINK(\"https://example.test\")", 'A neutralização não foi preservada após serialização.');
csvAssert($row[1] === '2', 'O inteiro não foi serializado corretamente.');
csvAssert($row[2] === 'São João, "Centro"', 'UTF-8, vírgula ou aspas não foram escapados corretamente.');

$invalidUtf8Rejected = false;
try {
    ReportCsvExporter::neutralizeSpreadsheetFormula("\xC3\x28");
} catch (InvalidArgumentException) {
    $invalidUtf8Rejected = true;
}
csvAssert($invalidUtf8Rejected, 'Texto UTF-8 inválido deveria ser rejeitado.');

echo "Exportação CSV: escaping, UTF-8, fórmulas e estrutura: OK\n";
