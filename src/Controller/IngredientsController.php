<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\IngredientConstants;
use App\Service\IngredientService;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\ORM\Query\SelectQuery;

/**
 * Ingredients (Insumos) — CRUD controller for the inventory item master.
 */
class IngredientsController extends AppController
{
    /**
     * @var array<string, mixed>
     */
    public array $paginate = [
        'limit' => 15,
        'maxLimit' => 15,
        'order' => ['Ingredients.name' => 'ASC'],
        'sortableFields' => ['name', 'stock_quantity', 'unit_cost', 'created'],
    ];

    private IngredientService $ingredientService;

    /**
     * Wires the IngredientService dependency.
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->ingredientService = new IngredientService();
    }

    /**
     * Paginated listing with search, unit and low-stock filters.
     */
    public function index(): void
    {
        $filters = $this->_currentFilters();
        $query = $this->_buildIndexQuery($filters);
        $ingredients = $this->paginate($query);

        $this->set(compact('ingredients', 'filters'));
        $this->set('breadcrumbs', [['label' => 'Ingredientes']]);
    }

    /**
     * Detail view for a single ingredient.
     */
    public function view(int $id): void
    {
        $ingredient = $this->Ingredients->get($id);
        $this->set('ingredient', $ingredient);
        $this->set('breadcrumbs', [
            ['label' => 'Ingredientes', 'url' => ['action' => 'index']],
            ['label' => $ingredient->name],
        ]);
    }

    /**
     * Create a new ingredient.
     *
     * @return \Cake\Http\Response|null
     */
    public function add()
    {
        $ingredient = $this->Ingredients->newEmptyEntity();

        if ($this->request->is('post')) {
            $result = $this->ingredientService->create($this->request->getData());
            if ($result['success']) {
                $this->Flash->success('Ingrediente creado.');

                return $this->redirect(['action' => 'index']);
            }
            foreach ($result['errors'] ?? ['No se pudo crear el ingrediente.'] as $msg) {
                $this->Flash->error($msg);
            }
            $ingredient = $result['ingredient'] ?? $ingredient;
        }

        $this->set('ingredient', $ingredient);
        $this->set('breadcrumbs', [
            ['label' => 'Ingredientes', 'url' => ['action' => 'index']],
            ['label' => 'Nuevo ingrediente'],
        ]);

        return null;
    }

    /**
     * Edit an existing ingredient.
     *
     * @return \Cake\Http\Response|null
     */
    public function edit(int $id)
    {
        $ingredient = $this->Ingredients->get($id);

        if ($this->request->is(['put', 'post', 'patch'])) {
            $result = $this->ingredientService->update($ingredient, $this->request->getData());
            if ($result['success']) {
                $this->Flash->success('Ingrediente actualizado.');

                return $this->redirect(['action' => 'index']);
            }
            foreach ($result['errors'] ?? ['No se pudo actualizar el ingrediente.'] as $msg) {
                $this->Flash->error($msg);
            }
            $ingredient = $result['ingredient'] ?? $ingredient;
        }

        $this->set('ingredient', $ingredient);
        $this->set('breadcrumbs', [
            ['label' => 'Ingredientes', 'url' => ['action' => 'index']],
            ['label' => $ingredient->name, 'url' => ['action' => 'view', $ingredient->id]],
            ['label' => 'Editar'],
        ]);

        return null;
    }

    /**
     * Delete an ingredient. Cascade for recipes/adjustments arrives with
     * those modules' migrations.
     *
     * @return \Cake\Http\Response|null
     */
    public function delete(int $id)
    {
        $this->request->allowMethod(['post', 'delete']);

        try {
            $ingredient = $this->Ingredients->get($id);
        } catch (RecordNotFoundException) {
            $this->Flash->error('El ingrediente ya no existe.');

            return $this->redirect(['action' => 'index']);
        }

        $result = $this->ingredientService->delete($ingredient);
        if ($result['success']) {
            $this->Flash->success('Ingrediente eliminado.');
        } else {
            $this->Flash->error($result['errors'][0] ?? 'No se pudo eliminar el ingrediente.');
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * @return array{q: string, unit: string, low_stock: string, sort: string, direction: string}
     */
    protected function _currentFilters(): array
    {
        $allowedSort = ['name', 'stock_quantity', 'unit_cost', 'created'];
        $allowedUnits = array_merge(['all'], IngredientConstants::UNITS);
        $allowedDir = ['asc', 'desc'];

        $sort = (string)$this->request->getQuery('sort', 'name');
        $direction = strtolower((string)$this->request->getQuery('direction', 'asc'));
        $unit = (string)$this->request->getQuery('unit', 'all');
        $lowStock = (string)$this->request->getQuery('low_stock', '0');

        return [
            'q' => trim((string)$this->request->getQuery('q', '')),
            'unit' => in_array($unit, $allowedUnits, true) ? $unit : 'all',
            'low_stock' => $lowStock === '1' ? '1' : '0',
            'sort' => in_array($sort, $allowedSort, true) ? $sort : 'name',
            'direction' => in_array($direction, $allowedDir, true) ? $direction : 'asc',
        ];
    }

    /**
     * @param array{q: string, unit: string, low_stock: string, sort: string, direction: string} $filters
     */
    protected function _buildIndexQuery(array $filters): SelectQuery
    {
        $query = $this->Ingredients->find();

        if ($filters['q'] !== '') {
            $query->where(['Ingredients.name LIKE' => '%' . $filters['q'] . '%']);
        }

        if ($filters['unit'] !== 'all') {
            $query->where(['Ingredients.unit' => $filters['unit']]);
        }

        if ($filters['low_stock'] === '1') {
            $query->where([
                'Ingredients.stock_quantity <=' => IngredientConstants::LOW_STOCK_THRESHOLD,
            ]);
        }

        $query->orderBy(['Ingredients.' . $filters['sort'] => strtoupper($filters['direction'])]);

        return $query;
    }
}
