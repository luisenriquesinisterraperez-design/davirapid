<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Seed for AccountPayments test cases.
 *
 * Two abonos against ReceivablesFixture[2] (paid_amount=5000 = 3000+2000)
 * and one full payment against ReceivablesFixture[3] (paid=30000, status=pagado).
 */
class AccountPaymentsFixture extends TestFixture
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $records = [
        [
            'id' => 1,
            'receivable_id' => 2,
            'amount' => '3000.00',
            'payment_method' => 'efectivo',
            'notes' => null,
            'created_by' => 1,
            'created' => '2026-05-23 13:30:00',
        ],
        [
            'id' => 2,
            'receivable_id' => 2,
            'amount' => '2000.00',
            'payment_method' => 'nequi',
            'notes' => 'Abono parcial',
            'created_by' => 1,
            'created' => '2026-05-23 14:00:00',
        ],
        [
            'id' => 3,
            'receivable_id' => 3,
            'amount' => '30000.00',
            'payment_method' => 'transferencia',
            'notes' => null,
            'created_by' => 1,
            'created' => '2026-05-22 15:00:00',
        ],
    ];
}
