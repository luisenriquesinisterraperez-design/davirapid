<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class UsersTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('users');
        $this->setPrimaryKey('id');
        $this->setDisplayField('username');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Roles', [
            'foreignKey' => 'role_id',
            'joinType' => 'INNER',
        ]);

        $this->belongsTo('Deliveries', [
            'foreignKey' => 'delivery_id',
            'joinType' => 'LEFT',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->notEmptyString('username', 'El usuario es requerido')
            ->lengthBetween('username', [3, 60], 'El usuario debe tener entre 3 y 60 caracteres')
            ->add('username', 'format', [
                'rule' => ['custom', '/^[a-zA-Z0-9._-]+$/'],
                'message' => 'Solo letras, números, punto, guion bajo o guion',
            ])
            ->notEmptyString('name', 'El nombre es requerido')
            ->lengthBetween('name', [2, 120], 'El nombre debe tener entre 2 y 120 caracteres')
            ->integer('role_id')
            ->notEmptyString('role_id', 'El rol es requerido')
            ->boolean('active')
            ->add('password', 'minLength', [
                'rule' => ['minLength', 8],
                'message' => 'La contraseña debe tener al menos 8 caracteres',
                'on' => function (array $context): bool {
                    return !empty($context['data']['password']);
                },
            ])
            ->allowEmptyString('delivery_id')
            ->add('delivery_id', 'naturalNumber', [
                'rule' => ['naturalNumber'],
                'message' => 'Repartidor inválido',
                'on' => function (array $context): bool {
                    return !empty($context['data']['delivery_id']);
                },
            ]);
    }

    public function validationCreate(Validator $validator): Validator
    {
        $validator = $this->validationDefault($validator);
        return $validator->notEmptyString('password', 'La contraseña es requerida');
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->isUnique(['username'], 'Ya existe un usuario con ese nombre'),
            'uniqueUsername'
        );
        $rules->add($rules->existsIn(['role_id'], 'Roles'));
        $rules->add(
            $rules->isUnique(
                ['delivery_id'],
                ['allowMultipleNulls' => true],
                'Este repartidor ya está vinculado a otro usuario'
            ),
            'uniqueDeliveryLink'
        );
        return $rules;
    }

    /**
     * Custom finder used exclusively by the Authentication identifier:
     * filters out inactive users and eager-loads the Role.
     */
    public function findAuth(SelectQuery $query): SelectQuery
    {
        return $query
            ->where(['Users.active' => true])
            ->contain(['Roles']);
    }
}
