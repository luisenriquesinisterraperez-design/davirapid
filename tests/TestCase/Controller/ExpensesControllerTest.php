<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class ExpensesControllerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.Roles',
        'app.Users',
        'app.Permissions',
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
        $this->session([
            'Auth' => $user,
        ]);
    }

    public function testIndexRequiresAuth(): void
    {
        $this->get('/expenses');
        $this->assertRedirect();
    }

    public function testIndexAsAdminShows(): void
    {
        $this->loginAs(1);
        $this->get('/expenses');
        $this->assertResponseOk();
        $this->assertResponseContains('Gastos');
    }

    public function testAddAsAdminCreatesExpense(): void
    {
        $this->loginAs(1);
        $this->post('/expenses/add', [
            'description' => 'Compra papel térmico',
            'amount' => '25000.00',
            'expense_date' => '2026-05-24',
        ]);
        $this->assertRedirect(['controller' => 'Expenses', 'action' => 'index']);
        $exists = $this->fetchTable('Expenses')->exists(['description' => 'Compra papel térmico']);
        $this->assertTrue($exists);
    }

    public function testEditAsAdminUpdates(): void
    {
        $this->loginAs(1);
        $this->put('/expenses/edit/1', [
            'description' => 'Compra carne ajustada',
            'amount' => '160000.00',
            'expense_date' => '2026-05-24',
        ]);
        $this->assertRedirect();
        $row = $this->fetchTable('Expenses')->get(1);
        $this->assertSame('Compra carne ajustada', $row->description);
    }

    public function testDeleteAsAdmin(): void
    {
        $this->loginAs(1);
        $this->post('/expenses/delete/1');
        $this->assertRedirect(['controller' => 'Expenses', 'action' => 'index']);
        $this->assertFalse($this->fetchTable('Expenses')->exists(['id' => 1]));
    }

    public function testFilterByQuery(): void
    {
        $this->loginAs(1);
        $this->get('/expenses?q=carne');
        $this->assertResponseOk();
        $this->assertResponseContains('Compra carne');
        $this->assertResponseNotContains('Pago servicios');
    }
}
