<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * IngredientsController integration test case.
 */
class IngredientsControllerTest extends TestCase
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
        'app.Ingredients',
    ];

    /**
     * @param int $userId 1=admin, 2=cajero (no delete), 3=solo lectura
     */
    private function loginAs(int $userId): void
    {
        /** @var \App\Model\Entity\User $user */
        $user = $this->fetchTable('Users')->get($userId, ['contain' => ['Roles']]);
        $userArray = $user->toArray();
        $userArray['role'] = $user->role !== null ? $user->role->toArray() : null;

        $this->session([
            'Auth' => $userArray,
        ]);
    }

    public function testIndexAnonymousRedirectsToLogin(): void
    {
        $this->get('/ingredients');
        $this->assertRedirectContains('/users/login');
    }

    public function testIndexAsAdminReturnsOk(): void
    {
        $this->loginAs(1);
        $this->get('/ingredients');
        $this->assertResponseOk();
        $this->assertResponseContains('Carne molida');
    }

    public function testIndexLowStockFilterReturnsOnlyMatching(): void
    {
        $this->loginAs(1);
        $this->get('/ingredients?low_stock=1');
        $this->assertResponseOk();
        $this->assertResponseContains('Aceite');
        $this->assertResponseContains('Sal');
        $this->assertResponseNotContains('Carne molida');
    }

    public function testIndexUnitFilterReturnsOnlyMatching(): void
    {
        $this->loginAs(1);
        $this->get('/ingredients?unit=gr');
        $this->assertResponseOk();
        $this->assertResponseContains('Carne molida');
        $this->assertResponseNotContains('Aceite');
    }

    public function testAddPostAsAdminCreatesRowAndRedirects(): void
    {
        $this->loginAs(1);
        $this->enableCsrfToken();
        $this->post('/ingredients/add', [
            'name' => 'Pollo',
            'unit' => 'gr',
            'stock_quantity' => '500',
            'unit_cost' => '18',
        ]);

        $this->assertRedirect(['controller' => 'Ingredients', 'action' => 'index']);
        $this->assertTrue($this->fetchTable('Ingredients')->exists(['name' => 'Pollo']));
    }

    public function testAddAsCajeroAllowed(): void
    {
        $this->loginAs(2); // Cajero: can create
        $this->enableCsrfToken();
        $this->post('/ingredients/add', [
            'name' => 'Cebolla',
            'unit' => 'kg',
            'stock_quantity' => '5',
            'unit_cost' => '4',
        ]);

        $this->assertRedirect(['controller' => 'Ingredients', 'action' => 'index']);
    }

    public function testAddAsReadOnlyForbidden(): void
    {
        $this->loginAs(3); // Solo lectura: no create
        $this->enableCsrfToken();
        $this->post('/ingredients/add', [
            'name' => 'Ajo',
            'unit' => 'kg',
            'stock_quantity' => '1',
            'unit_cost' => '10',
        ]);

        $this->assertResponseCode(403);
    }

    public function testDeleteRequiresPostMethod(): void
    {
        $this->loginAs(1);
        $this->get('/ingredients/delete/1');
        $this->assertResponseError();
    }

    public function testDeleteAsCajeroForbidden(): void
    {
        $this->loginAs(2); // Cajero: no delete
        $this->enableCsrfToken();
        $this->post('/ingredients/delete/1');
        $this->assertResponseCode(403);
    }

    public function testDeleteAsAdminSucceeds(): void
    {
        $this->loginAs(1);
        $this->enableCsrfToken();
        $this->post('/ingredients/delete/1');

        $this->assertRedirect(['controller' => 'Ingredients', 'action' => 'index']);
        $this->assertFalse($this->fetchTable('Ingredients')->exists(['id' => 1]));
    }

    public function testEditPostUpdatesRow(): void
    {
        $this->loginAs(1);
        $this->enableCsrfToken();
        $this->post('/ingredients/edit/1', [
            'name' => 'Carne molida',
            'unit' => 'gr',
            'stock_quantity' => '2000',
            'unit_cost' => '30',
        ]);

        $this->assertRedirect(['controller' => 'Ingredients', 'action' => 'index']);
        /** @var \App\Model\Entity\Ingredient $reloaded */
        $reloaded = $this->fetchTable('Ingredients')->get(1);
        $this->assertSame('2000.000', $reloaded->stock_quantity);
    }

    public function testViewAsAdminReturnsOk(): void
    {
        $this->loginAs(1);
        $this->get('/ingredients/view/1');
        $this->assertResponseOk();
        $this->assertResponseContains('Carne molida');
    }
}
