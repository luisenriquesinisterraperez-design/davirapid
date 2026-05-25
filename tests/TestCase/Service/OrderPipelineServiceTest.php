<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\OrderConstants;
use App\Model\Entity\Order;
use App\Service\OrderPipelineService;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * OrderPipelineService test case.
 */
class OrderPipelineServiceTest extends TestCase
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
        'app.Orders',
        'app.OrderItems',
        'app.OrderLogs',
    ];

    private OrderPipelineService $pipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pipeline = new OrderPipelineService();
    }

    public function testCanTransitionFromReceivedToPreparing(): void
    {
        $o = new Order(['status' => OrderConstants::STATUS_RECEIVED, 'type' => OrderConstants::TYPE_LOCAL]);
        $this->assertTrue($this->pipeline->canTransition($o, OrderConstants::STATUS_PREPARING));
    }

    public function testCanTransitionOnRouteRequiresDomicilio(): void
    {
        $local = new Order(['status' => OrderConstants::STATUS_PREPARING, 'type' => OrderConstants::TYPE_LOCAL]);
        $this->assertFalse($this->pipeline->canTransition($local, OrderConstants::STATUS_ON_ROUTE));

        $dom = new Order(['status' => OrderConstants::STATUS_PREPARING, 'type' => OrderConstants::TYPE_DOMICILIO]);
        $this->assertTrue($this->pipeline->canTransition($dom, OrderConstants::STATUS_ON_ROUTE));
    }

    public function testCanTransitionPreparingToDeliveredOnlyForLocal(): void
    {
        $local = new Order(['status' => OrderConstants::STATUS_PREPARING, 'type' => OrderConstants::TYPE_LOCAL]);
        $this->assertTrue($this->pipeline->canTransition($local, OrderConstants::STATUS_DELIVERED));

        $dom = new Order(['status' => OrderConstants::STATUS_PREPARING, 'type' => OrderConstants::TYPE_DOMICILIO]);
        $this->assertFalse($this->pipeline->canTransition($dom, OrderConstants::STATUS_DELIVERED));
    }

    public function testCanTransitionFromDeliveredAlwaysFalse(): void
    {
        $o = new Order(['status' => OrderConstants::STATUS_DELIVERED, 'type' => OrderConstants::TYPE_LOCAL]);
        $this->assertFalse($this->pipeline->canTransition($o, OrderConstants::STATUS_PREPARING));
        $this->assertFalse($this->pipeline->canTransition($o, OrderConstants::STATUS_CANCELLED));
    }

    public function testAdvanceSucceedsValidTransition(): void
    {
        $orders = $this->fetchTable('Orders');
        $order = $orders->get(4); // recibido, local
        $result = $this->pipeline->advance($order, OrderConstants::STATUS_PREPARING, 1);
        $this->assertTrue($result['success']);
        $this->assertSame(OrderConstants::STATUS_PREPARING, $orders->get(4)->status);
    }

    public function testAdvanceSetsDeliveredAtWhenDelivered(): void
    {
        $orders = $this->fetchTable('Orders');
        $order = $orders->get(4);
        // Move recibido → preparando → entregado (local).
        $this->pipeline->advance($order, OrderConstants::STATUS_PREPARING, 1);
        $order = $orders->get(4);
        $this->pipeline->advance($order, OrderConstants::STATUS_DELIVERED, 1);
        $fresh = $orders->get(4);
        $this->assertSame(OrderConstants::STATUS_DELIVERED, $fresh->status);
        $this->assertNotNull($fresh->delivered_at);
    }

    public function testAdvanceRejectsCancelDelegatedToService(): void
    {
        $orders = $this->fetchTable('Orders');
        $order = $orders->get(4);
        $result = $this->pipeline->advance($order, OrderConstants::STATUS_CANCELLED, 1);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('OrderService', $result['errors'][0]);
    }

    public function testAdvanceRejectsInvalidTransition(): void
    {
        $orders = $this->fetchTable('Orders');
        $order = $orders->get(4); // recibido
        $result = $this->pipeline->advance($order, OrderConstants::STATUS_DELIVERED, 1);
        $this->assertFalse($result['success']);
    }

    public function testAdvancePersistsLog(): void
    {
        $orders = $this->fetchTable('Orders');
        $logs = $this->fetchTable('OrderLogs');
        $before = $logs->find()->where(['kind' => 'state_changed'])->count();
        $order = $orders->get(4);
        $this->pipeline->advance($order, OrderConstants::STATUS_PREPARING, 1);
        $after = $logs->find()->where(['kind' => 'state_changed'])->count();
        $this->assertSame($before + 1, $after);
    }

    public function testNextValidStatesForReceivedLocal(): void
    {
        $o = new Order(['status' => OrderConstants::STATUS_RECEIVED, 'type' => OrderConstants::TYPE_LOCAL]);
        $next = $this->pipeline->nextValidStates($o);
        $this->assertContains(OrderConstants::STATUS_PREPARING, $next);
        $this->assertContains(OrderConstants::STATUS_CANCELLED, $next);
    }
}
