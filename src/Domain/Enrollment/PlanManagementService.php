<?php
declare(strict_types=1);

namespace FitControl\Domain\Enrollment;


use DomainException;
use InvalidArgumentException;
use LogicException;
use PDO;
use Throwable;
use UnexpectedValueException;

require_once __DIR__ . '/../../Security/AuditIntegrityChain.php';

/**
 * Gerencia o catálogo de planos sem alterar snapshots de matrículas existentes.
 *
 * Toda alteração e respectiva auditoria são atômicas.
 * As operações de alteração/exclusão bloqueiam primeiro a linha do plano.
 */
final class PlanManagementService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Cria um rascunho. Preço/duração podem permanecer ausentes enquanto incompleto.
     */
    public function createDraft(
        int $actorUserId,
        string $name,
        ?string $description = null,
        ?int $durationValue = null,
        ?string $durationUnit = null,
        ?string $price = null
    ): int {
        $this->assertActor($actorUserId);
        $data = self::normalizeAndValidateDetails(
            $name,
            $description,
            $durationValue,
            $durationUnit,
            $price
        );

        $this->beginOwnedTransaction();

        try {
            $insert = $this->pdo->prepare(
                "INSERT INTO planos (
                    nome, descricao, duracao_valor, duracao_unidade, valor_atual, situacao
                ) VALUES (
                    :name, :description, :duration_value, :duration_unit, :price, 'RASCUNHO'
                )"
            );
            $insert->execute($data);

            $planId = (int) $this->pdo->lastInsertId();
            if ($planId < 1) {
                throw new UnexpectedValueException('O banco não retornou o ID do plano criado.');
            }

            $this->recordAudit($actorUserId, 'plano_criado', $planId);
            $this->pdo->commit();

            return $planId;
        } catch (Throwable $exception) {
            $this->rollBackIfNeeded();
            throw $exception;
        }
    }

    /**
     * Atualiza os dados do catálogo. Se o plano estiver ATIVO, os campos continuam
     * obrigatoriamente completos e válidos; o snapshot histórico não é alterado.
     */
    public function updateDetails(
        int $planId,
        int $actorUserId,
        string $name,
        ?string $description,
        ?int $durationValue,
        ?string $durationUnit,
        ?string $price
    ): void {
        $this->assertIdentifiers($planId, $actorUserId);
        $data = self::normalizeAndValidateDetails(
            $name,
            $description,
            $durationValue,
            $durationUnit,
            $price
        );

        $this->beginOwnedTransaction();

        try {
            $plan = $this->lockPlan($planId);
            $currentSituation = (string) $plan['situacao'];

            if ($currentSituation === 'ATIVO') {
                self::assertActivatable($data);
            }

            $changed = $plan['nome'] !== $data['name']
                || $plan['descricao'] !== $data['description']
                || self::nullableInt($plan['duracao_valor']) !== $data['duration_value']
                || $plan['duracao_unidade'] !== $data['duration_unit']
                || $plan['valor_atual'] !== $data['price'];

            if (!$changed) {
                $this->pdo->commit();
                return;
            }

            $update = $this->pdo->prepare(
                'UPDATE planos
                 SET nome = :name,
                     descricao = :description,
                     duracao_valor = :duration_value,
                     duracao_unidade = :duration_unit,
                     valor_atual = :price
                 WHERE id = :plan_id'
            );
            $update->execute($data + ['plan_id' => $planId]);

            $this->recordAudit($actorUserId, 'plano_editado', $planId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBackIfNeeded();
            throw $exception;
        }
    }

    /**
     * Publica ou reativa um plano somente se os dados obrigatórios forem válidos.
     */
    public function activate(int $planId, int $actorUserId): void
    {
        $this->assertIdentifiers($planId, $actorUserId);
        $this->beginOwnedTransaction();

        try {
            $plan = $this->lockPlan($planId);
            $details = [
                'name' => (string) $plan['nome'],
                'description' => $plan['descricao'] === null ? null : (string) $plan['descricao'],
                'duration_value' => self::nullableInt($plan['duracao_valor']),
                'duration_unit' => $plan['duracao_unidade'] === null
                    ? null
                    : (string) $plan['duracao_unidade'],
                'price' => $plan['valor_atual'] === null ? null : (string) $plan['valor_atual'],
            ];
            self::assertActivatable($details);

            if ($plan['situacao'] === 'ATIVO') {
                $this->pdo->commit();
                return;
            }

            $update = $this->pdo->prepare(
                "UPDATE planos SET situacao = 'ATIVO' WHERE id = :plan_id"
            );
            $update->execute(['plan_id' => $planId]);

            $this->recordAudit($actorUserId, 'plano_ativado', $planId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBackIfNeeded();
            throw $exception;
        }
    }

    /**
     * Retira um plano ativo de novas contratações; matrículas antigas são preservadas.
     */
    public function deactivate(int $planId, int $actorUserId): void
    {
        $this->assertIdentifiers($planId, $actorUserId);
        $this->beginOwnedTransaction();

        try {
            $plan = $this->lockPlan($planId);

            if ($plan['situacao'] === 'INATIVO') {
                $this->pdo->commit();
                return;
            }

            if ($plan['situacao'] !== 'ATIVO') {
                throw new DomainException('Somente planos ativos podem ser desativados.');
            }

            $update = $this->pdo->prepare(
                "UPDATE planos SET situacao = 'INATIVO' WHERE id = :plan_id"
            );
            $update->execute(['plan_id' => $planId]);

            $this->recordAudit($actorUserId, 'plano_inativado', $planId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBackIfNeeded();
            throw $exception;
        }
    }

    /**
     * Exclui fisicamente apenas quando não existe qualquer matrícula vinculada.
     * O evento de exclusão é gravado antes do COMMIT, mesmo após remover a linha.
     */
    public function deleteIfUnused(int $planId, int $actorUserId): void
    {
        $this->assertIdentifiers($planId, $actorUserId);
        $this->beginOwnedTransaction();

        try {
            $this->lockPlan($planId);

            $linkedEnrollment = $this->pdo->prepare(
                'SELECT id
                 FROM matriculas
                 WHERE plano_id = :plan_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $linkedEnrollment->execute(['plan_id' => $planId]);

            if ($linkedEnrollment->fetchColumn() !== false) {
                throw new DomainException(
                    'O plano possui matrículas vinculadas; desative-o em vez de excluí-lo.'
                );
            }

            $delete = $this->pdo->prepare('DELETE FROM planos WHERE id = :plan_id');
            $delete->execute(['plan_id' => $planId]);

            if ($delete->rowCount() !== 1) {
                throw new UnexpectedValueException('O plano deixou de existir durante a exclusão.');
            }

            $this->recordAudit($actorUserId, 'plano_excluido', $planId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBackIfNeeded();
            throw $exception;
        }
    }

    private function lockPlan(int $planId): array
    {
        $query = $this->pdo->prepare(
            'SELECT id, nome, descricao, duracao_valor, duracao_unidade, valor_atual, situacao
             FROM planos
             WHERE id = :plan_id
             FOR UPDATE'
        );
        $query->execute(['plan_id' => $planId]);
        $plan = $query->fetch();

        if (!$plan) {
            throw new DomainException('Plano não encontrado.');
        }

        return $plan;
    }

    private function recordAudit(int $actorUserId, string $action, int $planId): void
    {
        AuditIntegrityChain::append($this->pdo, $actorUserId, $action, 'planos', $planId);
    }

    private function beginOwnedTransaction(): void
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('O serviço de planos precisa controlar sua própria transação.');
        }

        $this->pdo->beginTransaction();
    }

    private function rollBackIfNeeded(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function assertActor(int $actorUserId): void
    {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException('O identificador do funcionário precisa ser positivo.');
        }
    }

    private function assertIdentifiers(int $planId, int $actorUserId): void
    {
        if ($planId < 1 || $actorUserId < 1) {
            throw new InvalidArgumentException('Os identificadores precisam ser inteiros positivos.');
        }
    }

    /**
     * @return array{name:string,description:?string,duration_value:?int,duration_unit:?string,price:?string}
     */
    private static function normalizeAndValidateDetails(
        string $name,
        ?string $description,
        ?int $durationValue,
        ?string $durationUnit,
        ?string $price
    ): array {
        $name = trim($name);
        if ($name === '' || self::unicodeLength($name) > 100) {
            throw new InvalidArgumentException('O nome do plano é obrigatório e deve ter até 100 caracteres UTF-8.');
        }

        $description = $description === null ? null : trim($description);
        if ($description === '') {
            $description = null;
        }
        if ($description !== null && self::unicodeLength($description) > 255) {
            throw new InvalidArgumentException('A descrição deve ter até 255 caracteres UTF-8.');
        }

        if (($durationValue === null) !== ($durationUnit === null)) {
            throw new InvalidArgumentException('Duração e unidade devem ser informadas em conjunto.');
        }

        if ($durationValue !== null && ($durationValue < 1 || $durationValue > 65535)) {
            throw new InvalidArgumentException('A duração precisa estar entre 1 e 65535.');
        }

        if ($durationUnit !== null && !in_array($durationUnit, ['DIA', 'SEMANA', 'MES'], true)) {
            throw new InvalidArgumentException('A unidade de duração é inválida.');
        }

        if ($price !== null && !self::isNonNegativeDecimal($price)) {
            throw new InvalidArgumentException('O preço deve usar DECIMAL(10,2) sem valores negativos.');
        }

        return [
            'name' => $name,
            'description' => $description,
            'duration_value' => $durationValue,
            'duration_unit' => $durationUnit,
            'price' => $price,
        ];
    }

    /**
     * @param array{name:string,description:?string,duration_value:?int,duration_unit:?string,price:?string} $details
     */
    private static function assertActivatable(array $details): void
    {
        if (
            trim($details['name']) === ''
            || $details['duration_value'] === null
            || $details['duration_value'] < 1
            || $details['duration_unit'] === null
            || !in_array($details['duration_unit'], ['DIA', 'SEMANA', 'MES'], true)
            || $details['price'] === null
            || !self::isPositiveDecimal($details['price'])
        ) {
            throw new DomainException(
                'Para ativar o plano, informe nome, duração válida e preço maior que zero.'
            );
        }
    }

    private static function isNonNegativeDecimal(string $price): bool
    {
        return preg_match('/^\d{1,8}\.\d{2}$/D', $price) === 1;
    }

    private static function isPositiveDecimal(string $price): bool
    {
        if (!self::isNonNegativeDecimal($price)) {
            return false;
        }

        return ltrim(str_replace('.', '', $price), '0') !== '';
    }

    private static function unicodeLength(string $value): int
    {
        $length = preg_match_all('/./us', $value);
        if ($length === false) {
            throw new InvalidArgumentException('O texto precisa ser UTF-8 válido.');
        }

        return $length;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
