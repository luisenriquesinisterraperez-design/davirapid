<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\CustomerService;
use Cake\ORM\Query\SelectQuery;

class CustomersController extends AppController
{
    public array $paginate = [
        'limit' => 15,
        'maxLimit' => 15,
        'order' => ['Customers.name' => 'ASC'],
        'sortableFields' => ['name', 'phone', 'created'],
    ];

    private CustomerService $customerService;

    public function initialize(): void
    {
        parent::initialize();
        $this->customerService = new CustomerService();
    }

    public function index(): void
    {
        $filters = $this->_currentFilters();
        $query = $this->_buildIndexQuery($filters);
        $customers = $this->paginate($query);

        $this->set(compact('customers', 'filters'));
        $this->set('breadcrumbs', [['label' => 'Clientes']]);
    }

    public function view(int $id): void
    {
        $customer = $this->Customers->get($id);
        $this->set('customer', $customer);
        $this->set('breadcrumbs', [
            ['label' => 'Clientes', 'url' => ['action' => 'index']],
            ['label' => $customer->name],
        ]);
    }

    public function add()
    {
        $customer = $this->Customers->newEmptyEntity();

        if ($this->request->is('post')) {
            $data = $this->request->getData();
            $data['is_active'] = !empty($data['is_active']);

            $result = $this->customerService->create($data);
            if ($result['success']) {
                $this->Flash->success('Cliente creado.');
                return $this->redirect(['action' => 'index']);
            }
            foreach ($result['errors'] ?? ['No se pudo crear el cliente.'] as $msg) {
                $this->Flash->error($msg);
            }
            $customer = $result['customer'] ?? $customer;
        }

        $this->set('customer', $customer);
        $this->set('breadcrumbs', [
            ['label' => 'Clientes', 'url' => ['action' => 'index']],
            ['label' => 'Nuevo cliente'],
        ]);
        return null;
    }

    public function edit(int $id)
    {
        $customer = $this->Customers->get($id);

        if ($this->request->is(['put', 'post', 'patch'])) {
            $data = $this->request->getData();
            $data['is_active'] = !empty($data['is_active']);

            $result = $this->customerService->update($customer, $data);
            if ($result['success']) {
                $this->Flash->success('Cliente actualizado.');
                return $this->redirect(['action' => 'index']);
            }
            foreach ($result['errors'] ?? ['No se pudo actualizar el cliente.'] as $msg) {
                $this->Flash->error($msg);
            }
            $customer = $result['customer'] ?? $customer;
        }

        $this->set('customer', $customer);
        $this->set('breadcrumbs', [
            ['label' => 'Clientes', 'url' => ['action' => 'index']],
            ['label' => $customer->name, 'url' => ['action' => 'view', $customer->id]],
            ['label' => 'Editar'],
        ]);
        return null;
    }

    public function delete(int $id)
    {
        $this->request->allowMethod(['post', 'delete']);

        try {
            $customer = $this->Customers->get($id);
        } catch (\Cake\Datasource\Exception\RecordNotFoundException) {
            $this->Flash->error('El cliente ya no existe.');
            return $this->redirect(['action' => 'index']);
        }

        $result = $this->customerService->delete($customer);
        if ($result['success']) {
            $this->Flash->success('Cliente eliminado.');
        } else {
            $this->Flash->error($result['errors'][0] ?? 'No se pudo eliminar el cliente.');
        }
        return $this->redirect(['action' => 'index']);
    }

    public function toggleActive(int $id)
    {
        $this->request->allowMethod(['post']);

        try {
            $customer = $this->Customers->get($id);
        } catch (\Cake\Datasource\Exception\RecordNotFoundException) {
            $this->Flash->error('El cliente ya no existe.');
            return $this->redirect(['action' => 'index']);
        }

        $result = $this->customerService->toggleActive($customer);
        if ($result['success']) {
            $msg = $result['customer']->is_active ? 'Cliente activado.' : 'Cliente desactivado.';
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

    /**
     * @return array{q: string, status: string, sort: string, direction: string}
     */
    protected function _currentFilters(): array
    {
        $allowedSort = ['name', 'phone', 'created'];
        $allowedStatus = ['all', 'active', 'inactive'];
        $allowedDir = ['asc', 'desc'];

        $sort = (string)$this->request->getQuery('sort', 'name');
        $direction = strtolower((string)$this->request->getQuery('direction', 'asc'));
        $status = (string)$this->request->getQuery('status', 'all');

        return [
            'q' => trim((string)$this->request->getQuery('q', '')),
            'status' => in_array($status, $allowedStatus, true) ? $status : 'all',
            'sort' => in_array($sort, $allowedSort, true) ? $sort : 'name',
            'direction' => in_array($direction, $allowedDir, true) ? $direction : 'asc',
        ];
    }

    protected function _buildIndexQuery(array $filters): SelectQuery
    {
        $query = $this->Customers->find();

        if ($filters['q'] !== '') {
            $like = '%' . $filters['q'] . '%';
            $query->where(['OR' => [
                'Customers.name LIKE' => $like,
                'Customers.phone LIKE' => $like,
            ]]);
        }

        if ($filters['status'] === 'active') {
            $query->where(['Customers.is_active' => true]);
        } elseif ($filters['status'] === 'inactive') {
            $query->where(['Customers.is_active' => false]);
        }

        $query->orderBy(['Customers.' . $filters['sort'] => strtoupper($filters['direction'])]);

        return $query;
    }
}
