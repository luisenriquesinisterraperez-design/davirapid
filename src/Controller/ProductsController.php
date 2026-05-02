<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\ProductService;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\ORM\Query\SelectQuery;

class ProductsController extends AppController
{
    public array $paginate = [
        'limit' => 15,
        'maxLimit' => 15,
        'order' => ['Products.name' => 'ASC'],
        'sortableFields' => ['name', 'price', 'created', 'code'],
    ];

    private ProductService $productService;

    public function initialize(): void
    {
        parent::initialize();
        $this->productService = new ProductService();
    }

    public function index(): void
    {
        $filters = $this->_currentFilters();
        $query = $this->_buildIndexQuery($filters);
        $products = $this->paginate($query);

        $this->set(compact('products', 'filters'));
        $this->set('breadcrumbs', [['label' => 'Productos']]);
    }

    public function view(int $id): void
    {
        $product = $this->Products->get($id);
        $this->set('product', $product);
        $this->set('breadcrumbs', [
            ['label' => 'Productos', 'url' => ['action' => 'index']],
            ['label' => $product->name],
        ]);
    }

    public function add()
    {
        $product = $this->Products->newEmptyEntity();

        if ($this->request->is('post')) {
            $image = $this->request->getUploadedFile('image');
            $data = $this->request->getData();
            // Hidden input default; convert form value to bool.
            $data['is_active'] = !empty($data['is_active']);

            $result = $this->productService->create($data, $image);
            if ($result['success']) {
                $this->Flash->success('Producto creado.');
                return $this->redirect(['action' => 'index']);
            }
            foreach ($result['errors'] ?? ['No se pudo crear el producto.'] as $msg) {
                $this->Flash->error($msg);
            }
            $product = $result['product'] ?? $product;
        }

        $this->set('product', $product);
        $this->set('breadcrumbs', [
            ['label' => 'Productos', 'url' => ['action' => 'index']],
            ['label' => 'Nuevo producto'],
        ]);
        return null;
    }

    public function edit(int $id)
    {
        $product = $this->Products->get($id);

        if ($this->request->is(['put', 'post', 'patch'])) {
            $image = $this->request->getUploadedFile('image');
            $data = $this->request->getData();
            $data['is_active'] = !empty($data['is_active']);

            $result = $this->productService->update($product, $data, $image);
            if ($result['success']) {
                $this->Flash->success('Producto actualizado.');
                return $this->redirect(['action' => 'index']);
            }
            foreach ($result['errors'] ?? ['No se pudo actualizar el producto.'] as $msg) {
                $this->Flash->error($msg);
            }
            $product = $result['product'] ?? $product;
        }

        $this->set('product', $product);
        $this->set('breadcrumbs', [
            ['label' => 'Productos', 'url' => ['action' => 'index']],
            ['label' => $product->name, 'url' => ['action' => 'view', $product->id]],
            ['label' => 'Editar'],
        ]);
        return null;
    }

    public function delete(int $id)
    {
        $this->request->allowMethod(['post', 'delete']);

        try {
            $product = $this->Products->get($id);
        } catch (RecordNotFoundException) {
            $this->Flash->error('El producto ya no existe.');
            return $this->redirect(['action' => 'index']);
        }

        $result = $this->productService->delete($product);
        if ($result['success']) {
            $this->Flash->success('Producto eliminado.');
        } else {
            $this->Flash->error($result['errors'][0] ?? 'No se pudo eliminar el producto.');
        }
        return $this->redirect(['action' => 'index']);
    }

    public function toggleActive(int $id)
    {
        $this->request->allowMethod(['post']);

        try {
            $product = $this->Products->get($id);
        } catch (RecordNotFoundException) {
            $this->Flash->error('El producto ya no existe.');
            return $this->redirect(['action' => 'index']);
        }

        $result = $this->productService->toggleActive($product);
        if ($result['success']) {
            $msg = $result['product']->is_active ? 'Producto activado.' : 'Producto desactivado.';
            $this->Flash->success($msg);
        } else {
            $this->Flash->error($result['errors'][0] ?? 'No se pudo cambiar el estado.');
        }
        return $this->redirect($this->referer(['action' => 'index']));
    }

    /**
     * Sumamos el mapeo de la acción 'toggleActive' al permiso 'edit'.
     */
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
        $allowedSort = ['name', 'price', 'created', 'code'];
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
        $query = $this->Products->find();

        if ($filters['q'] !== '') {
            $like = '%' . $filters['q'] . '%';
            $query->where(['OR' => [
                'Products.name LIKE' => $like,
                'Products.code LIKE' => $like,
            ]]);
        }

        if ($filters['status'] === 'active') {
            $query->where(['Products.is_active' => true]);
        } elseif ($filters['status'] === 'inactive') {
            $query->where(['Products.is_active' => false]);
        }

        $query->orderBy(['Products.' . $filters['sort'] => strtoupper($filters['direction'])]);

        return $query;
    }
}
