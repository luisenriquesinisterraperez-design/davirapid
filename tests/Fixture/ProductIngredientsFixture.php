<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Minimal seed for Recipes module test cases.
 * - Hamburguesa (product 1) uses Carne (ingredient 1) and Aceite (ingredient 2).
 * - Pizza (product 2) uses Carne (ingredient 1).
 * - Empanada (product 3) has no recipe.
 */
class ProductIngredientsFixture extends TestFixture
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $records = [
        [
            'id' => 1,
            'product_id' => 1,
            'ingredient_id' => 1,
            'quantity' => '200.000',
            'created' => '2026-05-24 10:00:00',
            'modified' => '2026-05-24 10:00:00',
        ],
        [
            'id' => 2,
            'product_id' => 1,
            'ingredient_id' => 2,
            'quantity' => '1.000',
            'created' => '2026-05-24 10:00:00',
            'modified' => '2026-05-24 10:00:00',
        ],
        [
            'id' => 3,
            'product_id' => 2,
            'ingredient_id' => 1,
            'quantity' => '100.000',
            'created' => '2026-05-24 10:00:00',
            'modified' => '2026-05-24 10:00:00',
        ],
    ];
}
