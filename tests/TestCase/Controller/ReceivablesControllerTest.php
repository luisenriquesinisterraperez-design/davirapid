<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * ReceivablesController integration test case.
 */
class ReceivablesControllerTest extends TestCase
{
    use IntegrationTestTrait;
    use LocatorAwareTrait;

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
        'app.Ingredients',
        'app.ProductIngredients',
        'app.Orders',
        'app.OrderItems',
        'app.OrderLogs',
        'app.Receivables',
    ];

    /**
     * @param int $userId 1=admin (bypass).
     */
    private function loginAs(int $userId): void
    {
        /** @var \App\Model\Entity\User $user */
        $user = $this->fetchTable('Users')->get($userId, ['contain' => ['Roles']]);
        $userArray = $user->toArray();
        $userArray['role'] = $user->role !== null ? $user->role->toArray() : null;

        $this->session(['Auth' => $userArray]);
    }

    public function testIndexRedirectsAnonymous(): void
    {
        $this->get('/receivables');
        $this->assertRedirectContains('/users/login');
    }

    public function testIndexOkAsAdmin(): void
    {
        $this->loginAs(1);
        $this->get('/receivables');
        $this->assertResponseOk();
        $this->assertResponseContains('Cuentas por Cobrar');
    }

    public function testIndexListsPendingByDefault(): void
    {
        $this->loginAs(1);
        $this->get('/receivables');
        $this->assertResponseOk();
        $this->assertResponseContains('Préstamo personal'); // fixture row 1 (pendiente)
    }

    public function testIndexFilterByCustomer(): void
    {
        $this->loginAs(1);
        $this->get('/receivables?customer_id=1');
        $this->assertResponseOk();
    }

    public function testViewOk(): void
    {
        $this->loginAs(1);
        $this->get('/receivables/view/1');
        $this->assertResponseOk();
        $this->assertResponseContains('Préstamo personal');
    }

    public function testAddCreatesNewCxc(): void
    {
        $this->loginAs(1);
        $this->enableCsrfToken();
        $table = $this->fetchTable('Receivables');
        $before = $table->find()->count();

        $this->post('/receivables/add', [
            'customer_id' => 1,
            'total_amount' => '75000',
            'description' => 'Compra test',
        ]);

        $this->assertRedirectContains('/receivables');
        $this->assertSame($before + 1, $table->find()->count());
    }

    public function testMarkPaidFlipsStatus(): void
    {
        $this->loginAs(1);
        $this->enableCsrfToken();
        $this->post('/receivables/mark-paid/1');
        $this->assertRedirect();

        $reloaded = $this->fetchTable('Receivables')->get(1);
        $this->assertSame('pagado', $reloaded->status);
    }

    public function testDeleteBlocksWhenPayments(): void
    {
        $this->loginAs(1);
        $this->enableCsrfToken();
        $this->post('/receivables/delete/2'); // paid_amount > 0
        $this->assertRedirect();

        // Still there.
        $this->assertSame(
            1,
            $this->fetchTable('Receivables')->find()->where(['id' => 2])->count(),
        );
    }

    public function testDeleteSucceedsWhenNoPayments(): void
    {
        $this->loginAs(1);
        $this->enableCsrfToken();
        $this->post('/receivables/delete/1'); // paid_amount = 0
        $this->assertRedirect();

        $this->assertSame(
            0,
            $this->fetchTable('Receivables')->find()->where(['id' => 1])->count(),
        );
    }

    public function testMarkPaidRejectsGet(): void
    {
        $this->loginAs(1);
        $this->get('/receivables/mark-paid/1');
        $this->assertResponseCode(405);
    }
}
