<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\ReceivableConstants;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Receivables (Cuentas por Cobrar) Table.
 */
class ReceivablesTable extends Table
{
    /**
     * @param array<string, mixed> $config
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('receivables');
        $this->setPrimaryKey('id');
        $this->setDisplayField('description');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Customers', [
            'foreignKey' => 'customer_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('Orders', [
            'foreignKey' => 'order_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('Creator', [
            'className' => 'Users',
            'foreignKey' => 'created_by',
            'joinType' => 'LEFT',
        ]);

        $this->hasMany('AccountPayments', [
            'foreignKey' => 'receivable_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
    }

    /**
     * Validation: format-only. Domain rules (idempotency, payment guard)
     * live in ReceivableService.
     */
    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->requirePresence('customer_id', 'create')
            ->integer('customer_id', 'El cliente es requerido')
            ->requirePresence('total_amount', 'create')
            ->numeric('total_amount', 'El total debe ser numérico')
            ->greaterThan('total_amount', 0, 'El total debe ser mayor a 0')
            ->numeric('paid_amount', 'El monto abonado debe ser numérico')
            ->greaterThanOrEqual('paid_amount', 0, 'El monto abonado no puede ser negativo')
            ->notEmptyString('description', 'La descripción es requerida')
            ->maxLength(
                'description',
                ReceivableConstants::DESCRIPTION_MAX_LENGTH,
                'La descripción no puede exceder 255 caracteres',
            )
            ->inList('status', ReceivableConstants::STATUSES, 'Estado inválido');
    }

    /**
     * Application rules (cross-row + invariants).
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->existsIn(['customer_id'], 'Customers', ['message' => 'El cliente no existe']),
            'customerExists',
        );
        $rules->add(
            $rules->existsIn(['order_id'], 'Orders', ['allowNullableNulls' => true]),
            'orderExists',
        );
        $rules->add(
            $rules->existsIn(['created_by'], 'Users', ['allowNullableNulls' => true]),
            'creatorExists',
        );

        // Invariant: paid_amount <= total_amount (with 0.005 epsilon for float safety).
        $rules->add(
            function ($entity): bool {
                $paid = (float)($entity->paid_amount ?? 0);
                $total = (float)($entity->total_amount ?? 0);

                return $paid <= $total + 0.005;
            },
            'paidWithinTotal',
            [
                'errorField' => 'paid_amount',
                'message' => 'El monto abonado no puede superar el total.',
            ],
        );

        return $rules;
    }

    /**
     * Default ordering: pending first, then by created DESC.
     * Expressed with a CASE so it remains portable across MySQL / SQLite (tests).
     */
    public function findPendingFirst(SelectQuery $query): SelectQuery
    {
        return $query->orderBy([
            "CASE WHEN Receivables.status = 'pendiente' THEN 0 ELSE 1 END" => 'ASC',
            'Receivables.created' => 'DESC',
            'Receivables.id' => 'DESC',
        ]);
    }

    /**
     * @param \Cake\ORM\Query\SelectQuery $query
     * @param array{customer_id?: int} $options
     */
    public function findForCustomer(SelectQuery $query, array $options = []): SelectQuery
    {
        $id = (int)($options['customer_id'] ?? 0);
        if ($id <= 0) {
            return $query;
        }

        return $query->where(['Receivables.customer_id' => $id]);
    }

    /**
     * Restrict to non-paid (open) receivables.
     */
    public function findOpen(SelectQuery $query): SelectQuery
    {
        return $query->where(['Receivables.status' => ReceivableConstants::STATUS_PENDIENTE]);
    }
}
