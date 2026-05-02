<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class RolesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('roles');
        $this->setPrimaryKey('id');
        $this->setDisplayField('name');
        $this->addBehavior('Timestamp');

        $this->hasMany('Permissions', [
            'foreignKey' => 'role_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('Users', [
            'foreignKey' => 'role_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->notEmptyString('name', 'El nombre del rol es requerido')
            ->lengthBetween('name', [2, 60], 'El nombre debe tener entre 2 y 60 caracteres')
            ->boolean('is_admin');
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->isUnique(['name'], 'Ya existe un rol con ese nombre'),
            'uniqueName'
        );
        return $rules;
    }

    /**
     * Roles que pueden asignarse a usuarios — excluye el Administrador.
     */
    public function findAssignable(SelectQuery $query): SelectQuery
    {
        return $query->where(['Roles.is_admin' => false])->orderAsc('Roles.name');
    }
}
