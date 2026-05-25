<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Constants\ReceivableConstants;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * ReceivablesTable test case.
 */
class ReceivablesTableTest extends TestCase
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
    ];

    public function testValidationRequiresCustomerId(): void
    {
        $table = $this->fetchTable('Receivables');
        $rec = $table->newEntity([
            'total_amount' => '100.00',
            'description' => 'Test',
            'status' => ReceivableConstants::STATUS_PENDIENTE,
        ]);
        $this->assertNotEmpty($rec->getErrors());
    }

    public function testValidationRejectsZeroTotal(): void
    {
        $table = $this->fetchTable('Receivables');
        $rec = $table->newEntity([
            'customer_id' => 1,
            'total_amount' => '0',
            'description' => 'Test',
            'status' => ReceivableConstants::STATUS_PENDIENTE,
        ]);
        $this->assertArrayHasKey('total_amount', $rec->getErrors());
    }

    public function testValidationRejectsUnknownStatus(): void
    {
        $table = $this->fetchTable('Receivables');
        $rec = $table->newEntity([
            'customer_id' => 1,
            'total_amount' => '100.00',
            'description' => 'Test',
            'status' => 'inventado',
        ]);
        $this->assertArrayHasKey('status', $rec->getErrors());
    }

    public function testRulePaidWithinTotalBlocksSave(): void
    {
        $table = $this->fetchTable('Receivables');
        $rec = $table->newEntity([
            'customer_id' => 1,
            'total_amount' => '100.00',
            'paid_amount' => '150.00',
            'description' => 'Sobre-pago',
            'status' => ReceivableConstants::STATUS_PENDIENTE,
        ]);
        $saved = $table->save($rec);
        $this->assertFalse($saved);
        $this->assertArrayHasKey('paid_amount', $rec->getErrors());
    }

    public function testFindOpenReturnsOnlyPending(): void
    {
        $table = $this->fetchTable('Receivables');
        $results = $table->find('open')->all()->toArray();
        $this->assertGreaterThanOrEqual(1, count($results));
        foreach ($results as $r) {
            $this->assertSame(ReceivableConstants::STATUS_PENDIENTE, $r->status);
        }
    }

    public function testFindForCustomerFiltersByCustomer(): void
    {
        $table = $this->fetchTable('Receivables');
        $results = $table->find('forCustomer', ['customer_id' => 1])->all()->toArray();
        $this->assertGreaterThan(0, count($results));
        foreach ($results as $r) {
            $this->assertSame(1, $r->customer_id);
        }
    }

    public function testFindPendingFirstOrdersPendingBeforePaid(): void
    {
        $table = $this->fetchTable('Receivables');
        $results = $table->find('pendingFirst')->all()->toArray();
        $sawPaid = false;
        foreach ($results as $r) {
            if ($r->status === ReceivableConstants::STATUS_PAGADO) {
                $sawPaid = true;
            } elseif ($sawPaid) {
                $this->fail('Pending CxC appeared after a paid one in findPendingFirst');
            }
        }
        $this->addToAssertionCount(1);
    }
}
