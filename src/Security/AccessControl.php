<?php
declare(strict_types=1);

namespace FitControl\Security;

/**
 * Matriz RBAC do FitControl.
 *
 * ADMINISTRADOR: administração integral do sistema.
 * GESTOR: operação e gestão da academia, sem gestão de contas.
 * OPERADOR: rotina de alunos e matrículas, sem catálogo, revisão de legados
 * ou gestão de funcionários.
 */
final class AccessControl
{
    public const ADMINISTRATOR = 'ADMINISTRADOR';
    public const MANAGER = 'GESTOR';
    public const OPERATOR = 'OPERADOR';

    /** @var array<string, list<string>> */
    private const PERMISSIONS = [
        self::ADMINISTRATOR => ['*'],
        self::MANAGER => [
            'dashboard.view',
            'students.view',
            'students.create',
            'students.update',
            'students.status',
            'plans.view',
            'plans.manage',
            'enrollments.view',
            'enrollments.create',
            'enrollments.cancel',
            'legacy.view',
            'legacy.review',
            'legacy.rectify',
            'audit.view',
            'settings.view',
        ],
        self::OPERATOR => [
            'dashboard.view',
            'students.view',
            'students.create',
            'students.update',
            'plans.view',
            'enrollments.view',
            'enrollments.create',
            'enrollments.cancel',
            'settings.view',
        ],
    ];

    /** @var array<string, string> */
    private const LABELS = [
        self::ADMINISTRATOR => 'Administrador',
        self::MANAGER => 'Gestor',
        self::OPERATOR => 'Operador',
    ];

    public static function allows(string $role, string $permission): bool
    {
        if (!isset(self::PERMISSIONS[$role]) || trim($permission) === '') {
            return false;
        }

        $permissions = self::PERMISSIONS[$role];

        return in_array('*', $permissions, true)
            || in_array($permission, $permissions, true);
    }

    public static function label(string $role): string
    {
        return self::LABELS[$role] ?? 'Perfil desconhecido';
    }

    /** @return list<string> */
    public static function roles(): array
    {
        return array_keys(self::PERMISSIONS);
    }
}
