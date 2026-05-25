<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Constants\OrderConstants;
use App\Model\Entity\Customer;
use App\Model\Entity\Order;
use App\Model\Entity\OrderItem;
use Cake\TestSuite\TestCase;

/**
 * Order entity test case.
 */
class OrderTest extends TestCase
{
    public function testIsCancelledReturnsTrueWhenStatusCancelled(): void
    {
        $o = new Order(['status' => OrderConstants::STATUS_CANCELLED]);
        $this->assertTrue($o->isCancelled());
    }

    public function testIsEditableForReceivedAndPreparing(): void
    {
        $this->assertTrue((new Order(['status' => OrderConstants::STATUS_RECEIVED]))->isEditable());
        $this->assertTrue((new Order(['status' => OrderConstants::STATUS_PREPARING]))->isEditable());
    }

    public function testIsNotEditableForOnRouteOrDeliveredOrCancelled(): void
    {
        $this->assertFalse((new Order(['status' => OrderConstants::STATUS_ON_ROUTE]))->isEditable());
        $this->assertFalse((new Order(['status' => OrderConstants::STATUS_DELIVERED]))->isEditable());
        $this->assertFalse((new Order(['status' => OrderConstants::STATUS_CANCELLED]))->isEditable());
    }

    public function testIsCancellableForActiveStates(): void
    {
        $this->assertTrue((new Order(['status' => OrderConstants::STATUS_RECEIVED]))->isCancellable());
        $this->assertTrue((new Order(['status' => OrderConstants::STATUS_PREPARING]))->isCancellable());
        $this->assertTrue((new Order(['status' => OrderConstants::STATUS_ON_ROUTE]))->isCancellable());
    }

    public function testIsNotCancellableForDeliveredOrCancelled(): void
    {
        $this->assertFalse((new Order(['status' => OrderConstants::STATUS_DELIVERED]))->isCancellable());
        $this->assertFalse((new Order(['status' => OrderConstants::STATUS_CANCELLED]))->isCancellable());
    }

    public function testCanTransitionToValidNext(): void
    {
        $o = new Order(['status' => OrderConstants::STATUS_RECEIVED, 'type' => OrderConstants::TYPE_LOCAL]);
        $this->assertTrue($o->canTransitionTo(OrderConstants::STATUS_PREPARING));
    }

    public function testCanTransitionToInvalidReturnsFalse(): void
    {
        $o = new Order(['status' => OrderConstants::STATUS_DELIVERED, 'type' => OrderConstants::TYPE_LOCAL]);
        $this->assertFalse($o->canTransitionTo(OrderConstants::STATUS_PREPARING));
    }

    public function testCanTransitionOnRouteOnlyForDomicilio(): void
    {
        $localPrep = new Order(['status' => OrderConstants::STATUS_PREPARING, 'type' => OrderConstants::TYPE_LOCAL]);
        $this->assertFalse($localPrep->canTransitionTo(OrderConstants::STATUS_ON_ROUTE));

        $domPrep = new Order(['status' => OrderConstants::STATUS_PREPARING, 'type' => OrderConstants::TYPE_DOMICILIO]);
        $this->assertTrue($domPrep->canTransitionTo(OrderConstants::STATUS_ON_ROUTE));
    }

    public function testCanTransitionPreparingToDeliveredOnlyForLocal(): void
    {
        $local = new Order(['status' => OrderConstants::STATUS_PREPARING, 'type' => OrderConstants::TYPE_LOCAL]);
        $this->assertTrue($local->canTransitionTo(OrderConstants::STATUS_DELIVERED));

        $dom = new Order(['status' => OrderConstants::STATUS_PREPARING, 'type' => OrderConstants::TYPE_DOMICILIO]);
        $this->assertFalse($dom->canTransitionTo(OrderConstants::STATUS_DELIVERED));
    }

    public function testGetCustomerNamePrefersSnapshot(): void
    {
        $o = new Order(['customer_name' => 'Snap']);
        $o->customer = new Customer(['name' => 'Other']);
        $this->assertSame('Snap', $o->getCustomerName());
    }

    public function testGetCustomerNameFallsBackToRelation(): void
    {
        $o = new Order(['customer_name' => null]);
        $o->customer = new Customer(['name' => 'Relation Name']);
        $this->assertSame('Relation Name', $o->getCustomerName());
    }

    public function testGetCustomerNameReturnsPlaceholderIfBothNull(): void
    {
        $o = new Order(['customer_name' => null]);
        $this->assertSame('Sin nombre', $o->getCustomerName());
    }

    public function testGetItemsSummaryWithOneItem(): void
    {
        $o = new Order();
        $o->order_items = [
            new OrderItem(['product_name' => 'Hamburguesa', 'quantity' => '2.000']),
        ];
        $this->assertSame('2 × Hamburguesa', $o->getItemsSummary());
    }

    public function testGetItemsSummaryWithMultiple(): void
    {
        $o = new Order();
        $o->order_items = [
            new OrderItem(['product_name' => 'Hamburguesa', 'quantity' => '2.000']),
            new OrderItem(['product_name' => 'Pizza', 'quantity' => '1.000']),
            new OrderItem(['product_name' => 'Empanada', 'quantity' => '3.000']),
        ];
        $this->assertSame('2 × Hamburguesa (+2 más)', $o->getItemsSummary());
    }

    public function testGetItemsSummaryEmpty(): void
    {
        $o = new Order();
        $this->assertSame('—', $o->getItemsSummary());
    }

    public function testGetStatusCssClassMapsCorrectly(): void
    {
        $this->assertSame(
            'status-pending',
            (new Order(['status' => OrderConstants::STATUS_RECEIVED]))->getStatusCssClass(),
        );
        $this->assertSame(
            'status-preparing',
            (new Order(['status' => OrderConstants::STATUS_PREPARING]))->getStatusCssClass(),
        );
        $this->assertSame(
            'status-on-route',
            (new Order(['status' => OrderConstants::STATUS_ON_ROUTE]))->getStatusCssClass(),
        );
        $this->assertSame(
            'status-delivered',
            (new Order(['status' => OrderConstants::STATUS_DELIVERED]))->getStatusCssClass(),
        );
        $this->assertSame(
            'status-cancelled',
            (new Order(['status' => OrderConstants::STATUS_CANCELLED]))->getStatusCssClass(),
        );
    }

    public function testVirtualDisplayStatusAccessible(): void
    {
        $o = new Order(['status' => OrderConstants::STATUS_DELIVERED]);
        $this->assertSame('Entregado', $o->display_status);
    }

    public function testVirtualIsCreditAccessible(): void
    {
        $o = new Order(['payment_method' => OrderConstants::PAYMENT_CREDIT]);
        $this->assertTrue($o->is_credit);

        $o2 = new Order(['payment_method' => OrderConstants::PAYMENT_CASH]);
        $this->assertFalse($o2->is_credit);
    }
}
