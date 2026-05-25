<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * OrderLogsTable test case.
 */
class OrderLogsTableTest extends TestCase
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

    public function testValidationRejectsInvalidKind(): void
    {
        $logs = $this->fetchTable('OrderLogs');
        $entity = $logs->newEntity([
            'order_id_snapshot' => 1,
            'user_name_snapshot' => 'Test',
            'kind' => 'invalid_kind',
            'description' => 'Test',
        ]);
        $this->assertArrayHasKey('kind', $entity->getErrors());
    }

    public function testValidationRequiresOrderIdSnapshot(): void
    {
        $logs = $this->fetchTable('OrderLogs');
        $entity = $logs->newEntity([
            'user_name_snapshot' => 'Test',
            'kind' => 'created',
            'description' => 'Test',
        ]);
        $this->assertArrayHasKey('order_id_snapshot', $entity->getErrors());
    }

    public function testValidationRejectsTooLongDescription(): void
    {
        $logs = $this->fetchTable('OrderLogs');
        $entity = $logs->newEntity([
            'order_id_snapshot' => 1,
            'user_name_snapshot' => 'Test',
            'kind' => 'created',
            'description' => str_repeat('a', 501),
        ]);
        $this->assertArrayHasKey('description', $entity->getErrors());
    }

    public function testFindForOrderFiltersBySnapshot(): void
    {
        $logs = $this->fetchTable('OrderLogs');
        $rows = $logs->find('forOrder', order_id: 1)->all()->toArray();
        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $this->assertSame(1, $r->order_id_snapshot);
        }
    }

    public function testFindChronologicalOrdersByCreatedDescIdDesc(): void
    {
        $logs = $this->fetchTable('OrderLogs');
        $rows = $logs->find('chronological')->all()->toArray();
        $this->assertGreaterThan(0, count($rows));
        // First row should be the most recent (id=5, 2026-05-23 14:00).
        $this->assertSame(5, $rows[0]->id);
    }

    public function testTimestampSetsOnlyCreated(): void
    {
        $logs = $this->fetchTable('OrderLogs');
        $entity = $logs->newEntity([
            'order_id' => 1,
            'order_id_snapshot' => 1,
            'user_id' => 1,
            'user_name_snapshot' => 'Test',
            'kind' => 'created',
            'description' => 'Smoke',
        ]);
        $result = $logs->save($entity);
        $this->assertNotFalse($result);
        $this->assertNotNull($entity->created);
    }
}
