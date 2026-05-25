<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * ProductsController integration test for the four nested recipe actions.
 * Validates the actionModuleMap override pattern (actions under
 * ProductsController checked against module 'recipes').
 */
class ProductsControllerRecipeTest extends TestCase
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
        'app.Products',
        'app.Ingredients',
        'app.ProductIngredients',
    ];

    /**
     * @param int $userId 1=admin, 2=cajero (recipes v/c/e, no delete), 3=solo lectura (products.view yes, recipes.view no)
     */
    private function loginAs(int $userId): void
    {
        /** @var \App\Model\Entity\User $user */
        $user = $this->fetchTable('Users')->get($userId, ['contain' => ['Roles']]);
        $userArray = $user->toArray();
        $userArray['role'] = $user->role !== null ? $user->role->toArray() : null;

        $this->session(['Auth' => $userArray]);
    }

    public function testRecipeRedirectsAnonymous(): void
    {
        $this->get('/products/recipe/1');
        $this->assertRedirectContains('/users/login');
    }

    public function testRecipeForbiddenWithProductsViewButWithoutRecipesView(): void
    {
        // Role 3 (Solo lectura) has products.view=1, recipes.view=0.
        // The actionModuleMap on ProductsController must force the check
        // against 'recipes' even though the controller is Products.
        $this->loginAs(3);
        $this->get('/products/recipe/1');
        $this->assertResponseCode(403);
    }

    public function testRecipeOkWithRecipesView(): void
    {
        $this->loginAs(2);
        $this->get('/products/recipe/1');
        $this->assertResponseOk();
        $this->assertResponseContains('Hamburguesa');
        $this->assertResponseContains('Carne molida');
    }

    public function testAddRecipeLineForbiddenWithoutCreate(): void
    {
        $this->loginAs(3); // no recipes permissions at all
        $this->enableCsrfToken();
        $this->post('/products/add-recipe-line/3', [
            'ingredient_id' => 1,
            'quantity' => '50',
        ]);
        $this->assertResponseCode(403);
    }

    public function testAddRecipeLineRequiresPost(): void
    {
        $this->loginAs(1);
        $this->get('/products/add-recipe-line/3');
        $this->assertResponseError();
    }

    public function testAddRecipeLineSuccessFlashesAndRedirects(): void
    {
        $this->loginAs(2);
        $this->enableCsrfToken();
        $this->post('/products/add-recipe-line/3', [
            'ingredient_id' => 1,
            'quantity' => '75',
        ]);
        $this->assertRedirect(['controller' => 'Products', 'action' => 'recipe', 3]);
        $this->assertTrue(
            $this->fetchTable('ProductIngredients')->exists([
                'product_id' => 3,
                'ingredient_id' => 1,
            ]),
        );
    }

    public function testAddRecipeLineWithCostUpdateAffectsIngredient(): void
    {
        $this->loginAs(2);
        $this->enableCsrfToken();
        $this->post('/products/add-recipe-line/3', [
            'ingredient_id' => 1,
            'quantity' => '75',
            'update_ingredient_cost' => '1',
            'new_unit_cost' => '99.50',
        ]);
        $this->assertRedirect(['controller' => 'Products', 'action' => 'recipe', 3]);
        $ing = $this->fetchTable('Ingredients')->get(1);
        $this->assertSame('99.50', $ing->unit_cost);
    }

    public function testUpdateRecipeLineForbiddenWithoutEdit(): void
    {
        $this->loginAs(3);
        $this->enableCsrfToken();
        $this->post('/products/update-recipe-line/1/1', ['quantity' => '300']);
        $this->assertResponseCode(403);
    }

    public function testUpdateRecipeLineSuccess(): void
    {
        $this->loginAs(2);
        $this->enableCsrfToken();
        $this->post('/products/update-recipe-line/1/1', ['quantity' => '321']);
        $this->assertRedirect(['controller' => 'Products', 'action' => 'recipe', 1]);
        $line = $this->fetchTable('ProductIngredients')->get(1);
        $this->assertSame('321.000', $line->quantity);
    }

    public function testRemoveRecipeLineForbiddenWithoutDelete(): void
    {
        // Cajero (role 2) does NOT have recipes.delete.
        $this->loginAs(2);
        $this->enableCsrfToken();
        $this->post('/products/remove-recipe-line/1/1');
        $this->assertResponseCode(403);
    }

    public function testRemoveRecipeLineSuccess(): void
    {
        $this->loginAs(1); // admin bypass
        $this->enableCsrfToken();
        $this->post('/products/remove-recipe-line/1/1');
        $this->assertRedirect(['controller' => 'Products', 'action' => 'recipe', 1]);
        $this->assertFalse(
            $this->fetchTable('ProductIngredients')->exists(['id' => 1]),
        );
    }

    public function testAllRecipeActionsBypassedByAdministrator(): void
    {
        $this->loginAs(1);
        $this->enableCsrfToken();

        $this->get('/products/recipe/1');
        $this->assertResponseOk();

        $this->post('/products/add-recipe-line/3', [
            'ingredient_id' => 2,
            'quantity' => '5',
        ]);
        $this->assertRedirect(['controller' => 'Products', 'action' => 'recipe', 3]);

        $this->post('/products/update-recipe-line/1/2', ['quantity' => '7']);
        $this->assertRedirect(['controller' => 'Products', 'action' => 'recipe', 1]);

        $this->post('/products/remove-recipe-line/1/2');
        $this->assertRedirect(['controller' => 'Products', 'action' => 'recipe', 1]);
    }
}
