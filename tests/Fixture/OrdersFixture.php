<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Four-row seed for the Orders module test cases:
 *  1. local + entregado + efectivo
 *  2. domicilio + preparando + crédito
 *  3. cancelado
 *  4. recibido + local + efectivo
 */
class OrdersFixture extends TestFixture
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $records = [
        [
            'id' => 1,
            'customer_id' => null,
            'delivery_id' => null,
            'user_id' => 1,
            'type' => 'local',
            'status' => 'entregado',
            'payment_method' => 'efectivo',
            'customer_name' => 'Cliente Local',
            'customer_phone' => '3000000001',
            'customer_address' => null,
            'shipping_cost' => '0.00',
            'subtotal' => '5000.00',
            'total' => '5000.00',
            'notes' => null,
            'delivered_at' => '2026-05-23 12:15:00',
            'cancelled_at' => null,
            'cancelled_by' => null,
            'created' => '2026-05-23 12:00:00',
            'modified' => '2026-05-23 12:15:00',
        ],
        [
            'id' => 2,
            'customer_id' => 1,
            'delivery_id' => 1,
            'user_id' => 1,
            'type' => 'domicilio',
            'status' => 'preparando',
            'payment_method' => 'credito',
            'customer_name' => 'Juan Pérez',
            'customer_phone' => '3001234567',
            'customer_address' => 'Calle 1 #2-3',
            'shipping_cost' => '5000.00',
            'subtotal' => '8000.00',
            'total' => '13000.00',
            'notes' => null,
            'delivered_at' => null,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'created' => '2026-05-23 13:00:00',
            'modified' => '2026-05-23 13:00:00',
        ],
        [
            'id' => 3,
            'customer_id' => null,
            'delivery_id' => null,
            'user_id' => 1,
            'type' => 'local',
            'status' => 'cancelado',
            'payment_method' => 'efectivo',
            'customer_name' => 'Cliente Cancelado',
            'customer_phone' => '3000000003',
            'customer_address' => null,
            'shipping_cost' => '0.00',
            'subtotal' => '1500.00',
            'total' => '1500.00',
            'notes' => null,
            'delivered_at' => null,
            'cancelled_at' => '2026-05-23 13:30:00',
            'cancelled_by' => 1,
            'created' => '2026-05-23 13:20:00',
            'modified' => '2026-05-23 13:30:00',
        ],
        [
            'id' => 4,
            'customer_id' => null,
            'delivery_id' => null,
            'user_id' => 1,
            'type' => 'local',
            'status' => 'recibido',
            'payment_method' => 'efectivo',
            'customer_name' => 'Cliente Nuevo',
            'customer_phone' => '3000000004',
            'customer_address' => null,
            'shipping_cost' => '0.00',
            'subtotal' => '5000.00',
            'total' => '5000.00',
            'notes' => null,
            'delivered_at' => null,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'created' => '2026-05-24 09:00:00',
            'modified' => '2026-05-24 09:00:00',
        ],
    ];
}
