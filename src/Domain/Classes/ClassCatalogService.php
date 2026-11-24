<?php
declare(strict_types=1);

namespace FitControl\Domain\Classes;

use DomainException;
use InvalidArgumentException;
use LogicException;
use PDO;
use Throwable;

require_once __DIR__ . '/../../Security/AuditIntegrityChain.php';

/**
 * Catálogos de modalidades e instrutores.
 *
 * Exclusões são lógicas: o histórico e os vínculos permanecem no banco.
 * Toda mutação e seu evento de auditoria pertencem à mesma transação.
 */
final class ClassCatalogService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createModality(int $actorUserId, string $name, ?string $description = null): int
    {
        $this->assertActor($actorUserId);
        $data = self::normalizeModality($name, $description);
        $this->beginOwnedTransaction();

        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO modalidades (nome, descricao) VALUES (:name, :description)'
            );
            $insert->execute($data);
            $id = (int) $this->pdo->lastInsertId();
            if ($id < 1) {
                throw new DomainException('O banco não retornou o identificador da modalidade.');
            }

            $this->audit($actorUserId, 'modalidade_criada', 'modalidades', $id);
            $this->pdo->commit();
            return $id;
        } catch (Throwable $exception) {
            $this->rollbackIfNeeded();
            throw $exception;
        }
    }

    public function updateModality(
        int $modalityId,
        int $actorUserId,
        string $name,
        ?string $description = null
    ): void {
        $this->assertIdentifiers($modalityId, $actorUserId);
        $data = self::normalizeModality($name, $description);
        $this->beginOwnedTransaction();

        try {
            $current = $this->lockRow(
                'SELECT id, nome, descricao, ativo FROM modalidades WHERE id = :id FOR UPDATE',
                $modalityId,
                'Modalidade não encontrada.'
            );

            if (
                (string) $current['nome'] === $data['name']
                && $current['descricao'] === $data['description']
            ) {
                $this->pdo->commit();
                return;
            }

            $update = $this->pdo->prepare(
                'UPDATE modalidades SET nome = :name, descricao = :description WHERE id = :id'
            );
            $update->execute($data + ['id' => $modalityId]);
            $this->audit($actorUserId, 'modalidade_editada', 'modalidades', $modalityId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollbackIfNeeded();
            throw $exception;
        }
    }

    public function deactivateModality(int $modalityId, int $actorUserId): void
    {
        $this->assertIdentifiers($modalityId, $actorUserId);
        $this->beginOwnedTransaction();

        try {
            $modality = $this->lockRow(
                'SELECT id, ativo FROM modalidades WHERE id = :id FOR UPDATE',
                $modalityId,
                'Modalidade não encontrada.'
            );

            if ((int) $modality['ativo'] === 0) {
                $this->pdo->commit();
                return;
            }

            $activeSchedule = $this->pdo->prepare(
                'SELECT id FROM horarios_aula
                 WHERE modalidade_id = :id AND ativo = 1
                 LIMIT 1 FOR UPDATE'
            );
            $activeSchedule->execute(['id' => $modalityId]);
            if ($activeSchedule->fetchColumn() !== false) {
                throw new DomainException(
                    'Inative primeiro os horários ativos desta modalidade.'
                );
            }

            $update = $this->pdo->prepare('UPDATE modalidades SET ativo = 0 WHERE id = :id');
            $update->execute(['id' => $modalityId]);
            $this->audit($actorUserId, 'modalidade_inativada', 'modalidades', $modalityId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollbackIfNeeded();
            throw $exception;
        }
    }

    public function createInstructor(
        int $actorUserId,
        string $name,
        ?string $email = null,
        ?string $phone = null,
        ?int $userId = null
    ): int {
        $this->assertActor($actorUserId);
        $data = self::normalizeInstructor($name, $email, $phone, $userId);
        $this->beginOwnedTransaction();

        try {
            $this->assertLinkedUserAvailable($data['user_id']);
            $insert = $this->pdo->prepare(
                'INSERT INTO instrutores (nome, email, telefone, usuario_id)
                 VALUES (:name, :email, :phone, :user_id)'
            );
            $insert->execute($data);
            $id = (int) $this->pdo->lastInsertId();
            if ($id < 1) {
                throw new DomainException('O banco não retornou o identificador do instrutor.');
            }

            $this->audit($actorUserId, 'instrutor_criado', 'instrutores', $id);
            $this->pdo->commit();
            return $id;
        } catch (Throwable $exception) {
            $this->rollbackIfNeeded();
            throw $exception;
        }
    }

    public function updateInstructor(
        int $instructorId,
        int $actorUserId,
        string $name,
        ?string $email = null,
        ?string $phone = null,
        ?int $userId = null
    ): void {
        $this->assertIdentifiers($instructorId, $actorUserId);
        $data = self::normalizeInstructor($name, $email, $phone, $userId);
        $this->beginOwnedTransaction();

        try {
            $current = $this->lockRow(
                'SELECT id, nome, email, telefone, usuario_id, ativo
                 FROM instrutores WHERE id = :id FOR UPDATE',
                $instructorId,
                'Instrutor não encontrado.'
            );
            $this->assertLinkedUserAvailable($data['user_id']);

            if (
                (string) $current['nome'] === $data['name']
                && $current['email'] === $data['email']
                && $current['telefone'] === $data['phone']
                && self::nullableInt($current['usuario_id']) === $data['user_id']
            ) {
                $this->pdo->commit();
                return;
            }

            $update = $this->pdo->prepare(
                'UPDATE instrutores
                 SET nome = :name, email = :email, telefone = :phone, usuario_id = :user_id
                 WHERE id = :id'
            );
            $update->execute($data + ['id' => $instructorId]);
            $this->audit($actorUserId, 'instrutor_editado', 'instrutores', $instructorId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollbackIfNeeded();
            throw $exception;
        }
    }

    public function deactivateInstructor(int $instructorId, int $actorUserId): void
    {
        $this->assertIdentifiers($instructorId, $actorUserId);
        $this->beginOwnedTransaction();

        try {
            $instructor = $this->lockRow(
                'SELECT id, ativo FROM instrutores WHERE id = :id FOR UPDATE',
                $instructorId,
                'Instrutor não encontrado.'
            );

            if ((int) $instructor['ativo'] === 0) {
                $this->pdo->commit();
                return;
            }

            $activeSchedule = $this->pdo->prepare(
                'SELECT id FROM horarios_aula
                 WHERE instrutor_id = :id AND ativo = 1
                 LIMIT 1 FOR UPDATE'
            );
            $activeSchedule->execute(['id' => $instructorId]);
            if ($activeSchedule->fetchColumn() !== false) {
                throw new DomainException(
                    'Reatribua ou inative primeiro os horários ativos deste instrutor.'
                );
            }

            $update = $this->pdo->prepare('UPDATE instrutores SET ativo = 0 WHERE id = :id');
            $update->execute(['id' => $instructorId]);
            $this->audit($actorUserId, 'instrutor_inativado', 'instrutores', $instructorId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollbackIfNeeded();
            throw $exception;
        }
    }

    private function assertLinkedUserAvailable(?int $userId): void
    {
        if ($userId === null) {
            return;
        }

        $query = $this->pdo->prepare(
            'SELECT id, ativo FROM usuarios WHERE id = :id FOR UPDATE'
        );
        $query->execute(['id' => $userId]);
        $user = $query->fetch(PDO::FETCH_ASSOC);

        if (!$user || (int) $user['ativo'] !== 1) {
            throw new DomainException('A conta vinculada não existe ou está inativa.');
        }
    }

    private function lockRow(string $sql, int $id, string $notFound): array
    {
        $query = $this->pdo->prepare($sql);
        $query->execute(['id' => $id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new DomainException($notFound);
        }
        return $row;
    }

    private function audit(int $actorId, string $action, string $entity, int $entityId): void
    {
        \FitControl\Security\AuditIntegrityChain::append(
            $this->pdo,
            $actorId,
            $action,
            $entity,
            $entityId
        );
    }

    private function assertActor(int $actorId): void
    {
        if ($actorId < 1) {
            throw new InvalidArgumentException('O identificador do responsável é inválido.');
        }

        $query = $this->pdo->prepare('SELECT ativo FROM usuarios WHERE id = :id');
        $query->execute(['id' => $actorId]);
        if ((int) $query->fetchColumn() !== 1) {
            throw new DomainException('O responsável não existe ou está inativo.');
        }
    }

    private function assertIdentifiers(int $entityId, int $actorId): void
    {
        if ($entityId < 1 || $actorId < 1) {
            throw new InvalidArgumentException('Os identificadores precisam ser positivos.');
        }
        $this->assertActor($actorId);
    }

    private function beginOwnedTransaction(): void
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('O serviço precisa controlar sua própria transação.');
        }
        $this->pdo->beginTransaction();
    }

    private function rollbackIfNeeded(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private static function normalizeModality(string $name, ?string $description): array
    {
        $name = trim($name);
        $description = $description === null ? null : trim($description);
        if ($description === '') {
            $description = null;
        }

        if ($name === '' || self::length($name) > 100) {
            throw new InvalidArgumentException('O nome da modalidade deve ter entre 1 e 100 caracteres.');
        }
        if ($description !== null && self::length($description) > 255) {
            throw new InvalidArgumentException('A descrição deve ter até 255 caracteres.');
        }

        return ['name' => $name, 'description' => $description];
    }

    private static function normalizeInstructor(
        string $name,
        ?string $email,
        ?string $phone,
        ?int $userId
    ): array {
        $name = trim($name);
        $email = $email === null ? null : trim($email);
        $phone = $phone === null ? null : trim($phone);
        if ($email === '') {
            $email = null;
        }
        if ($phone === '') {
            $phone = null;
        }

        if ($name === '' || self::length($name) > 100) {
            throw new InvalidArgumentException('O nome do instrutor deve ter entre 1 e 100 caracteres.');
        }
        if ($email !== null && (self::length($email) > 150 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw new InvalidArgumentException('Informe um e-mail válido com até 150 caracteres.');
        }
        if ($phone !== null && (self::length($phone) > 20 || preg_match('/^[0-9+(). -]+$/D', $phone) !== 1)) {
            throw new InvalidArgumentException('O telefone deve ter até 20 caracteres e conter apenas números e símbolos telefônicos.');
        }
        if ($userId !== null && $userId < 1) {
            throw new InvalidArgumentException('O identificador da conta vinculada é inválido.');
        }

        return ['name' => $name, 'email' => $email, 'phone' => $phone, 'user_id' => $userId];
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
