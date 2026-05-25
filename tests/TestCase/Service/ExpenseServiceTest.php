<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ExpenseService;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

class ExpenseServiceTest extends TestCase
{
    use LocatorAwareTrait;

    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.Roles',
        'app.Users',
        'app.Expenses',
    ];

    private ExpenseService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ExpenseService();
    }

    public function testCreateValid(): void
    {
        $result = $this->service->create([
            'description' => 'Compra empaques',
            'amount' => '45000.00',
            'expense_date' => '2026-05-24',
        ], 1);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('expense', $result);
        $this->assertSame('Compra empaques', $result['expense']->description);
        $this->assertSame(1, (int)$result['expense']->created_by);
    }

    public function testCreateRejectsZeroAmount(): void
    {
        $result = $this->service->create([
            'description' => 'X',
            'amount' => '0.00',
            'expense_date' => '2026-05-24',
        ], 1);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors'] ?? []);
    }

    public function testCreateRejectsEmptyDescription(): void
    {
        $result = $this->service->create([
            'description' => '',
            'amount' => '100.00',
            'expense_date' => '2026-05-24',
        ], 1);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors'] ?? []);
    }

    public function testCreateWithZeroUserIdStoresNullCreator(): void
    {
        $result = $this->service->create([
            'description' => 'Compra anónima',
            'amount' => '100.00',
            'expense_date' => '2026-05-24',
        ], 0);

        $this->assertTrue($result['success']);
        $this->assertNull($result['expense']->created_by);
    }

    public function testUpdateChangesFields(): void
    {
        $expenses = $this->fetchTable('Expenses');
        $expense = $expenses->get(1);
        $result = $this->service->update($expense, [
            'description' => 'Compra carne premium',
            'amount' => '200000.00',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('Compra carne premium', $result['expense']->description);
    }

    public function testUpdateNeverOverwritesCreatedBy(): void
    {
        $expenses = $this->fetchTable('Expenses');
        $expense = $expenses->get(1);
        $originalCreator = $expense->created_by;
        $result = $this->service->update($expense, [
            'description' => 'X',
            'amount' => '100.00',
            'created_by' => 999, // Should be silently ignored.
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame($originalCreator, $result['expense']->created_by);
    }

    public function testDelete(): void
    {
        $expenses = $this->fetchTable('Expenses');
        $expense = $expenses->get(1);
        $result = $this->service->delete($expense);

        $this->assertTrue($result['success']);
        $this->assertFalse($expenses->exists(['id' => 1]));
    }

    public function testNormalizeDescriptionCollapsesWhitespace(): void
    {
        $result = $this->service->create([
            'description' => '  Compra    de    insumos  ',
            'amount' => '100.00',
            'expense_date' => '2026-05-24',
        ], 1);

        $this->assertTrue($result['success']);
        $this->assertSame('Compra de insumos', $result['expense']->description);
    }
}
