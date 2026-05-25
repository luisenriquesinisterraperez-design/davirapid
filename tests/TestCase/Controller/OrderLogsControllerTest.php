<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * OrderLogsController integration test case.
 */
class OrderLogsControllerTest extends TestCase
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

    private function loginAs(int $userId): void
    {
        /** @var \App\Model\Entity\User $user */
        $user = $this->fetchTable('Users')->get($userId, ['contain' => ['Roles']]);
        $userArray = $user->toArray();
        $userArray['role'] = $user->role !== null ? $user->role->toArray() : null;
        $this->session(['Auth' => $userArray]);
    }

    public function testIndexForbiddenForNonAdmin(): void
    {
        $this->loginAs(2); // Cajero — audit is admin-only.
        $this->get('/audit');
        $this->assertResponseCode(403);
    }

    public function testIndexOkForAdmin(): void
    {
        $this->loginAs(1);
        $this->get('/audit');
        $this->assertResponseOk();
    }

    public function testIndexFilterByOrderIdPath(): void
    {
        $this->loginAs(1);
        $this->get('/audit/order/1');
        $this->assertResponseOk();
        $this->assertResponseContains('#1');
    }

    public function testIndexFilterByKindQueryString(): void
    {
        $this->loginAs(1);
        $this->get('/audit?kind=cancelled');
        $this->assertResponseOk();
    }

    public function testIndexShowsOrphanLogs(): void
    {
        $this->loginAs(1);
        $this->get('/audit');
        $this->assertResponseOk();
        $this->assertResponseContains('(eliminado)');
    }
}
