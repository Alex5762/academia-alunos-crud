<?php
declare(strict_types=1);

namespace FitControl\Domain\Users;

use FitControl\Security\AccessControl;
use FitControl\Security\AuditIntegrityChain;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/../../Security/AccessControl.php';
require_once __DIR__ . '/../../Security/AuditIntegrityChain.php';

/**
 * Centraliza alterações administrativas de contas.
 *
 * Todas as operações administrativas que criam ou alteram contas bloqueiam
 * as contas em ordem crescente de ID e revalidam o administrador na transação.
 * Assim, uma requisição autorizada antes de aguardar um lock não mantém
 * privilégios revogados por uma alteração concorrente.
 */
final class UserAccountAdministrationService
{
    public const UNAUTHORIZED = 'UNAUTHORIZED';
    public const NOT_FOUND = 'NOT_FOUND';
    public const SELF_PROFILE = 'SELF_PROFILE';
    public const SELF_DEACTIVATION = 'SELF_DEACTIVATION';
    public const UNCHANGED = 'UNCHANGED';
    public const ALREADY_ACTIVE = 'ALREADY_ACTIVE';
    public const ALREADY_INACTIVE = 'ALREADY_INACTIVE';
    public const LAST_ADMINISTRATOR = 'LAST_ADMINISTRATOR';
    public const UPDATED = 'UPDATED';
    public const DEACTIVATED = 'DEACTIVATED';
    public const REACTIVATED = 'REACTIVATED';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Cria uma conta individual e registra a auditoria na mesma transação.
     *
     * O administrador é revalidado depois de obter o mesmo lock global usado
     * por alterações de perfil/status. Se perdeu o privilégio enquanto a
     * requisição aguardava o lock, nenhuma conta é criada.
     *
     * @return int|string ID da conta criada ou UNAUTHORIZED.
     */
    public function createAccount(
        int $actorId,
        string $name,
        string $email,
        string $passwordHash
    ): int|string {
        $name = trim($name);
        $email = strtolower(trim($email));

        $nameLength = preg_match_all('/./us', $name);
        if (
            $actorId < 1
            || $nameLength === false
            || $nameLength < 1
            || $nameLength > 100
            || strlen($email) > 150
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || $passwordHash === ''
            || strlen($passwordHash) > 255
        ) {
            throw new InvalidArgumentException('Dados de criação de conta inválidos.');
        }

        return $this->withLockedAccounts(function (array $accounts) use (
            $actorId,
            $name,
            $email,
            $passwordHash
        ): int|string {
            if (!$this->isActiveAdministrator($accounts[$actorId] ?? null)) {
                return self::UNAUTHORIZED;
            }

            $insert = $this->pdo->prepare(
                "INSERT INTO usuarios (nome, email, senha_hash, ativo, perfil, deve_trocar_senha)
                 VALUES (:name, :email, :password_hash, 1, 'OPERADOR', 1)"
            );
            $insert->execute([
                'name' => $name,
                'email' => $email,
                'password_hash' => $passwordHash,
            ]);

            $newUserId = (int) $this->pdo->lastInsertId();
            if ($newUserId < 1) {
                throw new RuntimeException('Não foi possível identificar a conta criada.');
            }

            AuditIntegrityChain::append(
                $this->pdo,
                $actorId,
                'usuario_criado',
                'usuarios',
                $newUserId
            );

            return $newUserId;
        });
    }

    /**
     * @return self::* Resultado estável para a camada HTTP.
     */
    public function changeProfile(int $actorId, int $targetId, string $newProfile): string
    {
        if ($actorId < 1 || $targetId < 1 || !in_array($newProfile, AccessControl::roles(), true)) {
            throw new InvalidArgumentException('Dados de alteração de perfil inválidos.');
        }

        return $this->withLockedAccounts(function (array $accounts) use ($actorId, $targetId, $newProfile): string {
            if (!$this->isActiveAdministrator($accounts[$actorId] ?? null)) {
                return self::UNAUTHORIZED;
            }

            if ($actorId === $targetId) {
                return self::SELF_PROFILE;
            }

            $target = $accounts[$targetId] ?? null;
            if ($target === null) {
                return self::NOT_FOUND;
            }

            $currentProfile = (string) $target['perfil'];
            if ($currentProfile === $newProfile) {
                return self::UNCHANGED;
            }

            if (
                (int) $target['ativo'] === 1
                && $currentProfile === AccessControl::ADMINISTRATOR
                && $newProfile !== AccessControl::ADMINISTRATOR
                && $this->countActiveAdministrators($accounts) <= 1
            ) {
                return self::LAST_ADMINISTRATOR;
            }

            $update = $this->pdo->prepare(
                'UPDATE usuarios
                 SET perfil = :new_profile
                 WHERE id = :target_id AND perfil = :current_profile'
            );
            $update->execute([
                'new_profile' => $newProfile,
                'target_id' => $targetId,
                'current_profile' => $currentProfile,
            ]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException('O perfil do funcionário não foi alterado.');
            }

            $history = $this->pdo->prepare(
                'INSERT INTO usuarios_perfis_eventos
                    (usuario_id, responsavel_id, perfil_anterior, perfil_novo)
                 VALUES (:target_id, :actor_id, :old_profile, :new_profile)'
            );
            $history->execute([
                'target_id' => $targetId,
                'actor_id' => $actorId,
                'old_profile' => $currentProfile,
                'new_profile' => $newProfile,
            ]);

            AuditIntegrityChain::append(
                $this->pdo,
                $actorId,
                'usuario_perfil_alterado',
                'usuarios',
                $targetId
            );

            return self::UPDATED;
        });
    }

    /**
     * @return self::* Resultado estável para a camada HTTP.
     */
    public function changeStatus(int $actorId, int $targetId, string $action): string
    {
        if (
            $actorId < 1
            || $targetId < 1
            || !in_array($action, ['desativar', 'reativar'], true)
        ) {
            throw new InvalidArgumentException('Dados de alteração de status inválidos.');
        }

        return $this->withLockedAccounts(function (array $accounts) use ($actorId, $targetId, $action): string {
            if (!$this->isActiveAdministrator($accounts[$actorId] ?? null)) {
                return self::UNAUTHORIZED;
            }

            if ($action === 'desativar' && $actorId === $targetId) {
                return self::SELF_DEACTIVATION;
            }

            $target = $accounts[$targetId] ?? null;
            if ($target === null) {
                return self::NOT_FOUND;
            }

            $isActive = (int) $target['ativo'] === 1;
            if ($action === 'desativar' && !$isActive) {
                return self::ALREADY_INACTIVE;
            }
            if ($action === 'reativar' && $isActive) {
                return self::ALREADY_ACTIVE;
            }

            if (
                $action === 'desativar'
                && (string) $target['perfil'] === AccessControl::ADMINISTRATOR
                && $this->countActiveAdministrators($accounts) <= 1
            ) {
                return self::LAST_ADMINISTRATOR;
            }

            $newStatus = $action === 'desativar' ? 0 : 1;
            $expectedStatus = $isActive ? 1 : 0;
            $update = $this->pdo->prepare(
                'UPDATE usuarios
                 SET ativo = :new_status,
                     sessao_versao = sessao_versao + 1
                 WHERE id = :target_id AND ativo = :expected_status'
            );
            $update->execute([
                'new_status' => $newStatus,
                'target_id' => $targetId,
                'expected_status' => $expectedStatus,
            ]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException('O status da conta não foi alterado.');
            }

            AuditIntegrityChain::append(
                $this->pdo,
                $actorId,
                $action === 'desativar' ? 'usuario_desativado' : 'usuario_reativado',
                'usuarios',
                $targetId
            );

            return $action === 'desativar' ? self::DEACTIVATED : self::REACTIVATED;
        });
    }

    /**
     * Obtém uma visão bloqueada e atualizada de todas as contas.
     *
     * O lock global é intencional: operações de perfil/status são pouco
     * frequentes e precisam compartilhar a mesma ordem de bloqueio para
     * serializar alterações concorrentes sobre o conjunto de administradores.
     *
     * @param callable(array<int, array{id: int|string, ativo: int|string, perfil: string, deve_trocar_senha: int|string}>): mixed $operation
     */
    private function withLockedAccounts(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('A administração de contas exige controle exclusivo da transação.');
        }

        $this->pdo->beginTransaction();

        try {
            $accounts = $this->pdo->query(
                'SELECT id, ativo, perfil, deve_trocar_senha
                 FROM usuarios
                 ORDER BY id
                 FOR UPDATE'
            )->fetchAll(PDO::FETCH_ASSOC);

            $indexed = [];
            foreach ($accounts as $account) {
                $indexed[(int) $account['id']] = $account;
            }

            $result = $operation($indexed);
            $this->pdo->commit();

            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $error;
        }
    }

    private function isActiveAdministrator(?array $account): bool
    {
        if ($account === null || (int) $account['ativo'] !== 1 || (int) $account['deve_trocar_senha'] !== 0) {
            return false;
        }

        return AccessControl::allows((string) $account['perfil'], 'users.manage');
    }

    /**
     * @param array<int, array{id: int|string, ativo: int|string, perfil: string, deve_trocar_senha: int|string}> $accounts
     */
    private function countActiveAdministrators(array $accounts): int
    {
        $count = 0;

        foreach ($accounts as $account) {
            if (
                (int) $account['ativo'] === 1
                && AccessControl::allows((string) $account['perfil'], 'users.manage')
            ) {
                $count++;
            }
        }

        return $count;
    }
}
