<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\DailyClosingService;
use Cake\I18n\Date;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

class DailyClosingServiceTest extends TestCase
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
        'app.Receivables',
        'app.AccountPayments',
        'app.Expenses',
        'app.DailyClosings',
    ];

    private DailyClosingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DailyClosingService();
    }

    public function testComputeExpectedHasShape(): void
    {
        $breakdown = $this->service->computeExpected('2026-05-24', 100000.0);
        $this->assertArrayHasKey('sales_total', $breakdown);
        $this->assertArrayHasKey('payments_total', $breakdown);
        $this->assertArrayHasKey('expenses_total', $breakdown);
        $this->assertArrayHasKey('expected', $breakdown);
    }

    public function testComputeExpectedAppliesInitialBalance(): void
    {
        $a = $this->service->computeExpected('2026-05-24', 0.0);
        $b = $this->service->computeExpected('2026-05-24', 50000.0);
        $this->assertEqualsWithDelta(50000.0, $b['expected'] - $a['expected'], 0.01);
    }

    public function testCreateValid(): void
    {
        $today = (new Date())->format('Y-m-d');
        $result = $this->service->create([
            'closing_date' => $today,
            'initial_balance' => '10000.00',
            'actual_amount' => '10000.00',
            'notes' => 'Sin movimientos',
        ], 1);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('closing', $result);
        $this->assertSame(0.0, (float)$result['closing']->difference);
    }

    public function testCreateRejectsMissingDate(): void
    {
        $result = $this->service->create([
            'initial_balance' => '0.00',
            'actual_amount' => '100.00',
        ], 1);
        $this->assertFalse($result['success']);
    }

    public function testCreateRejectsDuplicateDate(): void
    {
        // 2026-05-20 already exists in fixture.
        $result = $this->service->create([
            'closing_date' => '2026-05-20',
            'initial_balance' => '0.00',
            'actual_amount' => '100.00',
        ], 1);
        $this->assertFalse($result['success']);
    }

    public function testUpdateOnlyTouchesEditableFields(): void
    {
        $closings = $this->fetchTable('DailyClosings');
        $closing = $closings->get(1);
        $originalExpected = (float)$closing->expected_amount;

        $result = $this->service->update($closing, [
            'actual_amount' => '400000.00',
            'notes' => 'Editado',
            'expected_amount' => '999.00', // Should be ignored.
        ]);

        $this->assertTrue($result['success']);
        $this->assertEqualsWithDelta($originalExpected, (float)$result['closing']->expected_amount, 0.01);
        $this->assertSame('Editado', $result['closing']->notes);
    }

    public function testUpdateRecalculatesDifference(): void
    {
        $closings = $this->fetchTable('DailyClosings');
        $closing = $closings->get(1); // expected=380000
        $result = $this->service->update($closing, ['actual_amount' => '375000.00']);

        $this->assertTrue($result['success']);
        $this->assertEqualsWithDelta(-5000.0, (float)$result['closing']->difference, 0.01);
    }

    public function testDelete(): void
    {
        $closings = $this->fetchTable('DailyClosings');
        $closing = $closings->get(1);
        $result = $this->service->delete($closing);

        $this->assertTrue($result['success']);
        $this->assertFalse($closings->exists(['id' => 1]));
    }
}
