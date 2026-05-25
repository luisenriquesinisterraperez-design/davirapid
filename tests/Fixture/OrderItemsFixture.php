<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

class OrderItemsFixture extends TestFixture
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $records = [
        // Order 1 (local, entregado): 1 hamburguesa.
        [
            'id' => 1,
            'order_id' => 1,
            'product_id' => 1,
            'product_name' => 'Hamburguesa',
            'quantity' => '1.000',
            'price_at_sale' => '5000.00',
            'line_subtotal' => '5000.00',
            'notes' => null,
            'created' => '2026-05-23 12:00:00',
            'modified' => '2026-05-23 12:00:00',
        ],
        // Order 2 (domicilio, preparando, crédito): 1 hamburguesa + 1 empanada x2.
        [
            'id' => 2,
            'order_id' => 2,
            'product_id' => 1,
            'product_name' => 'Hamburguesa',
            'quantity' => '1.000',
            'price_at_sale' => '5000.00',
            'line_subtotal' => '5000.00',
            'notes' => null,
            'created' => '2026-05-23 13:00:00',
            'modified' => '2026-05-23 13:00:00',
        ],
        [
            'id' => 3,
            'order_id' => 2,
            'product_id' => 3,
            'product_name' => 'Empanada',
            'quantity' => '2.000',
            'price_at_sale' => '1500.00',
            'line_subtotal' => '3000.00',
            'notes' => null,
            'created' => '2026-05-23 13:00:00',
            'modified' => '2026-05-23 13:00:00',
        ],
        // Order 3 (cancelado).
        [
            'id' => 4,
            'order_id' => 3,
            'product_id' => 3,
            'product_name' => 'Empanada',
            'quantity' => '1.000',
            'price_at_sale' => '1500.00',
            'line_subtotal' => '1500.00',
            'notes' => null,
            'created' => '2026-05-23 13:20:00',
            'modified' => '2026-05-23 13:20:00',
        ],
        // Order 4 (recibido).
        [
            'id' => 5,
            'order_id' => 4,
            'product_id' => 1,
            'product_name' => 'Hamburguesa',
            'quantity' => '1.000',
            'price_at_sale' => '5000.00',
            'line_subtotal' => '5000.00',
            'notes' => null,
            'created' => '2026-05-24 09:00:00',
            'modified' => '2026-05-24 09:00:00',
        ],
    ];
}
