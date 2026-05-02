<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Entity\Role;
use App\Service\AuthorizationService;
use App\Service\RolePermissionService;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Http\Exception\ForbiddenException;
use Cake\ORM\Exception\PersistenceFailedException;

class RolesController extends AppController
{
    public array $paginate = [
        'limit' => 15,
        'maxLimit' => 15,
        'order' => ['Roles.is_admin' => 'DESC', 'Roles.name' => 'ASC'],
    ];

    private RolePermissionService $rolePermissions;

    public function initialize(): void
    {
        parent::initialize();
        $this->rolePermissions = new RolePermissionService();
    }

    public function index(): void
    {
        $roles = $this->paginate(
            $this->Roles->find()->contain(['Permissions', 'Users' => fn($q) => $q->select(['id', 'role_id'])])
        );
        $this->set(compact('roles'));
        $this->set('moduleCatalog', AuthorizationService::MODULES);
        $this->set('breadcrumbs', [['label' => 'Roles']]);
    }

    public function view(int $id): void
    {
        $role = $this->Roles->get($id, contain: ['Permissions']);
        $this->set('role', $role);
        $this->set('matrix', $this->_buildMatrixFromRole($role));
        $this->set('moduleCatalog', AuthorizationService::MODULES);
        $this->set('breadcrumbs', [
            ['label' => 'Roles', 'url' => ['action' => 'index']],
            ['label' => $role->name],
        ]);
    }

    public function add()
    {
        $role = $this->Roles->newEmptyEntity();

        if ($this->request->is('post')) {
            $data = $this->request->getData();
            $matrix = $data['permissions'] ?? [];
            unset($data['permissions']);

            $role = $this->Roles->patchEntity($role, $data);
            if ($this->Roles->save($role)) {
                $this->rolePermissions->syncMatrix((int)$role->id, is_array($matrix) ? $matrix : []);
                $this->Flash->success('Rol creado correctamente.');
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error('No se pudo crear el rol. Revisá los datos.');
        }

        $this->set('role', $role);
        $this->set('matrix', $this->_emptyMatrix());
        $this->set('moduleCatalog', AuthorizationService::MODULES);
        $this->set('breadcrumbs', [
            ['label' => 'Roles', 'url' => ['action' => 'index']],
            ['label' => 'Nuevo rol'],
        ]);
    }

    public function edit(int $id)
    {
        $role = $this->Roles->get($id, contain: ['Permissions']);

        if ($role->isAdministrator()) {
            throw new ForbiddenException('El rol Administrador no se puede editar.');
        }

        if ($this->request->is(['put', 'post', 'patch'])) {
            $data = $this->request->getData();
            $matrix = $data['permissions'] ?? [];
            unset($data['permissions']);

            $role = $this->Roles->patchEntity($role, $data);
            if ($this->Roles->save($role)) {
                $this->rolePermissions->syncMatrix((int)$role->id, is_array($matrix) ? $matrix : []);
                $this->Flash->success('Rol actualizado.');
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error('No se pudo actualizar el rol. Revisá los datos.');
        }

        $this->set('role', $role);
        $this->set('matrix', $this->_buildMatrixFromRole($role));
        $this->set('moduleCatalog', AuthorizationService::MODULES);
        $this->set('breadcrumbs', [
            ['label' => 'Roles', 'url' => ['action' => 'index']],
            ['label' => $role->name, 'url' => ['action' => 'view', $role->id]],
            ['label' => 'Editar'],
        ]);
    }

    public function delete(int $id)
    {
        $this->request->allowMethod(['post', 'delete']);

        try {
            $role = $this->Roles->get($id);
        } catch (RecordNotFoundException) {
            $this->Flash->error('El rol ya no existe.');
            return $this->redirect(['action' => 'index']);
        }

        if ($role->isAdministrator()) {
            throw new ForbiddenException('El rol Administrador no se puede eliminar.');
        }

        try {
            $this->Roles->deleteOrFail($role);
            $this->Flash->success('Rol eliminado.');
        } catch (PersistenceFailedException $e) {
            $this->Flash->error('No se puede eliminar este rol porque tiene usuarios asignados.');
            \Cake\Log\Log::warning('Failed to delete role {id}: {msg}', [
                'id' => $id,
                'msg' => $e->getMessage(),
                'scope' => ['rbac'],
            ]);
        }

        return $this->redirect(['action' => 'index']);
    }

    private function _buildMatrixFromRole(Role $role): array
    {
        $matrix = $this->_emptyMatrix();
        foreach ($role->permissions ?? [] as $perm) {
            if (!isset($matrix[$perm->module])) {
                continue;
            }
            $matrix[$perm->module] = [
                'can_view' => (bool)$perm->can_view,
                'can_create' => (bool)$perm->can_create,
                'can_edit' => (bool)$perm->can_edit,
                'can_delete' => (bool)$perm->can_delete,
            ];
        }
        return $matrix;
    }

    private function _emptyMatrix(): array
    {
        $matrix = [];
        foreach (array_keys(AuthorizationService::MODULES) as $module) {
            $matrix[$module] = [
                'can_view' => false,
                'can_create' => false,
                'can_edit' => false,
                'can_delete' => false,
            ];
        }
        return $matrix;
    }
}
