<?php
declare(strict_types=1);

namespace FitControl\Domain\Reports;

use InvalidArgumentException;
use RuntimeException;

/**
 * Escrita de CSV UTF-8 destinado à leitura humana em planilhas.
 *
 * Campos textuais são validados como UTF-8 e neutralizados contra prefixos
 * de fórmula conhecidos. A tabulação adicionada integra o conteúdo exportado.
 */
final class ReportCsvExporter
{
    private function __construct()
    {
    }

    /**
     * @param resource $stream
     * @param list<list<string|int|null>> $rows
     */
    public static function writeRows($stream, array $rows): void
    {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('O destino do CSV precisa ser um fluxo aberto.');
        }

        foreach ($rows as $row) {
            if (!is_array($row) || !array_is_list($row)) {
                throw new InvalidArgumentException('Cada linha do CSV precisa ser uma lista ordenada.');
            }

            $fields = [];
            foreach ($row as $field) {
                if (is_string($field)) {
                    if (preg_match('//u', $field) !== 1) {
                        throw new InvalidArgumentException('O CSV contém texto que não está codificado em UTF-8 válido.');
                    }
                    $fields[] = self::neutralizeSpreadsheetFormula($field);
                } elseif (is_int($field)) {
                    $fields[] = (string) $field;
                } elseif ($field === null) {
                    $fields[] = '';
                } else {
                    throw new InvalidArgumentException('O CSV aceita somente texto, inteiros e campos vazios.');
                }
            }

            // Escape vazio desativa a extensão proprietária com barra invertida
            // e mantém o escaping CSV por duplicação das aspas.
            if (fputcsv($stream, $fields, ',', '"', '') === false) {
                throw new RuntimeException('Não foi possível escrever uma linha do CSV.');
            }
        }
    }

    public static function neutralizeSpreadsheetFormula(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('O texto precisa estar codificado em UTF-8 válido.');
        }

        $startsWithControl = preg_match('/^[\t\r\n]/', $value) === 1;
        $formulaAfterWhitespace = preg_match(
            '/^[\x00-\x20\p{Z}\x{FEFF}]*[=+\-@\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}]/u',
            $value
        ) === 1;

        return ($startsWithControl || $formulaAfterWhitespace) ? "\t" . $value : $value;
    }
}
