<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * OrdersTable test case.
 */
class OrdersTableTest extends TestCase
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

    public function testValidationRejectsInvalidType(): void
    {
        $orders = $this->fetchTable('Orders');
        $entity = $orders->newEntity([
            'type' => 'invalid',
            'status' => 'recibido',
            'payment_method' => 'efectivo',
        ]);
        $this->assertNotEmpty($entity->getErrors());
    }

    public function testValidationRejectsInvalidStatus(): void
    {
        $orders = $this->fetchTable('Orders');
        $entity = $orders->newEntity([
            'type' => 'local',
            'status' => 'invalid_status',
            'payment_method' => 'efectivo',
        ]);
        $this->assertArrayHasKey('status', $entity->getErrors());
    }

    public function testValidationRejectsNegativeShippingCost(): void
    {
        $orders = $this->fetchTable('Orders');
        $entity = $orders->newEntity([
            'type' => 'local',
            'status' => 'recibido',
            'payment_method' => 'efectivo',
            'shipping_cost' => -100,
        ]);
        $this->assertArrayHasKey('shipping_cost', $entity->getErrors());
    }

    public function testFindVisibleExcludesCancelled(): void
    {
        $orders = $this->fetchTable('Orders');
        $rows = $orders->find('visible')->all()->toArray();
        $statuses = array_map(static fn($o) => $o->status, $rows);
        $this->assertNotContains('cancelado', $statuses);
    }

    public function testFindByStateMultipleArray(): void
    {
        $orders = $this->fetchTable('Orders');
        $rows = $orders->find('byState', status: ['recibido', 'entregado'])->all()->toArray();
        $this->assertGreaterThanOrEqual(2, count($rows));
        foreach ($rows as $r) {
            $this->assertContains($r->status, ['recibido', 'entregado']);
        }
    }

    public function testFindForRepartidorFilters(): void
    {
        $orders = $this->fetchTable('Orders');
        $rows = $orders->find('forRepartidor', delivery_id: 1)->all()->toArray();
        foreach ($rows as $r) {
            $this->assertSame(1, $r->delivery_id);
        }
    }

    public function testFindForRepartidorWithoutIdReturnsEmpty(): void
    {
        $orders = $this->fetchTable('Orders');
        $rows = $orders->find('forRepartidor', delivery_id: 0)->all()->toArray();
        $this->assertCount(0, $rows);
    }

    public function testFindInDateRangeInclusiveBounds(): void
    {
        $orders = $this->fetchTable('Orders');
        $rows = $orders->find('inDateRange', from: '2026-05-24', to: '2026-05-24')->all()->toArray();
        // Only order #4 was created on 2026-05-24.
        $this->assertCount(1, $rows);
        $this->assertSame(4, $rows[0]->id);
    }

    public function testFindWithItemsHydratesItems(): void
    {
        $orders = $this->fetchTable('Orders');
        $row = $orders->find('withItems')->where(['Orders.id' => 2])->first();
        $this->assertIsArray($row->order_items);
        $this->assertGreaterThan(0, count($row->order_items));
    }

    public function testHasManyOrderItemsDependentCascade(): void
    {
        $orders = $this->fetchTable('Orders');
        $items = $this->fetchTable('OrderItems');
        $order = $orders->get(4);
        $orders->delete($order);
        $this->assertSame(0, $items->find()->where(['order_id' => 4])->count());
    }

    public function testHasManyOrderLogsNotDependent(): void
    {
        $orders = $this->fetchTable('Orders');
        $logs = $this->fetchTable('OrderLogs');
        $order = $orders->get(1);
        $orders->delete($order);
        // Logs survive — order_id becomes NULL, snapshot remains.
        $surviving = $logs->find()->where(['order_id_snapshot' => 1])->all()->toArray();
        $this->assertGreaterThan(0, count($surviving));
        foreach ($surviving as $log) {
            $this->assertNull($log->order_id);
            $this->assertSame(1, $log->order_id_snapshot);
        }
    }

    public function testTimestampSetsCreatedAndModified(): void
    {
        $orders = $this->fetchTable('Orders');
        $entity = $orders->newEntity([
            'type' => 'local',
            'status' => 'recibido',
            'payment_method' => 'efectivo',
            'shipping_cost' => 0,
            'subtotal' => 0,
            'total' => 0,
        ]);
        $orders->save($entity);
        $this->assertNotNull($entity->created);
        $this->assertNotNull($entity->modified);
    }
}
