<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * RecipesController integration test case (global listing only).
 */
class RecipesControllerTest extends TestCase
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
     * @param int $userId 1=admin, 2=cajero (recipes view+create+edit), 3=solo lectura (no recipes.view)
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
        $this->get('/recipes');
        $this->assertRedirectContains('/users/login');
    }

    public function testIndexForbiddenWithoutPermission(): void
    {
        $this->loginAs(3); // Solo lectura: recipes.view=0
        $this->get('/recipes');
        $this->assertResponseCode(403);
    }

    public function testIndexOkWithPermission(): void
    {
        $this->loginAs(2); // Cajero: recipes.view=1
        $this->get('/recipes');
        $this->assertResponseOk();
        $this->assertResponseContains('Hamburguesa');
    }

    public function testIndexAsAdministratorBypass(): void
    {
        $this->loginAs(1);
        $this->get('/recipes');
        $this->assertResponseOk();
    }

    public function testIndexFilterHasRecipeWith(): void
    {
        $this->loginAs(1);
        $this->get('/recipes?has_recipe=with');
        $this->assertResponseOk();
        $this->assertResponseContains('Hamburguesa');
        $this->assertResponseContains('Pizza');
        // Empanada has no recipe — should be filtered out.
        $this->assertResponseNotContains('>Empanada<');
    }

    public function testIndexFilterHasRecipeWithout(): void
    {
        $this->loginAs(1);
        $this->get('/recipes?has_recipe=without');
        $this->assertResponseOk();
        $this->assertResponseContains('Empanada');
        $this->assertResponseNotContains('>Hamburguesa<');
    }

    public function testIndexSearchFilter(): void
    {
        $this->loginAs(1);
        $this->get('/recipes?q=Hambur');
        $this->assertResponseOk();
        $this->assertResponseContains('Hamburguesa');
        $this->assertResponseNotContains('>Pizza<');
    }
}
