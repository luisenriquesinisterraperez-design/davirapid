<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;

final class UserService
{
    use LocatorAwareTrait;

    /**
     * @return array{success: bool, user?: \App\Model\Entity\User, errors?: array<string>}
     */
    public function create(array $data): array
    {
        $usersTable = $this->fetchTable('Users');
        $user = $usersTable->newEmptyEntity();

        if (isset($data['role_id']) && $this->_isAdminRole((int)$data['role_id'])) {
            return [
                'success' => false,
                'errors' => ['No se puede asignar el rol Administrador a un usuario nuevo.'],
            ];
        }

        $user = $usersTable->patchEntity($user, $data, ['validate' => 'create']);

        if (!$usersTable->save($user)) {
            return [
                'success' => false,
                'errors' => $this->_flattenErrors($user->getErrors()),
            ];
        }

        Log::info('User created: {username} (role_id={role_id})', [
            'username' => $user->username,
            'role_id' => $user->role_id,
            'scope' => ['users'],
        ]);

        return ['success' => true, 'user' => $user];
    }

    /**
     * @return array{success: bool, user?: \App\Model\Entity\User, errors?: array<string>}
     */
    public function update(int $id, array $data): array
    {
        $usersTable = $this->fetchTable('Users');
        $user = $usersTable->get($id, contain: ['Roles']);

        if ($user->isAdministrator() && isset($data['role_id']) && (int)$data['role_id'] !== (int)$user->role_id) {
            return [
                'success' => false,
                'errors' => ['No se puede cambiar el rol del usuario Administrador.'],
            ];
        }

        if (isset($data['role_id']) && !$user->isAdministrator() && $this->_isAdminRole((int)$data['role_id'])) {
            return [
                'success' => false,
                'errors' => ['No se puede asignar el rol Administrador.'],
            ];
        }

        if ($user->isAdministrator() && array_key_exists('active', $data) && !$data['active']) {
            return [
                'success' => false,
                'errors' => ['El usuario Administrador no se puede desactivar.'],
            ];
        }

        $user = $usersTable->patchEntity($user, $data);

        if (!$usersTable->save($user)) {
            return [
                'success' => false,
                'errors' => $this->_flattenErrors($user->getErrors()),
            ];
        }

        return ['success' => true, 'user' => $user];
    }

    /**
     * @return array{success: bool}
     */
    public function unlock(int $id): array
    {
        $usersTable = $this->fetchTable('Users');
        $user = $usersTable->get($id);
        $user->failed_login_count = 0;
        $user->locked_until = null;
        $usersTable->saveOrFail($user);

        Log::info('Account manually unlocked: {username}', [
            'username' => $user->username,
            'scope' => ['auth', 'users'],
        ]);

        return ['success' => true];
    }

    private function _isAdminRole(int $roleId): bool
    {
        $role = $this->fetchTable('Roles')->find()
            ->where(['Roles.id' => $roleId])
            ->first();
        return $role !== null && (bool)$role->is_admin;
    }

    /**
     * @param array $errors Cake validator/rules error tree.
     * @return array<string>
     */
    private function _flattenErrors(array $errors): array
    {
        $flat = [];
        array_walk_recursive($errors, function ($message) use (&$flat): void {
            if (is_string($message) && $message !== '') {
                $flat[] = $message;
            }
        });
        return $flat ?: ['Datos inválidos.'];
    }
}
