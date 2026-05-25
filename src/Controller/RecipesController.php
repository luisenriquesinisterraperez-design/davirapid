<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\ORM\Query\SelectQuery;

/**
 * RecipesController — listado global de productos con estado de receta.
 * Las mutaciones sobre líneas de receta viven en ProductsController nested.
 */
class RecipesController extends AppController
{
    /**
     * @var array<string, mixed>
     */
    public array $paginate = [
        'limit' => 15,
        'maxLimit' => 15,
        'order' => ['Products.name' => 'ASC'],
        'sortableFields' => ['name', 'price'],
    ];

    /**
     * Listado global paginado con filtros de búsqueda y estado de receta.
     */
    public function index(): void
    {
        $filters = $this->_currentFilters();
        $query = $this->_buildIndexQuery($filters);
        $products = $this->paginate($query);

        $this->set(compact('products', 'filters'));
        $this->set('breadcrumbs', [['label' => 'Recetas']]);
    }

    /**
     * @return array{q: string, has_recipe: string}
     */
    protected function _currentFilters(): array
    {
        $allowedHasRecipe = ['all', 'with', 'without'];
        $hr = (string)$this->request->getQuery('has_recipe', 'all');

        return [
            'q' => trim((string)$this->request->getQuery('q', '')),
            'has_recipe' => in_array($hr, $allowedHasRecipe, true) ? $hr : 'all',
        ];
    }

    /**
     * @param array{q: string, has_recipe: string} $filters
     */
    protected function _buildIndexQuery(array $filters): SelectQuery
    {
        $query = $this->fetchTable('Products')
            ->find()
            ->contain(['ProductIngredients' => ['Ingredients']]);

        if ($filters['q'] !== '') {
            $like = '%' . $filters['q'] . '%';
            $query->where(['OR' => [
                'Products.name LIKE' => $like,
                'Products.code LIKE' => $like,
            ]]);
        }
        if ($filters['has_recipe'] === 'with') {
            $sub = $this->fetchTable('ProductIngredients')->find()
                ->select(['ProductIngredients.product_id'])
                ->distinct();
            $query->where(['Products.id IN' => $sub]);
        } elseif ($filters['has_recipe'] === 'without') {
            $sub = $this->fetchTable('ProductIngredients')->find()
                ->select(['ProductIngredients.product_id'])
                ->distinct();
            $query->where(['Products.id NOT IN' => $sub]);
        }

        return $query;
    }
}
