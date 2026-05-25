<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\ExpensesTable;
use Cake\TestSuite\TestCase;

class ExpensesTableTest extends TestCase
{
    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.Roles',
        'app.Users',
        'app.Expenses',
    ];

    private ExpensesTable $Expenses;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var \App\Model\Table\ExpensesTable $table */
        $table = $this->getTableLocator()->get('Expenses');
        $this->Expenses = $table;
    }

    public function testValidationRejectsEmptyDescription(): void
    {
        $e = $this->Expenses->newEntity([
            'description' => '',
            'amount' => '100.00',
            'expense_date' => '2026-05-24',
        ]);
        $errors = $e->getErrors();
        $this->assertArrayHasKey('description', $errors);
    }

    public function testValidationRejectsZeroAmount(): void
    {
        $e = $this->Expenses->newEntity([
            'description' => 'Test',
            'amount' => '0',
            'expense_date' => '2026-05-24',
        ]);
        $errors = $e->getErrors();
        $this->assertArrayHasKey('amount', $errors);
    }

    public function testValidationRejectsNegativeAmount(): void
    {
        $e = $this->Expenses->newEntity([
            'description' => 'Test',
            'amount' => '-50.00',
            'expense_date' => '2026-05-24',
        ]);
        $errors = $e->getErrors();
        $this->assertArrayHasKey('amount', $errors);
    }

    public function testValidationRequiresExpenseDate(): void
    {
        $e = $this->Expenses->newEntity([
            'description' => 'Test',
            'amount' => '100.00',
        ]);
        $errors = $e->getErrors();
        $this->assertArrayHasKey('expense_date', $errors);
    }

    public function testValidationAcceptsValidExpense(): void
    {
        $e = $this->Expenses->newEntity([
            'description' => 'Compra de insumos',
            'amount' => '150000.00',
            'expense_date' => '2026-05-24',
        ]);
        $this->assertSame([], $e->getErrors());
    }

    public function testFindInDateRange(): void
    {
        $rows = $this->Expenses->find('inDateRange', from: '2020-01-01', to: '2999-12-31')
            ->all()
            ->toArray();
        $this->assertCount(3, $rows);
    }

    public function testFindThisYear(): void
    {
        $rows = $this->Expenses->find('thisYear')->all()->toArray();
        // Today's date is 2026-05-25; year is 2026; fixtures span "today" and earlier.
        $this->assertGreaterThanOrEqual(1, count($rows));
    }
}
