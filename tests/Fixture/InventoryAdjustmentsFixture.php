<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Two-row seed for the InventoryAdjustments module test cases:
 * one entrada and one baja over the same ingredient (`id=1` Carne molida).
 */
class InventoryAdjustmentsFixture extends TestFixture
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $records = [
        [
            'id' => 1,
            'ingredient_id' => 1,
            'type' => 'entrada',
            'quantity' => '500.000',
            'reason' => 'Compra a proveedor',
            'notes' => null,
            'user_id' => 1,
            'created' => '2026-05-20 10:00:00',
        ],
        [
            'id' => 2,
            'ingredient_id' => 1,
            'type' => 'baja',
            'quantity' => '100.000',
            'reason' => 'Merma',
            'notes' => 'Producto vencido',
            'user_id' => 1,
            'created' => '2026-05-21 15:30:00',
        ],
    ];
}
