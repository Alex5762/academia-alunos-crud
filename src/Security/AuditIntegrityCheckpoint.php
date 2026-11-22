<?php
declare(strict_types=1);

namespace FitControl\Security;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Checkpoint externo da cadeia de auditoria.
 *
 * O HMAC vincula o marco e o último evento a uma chave mantida fora do banco.
 * O arquivo resultante deve ser copiado para armazenamento independente.
 */
final class AuditIntegrityCheckpoint
{
    private const SCHEMA_VERSION = 1;
    private const MAX_FILE_BYTES = 32768;
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    private function __construct()
    {
    }

    /**
     * Gera um snapshot consistente e assinado do estado atual da cadeia.
     *
     * @return array{payload: array, signature: string}
     */
    public static function create(PDO $pdo, string $databaseName, string $secret): array
    {
        self::assertConfiguration($databaseName, $secret);
        self::assertConnectedDatabase($pdo, $databaseName);

        if ($pdo->inTransaction()) {
            throw new RuntimeException('Gere checkpoints fora de transações já abertas.');
        }

        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();

        try {
            $integrity = AuditIntegrityChain::verify($pdo);
            if (!$integrity['ok']) {
                throw new RuntimeException(
                    'Não é possível assinar uma cadeia inválida: ' . $integrity['message']
                );
            }

            $state = $pdo->query(
                'SELECT baseline_inicializado, baseline_ultimo_id, baseline_hash,
                        ultimo_evento_id, ultimo_hash
                 FROM auditoria_integridade_estado
                 WHERE id = 1'
            )->fetch(PDO::FETCH_ASSOC);

            if (!$state || (int) $state['baseline_inicializado'] !== 1) {
                throw new RuntimeException('O marco inicial da auditoria não foi inicializado.');
            }

            $payload = [
                'schema_version' => self::SCHEMA_VERSION,
                'database_name' => $databaseName,
                'created_at_utc' => gmdate('Y-m-d H:i:s'),
                'baseline_event_id' => (int) $state['baseline_ultimo_id'],
                'baseline_hash' => (string) $state['baseline_hash'],
                'checkpoint_event_id' => (int) $state['ultimo_evento_id'],
                'checkpoint_hash' => (string) $state['ultimo_hash'],
            ];

            self::validatePayload($payload);
            $signature = hash_hmac('sha256', self::canonicalPayload($payload), $secret);

            $pdo->commit();

            return [
                'payload' => $payload,
                'signature' => $signature,
            ];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $error;
        }
    }

    /**
     * Cria o arquivo sem sobrescrever um checkpoint existente.
     * O chamador deve fornecer um caminho fora da raiz/publicação do projeto.
     *
     * @return array{path: string, checkpoint_event_id: int, checkpoint_hash: string}
     */
    public static function export(
        PDO $pdo,
        string $databaseName,
        string $secret,
        string $outputPath
    ): array {
        $envelope = self::create($pdo, $databaseName, $secret);
        self::writeAtomically($outputPath, $envelope);

        return [
            'path' => $outputPath,
            'checkpoint_event_id' => $envelope['payload']['checkpoint_event_id'],
            'checkpoint_hash' => $envelope['payload']['checkpoint_hash'],
        ];
    }

    /**
     * Verifica assinatura, identidade, integridade atual e presença do marco
     * assinado na cadeia. Um checkpoint antigo continua válido após novos eventos.
     *
     * @return array{ok: bool, message: string, checkpoint_event_id?: int, current_event_id?: int, events_since_checkpoint?: int}
     */
    public static function verify(
        PDO $pdo,
        string $databaseName,
        string $secret,
        string $checkpointPath
    ): array {
        try {
            self::assertConfiguration($databaseName, $secret);
            self::assertConnectedDatabase($pdo, $databaseName);
            $envelope = self::readEnvelope($checkpointPath);
            $payload = $envelope['payload'];
            self::validatePayload($payload);

            if (!hash_equals($databaseName, $payload['database_name'])) {
                throw new RuntimeException('O checkpoint pertence a outro banco de dados.');
            }

            $expectedSignature = hash_hmac('sha256', self::canonicalPayload($payload), $secret);
            if (!hash_equals($expectedSignature, $envelope['signature'])) {
                throw new RuntimeException('Assinatura HMAC inválida: o arquivo foi alterado ou a chave não corresponde.');
            }

            if ($pdo->inTransaction()) {
                throw new RuntimeException('Verifique checkpoints fora de transações já abertas.');
            }

            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->beginTransaction();

            $integrity = AuditIntegrityChain::verify($pdo);
            if (!$integrity['ok']) {
                throw new RuntimeException(
                    'A cadeia atual não passou na verificação: ' . $integrity['message']
                );
            }

            $state = $pdo->query(
                'SELECT baseline_inicializado, baseline_ultimo_id, baseline_hash,
                        ultimo_evento_id, ultimo_hash
                 FROM auditoria_integridade_estado
                 WHERE id = 1'
            )->fetch(PDO::FETCH_ASSOC);

            if (!$state || (int) $state['baseline_inicializado'] !== 1) {
                throw new RuntimeException('O banco atual não possui um marco inicial válido.');
            }

            $baselineId = (int) $state['baseline_ultimo_id'];
            $baselineHash = (string) $state['baseline_hash'];
            $currentId = (int) $state['ultimo_evento_id'];
            $currentHash = (string) $state['ultimo_hash'];
            $checkpointId = $payload['checkpoint_event_id'];

            if (
                $baselineId !== $payload['baseline_event_id']
                || !hash_equals($baselineHash, $payload['baseline_hash'])
            ) {
                throw new RuntimeException('O marco histórico do banco não corresponde ao checkpoint assinado.');
            }

            if ($checkpointId < $baselineId) {
                throw new RuntimeException('O checkpoint antecede o marco histórico atual.');
            }

            if ($currentId < $checkpointId) {
                throw new RuntimeException(
                    'O banco está atrás do checkpoint assinado; possível restauração de um backup antigo.'
                );
            }

            if ($checkpointId === $baselineId) {
                if (!hash_equals($baselineHash, $payload['checkpoint_hash'])) {
                    throw new RuntimeException('O hash do checkpoint não corresponde ao marco histórico.');
                }
            } else {
                $eventQuery = $pdo->prepare(
                    'SELECT hash_evento
                     FROM auditoria_integridade_eventos
                     WHERE evento_id = :evento_id'
                );
                $eventQuery->execute(['evento_id' => $checkpointId]);
                $storedHash = $eventQuery->fetchColumn();

                if (!is_string($storedHash) || !hash_equals($storedHash, $payload['checkpoint_hash'])) {
                    throw new RuntimeException('O evento assinado não está presente com o mesmo hash na cadeia atual.');
                }
            }

            if ($currentId === $checkpointId && !hash_equals($currentHash, $payload['checkpoint_hash'])) {
                throw new RuntimeException('O hash atual diverge do checkpoint assinado.');
            }

            $sinceQuery = $pdo->prepare(
                'SELECT COUNT(*) FROM auditoria_integridade_eventos WHERE evento_id > :checkpoint_id'
            );
            $sinceQuery->execute(['checkpoint_id' => $checkpointId]);
            $eventsSince = (int) $sinceQuery->fetchColumn();

            $pdo->commit();

            return [
                'ok' => true,
                'message' => 'Checkpoint assinado válido; a cadeia atual preserva o marco externo.',
                'checkpoint_event_id' => $checkpointId,
                'current_event_id' => $currentId,
                'events_since_checkpoint' => $eventsSince,
            ];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return [
                'ok' => false,
                'message' => $error->getMessage(),
            ];
        }
    }

    /**
     * Compara dois checkpoints assinados do mesmo banco e da mesma linhagem.
     * Ambos também precisam continuar presentes na cadeia atual.
     *
     * @return array{ok: bool, message: string, older_event_id?: int, newer_event_id?: int, events_between?: int}
     */
    public static function compare(
        PDO $pdo,
        string $databaseName,
        string $secret,
        string $olderPath,
        string $newerPath
    ): array {
        try {
            $olderEnvelope = self::readEnvelope($olderPath);
            $newerEnvelope = self::readEnvelope($newerPath);
            $olderPayload = $olderEnvelope['payload'];
            $newerPayload = $newerEnvelope['payload'];

            self::validatePayload($olderPayload);
            self::validatePayload($newerPayload);
            self::assertConfiguration($databaseName, $secret);
            self::assertConnectedDatabase($pdo, $databaseName);

            if (
                !hash_equals($databaseName, $olderPayload['database_name'])
                || !hash_equals($databaseName, $newerPayload['database_name'])
            ) {
                throw new RuntimeException('Os checkpoints não pertencem ao banco conectado.');
            }

            $expectedOlderSignature = hash_hmac('sha256', self::canonicalPayload($olderPayload), $secret);
            $expectedNewerSignature = hash_hmac('sha256', self::canonicalPayload($newerPayload), $secret);
            if (
                !hash_equals($expectedOlderSignature, $olderEnvelope['signature'])
                || !hash_equals($expectedNewerSignature, $newerEnvelope['signature'])
            ) {
                throw new RuntimeException('Um dos checkpoints possui assinatura HMAC inválida.');
            }

            if (
                $olderPayload['baseline_event_id'] !== $newerPayload['baseline_event_id']
                || !hash_equals($olderPayload['baseline_hash'], $newerPayload['baseline_hash'])
            ) {
                throw new RuntimeException('Os checkpoints pertencem a marcos históricos diferentes.');
            }

            $olderId = $olderPayload['checkpoint_event_id'];
            $newerId = $newerPayload['checkpoint_event_id'];
            if ($olderId > $newerId) {
                throw new RuntimeException('A ordem dos checkpoints está invertida.');
            }

            if (
                $olderId === $newerId
                && !hash_equals($olderPayload['checkpoint_hash'], $newerPayload['checkpoint_hash'])
            ) {
                throw new RuntimeException('Mesmo identificador com hashes diferentes: checkpoints incompatíveis.');
            }

            $olderVerification = self::verify($pdo, $databaseName, $secret, $olderPath);
            if (!$olderVerification['ok']) {
                throw new RuntimeException('Checkpoint anterior inválido: ' . $olderVerification['message']);
            }

            $newerVerification = self::verify($pdo, $databaseName, $secret, $newerPath);
            if (!$newerVerification['ok']) {
                throw new RuntimeException('Checkpoint posterior inválido: ' . $newerVerification['message']);
            }

            return [
                'ok' => true,
                'message' => 'Checkpoints válidos e pertencentes à mesma cadeia.',
                'older_event_id' => $olderId,
                'newer_event_id' => $newerId,
                'events_between' => max(0, $newerId - $olderId),
            ];
        } catch (Throwable $error) {
            return [
                'ok' => false,
                'message' => $error->getMessage(),
            ];
        }
    }

    /**
     * Serialização canônica pública para permitir interoperabilidade e testes.
     * As chaves extras são rejeitadas antes desta função ser usada para verificar.
     */
    public static function canonicalPayload(array $payload): string
    {
        $canonical = [
            'schema_version' => $payload['schema_version'],
            'database_name' => $payload['database_name'],
            'created_at_utc' => $payload['created_at_utc'],
            'baseline_event_id' => $payload['baseline_event_id'],
            'baseline_hash' => $payload['baseline_hash'],
            'checkpoint_event_id' => $payload['checkpoint_event_id'],
            'checkpoint_hash' => $payload['checkpoint_hash'],
        ];

        return json_encode($canonical, self::JSON_FLAGS);
    }

    private static function assertConnectedDatabase(PDO $pdo, string $databaseName): void
    {
        $connectedDatabase = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($connectedDatabase) || !hash_equals($databaseName, $connectedDatabase)) {
            throw new RuntimeException('O nome informado não corresponde ao banco conectado.');
        }
    }

    private static function assertConfiguration(string $databaseName, string $secret): void
    {
        if (trim($databaseName) === '' || strlen($databaseName) > 128) {
            throw new RuntimeException('Nome do banco inválido para o checkpoint.');
        }

        if (strlen($secret) < 32) {
            throw new RuntimeException(
                'FITCONTROL_AUDIT_CHECKPOINT_SECRET precisa ter pelo menos 32 bytes aleatórios.'
            );
        }
    }

    private static function validatePayload(array $payload): void
    {
        $expectedKeys = [
            'schema_version',
            'database_name',
            'created_at_utc',
            'baseline_event_id',
            'baseline_hash',
            'checkpoint_event_id',
            'checkpoint_hash',
        ];

        $actualKeys = array_keys($payload);
        sort($actualKeys);
        $sortedExpected = $expectedKeys;
        sort($sortedExpected);

        if ($actualKeys !== $sortedExpected) {
            throw new RuntimeException('Estrutura do payload do checkpoint inválida.');
        }

        if ($payload['schema_version'] !== self::SCHEMA_VERSION) {
            throw new RuntimeException('Versão do checkpoint não suportada.');
        }

        if (
            !is_string($payload['database_name'])
            || trim($payload['database_name']) === ''
            || strlen($payload['database_name']) > 128
        ) {
            throw new RuntimeException('Identidade do banco inválida no checkpoint.');
        }

        if (!is_string($payload['created_at_utc']) || !self::isUtcTimestamp($payload['created_at_utc'])) {
            throw new RuntimeException('Data de criação inválida no checkpoint.');
        }

        foreach (['baseline_event_id', 'checkpoint_event_id'] as $idKey) {
            if (!is_int($payload[$idKey]) || $payload[$idKey] < 0) {
                throw new RuntimeException('Identificador inválido no checkpoint.');
            }
        }

        foreach (['baseline_hash', 'checkpoint_hash'] as $hashKey) {
            if (!is_string($payload[$hashKey]) || preg_match('/^[a-f0-9]{64}$/D', $payload[$hashKey]) !== 1) {
                throw new RuntimeException('Hash inválido no checkpoint.');
            }
        }

        if ($payload['checkpoint_event_id'] < $payload['baseline_event_id']) {
            throw new RuntimeException('O checkpoint não pode anteceder o marco histórico.');
        }
    }

    private static function isUtcTimestamp(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value,
            new \DateTimeZone('UTC')
        );
        $errors = \DateTimeImmutable::getLastErrors();

        return $date instanceof \DateTimeImmutable
            && $date->format('Y-m-d H:i:s') === $value
            && (!is_array($errors) || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }

    private static function readEnvelope(string $path): array
    {
        if ($path === '' || is_link($path) || !is_file($path)) {
            throw new RuntimeException('Arquivo de checkpoint não encontrado ou não permitido.');
        }

        $size = filesize($path);
        if (!is_int($size) || $size < 2 || $size > self::MAX_FILE_BYTES) {
            throw new RuntimeException('Tamanho do arquivo de checkpoint inválido.');
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Não foi possível ler o arquivo de checkpoint.');
        }

        try {
            $envelope = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('JSON do checkpoint inválido.', 0, $error);
        }

        if (!is_array($envelope) || !isset($envelope['payload'], $envelope['signature']) || !is_array($envelope['payload'])) {
            throw new RuntimeException('Envelope do checkpoint inválido.');
        }

        $keys = array_keys($envelope);
        sort($keys);
        if ($keys !== ['payload', 'signature']) {
            throw new RuntimeException('O envelope contém campos inesperados.');
        }

        if (!is_string($envelope['signature']) || preg_match('/^[a-f0-9]{64}$/D', $envelope['signature']) !== 1) {
            throw new RuntimeException('Assinatura do checkpoint inválida.');
        }

        return $envelope;
    }

    private static function writeAtomically(string $path, array $envelope): void
    {
        if ($path === '' || is_link($path) || file_exists($path)) {
            throw new RuntimeException('O destino já existe ou não é permitido; use um nome novo para cada checkpoint.');
        }

        $directory = realpath(dirname($path));
        if ($directory === false || !is_dir($directory)) {
            throw new RuntimeException('A pasta de destino do checkpoint não existe.');
        }

        $filename = basename($path);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            throw new RuntimeException('Nome de arquivo de checkpoint inválido.');
        }

        $target = $directory . DIRECTORY_SEPARATOR . $filename;
        if (file_exists($target) || is_link($target)) {
            throw new RuntimeException('O arquivo de destino já existe; não será sobrescrito.');
        }

        $temporary = $directory . DIRECTORY_SEPARATOR
            . '.fitcontrol-checkpoint-' . bin2hex(random_bytes(12)) . '.tmp';

        $contents = json_encode(
            $envelope,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . PHP_EOL;

        try {
            $written = file_put_contents($temporary, $contents, LOCK_EX);
            if ($written !== strlen($contents)) {
                throw new RuntimeException('Não foi possível gravar o arquivo temporário completo.');
            }

            if (PHP_OS_FAMILY !== 'Windows' && !chmod($temporary, 0600)) {
                throw new RuntimeException('Não foi possível restringir as permissões do checkpoint.');
            }

            if (!rename($temporary, $target)) {
                throw new RuntimeException('Não foi possível publicar o arquivo de checkpoint.');
            }

            if (PHP_OS_FAMILY !== 'Windows') {
                chmod($target, 0600);
            }
        } catch (Throwable $error) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw $error;
        }
    }
}
