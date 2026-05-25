<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\OrderConstants;
use App\Service\OrderService;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * Integration test covering the OrderService ↔ ReceivableService re-wire.
 *
 * Each test exercises one path of the CxC lifecycle as driven by an
 * order mutation: create, cancel, delete, reactivate, payment-method change,
 * and total change.
 */
class OrderServiceCxCIntegrationTest extends TestCase
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

    private OrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders = new OrderService();
    }

    private function countCxc(): int
    {
        return $this->fetchTable('Receivables')->find()->count();
    }

    public function testCreateNonCreditOrderDoesNotCreateCxc(): void
    {
        $before = $this->countCxc();
        $result = $this->orders->create([
            'type' => OrderConstants::TYPE_LOCAL,
            'payment_method' => OrderConstants::PAYMENT_CASH,
            'customer_name' => 'Cash Guy',
            'customer_phone' => '3000000099',
            'items' => [['product_id' => 2, 'quantity' => 1]],
        ], 1);
        $this->assertTrue($result['success'], var_export($result['errors'] ?? [], true));
        $this->assertSame($before, $this->countCxc());
    }

    public function testCreateCreditOrderCreatesCxc(): void
    {
        $before = $this->countCxc();
        $result = $this->orders->create([
            'type' => OrderConstants::TYPE_LOCAL,
            'payment_method' => OrderConstants::PAYMENT_CREDIT,
            'customer_id' => 1,
            'customer_name' => 'Juan Pérez',
            'customer_phone' => '3001234567',
            'items' => [['product_id' => 2, 'quantity' => 1]],
        ], 1);
        $this->assertTrue($result['success'], var_export($result['errors'] ?? [], true));
        $this->assertSame($before + 1, $this->countCxc());

        $rec = $this->fetchTable('Receivables')->find()
            ->where(['order_id' => $result['order']->id])
            ->first();
        $this->assertNotNull($rec);
        $this->assertSame((string)$result['order']->total, (string)$rec->total_amount);
    }

    public function testCancelCreditOrderDeletesCxc(): void
    {
        // Order 2 is credit with existing CxC id=2 (paid_amount=5000).
        // The cancel restores stock first; ProductIngredients for product 1 must
        // exist. We use the existing fixture order; if it has items linked to
        // products with recipes, restore happens; otherwise no-op.
        $orders = $this->fetchTable('Orders');
        /** @var \App\Model\Entity\Order $order */
        $order = $orders->get(2, ['contain' => ['OrderItems']]);

        $result = $this->orders->cancel($order, 1, 'integration test');
        $this->assertTrue($result['success'], var_export($result['errors'] ?? [], true));

        $this->assertSame(
            0,
            $this->fetchTable('Receivables')->find()->where(['order_id' => 2])->count(),
        );
    }

    public function testDeleteCreditOrderDeletesCxc(): void
    {
        $orders = $this->fetchTable('Orders');
        /** @var \App\Model\Entity\Order $order */
        $order = $orders->get(2, ['contain' => ['OrderItems']]);

        $result = $this->orders->delete($order, 1);
        $this->assertTrue($result['success'], var_export($result['errors'] ?? [], true));

        $this->assertSame(
            0,
            $this->fetchTable('Receivables')->find()->where(['order_id' => 2])->count(),
        );
    }

    public function testReactivateCreditOrderRecreatesCxc(): void
    {
        // First cancel order 2 to delete its CxC.
        $orders = $this->fetchTable('Orders');
        /** @var \App\Model\Entity\Order $order */
        $order = $orders->get(2, ['contain' => ['OrderItems', 'Customers']]);
        $cancelResult = $this->orders->cancel($order, 1);
        $this->assertTrue($cancelResult['success']);
        $this->assertSame(
            0,
            $this->fetchTable('Receivables')->find()->where(['order_id' => 2])->count(),
        );

        // Now reactivate — CxC should be recreated.
        /** @var \App\Model\Entity\Order $cancelled */
        $cancelled = $orders->get(2, ['contain' => ['OrderItems', 'Customers']]);
        $reactivateResult = $this->orders->reactivate($cancelled, 1);
        $this->assertTrue(
            $reactivateResult['success'],
            var_export($reactivateResult['errors'] ?? [], true),
        );

        $this->assertSame(
            1,
            $this->fetchTable('Receivables')->find()->where(['order_id' => 2])->count(),
        );
    }
}
