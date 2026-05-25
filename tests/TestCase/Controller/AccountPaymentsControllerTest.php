<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * AccountPaymentsController integration test case.
 */
class AccountPaymentsControllerTest extends TestCase
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
        'app.AccountPayments',
    ];

    /**
     * @param int $userId 1=admin (bypass), 2=cajero (view+create), 3=solo lectura (no access)
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
        $this->get('/account-payments');
        $this->assertRedirectContains('/users/login');
    }

    public function testIndexOkAsAdmin(): void
    {
        $this->loginAs(1);
        $this->get('/account-payments');
        $this->assertResponseOk();
        $this->assertResponseContains('Abonos');
    }

    public function testIndexOkAsCajeroWithPermission(): void
    {
        $this->loginAs(2);
        $this->get('/account-payments');
        $this->assertResponseOk();
    }

    public function testIndexForbiddenForReadOnly(): void
    {
        $this->loginAs(3);
        $this->get('/account-payments');
        $this->assertResponseCode(403);
    }

    public function testAddGetRendersForm(): void
    {
        $this->loginAs(1);
        $this->get('/account-payments/add');
        $this->assertResponseOk();
        $this->assertResponseContains('Registrar abono');
    }

    public function testAddGetWithPreselectionShowsHint(): void
    {
        $this->loginAs(1);
        $this->get('/account-payments/add?receivable_id=1');
        $this->assertResponseOk();
        // ReceivablesFixture[1] is 'Préstamo personal' for customer 1.
        $this->assertResponseContains('Préstamo personal');
    }

    public function testAddPostCreatesAbonoAndRedirects(): void
    {
        $this->loginAs(1);
        $this->enableCsrfToken();
        $table = $this->fetchTable('AccountPayments');
        $before = $table->find()->count();

        $this->post('/account-payments/add', [
            'receivable_id' => 1,
            'amount' => '50000',
            'payment_method' => 'efectivo',
        ]);

        $this->assertRedirectContains('/receivables/view/1');
        $this->assertSame($before + 1, $table->find()->count());

        $rec = $this->fetchTable('Receivables')->get(1);
        $this->assertSame('50000.00', (string)$rec->paid_amount);
    }

    public function testAddPostRejectsCreditMethod(): void
    {
        $this->loginAs(1);
        $this->enableCsrfToken();
        $table = $this->fetchTable('AccountPayments');
        $before = $table->find()->count();

        $this->post('/account-payments/add', [
            'receivable_id' => 1,
            'amount' => '100',
            'payment_method' => 'credito',
        ]);

        // No new row.
        $this->assertSame($before, $table->find()->count());
        // Flash error captured.
        $this->assertFlashElement('flash/error');
    }

    public function testAddPostRejectsOverpayment(): void
    {
        $this->loginAs(1);
        $this->enableCsrfToken();
        $table = $this->fetchTable('AccountPayments');
        $before = $table->find()->count();

        // ReceivablesFixture[2]: total 13000, paid 5000 → overpay with 10000.
        $this->post('/account-payments/add', [
            'receivable_id' => 2,
            'amount' => '10000',
            'payment_method' => 'efectivo',
        ]);

        $this->assertSame($before, $table->find()->count());
        $this->assertFlashElement('flash/error');
    }

    public function testDeleteRemovesAbonoAndRedirects(): void
    {
        $this->loginAs(1);
        $this->enableCsrfToken();
        $table = $this->fetchTable('AccountPayments');

        $this->post('/account-payments/delete/1');
        // Either referer redirect or fallback — both acceptable.
        $this->assertResponseCode(302);
        $this->assertFalse($table->exists(['id' => 1]));
    }

    public function testDeleteRequiresPostOrDelete(): void
    {
        $this->loginAs(1);
        $this->get('/account-payments/delete/1');
        $this->assertResponseCode(405);
    }

    public function testAddForbiddenForCajeroIsNotForbiddenBecauseHasCreate(): void
    {
        // Defensive: cajero does have create per fixture — should NOT be 403.
        $this->loginAs(2);
        $this->get('/account-payments/add');
        $this->assertResponseOk();
    }

    public function testDeleteForbiddenForCajero(): void
    {
        // Cajero has create but NOT delete per fixture.
        $this->loginAs(2);
        $this->enableCsrfToken();
        $this->post('/account-payments/delete/1');
        $this->assertResponseCode(403);
    }
}
