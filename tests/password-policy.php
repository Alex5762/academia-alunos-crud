<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Security/PasswordPolicy.php';

use FitControl\Security\PasswordPolicy;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Política mínima: 12 grafemas Unicode.
assertSameValue(null, PasswordPolicy::validationError('SenhaSegura12'), 'Senha ASCII válida foi rejeitada.');
assertTrueValue(
    PasswordPolicy::validationError('SenhaCurta1') !== null,
    'Senha com menos de 12 caracteres deveria ser rejeitada.'
);

// Bcrypt considera no máximo 72 bytes, não 72 caracteres.
$exactly72Bytes = str_repeat('a', 72);
$over72Bytes = str_repeat('a', 73);
assertSameValue(null, PasswordPolicy::validationError($exactly72Bytes), 'O limite exato de 72 bytes deveria ser aceito.');
assertTrueValue(PasswordPolicy::validationError($over72Bytes) !== null, 'Uma senha com 73 bytes deveria ser rejeitada.');

$unicodeAt72Bytes = str_repeat('á', 36); // 36 grafemas, 72 bytes em UTF-8.
$unicodeOver72Bytes = str_repeat('á', 37); // 37 grafemas, 74 bytes em UTF-8.
assertSameValue(null, PasswordPolicy::validationError($unicodeAt72Bytes), 'Unicode com exatamente 72 bytes deveria ser aceito.');
assertTrueValue(PasswordPolicy::validationError($unicodeOver72Bytes) !== null, 'Unicode acima de 72 bytes deveria ser rejeitado.');

$emojiPassword = str_repeat('🔐', 12); // 12 grafemas, 48 bytes.
assertSameValue(null, PasswordPolicy::validationError($emojiPassword), 'Senha Unicode válida deveria contar grafemas, não bytes, no mínimo.');

assertTrueValue(
    PasswordPolicy::validationError("SenhaSegura12\0") !== null,
    'Senha contendo byte NUL deveria ser rejeitada.'
);

$invalidUtf8 = "Senha" . chr(0xFF) . "Segura12";
assertTrueValue(
    PasswordPolicy::validationError($invalidUtf8) !== null,
    'UTF-8 inválido deveria ser rejeitado.'
);

$hash = PasswordPolicy::hash($exactly72Bytes);
assertTrueValue(password_verify($exactly72Bytes, $hash), 'O hash da senha válida não verificou.');
assertTrueValue(password_get_info($hash)['algoName'] !== 'unknown', 'O hash gerado não foi reconhecido pelo PHP.');

try {
    PasswordPolicy::hash($over72Bytes);
    throw new RuntimeException('PasswordPolicy::hash aceitou uma senha acima de 72 bytes.');
} catch (InvalidArgumentException) {
    // Esperado: o serviço não deve gerar hashes para entradas truncáveis pelo bcrypt.
}

echo "Política de senha: mínimo Unicode, limite bcrypt de 72 bytes, NUL, UTF-8 e hashing: OK." . PHP_EOL;
