<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\CustomerConstants;
use App\Constants\OrderConstants;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Orders Table.
 *
 * Snapshots (customer_name/phone/address) are explicit and never auto-populated
 * by behaviors — they are written by OrderService at create/update time and are
 * immutable thereafter, preserving historical accuracy of tickets and audits.
 */
class OrdersTable extends Table
{
    /**
     * @param array<string, mixed> $config
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('orders');
        $this->setPrimaryKey('id');
        $this->setDisplayField('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Customers', [
            'foreignKey' => 'customer_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('Deliveries', [
            'foreignKey' => 'delivery_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('CancelledByUser', [
            'className' => 'Users',
            'foreignKey' => 'cancelled_by',
            'joinType' => 'LEFT',
        ]);
        $this->hasMany('OrderItems', [
            'foreignKey' => 'order_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('OrderLogs', [
            'foreignKey' => 'order_id',
            'dependent' => false,
        ]);
    }

    /**
     * Default validation rules — format only. Domain rules live in OrderService.
     */
    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->notEmptyString('type', 'El tipo es requerido')
            ->inList('type', OrderConstants::TYPES, 'Tipo inválido')
            ->notEmptyString('status', 'El estado es requerido')
            ->inList('status', OrderConstants::STATUSES, 'Estado inválido')
            ->notEmptyString('payment_method', 'El método de pago es requerido')
            ->inList('payment_method', OrderConstants::PAYMENT_METHODS, 'Método de pago inválido')
            ->maxLength('customer_name', CustomerConstants::NAME_MAX_LENGTH)
            ->allowEmptyString('customer_name')
            ->maxLength('customer_phone', CustomerConstants::PHONE_MAX_LENGTH)
            ->allowEmptyString('customer_phone')
            ->maxLength('customer_address', CustomerConstants::ADDRESS_MAX_LENGTH)
            ->allowEmptyString('customer_address')
            ->numeric('shipping_cost')
            ->greaterThanOrEqual('shipping_cost', 0, 'El costo de envío no puede ser negativo')
            ->numeric('subtotal')
            ->greaterThanOrEqual('subtotal', 0)
            ->numeric('total')
            ->greaterThanOrEqual('total', 0)
            ->allowEmptyString('notes');
    }

    /**
     * Application rules (cross-row checks).
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->existsIn(['customer_id'], 'Customers', ['allowNullableNulls' => true]),
            'customerExists',
        );
        $rules->add(
            $rules->existsIn(['delivery_id'], 'Deliveries', ['allowNullableNulls' => true]),
            'deliveryExists',
        );
        $rules->add(
            $rules->existsIn(['user_id'], 'Users', ['allowNullableNulls' => true]),
            'userExists',
        );

        return $rules;
    }

    // -------------------- Custom finders --------------------

    /**
     * Default operative listing: non-cancelled.
     */
    public function findVisible(SelectQuery $query): SelectQuery
    {
        return $query->where(['Orders.status !=' => OrderConstants::STATUS_CANCELLED]);
    }

    /**
     * @param array{status?: string|list<string>} $options
     */
    public function findByState(SelectQuery $query, array $options = []): SelectQuery
    {
        $raw = $options['status'] ?? [];
        $statuses = is_array($raw) ? $raw : [$raw];
        $statuses = array_values(array_filter($statuses, static fn($s) => $s !== '' && $s !== null));

        return $statuses !== []
            ? $query->where(['Orders.status IN' => $statuses])
            : $query;
    }

    /**
     * @param array{delivery_id?: int} $options
     */
    public function findForRepartidor(SelectQuery $query, array $options = []): SelectQuery
    {
        $id = (int)($options['delivery_id'] ?? 0);

        return $id > 0
            ? $query->where(['Orders.delivery_id' => $id])
            : $query->where('1=0');
    }

    /**
     * @param array{from?: string, to?: string} $options
     */
    public function findInDateRange(SelectQuery $query, array $options = []): SelectQuery
    {
        $from = trim((string)($options['from'] ?? ''));
        $to = trim((string)($options['to'] ?? ''));
        if ($from !== '') {
            $query->where(['Orders.created >=' => $from . ' 00:00:00']);
        }
        if ($to !== '') {
            $query->where(['Orders.created <=' => $to . ' 23:59:59']);
        }

        return $query;
    }

    /**
     * Eager-loads items + products. Used by view/edit/ticket.
     */
    public function findWithItems(SelectQuery $query): SelectQuery
    {
        return $query->contain(['OrderItems' => ['Products']]);
    }

    /**
     * Today's active orders (non-cancelled) — basis for dashboard KPIs.
     */
    public function findActiveToday(SelectQuery $query): SelectQuery
    {
        $today = date('Y-m-d');

        return $query
            ->where(['Orders.status !=' => OrderConstants::STATUS_CANCELLED])
            ->where(['Orders.created >=' => $today . ' 00:00:00'])
            ->where(['Orders.created <=' => $today . ' 23:59:59']);
    }
}
