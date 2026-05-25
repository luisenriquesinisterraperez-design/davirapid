<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\ProductConstants;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class ProductsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('products');
        $this->setPrimaryKey('id');
        $this->setDisplayField('name');
        $this->addBehavior('Timestamp');

        $this->hasMany('ProductIngredients', [
            'foreignKey' => 'product_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);

        $this->belongsToMany('Ingredients', [
            'through' => 'ProductIngredients',
            'foreignKey' => 'product_id',
            'targetForeignKey' => 'ingredient_id',
            'joinTable' => 'product_ingredients',
        ]);

        // Future associations declared when their tables exist:
        // $this->hasMany('OrderItems');
    }

    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->notEmptyString('name', 'El nombre es requerido')
            ->maxLength('name', 120, 'El nombre puede tener hasta 120 caracteres')
            ->notEmptyString('price', 'El precio es requerido')
            ->numeric('price', 'El precio debe ser numérico')
            ->greaterThanOrEqual(
                'price',
                ProductConstants::PRICE_MIN,
                'El precio debe ser mayor o igual a 1',
            )
            ->allowEmptyString('code')
            ->maxLength('code', ProductConstants::CODE_MAX_LENGTH, 'El código puede tener hasta 20 caracteres')
            ->add('code', 'codePattern', [
                'rule' => function ($value) {
                    if ($value === null || $value === '') {
                        return true;
                    }

                    return (bool)preg_match(ProductConstants::CODE_PATTERN, (string)$value);
                },
                'message' => 'El código solo permite letras, números y guiones',
            ])
            ->allowEmptyString('description')
            ->boolean('is_active');
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->isUnique(
                ['code'],
                ['allowMultipleNulls' => true, 'message' => 'Ese código ya está en uso'],
            ),
            'uniqueCode',
        );

        return $rules;
    }
}
