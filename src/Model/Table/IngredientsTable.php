<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\IngredientConstants;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Ingredients Table — inventory item master table.
 */
class IngredientsTable extends Table
{
    /**
     * @param array<string, mixed> $config
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('ingredients');
        $this->setPrimaryKey('id');
        $this->setDisplayField('name');
        $this->addBehavior('Timestamp');

        $this->hasMany('ProductIngredients', [
            'foreignKey' => 'ingredient_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);

        $this->belongsToMany('Products', [
            'through' => 'ProductIngredients',
            'foreignKey' => 'ingredient_id',
            'targetForeignKey' => 'product_id',
            'joinTable' => 'product_ingredients',
        ]);

        // Future associations declared when their tables exist:
        // $this->hasMany('InventoryAdjustments', [
        //     'foreignKey' => 'ingredient_id',
        //     'dependent' => true,
        //     'cascadeCallbacks' => true,
        // ]);
    }

    /**
     * Validation: presence, format, range. Domain rules belong in services.
     */
    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->notEmptyString('name', 'El nombre es requerido')
            ->maxLength(
                'name',
                IngredientConstants::NAME_MAX_LENGTH,
                'El nombre puede tener hasta 120 caracteres',
            )
            ->notEmptyString('unit', 'La unidad es requerida')
            ->inList('unit', IngredientConstants::UNITS, 'Unidad no válida')
            ->notEmptyString('stock_quantity', 'El stock es requerido')
            ->numeric('stock_quantity', 'El stock debe ser numérico')
            ->greaterThanOrEqual('stock_quantity', 0, 'El stock no puede ser negativo')
            ->notEmptyString('unit_cost', 'El costo es requerido')
            ->numeric('unit_cost', 'El costo debe ser numérico')
            ->greaterThanOrEqual('unit_cost', 0, 'El costo no puede ser negativo');
    }

    /**
     * Application rules (cross-row checks against the DB).
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->isUnique(
                ['name'],
                ['message' => 'Ya existe un ingrediente con ese nombre'],
            ),
            'uniqueName',
        );

        return $rules;
    }

    /**
     * Filters to ingredients at or below the low-stock threshold.
     */
    public function findLowStock(SelectQuery $query): SelectQuery
    {
        return $query->where([
            'Ingredients.stock_quantity <=' => IngredientConstants::LOW_STOCK_THRESHOLD,
        ]);
    }

    /**
     * @param \Cake\ORM\Query\SelectQuery $query
     * @param array{q?: string} $options
     */
    public function findSearch(SelectQuery $query, array $options = []): SelectQuery
    {
        $q = trim((string)($options['q'] ?? ''));
        if ($q === '') {
            return $query;
        }

        return $query->where(['Ingredients.name LIKE' => '%' . $q . '%']);
    }

    /**
     * Returns `[id => "name (unit)"]` for future Recipes/Adjustments selectors.
     * Named `findNameList` instead of overriding `findList()` because the
     * CakePHP 5 signature is incompatible (project convention).
     */
    public function findNameList(SelectQuery $query): SelectQuery
    {
        return $query
            ->select(['Ingredients.id', 'Ingredients.name', 'Ingredients.unit'])
            ->formatResults(
                fn($results) => $results->combine(
                    'id',
                    fn($row) => sprintf('%s (%s)', $row->name, $row->unit),
                ),
            );
    }
}
