<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * AdjustmentsController integration test case.
 */
class AdjustmentsControllerTest extends TestCase
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
        'app.InventoryAdjustments',
    ];

    /**
     * @param int $userId 1=admin, 2=cajero (view+create), 3=solo lectura (no access)
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
        $this->get('/adjustments');
        $this->assertRedirectContains('/users/login');
    }

    public function testIndexForbiddenWithoutPermission(): void
    {
        $this->loginAs(3); // Solo lectura: adjustments.view=0
        $this->get('/adjustments');
        $this->assertResponseCode(403);
    }

    public function testIndexOkWithPermission(): void
    {
        $this->loginAs(2); // Cajero: adjustments.view=1
        $this->get('/adjustments');
        $this->assertResponseOk();
        $this->assertResponseContains('Compra a proveedor');
    }

    public function testIndexAsAdministratorBypass(): void
    {
        $this->loginAs(1);
        $this->get('/adjustments');
        $this->assertResponseOk();
    }

    public function testIndexFilterByIngredient(): void
    {
        $this->loginAs(1);
        $this->get('/adjustments?ingredient_id=1');
        $this->assertResponseOk();
        $this->assertResponseContains('Carne molida');
    }

    public function testIndexFilterByType(): void
    {
        $this->loginAs(1);
        $this->get('/adjustments?type=entrada');
        $this->assertResponseOk();
        $this->assertResponseContains('Compra a proveedor');
        $this->assertResponseNotContains('Merma');
    }

    public function testIndexFilterByDateRange(): void
    {
        $this->loginAs(1);
        $this->get('/adjustments?from=2026-05-21&to=2026-05-21');
        $this->assertResponseOk();
        $this->assertResponseContains('Merma');
        $this->assertResponseNotContains('Compra a proveedor');
    }

    public function testIndexNormalizesInvertedDateRange(): void
    {
        $this->loginAs(1);
        $this->get('/adjustments?from=2026-05-22&to=2026-05-20');
        $this->assertResponseOk();
        // After normalization both fixture rows fall in [2026-05-20, 2026-05-22].
        $this->assertResponseContains('Compra a proveedor');
        $this->assertResponseContains('Merma');
    }

    public function testAddGetShowsForm(): void
    {
        $this->loginAs(1);
        $this->get('/adjustments/add');
        $this->assertResponseOk();
        $this->assertResponseContains('Nuevo ajuste');
    }

    public function testAddGetWithPreselectIngredient(): void
    {
        $this->loginAs(1);
        $this->get('/adjustments/add?ingredient_id=1');
        $this->assertResponseOk();
        // The select should mark id=1 as selected.
        $this->assertResponseContains('value="1"');
    }

    public function testAddPostForbiddenWithoutCreate(): void
    {
        $this->loginAs(3); // Solo lectura: no create
        $this->enableCsrfToken();
        $this->post('/adjustments/add', [
            'ingredient_id' => 1,
            'type' => 'entrada',
            'quantity' => '1',
            'reason' => 'Test',
        ]);
        $this->assertResponseCode(403);
    }

    public function testAddPostSuccessRedirectsAndFlashes(): void
    {
        $this->loginAs(1);
        $this->enableCsrfToken();
        $this->post('/adjustments/add', [
            'ingredient_id' => 1,
            'type' => 'entrada',
            'quantity' => '50',
            'reason' => 'Compra a proveedor',
        ]);

        $this->assertRedirect(['controller' => 'Adjustments', 'action' => 'index']);
        // Stock moved from 1500 → 1550.
        $this->assertSame(
            '1550.000',
            (string)$this->fetchTable('Ingredients')->get(1)->stock_quantity,
        );
    }

    public function testAddPostBajaInsufficientStockFlashesError(): void
    {
        $this->loginAs(1);
        $this->enableCsrfToken();
        // Aceite (id=2) has 3.500 → baja 100 must fail.
        $this->post('/adjustments/add', [
            'ingredient_id' => 2,
            'type' => 'baja',
            'quantity' => '100',
            'reason' => 'Merma exagerada',
        ]);
        $this->assertResponseOk();
        // Stock unchanged.
        $this->assertSame(
            '3.500',
            (string)$this->fetchTable('Ingredients')->get(2)->stock_quantity,
        );
    }

    public function testDeleteForbiddenWithoutDelete(): void
    {
        $this->loginAs(2); // Cajero: adjustments.delete=0
        $this->enableCsrfToken();
        $this->post('/adjustments/delete/1');
        $this->assertResponseCode(403);
    }

    public function testDeleteRequiresPostOrDelete(): void
    {
        $this->loginAs(1);
        $this->get('/adjustments/delete/1');
        $this->assertResponseError();
    }

    public function testDeleteSuccess(): void
    {
        // Bump stock so the reverse of fixture entrada (500) succeeds.
        $ingredients = $this->fetchTable('Ingredients');
        $ing = $ingredients->get(1);
        $ing->stock_quantity = '2000.000';
        $ingredients->save($ing);

        $this->loginAs(1);
        $this->enableCsrfToken();
        $this->post('/adjustments/delete/1');

        $this->assertRedirect(['controller' => 'Adjustments', 'action' => 'index']);
        $this->assertFalse($this->fetchTable('InventoryAdjustments')->exists(['id' => 1]));
        $this->assertSame('1500.000', (string)$ingredients->get(1)->stock_quantity);
    }

    public function testDeleteReverseFailsOnNegativeStock(): void
    {
        // Stock currently 1500 — reverse of fixture entrada (-500) ok normally. Drop to 200 to fail.
        $ingredients = $this->fetchTable('Ingredients');
        $ing = $ingredients->get(1);
        $ing->stock_quantity = '200.000';
        $ingredients->save($ing);

        $this->loginAs(1);
        $this->enableCsrfToken();
        $this->post('/adjustments/delete/1');

        $this->assertRedirect(['controller' => 'Adjustments', 'action' => 'index']);
        $this->assertTrue($this->fetchTable('InventoryAdjustments')->exists(['id' => 1]));
        $this->assertSame('200.000', (string)$ingredients->get(1)->stock_quantity);
    }

    public function testDeleteMissingIdFlashesError(): void
    {
        $this->loginAs(1);
        $this->enableCsrfToken();
        $this->post('/adjustments/delete/999');
        $this->assertRedirect(['controller' => 'Adjustments', 'action' => 'index']);
    }

    public function testEditAction404(): void
    {
        $this->loginAs(1);
        $this->get('/adjustments/edit/1');
        $this->assertResponseError();
    }

    public function testViewAction404(): void
    {
        $this->loginAs(1);
        $this->get('/adjustments/view/1');
        $this->assertResponseError();
    }
}
