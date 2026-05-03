<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\AuthorizationService;
use App\Service\LoginThrottleService;
use Cake\Controller\Controller;
use Cake\Event\EventInterface;
use Cake\Http\Exception\ForbiddenException;

class AppController extends Controller
{
    /**
     * Mapeo controller => módulo del catálogo AuthorizationService::MODULES.
     * Crece con cada fase nueva.
     */
    protected array $controllerModuleMap = [
        'Roles' => 'roles',
        'Users' => 'users',
        'Products' => 'products',
        'Customers' => 'customers',
    ];

    /**
     * Acciones públicas que NO requieren chequeo de permisos
     * (login, logout, y cualquier endpoint público de fases futuras).
     */
    protected array $publicActions = [
        'Users' => ['login', 'logout'],
    ];

    public AuthorizationService $authorization;

    public LoginThrottleService $throttle;

    public function initialize(): void
    {
        parent::initialize();

        $this->loadComponent('Flash');
        $this->loadComponent('Authentication.Authentication');

        $this->authorization = new AuthorizationService();
        $this->throttle = new LoginThrottleService();
    }

    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);

        $controller = (string)$this->request->getParam('controller');
        $action = (string)$this->request->getParam('action');

        // 1. Bypass de acciones públicas (login, logout, etc.).
        if (in_array($action, $this->publicActions[$controller] ?? [], true)) {
            return null;
        }

        // 2. Identidad obligatoria.
        $identity = $this->Authentication->getIdentity();
        if ($identity === null) {
            return $this->redirect(['controller' => 'Users', 'action' => 'login']);
        }

        $userArray = $this->_identityToArray($identity);

        // 3. Variables para todas las vistas autenticadas.
        $this->set('currentUser', $userArray);
        $this->set('currentRoleName', $userArray['role']['name'] ?? '—');
        $this->set('isAdministrator', !empty($userArray['role']['is_admin']));
        $this->set('userPermissions', $this->authorization->matrixFor($userArray));
        $this->set('sidebarCounters', []);
        $this->set('breadcrumbs', []);

        // 4. Enforce permission para esta request.
        $module = $this->controllerModuleMap[$controller] ?? null;
        if ($module === null) {
            return null;
        }

        $permAction = $this->_actionToPermission($action);
        if (!$this->authorization->isAllowed($userArray, $module, $permAction)) {
            throw new ForbiddenException('No tenés permiso para realizar esta acción.');
        }

        return null;
    }

    /**
     * Mapeo acción del controller => acción de permiso almacenada en DB.
     * Los controllers con acciones custom (ej. 'unlock', 'cancel') sobreescriben
     * este método para sumar entradas.
     */
    protected function _actionToPermission(string $action): string
    {
        return match ($action) {
            'index', 'view' => 'view',
            'add' => 'create',
            'edit' => 'edit',
            'delete' => 'delete',
            default => 'view',
        };
    }

    /**
     * Convierte la identity (entity User) a array plano consumible por AuthorizationService.
     *
     * @param \Authentication\IdentityInterface $identity
     */
    protected function _identityToArray($identity): array
    {
        $data = $identity->getOriginalData();
        if (is_array($data)) {
            return $data;
        }
        if (method_exists($data, 'toArray')) {
            $array = $data->toArray();
            if (isset($array['role']) && is_object($array['role']) && method_exists($array['role'], 'toArray')) {
                $array['role'] = $array['role']->toArray();
            }
            return $array;
        }
        return (array)$data;
    }
}
