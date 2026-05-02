<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Service\AuthorizationService;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class PermissionsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('permissions');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Roles', [
            'foreignKey' => 'role_id',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->integer('role_id')
            ->notEmptyString('role_id', 'El rol es requerido')
            ->notEmptyString('module', 'El módulo es requerido')
            ->inList('module', array_keys(AuthorizationService::MODULES), 'Módulo desconocido')
            ->boolean('can_view')
            ->boolean('can_create')
            ->boolean('can_edit')
            ->boolean('can_delete');
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->isUnique(['role_id', 'module'], 'Ya existe un registro de permisos para este módulo'),
            'uniqueRoleModule'
        );
        $rules->add($rules->existsIn(['role_id'], 'Roles'));
        return $rules;
    }
}
