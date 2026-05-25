<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\I18n\Date;
use Cake\TestSuite\Fixture\TestFixture;

/**
 * Seed for Expenses tests.
 *
 * Three rows: one today (for "Today KPI"), one earlier this month, one last month.
 */
class ExpensesFixture extends TestFixture
{
    public function init(): void
    {
        $today = (new Date())->format('Y-m-d');
        $earlierThisMonth = (new Date())->modify('first day of this month')->format('Y-m-d');
        $lastMonth = (new Date())->modify('first day of last month')->format('Y-m-d');

        $this->records = [
            [
                'id' => 1,
                'description' => 'Compra carne',
                'amount' => '150000.00',
                'expense_date' => $today,
                'created_by' => 1,
                'created' => $today . ' 09:30:00',
                'modified' => $today . ' 09:30:00',
            ],
            [
                'id' => 2,
                'description' => 'Pago servicios',
                'amount' => '80000.00',
                'expense_date' => $earlierThisMonth,
                'created_by' => 1,
                'created' => $earlierThisMonth . ' 10:00:00',
                'modified' => $earlierThisMonth . ' 10:00:00',
            ],
            [
                'id' => 3,
                'description' => 'Arriendo',
                'amount' => '1200000.00',
                'expense_date' => $lastMonth,
                'created_by' => 1,
                'created' => $lastMonth . ' 11:00:00',
                'modified' => $lastMonth . ' 11:00:00',
            ],
        ];
        parent::init();
    }
}
