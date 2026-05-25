<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\RecipeConstants;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * ProductIngredients Table — pivote enriquecido (Recetas).
 */
class ProductIngredientsTable extends Table
{
    /**
     * @param array<string, mixed> $config
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('product_ingredients');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Products', [
            'foreignKey' => 'product_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('Ingredients', [
            'foreignKey' => 'ingredient_id',
            'joinType' => 'INNER',
        ]);
    }

    /**
     * Validación de formato. Reglas de negocio van en RecipeService.
     */
    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->notEmptyString('product_id', 'El producto es requerido')
            ->integer('product_id')
            ->notEmptyString('ingredient_id', 'El ingrediente es requerido')
            ->integer('ingredient_id')
            ->notEmptyString('quantity', 'La cantidad es requerida')
            ->numeric('quantity', 'La cantidad debe ser numérica')
            ->greaterThan(
                'quantity',
                RecipeConstants::QUANTITY_MIN,
                'La cantidad debe ser mayor a cero',
            )
            ->lessThanOrEqual(
                'quantity',
                RecipeConstants::QUANTITY_MAX,
                'La cantidad excede el máximo permitido',
            );
    }

    /**
     * Application rules: existencia de FK y unicidad del par.
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn(['product_id'], 'Products'), 'productExists', [
            'errorField' => 'product_id',
            'message' => 'El producto seleccionado no existe',
        ]);
        $rules->add($rules->existsIn(['ingredient_id'], 'Ingredients'), 'ingredientExists', [
            'errorField' => 'ingredient_id',
            'message' => 'El ingrediente seleccionado no existe',
        ]);
        $rules->add(
            $rules->isUnique(
                ['product_id', 'ingredient_id'],
                ['message' => 'Ese ingrediente ya está en la receta de este producto'],
            ),
            'uniqueProductIngredient',
        );

        return $rules;
    }

    /**
     * Hidrata líneas con su ingrediente y las ordena por nombre del ingrediente.
     *
     * @param \Cake\ORM\Query\SelectQuery $query
     * @param array{product_id?: int|string} $options
     */
    public function findForProduct(SelectQuery $query, array $options = []): SelectQuery
    {
        $productId = (int)($options['product_id'] ?? 0);

        return $query
            ->contain(['Ingredients'])
            ->where(['ProductIngredients.product_id' => $productId])
            ->orderBy(['Ingredients.name' => 'ASC']);
    }

    /**
     * Reverse lookup: líneas (con producto hidratado) para un ingrediente dado.
     *
     * @param \Cake\ORM\Query\SelectQuery $query
     * @param array{ingredient_id?: int|string} $options
     */
    public function findForIngredient(SelectQuery $query, array $options = []): SelectQuery
    {
        $ingredientId = (int)($options['ingredient_id'] ?? 0);

        return $query
            ->contain(['Products'])
            ->where(['ProductIngredients.ingredient_id' => $ingredientId])
            ->orderBy(['Products.name' => 'ASC']);
    }
}
