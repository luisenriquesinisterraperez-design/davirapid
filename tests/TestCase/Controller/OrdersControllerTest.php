<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * OrdersController integration test case.
 */
class OrdersControllerTest extends TestCase
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
    ];

    /**
     * @param int $userId 1=admin, 2=cajero, 3=solo lectura
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
        $this->get('/orders');
        $this->assertRedirectContains('/users/login');
    }

    public function testIndexForbiddenWithoutPermission(): void
    {
        $this->loginAs(3); // Solo lectura: no orders permission seeded.
        $this->get('/orders');
        $this->assertResponseCode(403);
    }

    public function testIndexAsAdministratorBypass(): void
    {
        $this->loginAs(1);
        $this->get('/orders');
        $this->assertResponseOk();
    }

    public function testViewAsAdministrator(): void
    {
        $this->loginAs(1);
        $this->get('/orders/view/1');
        $this->assertResponseOk();
        $this->assertResponseContains('#1');
    }

    public function testAddGetShowsForm(): void
    {
        $this->loginAs(1);
        $this->get('/orders/add');
        $this->assertResponseOk();
        $this->assertResponseContains('Nuevo pedido');
    }

    public function testDeleteRequiresPost(): void
    {
        $this->loginAs(1);
        $this->get('/orders/delete/4');
        $this->assertResponseError();
    }

    public function testCancelRequiresPost(): void
    {
        $this->loginAs(1);
        $this->get('/orders/cancel/4');
        $this->assertResponseError();
    }

    public function testTicketGetRenders(): void
    {
        $this->loginAs(1);
        $this->get('/orders/ticket/1');
        $this->assertResponseOk();
        $this->assertResponseContains('PEDIDO #1');
    }

    public function testEditOnDeliveredRedirectsToView(): void
    {
        $this->loginAs(1);
        $this->get('/orders/edit/1'); // entregado
        $this->assertRedirectContains('/orders/view/1');
    }
}
