<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\UserService;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Http\Exception\ForbiddenException;

class UsersController extends AppController
{
    public array $paginate = [
        'limit' => 15,
        'maxLimit' => 15,
        'order' => ['Users.username' => 'ASC'],
    ];

    private UserService $userService;

    public function initialize(): void
    {
        parent::initialize();
        $this->userService = new UserService();
        // Allow login + logout to render without authentication
        $this->Authentication->addUnauthenticatedActions(['login', 'logout']);
    }

    public function login()
    {
        $this->viewBuilder()->setLayout('login');

        $username = (string)$this->request->getData('username', '');

        if ($this->request->is('post')) {
            $lockInfo = $this->throttle->checkLockout($username);
            if ($lockInfo !== null) {
                $this->Flash->error(
                    sprintf('Cuenta bloqueada. Intentá de nuevo en %d %s.',
                        $lockInfo['minutes_left'],
                        $lockInfo['minutes_left'] === 1 ? 'minuto' : 'minutos'
                    )
                );
                return null;
            }

            $result = $this->Authentication->getResult();
            if ($result !== null && $result->isValid()) {
                $user = $result->getData();
                $this->throttle->recordSuccess((int)$user->id);
                return $this->redirect($this->Authentication->getLoginRedirect() ?? '/');
            }

            $info = $this->throttle->recordFailure($username);
            $msg = ($info['attempts_left'] !== null && $info['attempts_left'] > 0)
                ? sprintf('Credenciales inválidas. Te quedan %d intentos.', $info['attempts_left'])
                : 'Credenciales inválidas.';
            $this->Flash->error($msg);
        }

        $this->set('username', $username);
        return null;
    }

    public function logout()
    {
        $this->Authentication->logout();
        return $this->redirect(['action' => 'login']);
    }

    public function index(): void
    {
        $q = trim((string)$this->request->getQuery('q', ''));
        $query = $this->Users->find()->contain(['Roles']);
        if ($q !== '') {
            $like = '%' . $q . '%';
            $query->where(['OR' => [
                'Users.username LIKE' => $like,
                'Users.name LIKE' => $like,
            ]]);
        }

        $users = $this->paginate($query);
        $this->set(compact('users', 'q'));
        $this->set('breadcrumbs', [['label' => 'Usuarios']]);
    }

    public function view(int $id): void
    {
        $user = $this->Users->get($id, contain: ['Roles', 'Deliveries']);
        $this->set('user', $user);
        $this->set('breadcrumbs', [
            ['label' => 'Usuarios', 'url' => ['action' => 'index']],
            ['label' => $user->username],
        ]);
    }

    public function add()
    {
        $user = $this->Users->newEmptyEntity();

        if ($this->request->is('post')) {
            $result = $this->userService->create($this->request->getData());
            if ($result['success']) {
                $this->Flash->success('Usuario creado correctamente.');
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(implode(' ', $result['errors'] ?? ['No se pudo crear el usuario.']));
            $data = $this->request->getData();
            unset($data['password']);
            $user = $this->Users->patchEntity($user, $data, ['validate' => false]);
        }

        $this->set('user', $user);
        $this->set('deliveriesList', $this->_availableDeliveriesList(null));
        $this->set('roles', $this->Users->Roles->find('assignable')->all());
        $this->set('isEditingAdministrator', false);
        $this->set('breadcrumbs', [
            ['label' => 'Usuarios', 'url' => ['action' => 'index']],
            ['label' => 'Nuevo usuario'],
        ]);
    }

    public function edit(int $id)
    {
        $user = $this->Users->get($id, contain: ['Roles']);
        $isEditingAdministrator = $user->isAdministrator();

        if ($this->request->is(['put', 'post', 'patch'])) {
            $result = $this->userService->update($id, $this->request->getData());
            if ($result['success']) {
                $this->Flash->success('Usuario actualizado.');
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(implode(' ', $result['errors'] ?? ['No se pudo actualizar el usuario.']));
        }

        $rolesQuery = $isEditingAdministrator
            ? $this->Users->Roles->find()->where(['Roles.id' => $user->role_id])
            : $this->Users->Roles->find('assignable');

        $this->set('user', $user);
        $this->set('deliveriesList', $this->_availableDeliveriesList((int)$user->id));
        $this->set('roles', $rolesQuery->all());
        $this->set('isEditingAdministrator', $isEditingAdministrator);
        $this->set('breadcrumbs', [
            ['label' => 'Usuarios', 'url' => ['action' => 'index']],
            ['label' => $user->username, 'url' => ['action' => 'view', $user->id]],
            ['label' => 'Editar'],
        ]);
    }

    public function delete(int $id)
    {
        $this->request->allowMethod(['post', 'delete']);

        try {
            $user = $this->Users->get($id, contain: ['Roles']);
        } catch (RecordNotFoundException) {
            $this->Flash->error('El usuario ya no existe.');
            return $this->redirect(['action' => 'index']);
        }

        if ($user->isAdministrator()) {
            throw new ForbiddenException('El usuario Administrador no se puede eliminar.');
        }

        $identity = $this->Authentication->getIdentity();
        if ($identity !== null && (int)$identity->getIdentifier() === (int)$user->id) {
            throw new ForbiddenException('No podés eliminar tu propio usuario.');
        }

        $this->Users->deleteOrFail($user);
        $this->Flash->success('Usuario eliminado.');
        return $this->redirect(['action' => 'index']);
    }

    public function unlock(int $id)
    {
        $this->request->allowMethod(['post']);
        $this->userService->unlock($id);
        $this->Flash->success('Cuenta desbloqueada.');
        return $this->redirect(['action' => 'index']);
    }

    /**
     * @return array<int, string>
     */
    private function _availableDeliveriesList(?int $currentUserId): array
    {
        $usersTable = $this->fetchTable('Users');
        $deliveriesTable = $this->fetchTable('Deliveries');

        $takenQuery = $usersTable->find()
            ->select(['delivery_id'])
            ->where(['Users.delivery_id IS NOT' => null]);
        if ($currentUserId !== null) {
            $takenQuery->where(['Users.id !=' => $currentUserId]);
        }
        $takenIds = array_filter(array_map(
            fn($u) => (int)$u->delivery_id,
            $takenQuery->all()->toArray()
        ));

        $query = $deliveriesTable->find('active')->find('fullNameList');
        if (!empty($takenIds)) {
            $query->where(['Deliveries.id NOT IN' => $takenIds]);
        }
        return $query->toArray();
    }

    /**
     * Sumamos el mapeo de la acción 'unlock' al permiso 'edit'.
     */
    protected function _actionToPermission(string $action): string
    {
        return match ($action) {
            'unlock' => 'edit',
            default => parent::_actionToPermission($action),
        };
    }
}
