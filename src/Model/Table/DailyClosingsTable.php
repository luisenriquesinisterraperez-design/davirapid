<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class DailyClosingsTable extends Table
{
    /**
     * @param array<string, mixed> $config
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('daily_closings');
        $this->setPrimaryKey('id');
        $this->setDisplayField('closing_date');
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
            ->requirePresence('closing_date', 'create', 'La fecha es requerida')
            ->date('closing_date', ['ymd'], 'La fecha no es válida')
            ->numeric('initial_balance', 'La base inicial debe ser numérica')
            ->greaterThanOrEqual('initial_balance', 0, 'La base inicial no puede ser negativa')
            ->numeric('actual_amount', 'El monto real debe ser numérico')
            ->greaterThanOrEqual('actual_amount', 0, 'El monto real no puede ser negativo')
            ->maxLength('notes', 2000, 'Las observaciones son demasiado largas')
            ->allowEmptyString('notes');
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->isUnique(
                ['closing_date'],
                ['message' => 'Ya existe un cierre para esa fecha'],
            ),
            'uniqueClosingDate',
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
            $query->where(['DailyClosings.closing_date >=' => $from]);
        }
        if ($to !== '') {
            $query->where(['DailyClosings.closing_date <=' => $to]);
        }

        return $query;
    }
}
