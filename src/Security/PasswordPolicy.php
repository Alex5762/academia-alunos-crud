<?php
declare(strict_types=1);

namespace FitControl\Security;

use InvalidArgumentException;
use RuntimeException;

/**
 * Política centralizada para criação de senhas novas.
 *
 * A política usa PASSWORD_BCRYPT explicitamente para manter o limite de 72 bytes determinístico. Como bcrypt
 * considera somente os primeiros 72 bytes, novas senhas acima desse limite são
 * rejeitadas antes do hash para evitar truncamento silencioso.
 *
 * O login aplica somente o limite máximo de bytes, sem reaplicar o mínimo
 * exigido para criação e sem regravar hashes existentes.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;
    public const MAX_BYTES = 72;

    private function __construct()
    {
    }

    /**
     * Retorna uma mensagem de validação ou null quando a senha é válida.
     * O mínimo conta grafemas Unicode; o máximo é medido em bytes UTF-8.
     */
    public static function validationError(string $password): ?string
    {
        if (str_contains($password, "\0")) {
            return 'A senha contém um caractere não permitido.';
        }

        $length = preg_match_all('/\X/u', $password);
        if ($length === false) {
            return 'A senha deve conter texto UTF-8 válido.';
        }

        if ($length < self::MIN_LENGTH) {
            return 'A nova senha deve ter pelo menos 12 caracteres.';
        }

        if (strlen($password) > self::MAX_BYTES) {
            return 'A nova senha não pode ultrapassar 72 bytes. Reduza o tamanho ou use uma frase mais curta.';
        }

        return null;
    }

    /**
     * Cria um hash somente após a validação da política.
     */
    public static function hash(string $password): string
    {
        $error = self::validationError($password);
        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        if (!is_string($passwordHash) || $passwordHash === '') {
            throw new RuntimeException('Não foi possível proteger a senha.');
        }

        return $passwordHash;
    }
}
