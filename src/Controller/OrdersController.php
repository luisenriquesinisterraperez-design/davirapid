<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\OrderConstants;
use App\Model\Table\DeliveriesTable;
use App\Model\Table\OrderItemsTable;
use App\Model\Table\OrderLogsTable;
use App\Model\Table\OrdersTable;
use App\Model\Table\ProductsTable;
use App\Service\OrderFilterService;
use App\Service\OrderPipelineService;
use App\Service\OrderService;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Http\Exception\ForbiddenException;
use Cake\ORM\Query\SelectQuery;

/**
 * Orders Controller.
 *
 * Hosts the pedido workflow: CRUD + custom advance/cancel/reactivate/ticket.
 * All non-trivial logic delegates to OrderService / OrderPipelineService.
 */
class OrdersController extends AppController
{
    /**
     * @var array<string, mixed>
     */
    public array $paginate = [
        'limit' => 15,
        'maxLimit' => 15,
        'order' => [
            'Orders.created' => 'DESC',
            'Orders.id' => 'DESC',
        ],
        'sortableFields' => ['created', 'id', 'total', 'status'],
    ];

    private OrderService $orderService;
    private OrderPipelineService $pipeline;
    private OrderFilterService $filters;
    private OrdersTable $Orders;
    private OrderItemsTable $OrderItems;
    private OrderLogsTable $OrderLogs;
    private ProductsTable $Products;
    private DeliveriesTable $Deliveries;

    /**
     * Wires services and explicitly loads tables.
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->orderService = new OrderService();
        $this->pipeline = new OrderPipelineService();
        $this->filters = new OrderFilterService();

        /** @var \App\Model\Table\OrdersTable $orders */
        $orders = $this->fetchTable('Orders');
        $this->Orders = $orders;
        /** @var \App\Model\Table\OrderItemsTable $items */
        $items = $this->fetchTable('OrderItems');
        $this->OrderItems = $items;
        /** @var \App\Model\Table\OrderLogsTable $logs */
        $logs = $this->fetchTable('OrderLogs');
        $this->OrderLogs = $logs;
        /** @var \App\Model\Table\ProductsTable $products */
        $products = $this->fetchTable('Products');
        $this->Products = $products;
        /** @var \App\Model\Table\DeliveriesTable $deliveries */
        $deliveries = $this->fetchTable('Deliveries');
        $this->Deliveries = $deliveries;
    }

    /**
     * Maps custom actions to RBAC permission keys.
     */
    protected function _actionToPermission(string $action): string
    {
        return match ($action) {
            'index', 'view', 'ticket' => 'view',
            'add' => 'create',
            'edit', 'advance', 'reactivate', 'cancel' => 'edit',
            'delete' => 'delete',
            default => parent::_actionToPermission($action),
        };
    }

    /**
     * Paginated index with KPI strip, filters, and repartidor scoping.
     */
    public function index(): void
    {
        $filters = $this->_currentFilters();
        $query = $this->_buildIndexQuery($filters);
        $orders = $this->paginate($query);

        $kpis = $this->_computeKpis();
        $deliveries = $this->Deliveries->find('list', keyField: 'id', valueField: function ($d) {
            return trim(($d->first_name ?? '') . ' ' . ($d->last_name ?? ''));
        })->toArray();

        $this->set(compact('orders', 'filters', 'kpis', 'deliveries'));
        $this->set('isRepartidor', $this->_currentDeliveryId() !== null);
        $this->set('breadcrumbs', [['label' => 'Pedidos']]);
    }

    /**
     * @return \Cake\Http\Response|null
     */
    public function view(int $id)
    {
        try {
            /** @var \App\Model\Entity\Order $order */
            $order = $this->Orders->get($id, [
                'contain' => [
                    'OrderItems' => ['Products'],
                    'Customers',
                    'Deliveries',
                    'Users',
                    'CancelledByUser',
                ],
            ]);
        } catch (RecordNotFoundException) {
            $this->Flash->error('Pedido no encontrado.');

            return $this->redirect(['action' => 'index']);
        }

        $this->_enforceRepartidorAccess($order->delivery_id);

        $logs = $this->OrderLogs->find('forOrder', order_id: $id)->limit(5)->toArray();
        $nextStates = $this->pipeline->nextValidStates($order);

        $this->set(compact('order', 'logs', 'nextStates'));
        $this->set('breadcrumbs', [
            ['label' => 'Pedidos', 'url' => ['action' => 'index']],
            ['label' => '#' . $order->id],
        ]);

        return null;
    }

    /**
     * @return \Cake\Http\Response|null
     */
    public function add()
    {
        if (!$this->_canCreateOrders()) {
            throw new ForbiddenException('Los repartidores no pueden crear pedidos.');
        }

        /** @var \App\Model\Entity\Order $order */
        $order = $this->Orders->newEmptyEntity();

        if ($this->request->is(['post', 'put'])) {
            $identity = $this->Authentication->getIdentity();
            $userId = $identity !== null ? (int)$identity->get('id') : 0;
            $data = (array)$this->request->getData();
            $result = $this->orderService->create($data, $userId);
            if (!empty($result['success']) && isset($result['order'])) {
                $this->Flash->success(sprintf('Pedido #%d creado correctamente.', $result['order']->id));

                return $this->redirect(['action' => 'view', $result['order']->id]);
            }
            foreach ((array)($result['errors'] ?? ['No se pudo crear el pedido.']) as $msg) {
                $this->Flash->error((string)$msg);
            }
            // Re-hydrate entity with POST data for re-display.
            $order = $this->Orders->patchEntity($order, $data);
        }

        $productsList = $this->Products->find()
            ->where(['Products.is_active' => true])
            ->all()
            ->toArray();
        $deliveriesList = $this->Deliveries->find()
            ->where(['Deliveries.is_active' => true])
            ->all()
            ->toArray();

        $this->set(compact('order', 'productsList', 'deliveriesList'));
        $this->set('breadcrumbs', [
            ['label' => 'Pedidos', 'url' => ['action' => 'index']],
            ['label' => 'Nuevo pedido'],
        ]);

        return null;
    }

    /**
     * @return \Cake\Http\Response|null
     */
    public function edit(int $id)
    {
        try {
            /** @var \App\Model\Entity\Order $order */
            $order = $this->Orders->get($id, [
                'contain' => ['OrderItems' => ['Products']],
            ]);
        } catch (RecordNotFoundException) {
            $this->Flash->error('Pedido no encontrado.');

            return $this->redirect(['action' => 'index']);
        }

        $this->_enforceRepartidorAccess($order->delivery_id);

        if (!$order->isEditable()) {
            $this->Flash->warning('Este pedido no se puede editar en su estado actual.');

            return $this->redirect(['action' => 'view', $id]);
        }

        if ($this->request->is(['post', 'put'])) {
            $identity = $this->Authentication->getIdentity();
            $userId = $identity !== null ? (int)$identity->get('id') : 0;
            $data = (array)$this->request->getData();
            $result = $this->orderService->update($order, $data, $userId);
            if (!empty($result['success'])) {
                $this->Flash->success('Pedido actualizado correctamente.');

                return $this->redirect(['action' => 'view', $id]);
            }
            foreach ((array)($result['errors'] ?? ['No se pudo actualizar el pedido.']) as $msg) {
                $this->Flash->error((string)$msg);
            }
        }

        $productsList = $this->Products->find()
            ->where(['Products.is_active' => true])
            ->all()
            ->toArray();
        $deliveriesList = $this->Deliveries->find()
            ->where(['Deliveries.is_active' => true])
            ->all()
            ->toArray();

        $this->set(compact('order', 'productsList', 'deliveriesList'));
        $this->set('breadcrumbs', [
            ['label' => 'Pedidos', 'url' => ['action' => 'index']],
            ['label' => '#' . $order->id, 'url' => ['action' => 'view', $order->id]],
            ['label' => 'Editar'],
        ]);

        return null;
    }

    /**
     * @return \Cake\Http\Response|null
     */
    public function delete(int $id)
    {
        $this->request->allowMethod(['post', 'delete']);

        try {
            /** @var \App\Model\Entity\Order $order */
            $order = $this->Orders->get($id, [
                'contain' => ['OrderItems'],
            ]);
        } catch (RecordNotFoundException) {
            $this->Flash->error('Pedido no encontrado.');

            return $this->redirect(['action' => 'index']);
        }

        $this->_enforceRepartidorAccess($order->delivery_id);

        $identity = $this->Authentication->getIdentity();
        $userId = $identity !== null ? (int)$identity->get('id') : 0;
        $result = $this->orderService->delete($order, $userId);
        if (!empty($result['success'])) {
            $this->Flash->success('Pedido eliminado.');
        } else {
            $this->Flash->error($result['errors'][0] ?? 'No se pudo eliminar el pedido.');
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * @return \Cake\Http\Response|null
     */
    public function cancel(int $id)
    {
        $this->request->allowMethod('post');

        try {
            /** @var \App\Model\Entity\Order $order */
            $order = $this->Orders->get($id, [
                'contain' => ['OrderItems'],
            ]);
        } catch (RecordNotFoundException) {
            $this->Flash->error('Pedido no encontrado.');

            return $this->redirect(['action' => 'index']);
        }

        $this->_enforceRepartidorAccess($order->delivery_id);

        $identity = $this->Authentication->getIdentity();
        $userId = $identity !== null ? (int)$identity->get('id') : 0;
        $reason = $this->request->getData('reason');
        $reason = is_string($reason) ? trim($reason) : null;
        $result = $this->orderService->cancel($order, $userId, $reason);
        if (!empty($result['success'])) {
            $this->Flash->success('Pedido cancelado.');
        } else {
            $this->Flash->error($result['errors'][0] ?? 'No se pudo cancelar el pedido.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * @return \Cake\Http\Response|null
     */
    public function reactivate(int $id)
    {
        $this->request->allowMethod('post');

        try {
            /** @var \App\Model\Entity\Order $order */
            $order = $this->Orders->get($id, [
                'contain' => ['OrderItems'],
            ]);
        } catch (RecordNotFoundException) {
            $this->Flash->error('Pedido no encontrado.');

            return $this->redirect(['action' => 'index']);
        }

        $this->_enforceRepartidorAccess($order->delivery_id);

        $identity = $this->Authentication->getIdentity();
        $userId = $identity !== null ? (int)$identity->get('id') : 0;
        $result = $this->orderService->reactivate($order, $userId);
        if (!empty($result['success'])) {
            $this->Flash->success('Pedido reactivado.');
        } else {
            $this->Flash->error($result['errors'][0] ?? 'No se pudo reactivar el pedido.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * @return \Cake\Http\Response|null
     */
    public function advance(int $id)
    {
        $this->request->allowMethod('post');

        try {
            /** @var \App\Model\Entity\Order $order */
            $order = $this->Orders->get($id);
        } catch (RecordNotFoundException) {
            $this->Flash->error('Pedido no encontrado.');

            return $this->redirect(['action' => 'index']);
        }

        $this->_enforceRepartidorAccess($order->delivery_id);

        $identity = $this->Authentication->getIdentity();
        $userId = $identity !== null ? (int)$identity->get('id') : 0;
        $toStatus = (string)$this->request->getData('to_status');
        $result = $this->pipeline->advance($order, $toStatus, $userId);
        if (!empty($result['success'])) {
            $this->Flash->success('Estado actualizado.');
        } else {
            $this->Flash->error($result['errors'][0] ?? 'No se pudo cambiar el estado.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * @return \Cake\Http\Response|null
     */
    public function ticket(int $id)
    {
        try {
            /** @var \App\Model\Entity\Order $order */
            $order = $this->Orders->get($id, [
                'contain' => ['OrderItems' => ['Products'], 'Customers', 'Deliveries'],
            ]);
        } catch (RecordNotFoundException) {
            $this->Flash->error('Pedido no encontrado.');

            return $this->redirect(['action' => 'index']);
        }

        $this->_enforceRepartidorAccess($order->delivery_id);

        $this->viewBuilder()->setLayout('ticket');
        $this->set('order', $order);

        return null;
    }

    // -------------------- Helpers --------------------

    /**
     * @param array<string, mixed> $filters
     */
    protected function _buildIndexQuery(array $filters): SelectQuery
    {
        $query = $this->Orders->find()
            ->contain(['Customers', 'Deliveries', 'Users']);
        $this->_scopeToRepartidor($query);

        return $this->filters->apply($query, $filters);
    }

    /**
     * @return array<string, mixed>
     */
    protected function _currentFilters(): array
    {
        $allowedStatus = array_merge(['visible', 'all'], OrderConstants::STATUSES);
        $allowedType = array_merge(['all'], OrderConstants::TYPES);
        $allowedMethod = array_merge(['all'], OrderConstants::PAYMENT_METHODS);

        $status = (string)$this->request->getQuery('status', 'visible');
        $type = (string)$this->request->getQuery('type', 'all');
        $method = (string)$this->request->getQuery('payment_method', 'all');
        $isRepartidor = $this->_currentDeliveryId() !== null;
        $deliveryId = $isRepartidor ? 0 : (int)$this->request->getQuery('delivery_id', 0);

        return [
            'q' => trim((string)$this->request->getQuery('q', '')),
            'status' => in_array($status, $allowedStatus, true) ? $status : 'visible',
            'type' => in_array($type, $allowedType, true) ? $type : 'all',
            'payment_method' => in_array($method, $allowedMethod, true) ? $method : 'all',
            'delivery_id' => $deliveryId,
            'customer' => trim((string)$this->request->getQuery('customer', '')),
            'from' => trim((string)$this->request->getQuery('from', '')),
            'to' => trim((string)$this->request->getQuery('to', '')),
        ];
    }

    /**
     * Compute the four KPI cards for the index strip.
     *
     * @return array<string, int|string>
     */
    protected function _computeKpis(): array
    {
        $today = date('Y-m-d');
        $isRepartidor = $this->_currentDeliveryId() !== null;
        $deliveryId = $this->_currentDeliveryId();

        $base = $this->Orders->find()
            ->where(['Orders.created >=' => $today . ' 00:00:00'])
            ->where(['Orders.created <=' => $today . ' 23:59:59']);

        if ($isRepartidor && $deliveryId !== null) {
            $base->where(['Orders.delivery_id' => $deliveryId]);
        }

        $ordersToday = (clone $base)
            ->where(['Orders.status !=' => OrderConstants::STATUS_CANCELLED])
            ->count();
        $salesTodayRows = (clone $base)
            ->where(['Orders.status !=' => OrderConstants::STATUS_CANCELLED])
            ->select(['t' => $base->func()->sum('Orders.total')])
            ->disableHydration()
            ->first();
        $salesToday = $salesTodayRows['t'] ?? '0';

        $preparingQ = $this->Orders->find()
            ->where(['Orders.status' => OrderConstants::STATUS_PREPARING]);
        if ($isRepartidor && $deliveryId !== null) {
            $preparingQ->where(['Orders.delivery_id' => $deliveryId]);
        }
        $preparing = $preparingQ->count();

        $onRouteQ = $this->Orders->find()
            ->where(['Orders.status' => OrderConstants::STATUS_ON_ROUTE]);
        if ($isRepartidor && $deliveryId !== null) {
            $onRouteQ->where(['Orders.delivery_id' => $deliveryId]);
        }
        $onRoute = $onRouteQ->count();

        return [
            'orders_today' => $ordersToday,
            'sales_today' => (string)$salesToday,
            'preparing' => $preparing,
            'on_route' => $onRoute,
        ];
    }

    /**
     * Repartidores don't create orders — only consult them.
     */
    protected function _canCreateOrders(): bool
    {
        return $this->_currentDeliveryId() === null;
    }
}
