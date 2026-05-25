<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\AuthorizationService;
use App\Service\LoginThrottleService;
use Authentication\IdentityInterface;
use Cake\Controller\Controller;
use Cake\Event\EventInterface;
use Cake\Http\Exception\ForbiddenException;
use Cake\ORM\Query\SelectQuery;

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
        'Deliveries' => 'deliveries',
        'Ingredients' => 'ingredients',
        'Recipes' => 'recipes',
        'Adjustments' => 'adjustments',
        'Orders' => 'orders',
        'OrderLogs' => 'audit',
        'Receivables' => 'receivables',
        'AccountPayments' => 'account_payments',
        'Expenses' => 'expenses',
        'CashCloses' => 'cash_closes',
        'Dashboard' => 'dashboard',
    ];

    /**
     * Acciones públicas que NO requieren chequeo de permisos
     * (login, logout, y cualquier endpoint público de fases futuras).
     */
    protected array $publicActions = [
        'Users' => ['login', 'logout'],
    ];

    /**
     * Override per-acción del mapeo a módulo. Las subclases declaran acá las
     * acciones cuyo permiso debe chequearse contra un módulo distinto al que
     * corresponde al controller (ej: una acción de ProductsController que
     * realmente pertenece al módulo 'recipes' a efectos de RBAC).
     *
     * Vacío por default = sin override; cae al $controllerModuleMap.
     *
     * @var array<string, string> Mapa action => moduleKey.
     */
    protected array $actionModuleMap = [];

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
        $module = $this->actionModuleMap[$action]
            ?? $this->controllerModuleMap[$controller]
            ?? null;
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
     * Si el currentUser está vinculado a un repartidor (delivery_id no nulo),
     * restringe la query al alias dado a sus propios pedidos. Regla §21 acceso 4.
     *
     * Early-return seguro si el user no es repartidor — invocable siempre.
     */
    protected function _scopeToRepartidor(SelectQuery $query, string $alias = 'Orders'): SelectQuery
    {
        $deliveryId = $this->_currentDeliveryId();
        if ($deliveryId === null) {
            return $query;
        }

        return $query->where(["{$alias}.delivery_id" => $deliveryId]);
    }

    /**
     * Guard para vistas puntuales (view/edit/cancel/...). Si el user es repartidor
     * y el delivery_id del pedido no coincide con el suyo, 403.
     */
    protected function _enforceRepartidorAccess(?int $orderDeliveryId): void
    {
        $deliveryId = $this->_currentDeliveryId();
        if ($deliveryId !== null && (int)$orderDeliveryId !== $deliveryId) {
            throw new ForbiddenException('No tenés acceso a este pedido.');
        }
    }

    /**
     * Returns the delivery_id linked to the current user (if any).
     * Defensive: `(int)null === 0` would be a valid id, so we check is_numeric first.
     */
    protected function _currentDeliveryId(): ?int
    {
        $identity = $this->Authentication->getIdentity();
        if ($identity === null) {
            return null;
        }
        $delivery = $identity->get('delivery_id');

        return is_numeric($delivery) ? (int)$delivery : null;
    }

    /**
     * Convierte la identity (entity User) a array plano consumible por AuthorizationService.
     *
     * @param \Authentication\IdentityInterface $identity
     */
    protected function _identityToArray(IdentityInterface $identity): array
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
