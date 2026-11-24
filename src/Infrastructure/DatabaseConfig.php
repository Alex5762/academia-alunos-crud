<?php
declare(strict_types=1);

namespace FitControl\Infrastructure;

use InvalidArgumentException;

final class DatabaseConfig
{
    /**
     * @param array<string, string> $environment
     * @param array<string, string>|null $localConfig
     * @return array{host:string,port:int,database:string,username:string,password:string}
     */
    public static function fromEnvironment(array $environment, ?array $localConfig = null): array
    {
        if (array_key_exists('APP_ENV', $environment)) {
            $environmentName = $environment['APP_ENV'];

            if (!is_string($environmentName) || trim($environmentName) === '') {
                throw new InvalidArgumentException('APP_ENV deve ser local ou production.');
            }

            $environmentName = strtolower(trim($environmentName));
        } else {
            $environmentName = $localConfig !== null ? 'local' : 'production';
        }

        if (!in_array($environmentName, ['local', 'production'], true)) {
            throw new InvalidArgumentException('APP_ENV deve ser local ou production.');
        }

        if ($environmentName === 'production') {
            $requiredKeys = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'];

            foreach ($requiredKeys as $key) {
                if (
                    !array_key_exists($key, $environment) ||
                    !is_string($environment[$key]) ||
                    trim($environment[$key]) === ''
                ) {
                    throw new InvalidArgumentException(
                        "A variável {$key} deve ser definida explicitamente em production."
                    );
                }
            }

            $host = $environment['DB_HOST'];
            $port = $environment['DB_PORT'];
            $database = $environment['DB_NAME'];
            $username = $environment['DB_USER'];
            $password = $environment['DB_PASSWORD'];
        } else {
            $localConfig ??= [];

            $host = self::resolveValue($environment, 'DB_HOST', $localConfig, 'host', '127.0.0.1');
            $port = self::resolveValue($environment, 'DB_PORT', $localConfig, 'port', '3306');
            $database = self::resolveValue($environment, 'DB_NAME', $localConfig, 'database', 'academia_alunos');
            $username = self::resolveValue($environment, 'DB_USER', $localConfig, 'username', null);
            $password = self::resolveValue($environment, 'DB_PASSWORD', $localConfig, 'password', null);

            if ($username === null || trim($username) === '') {
                throw new InvalidArgumentException('DB_USER deve ser definido na configuração local.');
            }

            // Senha vazia é aceita somente no modo local e quando foi
            // declarada explicitamente no arquivo ou no ambiente.
            if ($password === null) {
                throw new InvalidArgumentException('DB_PASSWORD deve ser definido na configuração local.');
            }
        }

        if (
            trim($host) === '' ||
            trim($database) === '' ||
            trim($username) === '' ||
            str_contains($host, "\0") ||
            str_contains($database, "\0") ||
            str_contains($username, "\0")
        ) {
            throw new InvalidArgumentException('A configuração do banco contém um campo inválido.');
        }

        if (!preg_match('/\A[0-9]{1,5}\z/', $port) || (int) $port < 1 || (int) $port > 65535) {
            throw new InvalidArgumentException('DB_PORT deve ser um número entre 1 e 65535.');
        }

        if (str_contains($password, "\0")) {
            throw new InvalidArgumentException('DB_PASSWORD contém um caractere inválido.');
        }

        return [
            'host' => $host,
            'port' => (int) $port,
            'database' => $database,
            'username' => $username,
            'password' => $password
        ];
    }

    /**
     * @param array<string, string> $environment
     * @param array<string, string> $localConfig
     */
    private static function resolveValue(
        array $environment,
        string $environmentKey,
        array $localConfig,
        string $localKey,
        ?string $default
    ): ?string {
        if (array_key_exists($environmentKey, $environment)) {
            $value = $environment[$environmentKey];

            if (!is_string($value)) {
                throw new InvalidArgumentException("{$environmentKey} deve ser uma string.");
            }

            return $value;
        }

        if (array_key_exists($localKey, $localConfig)) {
            $value = $localConfig[$localKey];

            if (!is_string($value)) {
                throw new InvalidArgumentException("A configuração local {$localKey} deve ser uma string.");
            }

            return $value;
        }

        return $default;
    }
}
