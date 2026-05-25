<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\OrderLogConstants;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * OrderLogs Table — append-only audit trail for orders.
 *
 * Timestamp behavior is configured for `created` only — table has no
 * `modified` column (immutable log entries by design).
 */
class OrderLogsTable extends Table
{
    /**
     * @param array<string, mixed> $config
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('order_logs');
        $this->setPrimaryKey('id');
        $this->setDisplayField('description');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => ['created' => 'new'],
            ],
        ]);

        $this->belongsTo('Orders', [
            'foreignKey' => 'order_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
            'joinType' => 'LEFT',
        ]);
    }

    /**
     * Default validation: presence of order_id_snapshot/kind/description and
     * inList check on kind.
     */
    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->integer('order_id_snapshot')
            ->requirePresence('order_id_snapshot', 'create')
            ->notEmptyString('kind')
            ->inList('kind', OrderLogConstants::KINDS)
            ->notEmptyString('description')
            ->maxLength('description', 500);
    }

    /**
     * @param array{order_id?: int} $options
     */
    public function findForOrder(SelectQuery $query, array $options = []): SelectQuery
    {
        $orderId = (int)($options['order_id'] ?? 0);

        return $query
            ->where(['OrderLogs.order_id_snapshot' => $orderId])
            ->orderBy(['OrderLogs.created' => 'DESC', 'OrderLogs.id' => 'DESC']);
    }

    /**
     * Default chronological ordering (newest first).
     */
    public function findChronological(SelectQuery $query): SelectQuery
    {
        return $query->orderBy(['OrderLogs.created' => 'DESC', 'OrderLogs.id' => 'DESC']);
    }
}
