<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\DeliveryConstants;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class DeliveriesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('deliveries');
        $this->setPrimaryKey('id');
        $this->setDisplayField('last_name');
        $this->addBehavior('Timestamp');

        $this->hasOne('Users', [
            'foreignKey' => 'delivery_id',
            'dependent' => false,
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->notEmptyString('first_name', 'El nombre es requerido')
            ->maxLength('first_name', DeliveryConstants::NAME_MAX_LENGTH,
                'El nombre no puede superar 60 caracteres')
            ->notEmptyString('last_name', 'El apellido es requerido')
            ->maxLength('last_name', DeliveryConstants::NAME_MAX_LENGTH,
                'El apellido no puede superar 60 caracteres')
            ->notEmptyString('phone', 'El teléfono es requerido')
            ->maxLength('phone', DeliveryConstants::PHONE_MAX_LENGTH,
                'El teléfono no puede superar 20 caracteres')
            ->add('phone', 'format', [
                'rule' => ['custom', DeliveryConstants::PHONE_REGEX],
                'message' => 'El teléfono solo admite dígitos, espacios, "+", "-" y paréntesis',
            ])
            ->boolean('is_active');
    }

    public function findActive(SelectQuery $query): SelectQuery
    {
        return $query->where(['Deliveries.is_active' => true]);
    }

    public function findFullNameList(SelectQuery $query): SelectQuery
    {
        return $query
            ->select(['id', 'first_name', 'last_name'])
            ->orderBy(['last_name' => 'ASC', 'first_name' => 'ASC'])
            ->formatResults(function ($results) {
                return $results->combine(
                    'id',
                    fn($row) => trim(($row->last_name ?? '') . ', ' . ($row->first_name ?? ''))
                );
            });
    }
}
