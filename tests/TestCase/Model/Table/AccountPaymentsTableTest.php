<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * AccountPaymentsTable test case.
 */
class AccountPaymentsTableTest extends TestCase
{
    use LocatorAwareTrait;

    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.Roles',
        'app.Users',
        'app.Customers',
        'app.Deliveries',
        'app.Products',
        'app.Ingredients',
        'app.ProductIngredients',
        'app.Orders',
        'app.OrderItems',
        'app.OrderLogs',
        'app.Receivables',
        'app.AccountPayments',
    ];

    public function testValidationRejectsZeroAmount(): void
    {
        $table = $this->fetchTable('AccountPayments');
        $entity = $table->newEntity([
            'receivable_id' => 1,
            'amount' => '0',
            'payment_method' => 'efectivo',
        ]);
        $this->assertNotEmpty($entity->getErrors());
        $this->assertArrayHasKey('amount', $entity->getErrors());
    }

    public function testValidationRejectsNegativeAmount(): void
    {
        $table = $this->fetchTable('AccountPayments');
        $entity = $table->newEntity([
            'receivable_id' => 1,
            'amount' => '-5',
            'payment_method' => 'efectivo',
        ]);
        $this->assertArrayHasKey('amount', $entity->getErrors());
    }

    public function testValidationRejectsCreditMethod(): void
    {
        $table = $this->fetchTable('AccountPayments');
        $entity = $table->newEntity([
            'receivable_id' => 1,
            'amount' => '100',
            'payment_method' => 'credito',
        ]);
        $this->assertArrayHasKey('payment_method', $entity->getErrors());
    }

    public function testValidationRejectsUnknownMethod(): void
    {
        $table = $this->fetchTable('AccountPayments');
        $entity = $table->newEntity([
            'receivable_id' => 1,
            'amount' => '100',
            'payment_method' => 'bitcoin',
        ]);
        $this->assertArrayHasKey('payment_method', $entity->getErrors());
    }

    public function testValidationRequiresFields(): void
    {
        $table = $this->fetchTable('AccountPayments');
        $entity = $table->newEntity([]);
        $errors = $entity->getErrors();
        $this->assertArrayHasKey('receivable_id', $errors);
        $this->assertArrayHasKey('amount', $errors);
        $this->assertArrayHasKey('payment_method', $errors);
    }

    public function testBuildRulesRejectsMissingReceivable(): void
    {
        $table = $this->fetchTable('AccountPayments');
        $entity = $table->newEntity([
            'receivable_id' => 99999,
            'amount' => '100',
            'payment_method' => 'efectivo',
        ]);
        $saved = $table->save($entity);
        $this->assertFalse($saved);
        $this->assertArrayHasKey('receivable_id', $entity->getErrors());
    }

    public function testFindForReceivable(): void
    {
        $table = $this->fetchTable('AccountPayments');
        $results = $table->find('forReceivable', ['receivable_id' => 2])->all();
        $this->assertCount(2, $results);
        // Ordered DESC by created.
        $rows = $results->toList();
        $this->assertGreaterThanOrEqual(
            $rows[1]->created->getTimestamp(),
            $rows[0]->created->getTimestamp(),
        );
    }

    public function testFindForReceivableNoOpForZero(): void
    {
        $table = $this->fetchTable('AccountPayments');
        // Should not filter when id is 0; returns full set.
        $results = $table->find('forReceivable', ['receivable_id' => 0])->all();
        $this->assertCount(3, $results);
    }

    public function testFindInDateRange(): void
    {
        $table = $this->fetchTable('AccountPayments');
        $results = $table->find('inDateRange', [
            'from' => '2026-05-23', 'to' => '2026-05-23',
        ])->all();
        $this->assertCount(2, $results);
    }

    public function testFindInDateRangeFromOnly(): void
    {
        $table = $this->fetchTable('AccountPayments');
        $results = $table->find('inDateRange', ['from' => '2026-05-23'])->all();
        $this->assertCount(2, $results);
    }

    public function testFindByMethod(): void
    {
        $table = $this->fetchTable('AccountPayments');
        $results = $table->find('byMethod', ['payment_method' => 'efectivo'])->all();
        $this->assertCount(1, $results);
        $this->assertSame('efectivo', $results->first()->payment_method);
    }

    public function testFindByMethodEmptyReturnsAll(): void
    {
        $table = $this->fetchTable('AccountPayments');
        $results = $table->find('byMethod', ['payment_method' => ''])->all();
        $this->assertCount(3, $results);
    }
}
