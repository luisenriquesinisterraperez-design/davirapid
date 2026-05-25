<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Seed for DailyClosings tests.
 */
class DailyClosingsFixture extends TestFixture
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $records = [
        [
            'id' => 1,
            'closing_date' => '2026-05-20',
            'initial_balance' => '50000.00',
            'sales_total' => '350000.00',
            'payments_total' => '20000.00',
            'expenses_total' => '40000.00',
            'expected_amount' => '380000.00',
            'actual_amount' => '380000.00',
            'difference' => '0.00',
            'notes' => 'Cierre cuadrado',
            'created_by' => 1,
            'created' => '2026-05-20 22:30:00',
            'modified' => '2026-05-20 22:30:00',
        ],
        [
            'id' => 2,
            'closing_date' => '2026-05-21',
            'initial_balance' => '50000.00',
            'sales_total' => '200000.00',
            'payments_total' => '0.00',
            'expenses_total' => '15000.00',
            'expected_amount' => '235000.00',
            'actual_amount' => '230000.00',
            'difference' => '-5000.00',
            'notes' => 'Faltante de 5000',
            'created_by' => 1,
            'created' => '2026-05-21 22:00:00',
            'modified' => '2026-05-21 22:00:00',
        ],
    ];
}
