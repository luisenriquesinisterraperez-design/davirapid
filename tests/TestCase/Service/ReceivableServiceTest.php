<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\ReceivableConstants;
use App\Model\Entity\Order;
use App\Service\ReceivableService;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * ReceivableService test case.
 */
class ReceivableServiceTest extends TestCase
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

    private ReceivableService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReceivableService();
    }

    private function loadOrder(int $id): Order
    {
        /** @var \App\Model\Entity\Order $order */
        $order = $this->fetchTable('Orders')->get($id, ['contain' => ['Customers']]);

        return $order;
    }

    // --- createFromOrder --------------------------------------------------

    public function testCreateFromOrderNoOpForNonCredit(): void
    {
        $order = $this->loadOrder(1); // efectivo
        $result = $this->service->createFromOrder($order, 1);
        $this->assertTrue($result['success']);
        $this->assertNull($result['receivable']);
    }

    public function testCreateFromOrderReusesExistingForSameOrder(): void
    {
        // Order 2 already has Receivable id=2 in fixtures.
        $order = $this->loadOrder(2);
        $result = $this->service->createFromOrder($order, 1);
        $this->assertTrue($result['success']);
        $this->assertNotNull($result['receivable']);
        $this->assertSame(2, $result['receivable']->id);
    }

    public function testCreateFromOrderRejectsZeroTotal(): void
    {
        $order = $this->loadOrder(2);
        $order->total = '0.00';
        // Force a fresh order without existing CxC by using a non-existing id.
        // Easiest: just override id to a value that has no receivable, and total=0.
        $order->id = 9999;
        $result = $this->service->createFromOrder($order, 1);
        $this->assertFalse($result['success']);
    }

    // --- createManual -----------------------------------------------------

    public function testCreateManualSucceeds(): void
    {
        $table = $this->fetchTable('Receivables');
        $before = $table->find()->count();

        $result = $this->service->createManual([
            'customer_id' => 1,
            'total_amount' => '50000',
            'description' => 'Compra del día',
        ], 1);

        $this->assertTrue($result['success'], var_export($result['errors'] ?? [], true));
        $this->assertSame($before + 1, $table->find()->count());
    }

    public function testCreateManualRejectsMissingCustomer(): void
    {
        $result = $this->service->createManual([
            'customer_id' => 0,
            'total_amount' => '100',
            'description' => 'X',
        ], 1);
        $this->assertFalse($result['success']);
    }

    public function testCreateManualRejectsEmptyDescription(): void
    {
        $result = $this->service->createManual([
            'customer_id' => 1,
            'total_amount' => '100',
            'description' => '',
        ], 1);
        $this->assertFalse($result['success']);
    }

    public function testCreateManualRejectsZeroAmount(): void
    {
        $result = $this->service->createManual([
            'customer_id' => 1,
            'total_amount' => '0',
            'description' => 'Test',
        ], 1);
        $this->assertFalse($result['success']);
    }

    // --- markAsPaid -------------------------------------------------------

    public function testMarkAsPaidFlipsStatusAndZeroesBalance(): void
    {
        $rec = $this->fetchTable('Receivables')->get(1); // pendiente, 0 paid
        $result = $this->service->markAsPaid($rec, 1);
        $this->assertTrue($result['success']);
        $reloaded = $this->fetchTable('Receivables')->get(1);
        $this->assertSame(ReceivableConstants::STATUS_PAGADO, $reloaded->status);
        $this->assertSame(0.0, $reloaded->getBalance());
    }

    public function testMarkAsPaidIsIdempotentWhenAlreadyPaid(): void
    {
        $rec = $this->fetchTable('Receivables')->get(3); // pagado
        $result = $this->service->markAsPaid($rec, 1);
        $this->assertTrue($result['success']);
    }

    // --- delete -----------------------------------------------------------

    public function testDeleteBlocksWhenPayments(): void
    {
        $rec = $this->fetchTable('Receivables')->get(2); // paid_amount = 5000
        $result = $this->service->delete($rec, 1);
        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testDeleteRemovesRowWhenNoPayments(): void
    {
        $table = $this->fetchTable('Receivables');
        $rec = $table->get(1); // paid_amount = 0
        $result = $this->service->delete($rec, 1);
        $this->assertTrue($result['success']);
        $this->assertSame(0, $table->find()->where(['id' => 1])->count());
    }

    // --- deleteForOrder ---------------------------------------------------

    public function testDeleteForOrderIsNoOpWhenNoCxc(): void
    {
        $order = $this->loadOrder(1); // efectivo, no CxC
        $result = $this->service->deleteForOrder($order, 1, 'test');
        $this->assertTrue($result['success']);
    }

    public function testDeleteForOrderRemovesEvenWithPayments(): void
    {
        // Order 2 has CxC id=2 with paid_amount=5000.
        $table = $this->fetchTable('Receivables');
        $order = $this->loadOrder(2);
        $result = $this->service->deleteForOrder($order, 1, 'test_cancel');
        $this->assertTrue($result['success']);
        $this->assertSame(0, $table->find()->where(['order_id' => 2])->count());
    }

    // --- updateAmountForOrder --------------------------------------------

    public function testUpdateAmountForOrderRaisesTotal(): void
    {
        $order = $this->loadOrder(2);
        $order->total = '20000.00';
        $result = $this->service->updateAmountForOrder($order, 1);
        $this->assertTrue($result['success']);
        $reloaded = $this->fetchTable('Receivables')->find()
            ->where(['order_id' => 2])->first();
        $this->assertSame('20000.00', (string)$reloaded->total_amount);
    }

    public function testUpdateAmountForOrderFailsWhenPaidExceedsNewTotal(): void
    {
        $order = $this->loadOrder(2); // paid_amount = 5000
        $order->total = '4000.00';
        $result = $this->service->updateAmountForOrder($order, 1);
        $this->assertFalse($result['success']);
    }

    public function testUpdateAmountForOrderCreatesWhenMissing(): void
    {
        // Use an order without CxC (order 4: efectivo, no CxC yet) made credit.
        $order = $this->loadOrder(4);
        $order->payment_method = 'credito';
        $order->customer_id = 1;
        $result = $this->service->updateAmountForOrder($order, 1);
        $this->assertTrue($result['success']);
    }
}
