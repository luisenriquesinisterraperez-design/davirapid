<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class DashboardControllerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.Roles',
        'app.Users',
        'app.Permissions',
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
    }

    private function loginAs(int $userId): void
    {
        $user = $this->fetchTable('Users')->get($userId, contain: ['Roles']);
        $this->session(['Auth' => $user]);
    }

    public function testIndexRequiresAuth(): void
    {
        $this->get('/dashboard');
        $this->assertRedirect();
    }

    public function testIndexAsAdminShowsGeneralView(): void
    {
        $this->loginAs(1);
        $this->get('/dashboard');
        $this->assertResponseOk();
        $this->assertResponseContains('Dashboard');
        $this->assertResponseContains('Ingresos reales');
    }

    public function testIndexRespectsDateRangeFilter(): void
    {
        $this->loginAs(1);
        $this->get('/dashboard?from=2020-01-01&to=2999-12-31');
        $this->assertResponseOk();
    }

    public function testIndexInvalidDateFallsBack(): void
    {
        $this->loginAs(1);
        $this->get('/dashboard?from=not-a-date&to=also-bad');
        $this->assertResponseOk();
    }

    public function testHomeRouteResolvesToDashboard(): void
    {
        $this->loginAs(1);
        $this->get('/');
        $this->assertResponseOk();
        $this->assertResponseContains('Dashboard');
    }
}
