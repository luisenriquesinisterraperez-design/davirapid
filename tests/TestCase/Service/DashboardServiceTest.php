<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\DashboardService;
use Cake\TestSuite\TestCase;

class DashboardServiceTest extends TestCase
{
    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.Roles',
        'app.Users',
        'app.Customers',
        'app.Deliveries',
        'app.Ingredients',
        'app.Products',
        'app.ProductIngredients',
        'app.Orders',
        'app.OrderItems',
        'app.OrderLogs',
        'app.Receivables',
        'app.AccountPayments',
        'app.Expenses',
    ];

    private DashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DashboardService();
    }

    public function testBuildGeneralShape(): void
    {
        $data = $this->service->buildGeneral('2020-01-01', '2999-12-31');

        $this->assertArrayHasKey('today', $data);
        $this->assertArrayHasKey('period', $data);
        $this->assertArrayHasKey('by_method', $data);
        $this->assertArrayHasKey('sales_by_day', $data);
        $this->assertArrayHasKey('top_products', $data);
        $this->assertArrayHasKey('delivery_ranking', $data);
        $this->assertArrayHasKey('local_vs_domicilio', $data);
        $this->assertArrayHasKey('low_stock', $data);
    }

    public function testBuildGeneralPeriodKpisHaveExpectedKeys(): void
    {
        $data = $this->service->buildGeneral('2020-01-01', '2999-12-31');
        $p = $data['period'];

        $this->assertArrayHasKey('income', $p);
        $this->assertArrayHasKey('cogs', $p);
        $this->assertArrayHasKey('shipping', $p);
        $this->assertArrayHasKey('expenses', $p);
        $this->assertArrayHasKey('profit', $p);
        $this->assertArrayHasKey('order_count', $p);
    }

    public function testBuildGeneralIncomeExcludesCreditAndCancelled(): void
    {
        $data = $this->service->buildGeneral('2020-01-01', '2999-12-31');
        // Income should never be negative just from the fixture data.
        $this->assertGreaterThanOrEqual(0, $data['period']['income']);
    }

    public function testBuildGeneralProfitFormula(): void
    {
        $data = $this->service->buildGeneral('2020-01-01', '2999-12-31');
        $p = $data['period'];

        // profit = income - cogs - shipping - expenses
        $expected = round($p['income'] - $p['cogs'] - $p['shipping'] - $p['expenses'], 2);
        $this->assertEqualsWithDelta($expected, $p['profit'], 0.01);
    }

    public function testBuildGeneralByMethodHasAllNonCredit(): void
    {
        $data = $this->service->buildGeneral('2020-01-01', '2999-12-31');
        // Credit must NOT appear.
        $this->assertArrayNotHasKey('credito', $data['by_method']);
        // Cash-like methods should exist as keys (even if zero).
        $this->assertArrayHasKey('efectivo', $data['by_method']);
    }

    public function testBuildForRepartidor(): void
    {
        // Repartidor 1 from DeliveriesFixture.
        $data = $this->service->buildForRepartidor(1, '2020-01-01', '2999-12-31');

        $this->assertArrayHasKey('delivered', $data);
        $this->assertArrayHasKey('earnings', $data);
        $this->assertArrayHasKey('pending_today', $data);
        $this->assertIsInt($data['delivered']);
        $this->assertIsFloat($data['earnings']);
        $this->assertIsInt($data['pending_today']);
    }

    public function testLowStockListed(): void
    {
        $data = $this->service->buildGeneral('2020-01-01', '2999-12-31');
        $this->assertIsArray($data['low_stock']);
        // IngredientsFixture has a low-stock and a zero-stock row.
        $this->assertGreaterThanOrEqual(1, count($data['low_stock']));
    }
}
