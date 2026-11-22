<?php
declare(strict_types=1);

use FitControl\Security\AccessControl;

require_once dirname(__DIR__) . '/src/Security/AccessControl.php';

function assertPermission(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$matrix = [
    'ADMINISTRADOR' => [
        'dashboard.view', 'students.view', 'students.create', 'students.update',
        'students.status', 'plans.view', 'plans.manage', 'enrollments.view',
        'enrollments.create', 'enrollments.cancel', 'legacy.view', 'legacy.review',
        'legacy.rectify', 'users.manage', 'audit.view', 'settings.view',
    ],
    'GESTOR' => [
        'dashboard.view', 'students.view', 'students.create', 'students.update',
        'students.status', 'plans.view', 'plans.manage', 'enrollments.view',
        'enrollments.create', 'enrollments.cancel', 'legacy.view', 'legacy.review',
        'legacy.rectify', 'audit.view', 'settings.view',
    ],
    'OPERADOR' => [
        'dashboard.view', 'students.view', 'students.create', 'students.update',
        'plans.view', 'enrollments.view', 'enrollments.create',
        'enrollments.cancel', 'settings.view',
    ],
];

foreach ($matrix as $role => $permissions) {
    foreach ($permissions as $permission) {
        assertPermission(
            AccessControl::allows($role, $permission),
            $role . ' deveria possuir ' . $permission
        );
    }
}

$allPermissions = [
    'dashboard.view', 'students.view', 'students.create', 'students.update',
    'students.status', 'plans.view', 'plans.manage', 'enrollments.view',
    'enrollments.create', 'enrollments.cancel', 'legacy.view', 'legacy.review',
    'legacy.rectify', 'users.manage', 'audit.view', 'settings.view',
];

foreach (['GESTOR', 'OPERADOR'] as $role) {
    foreach (array_diff($allPermissions, $matrix[$role]) as $permission) {
        assertPermission(
            !AccessControl::allows($role, $permission),
            $role . ' não deveria possuir ' . $permission
        );
    }
}

assertPermission(!AccessControl::allows('PERFIL_INVALIDO', 'dashboard.view'), 'Perfil desconhecido deve negar acesso.');
assertPermission(!AccessControl::allows('OPERADOR', ''), 'Permissão vazia deve ser negada.');
assertPermission(AccessControl::label('GESTOR') === 'Gestor', 'Rótulo do perfil gestor incorreto.');
assertPermission(
    AccessControl::roles() === ['ADMINISTRADOR', 'GESTOR', 'OPERADOR'],
    'Lista de perfis disponível para formulários está incorreta.'
);

echo "Matriz RBAC: permissões positivas e negativas aprovadas.\\n";
