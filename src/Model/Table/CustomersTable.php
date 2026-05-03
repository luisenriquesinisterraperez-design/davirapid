<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\CustomerConstants;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class CustomersTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('customers');
        $this->setPrimaryKey('id');
        $this->setDisplayField('name');
        $this->addBehavior('Timestamp');
    }

    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->notEmptyString('name', 'El nombre es requerido')
            ->maxLength('name', CustomerConstants::NAME_MAX_LENGTH, 'El nombre puede tener hasta 150 caracteres')
            ->notEmptyString('phone', 'El teléfono es requerido')
            ->maxLength('phone', CustomerConstants::PHONE_MAX_LENGTH, 'El teléfono puede tener hasta 30 caracteres')
            ->scalar('phone')
            ->allowEmptyString('address')
            ->maxLength('address', CustomerConstants::ADDRESS_MAX_LENGTH, 'La dirección puede tener hasta 255 caracteres')
            ->boolean('is_active');
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->isUnique(
                ['phone'],
                ['message' => 'Ya existe un cliente con este teléfono']
            ),
            'uniquePhone'
        );
        return $rules;
    }

    public function findActive(SelectQuery $query): SelectQuery
    {
        return $query->where(['Customers.is_active' => true]);
    }

    public function findByPhone(SelectQuery $query, array $options = []): SelectQuery
    {
        $phone = (string)($options['phone'] ?? '');
        return $query->where(['Customers.phone' => $phone]);
    }
}
