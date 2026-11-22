<?php
declare(strict_types=1);

namespace FitControl\Security;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Diagnóstico somente leitura de um banco restaurado contra checkpoints HMAC externos.
 * Não repara, completa, insere ou remove registros de auditoria.
 */
final class AuditRecoveryDiagnostic
{
    /**
     * @param list<string> $checkpointPaths
     * @return array<string, mixed>
     */
    public static function diagnose(
        PDO $pdo,
        string $databaseName,
        string $secret,
        array $checkpointPaths
    ): array {
        if ($pdo->inTransaction()) {
            return [
                'status' => 'diagnostic_error',
                'message' => 'Execute o diagnóstico fora de uma transação já aberta.',
                'read_only' => true,
            ];
        }

        try {
            if (!preg_match('/^[a-fA-F0-9]{64,}$/', $secret)) {
                throw new RuntimeException('A chave HMAC deve conter pelo menos 32 bytes em hexadecimal.');
            }

            $actualDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
            if ($actualDatabase === '' || !hash_equals($databaseName, $actualDatabase)) {
                throw new RuntimeException('DB_NAME não corresponde ao banco conectado.');
            }

            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->beginTransaction();

            $state = $pdo->query(
                'SELECT baseline_inicializado, baseline_ultimo_id, baseline_hash,
                        ultimo_evento_id, ultimo_hash
                 FROM auditoria_integridade_estado
                 WHERE id = 1'
            )->fetch(PDO::FETCH_ASSOC);

            if (!$state || (int) $state['baseline_inicializado'] !== 1) {
                throw new RuntimeException('O banco restaurado não possui um marco inicial válido.');
            }

            $baselineId = (int) $state['baseline_ultimo_id'];
            $baselineHash = (string) $state['baseline_hash'];
            $currentId = (int) $state['ultimo_evento_id'];
            $currentHash = (string) $state['ultimo_hash'];

            $chain = AuditIntegrityChain::verify($pdo);
            $valid = [];
            $rejected = [];
            $ahead = [];
            $divergent = [];

            foreach (array_values(array_unique($checkpointPaths)) as $path) {
                try {
                    $signed = AuditIntegrityCheckpoint::readSignedPayload(
                        $databaseName,
                        $secret,
                        $path
                    );
                    $payload = $signed['payload'];

                    if (
                        $payload['baseline_event_id'] !== $baselineId
                        || !hash_equals($baselineHash, $payload['baseline_hash'])
                    ) {
                        $rejected[] = [
                            'file' => basename($path),
                            'reason' => 'Marco histórico diferente do banco restaurado.',
                        ];
                        continue;
                    }

                    $checkpointId = $payload['checkpoint_event_id'];
                    if ($checkpointId > $currentId) {
                        $ahead[] = [
                            'file' => basename($path),
                            'event_id' => $checkpointId,
                            'checkpoint_hash' => $payload['checkpoint_hash'],
                            'created_at_utc' => $payload['created_at_utc'],
                        ];
                        continue;
                    }

                    if ($checkpointId === $baselineId) {
                        $storedHash = $baselineHash;
                    } else {
                        $query = $pdo->prepare(
                            'SELECT hash_evento
                             FROM auditoria_integridade_eventos
                             WHERE evento_id = :event_id'
                        );
                        $query->execute(['event_id' => $checkpointId]);
                        $storedHash = $query->fetchColumn();
                    }

                    if (!is_string($storedHash) || !hash_equals($storedHash, $payload['checkpoint_hash'])) {
                        $divergent[] = [
                            'file' => basename($path),
                            'event_id' => $checkpointId,
                            'reason' => 'O evento assinado não está presente com o mesmo hash.',
                        ];
                        continue;
                    }

                    $valid[] = [
                        'file' => basename($path),
                        'event_id' => $checkpointId,
                        'checkpoint_hash' => $payload['checkpoint_hash'],
                        'created_at_utc' => $payload['created_at_utc'],
                    ];
                } catch (Throwable $error) {
                    $rejected[] = [
                        'file' => basename($path),
                        'reason' => $error->getMessage(),
                    ];
                }
            }

            usort($valid, static fn (array $a, array $b): int => $a['event_id'] <=> $b['event_id']);
            usort($ahead, static fn (array $a, array $b): int => $a['event_id'] <=> $b['event_id']);

            $latestValid = $valid !== [] ? $valid[array_key_last($valid)] : null;
            $earliestAhead = $ahead !== [] ? $ahead[0] : null;
            $latestAhead = $ahead !== [] ? $ahead[array_key_last($ahead)] : null;
            $potentialLossWindow = $earliestAhead === null ? null : [
                'restored_through_event_id' => $currentId,
                'event_id_bounds' => [
                    'exclusive_lower_bound' => $currentId,
                    'inclusive_upper_bound' => $earliestAhead['event_id'],
                ],
                'supporting_checkpoint' => $earliestAhead,
                'exact_missing_event_count' => null,
                'count_limitation' => 'Identificadores podem conter lacunas; o checkpoint assina um hash de ponta, não um inventário completo dos eventos intermediários. Não estime a quantidade de eventos perdidos a partir da diferença entre IDs.',
            ];

            $status = 'insufficient_evidence';
            $message = 'Nenhum checkpoint externo compatível foi encontrado; não é possível concluir se houve perda de eventos.';

            if (!$chain['ok']) {
                $status = 'chain_invalid';
                $message = 'A cadeia de auditoria do banco restaurado falhou na verificação. Não execute reparos automáticos.';
            } elseif ($divergent !== []) {
                $status = 'chain_divergence';
                $message = 'Um ou mais checkpoints assinados não correspondem aos hashes presentes no banco restaurado.';
            } elseif ($earliestAhead !== null) {
                $status = 'possible_older_restore';
                $message = 'Há checkpoint assinado posterior ao estado restaurado. Isso é compatível com restauração de backup antigo, mas não prova quando nem por que ocorreu.';
            } elseif ($latestValid !== null) {
                $status = 'consistent_through_checkpoint';
                $message = 'A cadeia restaurada contém o último checkpoint compatível encontrado. Eventos posteriores ao checkpoint não podem ser considerados recuperáveis sem evidência adicional.';
            }

            $report = [
                'report_version' => 2,
                'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                'read_only' => true,
                'status' => $status,
                'message' => $message,
                'database_name' => $databaseName,
                'chain_integrity' => [
                    'ok' => $chain['ok'],
                    'message' => $chain['message'],
                    'baseline_count' => $chain['baseline_count'],
                    'chained_count' => $chain['chained_count'],
                ],
                'restored_state' => [
                    'baseline_event_id' => $baselineId,
                    'baseline_hash' => $baselineHash,
                    'current_event_id' => $currentId,
                    'current_hash' => $currentHash,
                ],
                'latest_matching_checkpoint' => $latestValid,
                'earliest_checkpoint_ahead' => $earliestAhead,
                'latest_checkpoint_ahead' => $latestAhead,
                'matching_checkpoints' => $valid,
                'checkpoints_ahead_of_restore' => $ahead,
                'potential_loss_window' => $potentialLossWindow,
                'checkpoint_evidence_summary' => [
                    'matching_count' => count($valid),
                    'ahead_of_restore_count' => count($ahead),
                    'divergent_count' => count($divergent),
                    'rejected_count' => count($rejected),
                ],
                'divergent_checkpoints' => $divergent,
                'rejected_checkpoints' => $rejected,
                'limitations' => [
                    'A ausência de checkpoint posterior não prova que nenhum evento foi perdido.',
                    'A comparação depende da mesma chave HMAC e do mesmo marco histórico.',
                    'Um checkpoint posterior ao estado restaurado indica possibilidade de rollback, não sua causa nem horário.',
                    'O diagnóstico não restaura backups, não altera a auditoria e não estima eventos sem evidência externa.',
                ],
            ];

            $pdo->commit();
            return $report;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return [
                'report_version' => 1,
                'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                'read_only' => true,
                'status' => 'diagnostic_error',
                'message' => $error->getMessage(),
            ];
        }
    }
}
