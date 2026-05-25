<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\OrderConstants;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * OrderItems Table — single line of a customer order.
 *
 * product_name, price_at_sale, line_subtotal are snapshots written by
 * OrderService; never trust POST values. The line_subtotal is recomputed
 * server-side and validated against the price snapshot.
 */
class OrderItemsTable extends Table
{
    /**
     * @param array<string, mixed> $config
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('order_items');
        $this->setPrimaryKey('id');
        $this->setDisplayField('product_name');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Orders', [
            'foreignKey' => 'order_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('Products', [
            'foreignKey' => 'product_id',
            'joinType' => 'LEFT',
        ]);
    }

    /**
     * Default validation rules — format only. Domain logic in OrderService.
     */
    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->notEmptyString('product_name', 'El nombre del producto es requerido')
            ->maxLength('product_name', 120)
            ->numeric('quantity', 'La cantidad debe ser numérica')
            ->greaterThan('quantity', 0, 'La cantidad debe ser mayor a 0')
            ->numeric('price_at_sale')
            ->greaterThanOrEqual('price_at_sale', 0)
            ->numeric('line_subtotal')
            ->greaterThanOrEqual('line_subtotal', 0)
            ->maxLength('notes', OrderConstants::LINE_NOTES_MAX_LENGTH)
            ->allowEmptyString('notes');
    }

    /**
     * Aggregation: top N products by units sold (excluding cancelled orders).
     *
     * @param array{limit?: int} $options
     */
    public function findTopProducts(SelectQuery $query, array $options = []): SelectQuery
    {
        $limit = (int)($options['limit'] ?? 5);

        return $query
            ->select([
                'product_id' => 'OrderItems.product_id',
                'product_name' => 'OrderItems.product_name',
                'units' => $query->func()->sum('OrderItems.quantity'),
                'revenue' => $query->func()->sum('OrderItems.line_subtotal'),
            ])
            ->innerJoinWith('Orders', static fn($q) => $q->where([
                'Orders.status !=' => OrderConstants::STATUS_CANCELLED,
            ]))
            ->groupBy(['OrderItems.product_id', 'OrderItems.product_name'])
            ->orderBy(['units' => 'DESC'])
            ->limit($limit);
    }
}
