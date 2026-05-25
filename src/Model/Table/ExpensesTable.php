<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\ExpenseConstants;
use Cake\I18n\Date;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class ExpensesTable extends Table
{
    /**
     * @param array<string, mixed> $config
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('expenses');
        $this->setPrimaryKey('id');
        $this->setDisplayField('description');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Creator', [
            'className' => 'Users',
            'foreignKey' => 'created_by',
            'joinType' => 'LEFT',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->notEmptyString('description', 'La descripción es requerida')
            ->maxLength(
                'description',
                ExpenseConstants::DESCRIPTION_MAX_LENGTH,
                'La descripción puede tener hasta 255 caracteres',
            )
            ->requirePresence('amount', 'create', 'El monto es requerido')
            ->numeric('amount', 'El monto debe ser numérico')
            ->greaterThan('amount', 0, 'El monto debe ser mayor a cero')
            ->requirePresence('expense_date', 'create', 'La fecha es requerida')
            ->date('expense_date', ['ymd'], 'La fecha no es válida');
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->existsIn(['created_by'], 'Creator', ['allowNullableNulls' => true]),
            'creatorExists',
        );

        return $rules;
    }

    /**
     * @param array{from?: string, to?: string} $options
     */
    public function findInDateRange(SelectQuery $query, array $options = []): SelectQuery
    {
        $from = trim((string)($options['from'] ?? ''));
        $to = trim((string)($options['to'] ?? ''));

        if ($from !== '') {
            $query->where(['Expenses.expense_date >=' => $from]);
        }
        if ($to !== '') {
            $query->where(['Expenses.expense_date <=' => $to]);
        }

        return $query;
    }

    public function findToday(SelectQuery $query): SelectQuery
    {
        return $query->where([
            'Expenses.expense_date' => (new Date())->format('Y-m-d'),
        ]);
    }

    public function findThisMonth(SelectQuery $query): SelectQuery
    {
        $firstDay = (new Date())->modify('first day of this month')->format('Y-m-d');

        return $query->where(['Expenses.expense_date >=' => $firstDay]);
    }

    public function findThisYear(SelectQuery $query): SelectQuery
    {
        $firstDay = (new Date())->modify('first day of january ' . date('Y'))->format('Y-m-d');

        return $query->where(['Expenses.expense_date >=' => $firstDay]);
    }
}
