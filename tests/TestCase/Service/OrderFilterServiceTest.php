<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\OrderConstants;
use App\Service\OrderFilterService;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * OrderFilterService test case.
 */
class OrderFilterServiceTest extends TestCase
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
    ];

    private OrderFilterService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OrderFilterService();
    }

    private function baseQuery()
    {
        return $this->fetchTable('Orders')->find();
    }

    public function testStatusVisibleExcludesCancelled(): void
    {
        $q = $this->service->apply($this->baseQuery(), ['status' => 'visible']);
        $statuses = array_map(static fn($r) => $r->status, $q->all()->toArray());
        $this->assertNotContains(OrderConstants::STATUS_CANCELLED, $statuses);
    }

    public function testStatusAllReturnsAll(): void
    {
        $q = $this->service->apply($this->baseQuery(), ['status' => 'all']);
        $this->assertSame(4, $q->count());
    }

    public function testStatusSpecificFilters(): void
    {
        $q = $this->service->apply($this->baseQuery(), ['status' => OrderConstants::STATUS_CANCELLED]);
        $this->assertSame(1, $q->count());
    }

    public function testTypeFilter(): void
    {
        $q = $this->service->apply($this->baseQuery(), ['status' => 'all', 'type' => OrderConstants::TYPE_DOMICILIO]);
        $rows = $q->all()->toArray();
        foreach ($rows as $r) {
            $this->assertSame(OrderConstants::TYPE_DOMICILIO, $r->type);
        }
    }

    public function testPaymentMethodFilter(): void
    {
        $q = $this->service->apply($this->baseQuery(), [
            'status' => 'all',
            'payment_method' => OrderConstants::PAYMENT_CREDIT,
        ]);
        foreach ($q->all()->toArray() as $r) {
            $this->assertSame(OrderConstants::PAYMENT_CREDIT, $r->payment_method);
        }
    }

    public function testDeliveryIdFilter(): void
    {
        $q = $this->service->apply($this->baseQuery(), ['status' => 'all', 'delivery_id' => 1]);
        foreach ($q->all()->toArray() as $r) {
            $this->assertSame(1, $r->delivery_id);
        }
    }

    public function testDateRangeInclusive(): void
    {
        $q = $this->service->apply($this->baseQuery(), [
            'status' => 'all',
            'from' => '2026-05-24',
            'to' => '2026-05-24',
        ]);
        $this->assertSame(1, $q->count());
    }

    public function testSearchNumericExactById(): void
    {
        $q = $this->service->apply($this->baseQuery(), ['status' => 'all', 'q' => '2']);
        $rows = $q->all()->toArray();
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]->id);
    }

    public function testSearchStringLikeNameOrPhone(): void
    {
        $q = $this->service->apply($this->baseQuery(), ['status' => 'all', 'q' => 'Juan']);
        $rows = $q->all()->toArray();
        $this->assertGreaterThan(0, count($rows));
        foreach ($rows as $r) {
            $this->assertStringContainsString('Juan', (string)$r->customer_name);
        }
    }

    public function testEmptyFiltersReturnsBaseQuery(): void
    {
        $q = $this->service->apply($this->baseQuery(), []);
        // Default status = 'visible' → excludes cancelled.
        $statuses = array_map(static fn($r) => $r->status, $q->all()->toArray());
        $this->assertNotContains(OrderConstants::STATUS_CANCELLED, $statuses);
    }
}
