<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Minimal seed for the Ingredients module test cases.
 * Three rows covering the three stock states the UI cares about:
 * healthy, low (<= 5), and zero.
 */
class IngredientsFixture extends TestFixture
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $records = [
        [
            'id' => 1,
            'name' => 'Carne molida',
            'unit' => 'gr',
            'stock_quantity' => '1500.000',
            'unit_cost' => '25.00',
            'created' => '2026-05-24 10:00:00',
            'modified' => '2026-05-24 10:00:00',
        ],
        [
            'id' => 2,
            'name' => 'Aceite',
            'unit' => 'ml',
            'stock_quantity' => '3.500',
            'unit_cost' => '12.00',
            'created' => '2026-05-24 10:00:00',
            'modified' => '2026-05-24 10:00:00',
        ],
        [
            'id' => 3,
            'name' => 'Sal',
            'unit' => 'kg',
            'stock_quantity' => '0.000',
            'unit_cost' => '5.00',
            'created' => '2026-05-24 10:00:00',
            'modified' => '2026-05-24 10:00:00',
        ],
    ];
}
