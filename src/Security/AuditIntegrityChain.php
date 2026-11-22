<?php
declare(strict_types=1);

namespace FitControl\Security;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Encadeamento SHA-256 da trilha geral de auditoria.
 *
 * O marco inicial resume os eventos existentes no momento da ativação.
 * Eventos posteriores são encadeados e gravados na mesma transação do evento.
 */
final class AuditIntegrityChain
{
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    private const JSON_FLAGS =
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_THROW_ON_ERROR;

    private function __construct()
    {
    }

    public static function canonicalEvent(array $event): string
    {
        $payload = [
            'id' => (int) $event['id'],
            'usuario_id' => $event['usuario_id'] === null ? null : (int) $event['usuario_id'],
            'acao' => (string) $event['acao'],
            'entidade' => (string) $event['entidade'],
            'entidade_id' => $event['entidade_id'] === null ? null : (int) $event['entidade_id'],
            'criado_em' => (string) $event['criado_em'],
        ];

        return json_encode($payload, self::JSON_FLAGS);
    }

    public static function hashEvent(string $previousHash, array $event): string
    {
        self::assertHash($previousHash);

        return hash('sha256', $previousHash . "\n" . self::canonicalEvent($event));
    }

    private static function assertHash(string $hash): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new RuntimeException('Hash de integridade inválido.');
        }
    }

    private static function validateTimestamp(string $value): string
    {
        $timestamp = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value,
            new \DateTimeZone('UTC')
        );
        $errors = \DateTimeImmutable::getLastErrors();

        if (
            !$timestamp instanceof \DateTimeImmutable
            || $timestamp->format('Y-m-d H:i:s') !== $value
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new RuntimeException('Instante de auditoria deve estar no formato UTC Y-m-d H:i:s.');
        }

        return $value;
    }

    /**
     * Registra o evento e seu hash atomicamente. Se já houver transação aberta,
     * participa dela; caso contrário, administra sua própria transação.
     */
    public static function append(
        PDO $pdo,
        ?int $usuarioId,
        string $acao,
        string $entidade,
        ?int $entidadeId = null,
        ?string $criadoEmUtc = null
    ): int {
        if (
            trim($acao) === ''
            || strlen($acao) > 50
            || trim($entidade) === ''
            || strlen($entidade) > 40
            || ($usuarioId !== null && $usuarioId < 1)
            || ($entidadeId !== null && $entidadeId < 1)
        ) {
            throw new RuntimeException('Dados do evento de auditoria inválidos.');
        }

        $createdAt = self::validateTimestamp($criadoEmUtc ?? gmdate('Y-m-d H:i:s'));
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $stateQuery = $pdo->query(
                'SELECT baseline_inicializado, ultimo_hash
                 FROM auditoria_integridade_estado
                 WHERE id = 1
                 FOR UPDATE'
            );
            $state = $stateQuery->fetch(PDO::FETCH_ASSOC);

            if (!$state || (int) $state['baseline_inicializado'] !== 1) {
                throw new RuntimeException(
                    'Marco inicial da integridade não foi inicializado. Execute o procedimento CLI antes de habilitar gravações.'
                );
            }

            $previousHash = (string) $state['ultimo_hash'];
            self::assertHash($previousHash);

            $insertAudit = $pdo->prepare(
                'INSERT INTO auditoria (usuario_id, acao, entidade, entidade_id, criado_em)
                 VALUES (:usuario_id, :acao, :entidade, :entidade_id, :criado_em)'
            );
            $insertAudit->execute([
                'usuario_id' => $usuarioId,
                'acao' => $acao,
                'entidade' => $entidade,
                'entidade_id' => $entidadeId,
                'criado_em' => $createdAt,
            ]);

            $eventId = (int) $pdo->lastInsertId();
            if ($eventId < 1) {
                throw new RuntimeException('Não foi possível obter o identificador do evento de auditoria.');
            }

            $event = [
                'id' => $eventId,
                'usuario_id' => $usuarioId,
                'acao' => $acao,
                'entidade' => $entidade,
                'entidade_id' => $entidadeId,
                'criado_em' => $createdAt,
            ];
            $eventHash = self::hashEvent($previousHash, $event);

            $insertChain = $pdo->prepare(
                'INSERT INTO auditoria_integridade_eventos
                    (evento_id, hash_anterior, hash_evento, criado_em)
                 VALUES (:evento_id, :hash_anterior, :hash_evento, :criado_em)'
            );
            $insertChain->execute([
                'evento_id' => $eventId,
                'hash_anterior' => $previousHash,
                'hash_evento' => $eventHash,
                'criado_em' => $createdAt,
            ]);

            $updateState = $pdo->prepare(
                'UPDATE auditoria_integridade_estado
                 SET ultimo_evento_id = :evento_id, ultimo_hash = :hash_novo
                 WHERE id = 1 AND ultimo_hash = :hash_anterior AND baseline_inicializado = 1'
            );
            $updateState->execute([
                'evento_id' => $eventId,
                'hash_novo' => $eventHash,
                'hash_anterior' => $previousHash,
            ]);

            if ($updateState->rowCount() !== 1) {
                throw new RuntimeException('O estado da cadeia de auditoria mudou inesperadamente.');
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $eventId;
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $error;
        }
    }

    /**
     * Calcula uma única vez o marco dos registros anteriores à ativação.
     * A operação é idempotente e nunca substitui um marco já inicializado.
     */
    public static function initializeBaseline(PDO $pdo): array
    {
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $state = $pdo->query(
                'SELECT baseline_inicializado, baseline_ultimo_id, baseline_hash
                 FROM auditoria_integridade_estado
                 WHERE id = 1
                 FOR UPDATE'
            )->fetch(PDO::FETCH_ASSOC);

            if (!$state) {
                throw new RuntimeException('Migration 008 não encontrada: estado de integridade ausente.');
            }

            if ((int) $state['baseline_inicializado'] === 1) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }

                return [
                    'initialized' => false,
                    'baseline_id' => (int) $state['baseline_ultimo_id'],
                    'baseline_hash' => (string) $state['baseline_hash'],
                ];
            }

            $chainCount = (int) $pdo->query(
                'SELECT COUNT(*) FROM auditoria_integridade_eventos'
            )->fetchColumn();

            if ($chainCount !== 0) {
                throw new RuntimeException('Existem eventos encadeados antes da inicialização do marco.');
            }

            $previousHash = self::GENESIS_HASH;
            $lastId = 0;
            $events = $pdo->query(
                'SELECT id, usuario_id, acao, entidade, entidade_id, criado_em
                 FROM auditoria
                 ORDER BY id ASC'
            );

            while ($event = $events->fetch(PDO::FETCH_ASSOC)) {
                $previousHash = self::hashEvent($previousHash, $event);
                $lastId = (int) $event['id'];
            }

            $update = $pdo->prepare(
                'UPDATE auditoria_integridade_estado
                 SET baseline_ultimo_id = :ultimo_id,
                     baseline_hash = :baseline_hash,
                     ultimo_evento_id = :ultimo_id_estado,
                     ultimo_hash = :ultimo_hash,
                     baseline_inicializado = 1
                 WHERE id = 1 AND baseline_inicializado = 0'
            );
            $update->execute([
                'ultimo_id' => $lastId,
                'baseline_hash' => $previousHash,
                'ultimo_id_estado' => $lastId,
                'ultimo_hash' => $previousHash,
            ]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Não foi possível confirmar o marco inicial da auditoria.');
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return [
                'initialized' => true,
                'baseline_id' => $lastId,
                'baseline_hash' => $previousHash,
            ];
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $error;
        }
    }

    /**
     * Compara o marco histórico e todos os eventos posteriores.
     *
     * @return array{ok: bool, baseline_count: int, chained_count: int, message: string}
     */
    public static function verify(PDO $pdo): array
    {
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $state = $pdo->query(
                'SELECT baseline_inicializado, baseline_ultimo_id, baseline_hash,
                        ultimo_evento_id, ultimo_hash
                 FROM auditoria_integridade_estado
                 WHERE id = 1'
            )->fetch(PDO::FETCH_ASSOC);

            if (!$state || (int) $state['baseline_inicializado'] !== 1) {
                throw new RuntimeException('Marco inicial da integridade não foi inicializado.');
            }

            $baselineId = (int) $state['baseline_ultimo_id'];
            $previousHash = self::GENESIS_HASH;
            $lastBaselineId = 0;
            $baselineCount = 0;

            $baselineQuery = $pdo->prepare(
                'SELECT id, usuario_id, acao, entidade, entidade_id, criado_em
                 FROM auditoria
                 WHERE id <= :baseline_id
                 ORDER BY id ASC'
            );
            $baselineQuery->execute(['baseline_id' => $baselineId]);

            while ($event = $baselineQuery->fetch(PDO::FETCH_ASSOC)) {
                $previousHash = self::hashEvent($previousHash, $event);
                $lastBaselineId = (int) $event['id'];
                $baselineCount++;
            }

            if ($lastBaselineId !== $baselineId || !hash_equals((string) $state['baseline_hash'], $previousHash)) {
                throw new RuntimeException('Falha de integridade no marco histórico anterior à ativação.');
            }

            $chainCount = (int) $pdo->query(
                'SELECT COUNT(*) FROM auditoria_integridade_eventos'
            )->fetchColumn();
            $untrackedCount = $pdo->prepare(
                'SELECT COUNT(*) FROM auditoria WHERE id > :baseline_id'
            );
            $untrackedCount->execute(['baseline_id' => $baselineId]);
            $auditAfterBaseline = (int) $untrackedCount->fetchColumn();

            if ($chainCount !== $auditAfterBaseline) {
                throw new RuntimeException('Quantidade de eventos posteriores difere da quantidade de hashes encadeados.');
            }

            $events = $pdo->prepare(
                'SELECT c.evento_id, c.hash_anterior, c.hash_evento,
                        a.id, a.usuario_id, a.acao, a.entidade, a.entidade_id, a.criado_em
                 FROM auditoria_integridade_eventos c
                 INNER JOIN auditoria a ON a.id = c.evento_id
                 WHERE c.evento_id > :baseline_id
                 ORDER BY c.evento_id ASC'
            );
            $events->execute(['baseline_id' => $baselineId]);

            $lastId = $baselineId;
            $chainedCount = 0;
            while ($event = $events->fetch(PDO::FETCH_ASSOC)) {
                $eventId = (int) $event['evento_id'];
                if ($eventId <= $lastId || !hash_equals($previousHash, (string) $event['hash_anterior'])) {
                    throw new RuntimeException('A sequência ou o hash anterior da cadeia foi alterado.');
                }

                $expectedHash = self::hashEvent($previousHash, $event);
                if (!hash_equals($expectedHash, (string) $event['hash_evento'])) {
                    throw new RuntimeException('Hash divergente no evento de auditoria #' . $eventId . '.');
                }

                $previousHash = $expectedHash;
                $lastId = $eventId;
                $chainedCount++;
            }

            $stateLastId = (int) $state['ultimo_evento_id'];
            if (
                $stateLastId !== $lastId
                || !hash_equals((string) $state['ultimo_hash'], $previousHash)
            ) {
                throw new RuntimeException('O último hash/identificador não corresponde ao estado da cadeia.');
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return [
                'ok' => true,
                'baseline_count' => $baselineCount,
                'chained_count' => $chainedCount,
                'message' => 'Integridade confirmada.',
            ];
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return [
                'ok' => false,
                'baseline_count' => 0,
                'chained_count' => 0,
                'message' => $error->getMessage(),
            ];
        }
    }
}
