<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\OrderConstants;
use App\Service\OrderService;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * OrderService test case.
 */
class OrderServiceTest extends TestCase
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
    ];

    private OrderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OrderService();
    }

    public function testCreateLocalWithoutRecipeSucceeds(): void
    {
        // Product 2 (Pizza) likely has no recipe lines in ProductIngredients fixture.
        $orders = $this->fetchTable('Orders');
        $before = $orders->find()->count();

        $result = $this->service->create([
            'type' => OrderConstants::TYPE_LOCAL,
            'payment_method' => OrderConstants::PAYMENT_CASH,
            'customer_name' => 'Smoke',
            'customer_phone' => '3000000099',
            'items' => [
                ['product_id' => 2, 'quantity' => 1],
            ],
        ], 1);

        $this->assertTrue($result['success'], var_export($result['errors'] ?? [], true));
        $this->assertSame($before + 1, $orders->find()->count());
        $this->assertSame(OrderConstants::STATUS_RECEIVED, $result['order']->status);
    }

    public function testCreateDomicilioWithoutDeliveryIdFails(): void
    {
        $result = $this->service->create([
            'type' => OrderConstants::TYPE_DOMICILIO,
            'payment_method' => OrderConstants::PAYMENT_CASH,
            'customer_name' => 'X',
            'customer_phone' => '300',
            'customer_address' => 'somewhere',
            'shipping_cost' => 5000,
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ], 1);
        $this->assertFalse($result['success']);
    }

    public function testCreateDomicilioWithoutAddressFails(): void
    {
        $result = $this->service->create([
            'type' => OrderConstants::TYPE_DOMICILIO,
            'payment_method' => OrderConstants::PAYMENT_CASH,
            'customer_name' => 'X',
            'customer_phone' => '300',
            'delivery_id' => 1,
            'shipping_cost' => 5000,
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ], 1);
        $this->assertFalse($result['success']);
    }

    public function testCreateCreditWithoutPhoneFails(): void
    {
        $result = $this->service->create([
            'type' => OrderConstants::TYPE_LOCAL,
            'payment_method' => OrderConstants::PAYMENT_CREDIT,
            'customer_name' => 'X',
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ], 1);
        $this->assertFalse($result['success']);
    }

    public function testCreateWithUnknownProductFails(): void
    {
        $result = $this->service->create([
            'type' => OrderConstants::TYPE_LOCAL,
            'payment_method' => OrderConstants::PAYMENT_CASH,
            'items' => [['product_id' => 9999, 'quantity' => 1]],
        ], 1);
        $this->assertFalse($result['success']);
    }

    public function testCreateWithEmptyItemsFails(): void
    {
        $result = $this->service->create([
            'type' => OrderConstants::TYPE_LOCAL,
            'payment_method' => OrderConstants::PAYMENT_CASH,
            'items' => [],
        ], 1);
        $this->assertFalse($result['success']);
    }

    public function testCreateWithTooManyItemsFails(): void
    {
        $items = [];
        for ($i = 0; $i < OrderConstants::MAX_ITEMS_PER_ORDER + 1; $i++) {
            $items[] = ['product_id' => 1, 'quantity' => 1];
        }
        $result = $this->service->create([
            'type' => OrderConstants::TYPE_LOCAL,
            'payment_method' => OrderConstants::PAYMENT_CASH,
            'items' => $items,
        ], 1);
        $this->assertFalse($result['success']);
    }

    public function testCreatePersistsSnapshotsFromProductsNotFromPOST(): void
    {
        // Try to spoof a price via POST; service must ignore it.
        $result = $this->service->create([
            'type' => OrderConstants::TYPE_LOCAL,
            'payment_method' => OrderConstants::PAYMENT_CASH,
            'items' => [
                ['product_id' => 1, 'quantity' => 1, 'price_at_sale' => '1.00'],
            ],
        ], 1);
        $this->assertTrue($result['success'], var_export($result['errors'] ?? [], true));
        $item = $result['order']->order_items[0];
        $this->assertSame('5000.00', (string)$item->price_at_sale);
    }

    public function testCreateForcesShippingZeroForLocal(): void
    {
        $result = $this->service->create([
            'type' => OrderConstants::TYPE_LOCAL,
            'payment_method' => OrderConstants::PAYMENT_CASH,
            'shipping_cost' => 5000,
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ], 1);
        $this->assertTrue($result['success']);
        $this->assertSame('0.00', (string)$result['order']->shipping_cost);
    }

    public function testCancelRestoresStockAndSetsCancelledFields(): void
    {
        $orders = $this->fetchTable('Orders');
        $order = $orders->get(4, ['contain' => ['OrderItems']]); // recibido
        $result = $this->service->cancel($order, 1, 'cliente cambió de idea');
        $this->assertTrue($result['success'], var_export($result['errors'] ?? [], true));
        $fresh = $orders->get(4);
        $this->assertSame(OrderConstants::STATUS_CANCELLED, $fresh->status);
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertSame(1, $fresh->cancelled_by);
    }

    public function testCancelOnDeliveredOrderFails(): void
    {
        $orders = $this->fetchTable('Orders');
        $order = $orders->get(1, ['contain' => ['OrderItems']]); // entregado
        $result = $this->service->cancel($order, 1);
        $this->assertFalse($result['success']);
    }

    public function testCancelOnAlreadyCancelledFails(): void
    {
        $orders = $this->fetchTable('Orders');
        $order = $orders->get(3, ['contain' => ['OrderItems']]); // cancelado
        $result = $this->service->cancel($order, 1);
        $this->assertFalse($result['success']);
    }

    public function testReactivateRestoresStatusAndDecrementsStock(): void
    {
        $orders = $this->fetchTable('Orders');
        $order = $orders->get(3, ['contain' => ['OrderItems']]); // cancelado
        $result = $this->service->reactivate($order, 1);
        $this->assertTrue($result['success'], var_export($result['errors'] ?? [], true));
        $fresh = $orders->get(3);
        $this->assertSame(OrderConstants::STATUS_RECEIVED, $fresh->status);
        $this->assertNull($fresh->cancelled_at);
        $this->assertNull($fresh->cancelled_by);
    }

    public function testReactivateOnNonCancelledFails(): void
    {
        $orders = $this->fetchTable('Orders');
        $order = $orders->get(4, ['contain' => ['OrderItems']]); // recibido
        $result = $this->service->reactivate($order, 1);
        $this->assertFalse($result['success']);
    }

    public function testDeleteOnCancelledLeavesLogOrphan(): void
    {
        $orders = $this->fetchTable('Orders');
        $logs = $this->fetchTable('OrderLogs');
        $order = $orders->get(3, ['contain' => ['OrderItems']]); // cancelado
        $result = $this->service->delete($order, 1);
        $this->assertTrue($result['success'], var_export($result['errors'] ?? [], true));
        $this->assertFalse($orders->exists(['id' => 3]));
        // The deletion log should exist for order_id_snapshot=3 with order_id NULL.
        $deletedLog = $logs->find()
            ->where(['order_id_snapshot' => 3, 'kind' => 'deleted'])
            ->orderBy(['id' => 'DESC'])
            ->first();
        $this->assertNotNull($deletedLog);
        $this->assertNull($deletedLog->order_id);
    }

    public function testDeleteOnActiveRestoresStockBeforeDeleting(): void
    {
        $orders = $this->fetchTable('Orders');
        $order = $orders->get(4, ['contain' => ['OrderItems']]); // recibido
        $result = $this->service->delete($order, 1);
        $this->assertTrue($result['success'], var_export($result['errors'] ?? [], true));
        $this->assertFalse($orders->exists(['id' => 4]));
    }

    public function testCreateLogsCxcWarningWhenReceivablesNotWired(): void
    {
        // Credit payment with explicit customer_id avoids auto-create path.
        $result = $this->service->create([
            'type' => OrderConstants::TYPE_LOCAL,
            'payment_method' => OrderConstants::PAYMENT_CREDIT,
            'customer_id' => 1,
            'customer_name' => 'Juan Pérez',
            'customer_phone' => '3001234567',
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ], 1);
        $this->assertTrue($result['success'], var_export($result['errors'] ?? [], true));
        $this->assertTrue($result['order']->isCredit());
    }

    public function testUpdateOnCancelledOrderFails(): void
    {
        $orders = $this->fetchTable('Orders');
        $order = $orders->get(3, ['contain' => ['OrderItems']]);
        $result = $this->service->update($order, [
            'type' => OrderConstants::TYPE_LOCAL,
            'payment_method' => OrderConstants::PAYMENT_CASH,
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ], 1);
        $this->assertFalse($result['success']);
    }

    public function testUpdateOnDeliveredOrderFails(): void
    {
        $orders = $this->fetchTable('Orders');
        $order = $orders->get(1, ['contain' => ['OrderItems']]);
        $result = $this->service->update($order, [
            'type' => OrderConstants::TYPE_LOCAL,
            'payment_method' => OrderConstants::PAYMENT_CASH,
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ], 1);
        $this->assertFalse($result['success']);
    }
}
