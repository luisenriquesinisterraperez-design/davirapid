<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Seed for Receivables test cases:
 *  1. Pending manual debt for customer 1 (Juan).
 *  2. Pending CxC from order 2 (credit) with partial payment.
 *  3. Paid CxC for customer 2 (María).
 *  4. Another pending manual debt for customer 1 (multi-debt scenario).
 */
class ReceivablesFixture extends TestFixture
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $records = [
        [
            'id' => 1,
            'customer_id' => 1,
            'order_id' => null,
            'total_amount' => '100000.00',
            'paid_amount' => '0.00',
            'description' => 'Préstamo personal',
            'status' => 'pendiente',
            'created_by' => 1,
            'created' => '2026-05-20 09:00:00',
            'modified' => '2026-05-20 09:00:00',
        ],
        [
            'id' => 2,
            'customer_id' => 1,
            'order_id' => 2,
            'total_amount' => '13000.00',
            'paid_amount' => '5000.00',
            'description' => 'Pedido #2 - Juan Pérez',
            'status' => 'pendiente',
            'created_by' => 1,
            'created' => '2026-05-23 13:00:00',
            'modified' => '2026-05-23 14:00:00',
        ],
        [
            'id' => 3,
            'customer_id' => 2,
            'order_id' => null,
            'total_amount' => '30000.00',
            'paid_amount' => '30000.00',
            'description' => 'Mercado del 12/05',
            'status' => 'pagado',
            'created_by' => 1,
            'created' => '2026-05-18 10:00:00',
            'modified' => '2026-05-22 16:00:00',
        ],
        [
            'id' => 4,
            'customer_id' => 1,
            'order_id' => null,
            'total_amount' => '25000.00',
            'paid_amount' => '0.00',
            'description' => 'Adelanto de mercancía',
            'status' => 'pendiente',
            'created_by' => 1,
            'created' => '2026-05-22 11:00:00',
            'modified' => '2026-05-22 11:00:00',
        ],
    ];
}
