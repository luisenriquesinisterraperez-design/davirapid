<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\Locator\LocatorAwareTrait;

final class AuthorizationService
{
    use LocatorAwareTrait;

    /**
     * Catálogo de módulos del sistema. Cada fase agrega entradas acá.
     * La clave es el identificador interno (snake_case sin guión bajo);
     * el valor es la etiqueta visible en la UI.
     */
    public const MODULES = [
        'roles' => 'Roles',
        'users' => 'Usuarios',
        'products' => 'Productos',
    ];

    /** Acciones de permiso almacenadas en DB. */
    public const ACTIONS = ['view', 'create', 'edit', 'delete'];

    /** @var array<int, array<string, array<string, bool>>> Cache por proceso, no persistente. */
    private array $cache = [];

    /**
     * Determina si el usuario tiene permiso para ejecutar la acción dada en el módulo dado.
     *
     * @param array $user Identidad como array (debe contener 'role_id' y 'role.is_admin').
     */
    public function isAllowed(array $user, string $module, string $action): bool
    {
        // 1. Bypass del Administrador.
        if (!empty($user['role']['is_admin'])) {
            return true;
        }

        // 2. Módulo desconocido = denegado.
        if (!array_key_exists($module, self::MODULES)) {
            return false;
        }

        // 3. Roles solo lo gestiona el Administrador (defensa más allá del bypass).
        if ($module === 'roles') {
            return false;
        }

        $perm = $this->loadPermissionsFor((int)($user['role_id'] ?? 0))[$module] ?? null;
        if ($perm === null) {
            return false;
        }

        return match ($action) {
            'view' => (bool)$perm['can_view'],
            'create' => (bool)$perm['can_create'],
            'edit' => (bool)$perm['can_edit'],
            'delete' => (bool)$perm['can_delete'],
            default => false,
        };
    }

    /**
     * Devuelve la matriz completa de permisos para el usuario.
     *
     * @return array<string, array<string, bool>> ['users' => ['view'=>bool, 'create'=>bool, ...], ...]
     */
    public function matrixFor(array $user): array
    {
        $matrix = [];
        foreach (array_keys(self::MODULES) as $module) {
            $matrix[$module] = [];
            foreach (self::ACTIONS as $action) {
                $matrix[$module][$action] = $this->isAllowed($user, $module, $action);
            }
        }
        return $matrix;
    }

    /**
     * Lee de DB todos los permisos del rol (cacheado por proceso).
     *
     * @return array<string, array<string, mixed>> ['users' => ['can_view'=>1, ...], ...]
     */
    private function loadPermissionsFor(int $roleId): array
    {
        if ($roleId <= 0) {
            return [];
        }
        if (isset($this->cache[$roleId])) {
            return $this->cache[$roleId];
        }

        $permissions = $this->fetchTable('Permissions')
            ->find()
            ->where(['Permissions.role_id' => $roleId])
            ->all()
            ->toArray();

        $byModule = [];
        foreach ($permissions as $p) {
            $byModule[$p->module] = [
                'can_view' => $p->can_view,
                'can_create' => $p->can_create,
                'can_edit' => $p->can_edit,
                'can_delete' => $p->can_delete,
            ];
        }

        $this->cache[$roleId] = $byModule;
        return $byModule;
    }
}
