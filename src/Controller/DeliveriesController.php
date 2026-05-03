<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\DeliveryService;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\ORM\Query\SelectQuery;

class DeliveriesController extends AppController
{
    public array $paginate = [
        'limit' => 15,
        'maxLimit' => 15,
        'order' => ['Deliveries.is_active' => 'DESC', 'Deliveries.last_name' => 'ASC', 'Deliveries.first_name' => 'ASC'],
        'sortableFields' => ['first_name', 'last_name', 'phone', 'created'],
    ];

    private DeliveryService $deliveryService;

    public function initialize(): void
    {
        parent::initialize();
        $this->deliveryService = new DeliveryService();
    }

    public function index(): void
    {
        $filters = $this->_currentFilters();
        $query = $this->_buildIndexQuery($filters);
        $deliveries = $this->paginate($query);

        $this->set(compact('deliveries', 'filters'));
        $this->set('breadcrumbs', [['label' => 'Repartidores']]);
    }

    public function view(int $id): void
    {
        $delivery = $this->Deliveries->get($id, contain: ['Users' => ['Roles']]);
        $this->set('delivery', $delivery);
        $this->set('breadcrumbs', [
            ['label' => 'Repartidores', 'url' => ['action' => 'index']],
            ['label' => $delivery->full_name],
        ]);
    }

    public function add()
    {
        $delivery = $this->Deliveries->newEmptyEntity();

        if ($this->request->is('post')) {
            $data = $this->request->getData();
            $data['is_active'] = !empty($data['is_active']);

            $result = $this->deliveryService->create($data);
            if ($result['success']) {
                $this->Flash->success('Repartidor creado.');
                return $this->redirect(['action' => 'index']);
            }
            foreach ($result['errors'] ?? ['No se pudo crear el repartidor.'] as $msg) {
                $this->Flash->error($msg);
            }
            $delivery = $result['delivery'] ?? $delivery;
        }

        $this->set('delivery', $delivery);
        $this->set('breadcrumbs', [
            ['label' => 'Repartidores', 'url' => ['action' => 'index']],
            ['label' => 'Nuevo repartidor'],
        ]);
        return null;
    }

    public function edit(int $id)
    {
        $delivery = $this->Deliveries->get($id);

        if ($this->request->is(['put', 'post', 'patch'])) {
            $data = $this->request->getData();
            $data['is_active'] = !empty($data['is_active']);

            $result = $this->deliveryService->update($delivery, $data);
            if ($result['success']) {
                $this->Flash->success('Repartidor actualizado.');
                return $this->redirect(['action' => 'index']);
            }
            foreach ($result['errors'] ?? ['No se pudo actualizar el repartidor.'] as $msg) {
                $this->Flash->error($msg);
            }
            $delivery = $result['delivery'] ?? $delivery;
        }

        $this->set('delivery', $delivery);
        $this->set('breadcrumbs', [
            ['label' => 'Repartidores', 'url' => ['action' => 'index']],
            ['label' => $delivery->full_name, 'url' => ['action' => 'view', $delivery->id]],
            ['label' => 'Editar'],
        ]);
        return null;
    }

    public function toggleActive(int $id)
    {
        $this->request->allowMethod(['post']);

        try {
            $delivery = $this->Deliveries->get($id);
        } catch (RecordNotFoundException) {
            $this->Flash->error('El repartidor ya no existe.');
            return $this->redirect(['action' => 'index']);
        }

        $result = $this->deliveryService->toggleActive($delivery);
        if ($result['success']) {
            $msg = $result['delivery']->is_active ? 'Repartidor activado.' : 'Repartidor desactivado.';
            $this->Flash->success($msg);
        } else {
            $this->Flash->error($result['errors'][0] ?? 'No se pudo cambiar el estado.');
        }
        return $this->redirect($this->referer(['action' => 'index']));
    }

    protected function _actionToPermission(string $action): string
    {
        return match ($action) {
            'toggleActive' => 'edit',
            default => parent::_actionToPermission($action),
        };
    }

    protected function _currentFilters(): array
    {
        $allowedSort = ['first_name', 'last_name', 'phone', 'created'];
        $allowedStatus = ['all', 'active', 'inactive'];
        $allowedDir = ['asc', 'desc'];

        $sort = (string)$this->request->getQuery('sort', 'last_name');
        $direction = strtolower((string)$this->request->getQuery('direction', 'asc'));
        $status = (string)$this->request->getQuery('status', 'all');

        return [
            'q' => trim((string)$this->request->getQuery('q', '')),
            'status' => in_array($status, $allowedStatus, true) ? $status : 'all',
            'sort' => in_array($sort, $allowedSort, true) ? $sort : 'last_name',
            'direction' => in_array($direction, $allowedDir, true) ? $direction : 'asc',
        ];
    }

    protected function _buildIndexQuery(array $filters): SelectQuery
    {
        $query = $this->Deliveries->find()->contain(['Users']);

        if ($filters['q'] !== '') {
            $like = '%' . $filters['q'] . '%';
            $query->where(['OR' => [
                'Deliveries.first_name LIKE' => $like,
                'Deliveries.last_name LIKE' => $like,
                'Deliveries.phone LIKE' => $like,
            ]]);
        }

        if ($filters['status'] === 'active') {
            $query->where(['Deliveries.is_active' => true]);
        } elseif ($filters['status'] === 'inactive') {
            $query->where(['Deliveries.is_active' => false]);
        }

        $query->orderBy([
            'Deliveries.' . $filters['sort'] => strtoupper($filters['direction']),
        ]);

        return $query;
    }
}
