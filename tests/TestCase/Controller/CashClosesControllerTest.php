<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class CashClosesControllerTest extends TestCase
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
        'app.Products',
        'app.Orders',
        'app.OrderItems',
        'app.OrderLogs',
        'app.Receivables',
        'app.AccountPayments',
        'app.Expenses',
        'app.DailyClosings',
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
        $this->get('/cash-closes');
        $this->assertRedirect();
    }

    public function testIndexAsAdmin(): void
    {
        $this->loginAs(1);
        $this->get('/cash-closes');
        $this->assertResponseOk();
        $this->assertResponseContains('Cierre Diario');
    }

    public function testViewExisting(): void
    {
        $this->loginAs(1);
        $this->get('/cash-closes/view/1');
        $this->assertResponseOk();
    }

    public function testPreviewReturnsJson(): void
    {
        $this->loginAs(1);
        $this->get('/cash-closes/preview?date=2026-05-24&initial_balance=50000');
        $this->assertResponseOk();
        $this->assertContentType('application/json');
        $body = (string)$this->_response->getBody();
        $data = json_decode($body, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('expected', $data);
    }

    public function testAddCreatesClosing(): void
    {
        $this->loginAs(1);
        $this->post('/cash-closes/add', [
            'closing_date' => '2026-04-15',
            'initial_balance' => '0.00',
            'actual_amount' => '0.00',
            'notes' => 'Test',
        ]);
        $this->assertRedirect();
        $exists = $this->fetchTable('DailyClosings')->exists(['closing_date' => '2026-04-15']);
        $this->assertTrue($exists);
    }
}
