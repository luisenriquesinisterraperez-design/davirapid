<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Datasource\ConnectionManager;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Sincroniza la matriz de permisos que llega del form de Roles con las filas
 * de la tabla `permissions`. Se invoca desde RolesController::add y ::edit.
 */
final class RolePermissionService
{
    use LocatorAwareTrait;

    /**
     * @param int $roleId Rol al que pertenecen los permisos.
     * @param array<string, array<string, mixed>> $matrix Estructura del form: ['users' => ['can_view'=>'1', 'can_create'=>'0', ...], ...]
     * @return array{success: bool}
     */
    public function syncMatrix(int $roleId, array $matrix): array
    {
        $conn = ConnectionManager::get('default');

        $conn->transactional(function () use ($roleId, $matrix): void {
            $permissionsTable = $this->fetchTable('Permissions');

            $existing = $permissionsTable
                ->find()
                ->where(['Permissions.role_id' => $roleId])
                ->all()
                ->indexBy('module')
                ->toArray();

            foreach (AuthorizationService::MODULES as $module => $_label) {
                $row = $matrix[$module] ?? [];

                $canView = !empty($row['can_view']);
                $canCreate = !empty($row['can_create']);
                $canEdit = !empty($row['can_edit']);
                $canDelete = !empty($row['can_delete']);

                // Jerarquía implícita: sin Ver no hay nada más. Si el form se contradice,
                // el service forza can_view=true cuando hay alguna acción mayor activa.
                if ($canCreate || $canEdit || $canDelete) {
                    $canView = true;
                }

                $hasAny = $canView || $canCreate || $canEdit || $canDelete;
                $existingRow = $existing[$module] ?? null;

                if ($existingRow !== null) {
                    // UPDATE
                    $existingRow->can_view = $canView;
                    $existingRow->can_create = $canCreate;
                    $existingRow->can_edit = $canEdit;
                    $existingRow->can_delete = $canDelete;
                    $permissionsTable->saveOrFail($existingRow);
                } elseif ($hasAny) {
                    // INSERT
                    $entity = $permissionsTable->newEntity([
                        'role_id' => $roleId,
                        'module' => $module,
                        'can_view' => $canView,
                        'can_create' => $canCreate,
                        'can_edit' => $canEdit,
                        'can_delete' => $canDelete,
                    ]);
                    $permissionsTable->saveOrFail($entity);
                }
                // Si no existía y todos los flags son false: skip (no creamos filas vacías).
            }
        });

        return ['success' => true];
    }
}
