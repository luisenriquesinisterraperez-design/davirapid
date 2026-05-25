<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\AccountPaymentConstants;
use Cake\I18n\DateTime;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * AccountPayments Table — append-only ledger of abonos against CxC.
 *
 * The table has no `modified` column by design: abonos are immutable
 * accounting events. Mistakes are corrected via delete + new insert.
 */
class AccountPaymentsTable extends Table
{
    /**
     * @param array<string, mixed> $config
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('account_payments');
        $this->setPrimaryKey('id');
        $this->setDisplayField('id');

        // Timestamp behavior for `created` only — table has no `modified` column.
        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => ['created' => 'new'],
            ],
        ]);

        $this->belongsTo('Receivables', [
            'foreignKey' => 'receivable_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('Creator', [
            'className' => 'Users',
            'foreignKey' => 'created_by',
            'joinType' => 'LEFT',
        ]);
    }

    /**
     * Validation: format only. Domain rules (lock, overpayment) live in
     * AccountPaymentService.
     */
    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->requirePresence('receivable_id', 'create')
            ->integer('receivable_id', 'La cuenta es requerida')
            ->requirePresence('amount', 'create')
            ->numeric('amount', 'El monto debe ser numérico')
            ->greaterThan('amount', 0, 'El monto debe ser mayor a 0')
            ->decimal('amount', 2, 'El monto soporta hasta 2 decimales')
            ->requirePresence('payment_method', 'create')
            ->inList(
                'payment_method',
                AccountPaymentConstants::PAYMENT_METHODS,
                'Método de pago inválido (no se puede abonar con crédito).',
            )
            ->allowEmptyString('notes')
            ->maxLength(
                'notes',
                AccountPaymentConstants::NOTES_MAX_LENGTH,
                'Las observaciones son demasiado extensas.',
            );
    }

    /**
     * Application rules (cross-row FK checks).
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->existsIn(
                ['receivable_id'],
                'Receivables',
                ['message' => 'La cuenta por cobrar no existe'],
            ),
            'receivableExists',
        );
        $rules->add(
            $rules->existsIn(
                ['created_by'],
                'Users',
                ['allowNullableNulls' => true],
            ),
            'creatorExists',
        );

        return $rules;
    }

    /**
     * @param \Cake\ORM\Query\SelectQuery $query
     * @param array{receivable_id?: int} $options
     */
    public function findForReceivable(SelectQuery $query, array $options = []): SelectQuery
    {
        $id = (int)($options['receivable_id'] ?? 0);
        if ($id <= 0) {
            return $query;
        }

        return $query
            ->where(['AccountPayments.receivable_id' => $id])
            ->orderBy(['AccountPayments.created' => 'DESC', 'AccountPayments.id' => 'DESC']);
    }

    /**
     * @param \Cake\ORM\Query\SelectQuery $query
     * @param array{from?: string|null, to?: string|null} $options Inclusive 'YYYY-MM-DD' bounds.
     */
    public function findInDateRange(SelectQuery $query, array $options = []): SelectQuery
    {
        $from = trim((string)($options['from'] ?? ''));
        $to = trim((string)($options['to'] ?? ''));

        if ($from !== '') {
            $query->where(['AccountPayments.created >=' => $from . ' 00:00:00']);
        }
        if ($to !== '') {
            $query->where(['AccountPayments.created <=' => $to . ' 23:59:59']);
        }

        return $query;
    }

    /**
     * Shortcut for "abonos de hoy" — uses an inclusive half-open day range
     * to remain portable across MySQL / SQLite (no DATE() functions).
     */
    public function findToday(SelectQuery $query): SelectQuery
    {
        $today = (new DateTime())->format('Y-m-d');

        return $query->where([
            'AccountPayments.created >=' => $today . ' 00:00:00',
            'AccountPayments.created <=' => $today . ' 23:59:59',
        ]);
    }

    /**
     * @param \Cake\ORM\Query\SelectQuery $query
     * @param array{payment_method?: string} $options
     */
    public function findByMethod(SelectQuery $query, array $options = []): SelectQuery
    {
        $method = trim((string)($options['payment_method'] ?? ''));
        if ($method === '' || $method === 'all') {
            return $query;
        }

        return $query->where(['AccountPayments.payment_method' => $method]);
    }
}
