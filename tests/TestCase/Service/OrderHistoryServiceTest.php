<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\OrderLogConstants;
use App\Service\OrderHistoryService;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * OrderHistoryService test case.
 */
class OrderHistoryServiceTest extends TestCase
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

    private OrderHistoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OrderHistoryService();
    }

    public function testLogCreatedPersistsCorrectKind(): void
    {
        $order = $this->fetchTable('Orders')->get(4);
        $logs = $this->fetchTable('OrderLogs');
        $before = $logs->find()->where(['kind' => OrderLogConstants::KIND_CREATED, 'order_id_snapshot' => 4])->count();
        $this->service->logCreated($order, 1);
        $after = $logs->find()->where(['kind' => OrderLogConstants::KIND_CREATED, 'order_id_snapshot' => 4])->count();
        $this->assertSame($before + 1, $after);
    }

    public function testLogStateChangedUsesLabels(): void
    {
        $order = $this->fetchTable('Orders')->get(4);
        $this->service->logStateChanged($order, 1, 'recibido', 'preparando');
        $logs = $this->fetchTable('OrderLogs');
        $last = $logs->find()
            ->where(['order_id_snapshot' => 4, 'kind' => OrderLogConstants::KIND_STATE_CHANGED])
            ->orderBy(['id' => 'DESC'])
            ->first();
        $this->assertNotNull($last);
        $this->assertStringContainsString('Recibido', $last->description);
        $this->assertStringContainsString('Preparando', $last->description);
    }

    public function testLogFieldChangeIgnoresFalsePositiveStringVsNumber(): void
    {
        $order = $this->fetchTable('Orders')->get(4);
        $logs = $this->fetchTable('OrderLogs');
        $before = $logs->find()->where(['order_id_snapshot' => 4, 'kind' => 'field_changed'])->count();
        // '10.00' vs 10 → normalize to '10.00' both → no log.
        $this->service->logFieldChange($order, 1, 'shipping_cost', '10.00', 10);
        $after = $logs->find()->where(['order_id_snapshot' => 4, 'kind' => 'field_changed'])->count();
        $this->assertSame($before, $after);
    }

    public function testLogFieldChangeDetectsRealChange(): void
    {
        $order = $this->fetchTable('Orders')->get(4);
        $logs = $this->fetchTable('OrderLogs');
        $before = $logs->find()->where(['order_id_snapshot' => 4, 'kind' => 'field_changed'])->count();
        $this->service->logFieldChange($order, 1, 'payment_method', 'efectivo', 'credito');
        $after = $logs->find()->where(['order_id_snapshot' => 4, 'kind' => 'field_changed'])->count();
        $this->assertSame($before + 1, $after);
    }

    public function testLogFieldChangeNormalizesDateTime(): void
    {
        $order = $this->fetchTable('Orders')->get(4);
        $logs = $this->fetchTable('OrderLogs');
        $before = $logs->find()->where(['order_id_snapshot' => 4, 'kind' => 'field_changed'])->count();
        $dt1 = new DateTime('2026-05-23 12:00:00');
        $dt2 = new DateTime('2026-05-23 12:00:00');
        $this->service->logFieldChange($order, 1, 'created', $dt1, $dt2);
        $after = $logs->find()->where(['order_id_snapshot' => 4, 'kind' => 'field_changed'])->count();
        $this->assertSame($before, $after);
    }

    public function testLogDeletedPersistsBeforeDelete(): void
    {
        $order = $this->fetchTable('Orders')->get(4);
        $logs = $this->fetchTable('OrderLogs');
        $this->service->logDeleted($order, 1);
        $log = $logs->find()
            ->where(['order_id_snapshot' => 4, 'kind' => 'deleted'])
            ->orderBy(['id' => 'DESC'])
            ->first();
        $this->assertNotNull($log);
        // Before deletion of order, order_id is still 4.
        $this->assertSame(4, $log->order_id);
    }

    public function testUserNameSnapshotResolvedFromUserId(): void
    {
        $order = $this->fetchTable('Orders')->get(4);
        $logs = $this->fetchTable('OrderLogs');
        $this->service->logReactivated($order, 1);
        $log = $logs->find()
            ->where(['order_id_snapshot' => 4, 'kind' => 'reactivated'])
            ->orderBy(['id' => 'DESC'])
            ->first();
        $this->assertNotNull($log);
        $this->assertSame('Administrador', $log->user_name_snapshot);
    }

    public function testUserNameSnapshotFallsBackToPlaceholderWhenUserMissing(): void
    {
        $order = $this->fetchTable('Orders')->get(4);
        $logs = $this->fetchTable('OrderLogs');
        $this->service->logCancelled($order, 999, 'no user');
        $log = $logs->find()
            ->where(['order_id_snapshot' => 4, 'kind' => 'cancelled'])
            ->orderBy(['id' => 'DESC'])
            ->first();
        $this->assertNotNull($log);
        $this->assertSame('—', $log->user_name_snapshot);
    }
}
