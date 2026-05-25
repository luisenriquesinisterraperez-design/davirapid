<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\InventoryAdjustmentConstants;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * InventoryAdjustments Table — append-only ledger of stock movements.
 */
class InventoryAdjustmentsTable extends Table
{
    /**
     * @param array<string, mixed> $config
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('inventory_adjustments');
        $this->setPrimaryKey('id');
        $this->setDisplayField('reason');

        // Timestamp behavior for `created` only — table has no `modified` column.
        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => ['created' => 'new'],
            ],
        ]);

        $this->belongsTo('Ingredients', [
            'foreignKey' => 'ingredient_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
            'joinType' => 'LEFT',
        ]);
    }

    /**
     * Validation: format only. Domain rules live in InventoryAdjustmentService.
     */
    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->requirePresence('ingredient_id', 'create')
            ->integer('ingredient_id', 'El ingrediente es requerido')
            ->notEmptyString('type', 'El tipo es requerido')
            ->inList('type', InventoryAdjustmentConstants::TYPES, 'Tipo inválido')
            ->notEmptyString('reason', 'El motivo es requerido')
            ->maxLength(
                'reason',
                InventoryAdjustmentConstants::REASON_MAX_LENGTH,
                'El motivo no puede exceder 120 caracteres',
            )
            ->numeric('quantity', 'La cantidad debe ser numérica')
            ->greaterThan('quantity', 0, 'La cantidad debe ser mayor a 0')
            ->allowEmptyString('notes');
    }

    /**
     * Application rules (cross-row checks against the DB).
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->existsIn(
                ['ingredient_id'],
                'Ingredients',
                ['message' => 'El ingrediente no existe'],
            ),
            'ingredientExists',
        );
        $rules->add(
            $rules->existsIn(
                ['user_id'],
                'Users',
                ['allowNullableNulls' => true],
            ),
            'userExists',
        );

        return $rules;
    }

    /**
     * Default chronological ordering: newest first, id desc as tiebreaker.
     */
    public function findChronological(SelectQuery $query): SelectQuery
    {
        return $query->orderBy([
            'InventoryAdjustments.created' => 'DESC',
            'InventoryAdjustments.id' => 'DESC',
        ]);
    }

    /**
     * @param \Cake\ORM\Query\SelectQuery $query
     * @param array{ingredient_id?: int} $options
     */
    public function findByIngredient(SelectQuery $query, array $options = []): SelectQuery
    {
        $id = (int)($options['ingredient_id'] ?? 0);
        if ($id <= 0) {
            return $query;
        }

        return $query->where(['InventoryAdjustments.ingredient_id' => $id]);
    }

    /**
     * @param \Cake\ORM\Query\SelectQuery $query
     * @param array{type?: string} $options
     */
    public function findByType(SelectQuery $query, array $options = []): SelectQuery
    {
        $type = (string)($options['type'] ?? '');
        if ($type === '' || $type === 'all') {
            return $query;
        }

        return $query->where(['InventoryAdjustments.type' => $type]);
    }

    /**
     * @param \Cake\ORM\Query\SelectQuery $query
     * @param array{from?: string|null, to?: string|null} $options Inclusive bounds 'YYYY-MM-DD'.
     */
    public function findInDateRange(SelectQuery $query, array $options = []): SelectQuery
    {
        $from = trim((string)($options['from'] ?? ''));
        $to = trim((string)($options['to'] ?? ''));

        if ($from !== '') {
            $query->where(['InventoryAdjustments.created >=' => $from . ' 00:00:00']);
        }
        if ($to !== '') {
            $query->where(['InventoryAdjustments.created <=' => $to . ' 23:59:59']);
        }

        return $query;
    }
}
