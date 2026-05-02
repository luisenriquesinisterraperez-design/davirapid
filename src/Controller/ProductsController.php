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
        // implemented in Task 8
    }

    public function edit(int $id)
    {
        // implemented in Task 9
    }

    public function delete(int $id)
    {
        // implemented in Task 10
    }

    public function toggleActive(int $id)
    {
        // implemented in Task 10
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
