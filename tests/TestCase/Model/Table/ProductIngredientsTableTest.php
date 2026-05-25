<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\ProductIngredientsTable;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * ProductIngredientsTable test case.
 */
class ProductIngredientsTableTest extends TestCase
{
    use LocatorAwareTrait;

    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.Products',
        'app.Ingredients',
        'app.ProductIngredients',
    ];

    protected ProductIngredientsTable $ProductIngredients;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var \App\Model\Table\ProductIngredientsTable $table */
        $table = $this->fetchTable('ProductIngredients');
        $this->ProductIngredients = $table;
    }

    protected function tearDown(): void
    {
        unset($this->ProductIngredients);
        parent::tearDown();
    }

    public function testValidationRequiresProductId(): void
    {
        $entity = $this->ProductIngredients->newEntity([
            'product_id' => '',
            'ingredient_id' => 1,
            'quantity' => '10',
        ]);
        $this->assertNotEmpty($entity->getError('product_id'));
    }

    public function testValidationRequiresIngredientId(): void
    {
        $entity = $this->ProductIngredients->newEntity([
            'product_id' => 1,
            'ingredient_id' => '',
            'quantity' => '10',
        ]);
        $this->assertNotEmpty($entity->getError('ingredient_id'));
    }

    public function testValidationRejectsNonNumericQuantity(): void
    {
        $entity = $this->ProductIngredients->newEntity([
            'product_id' => 1,
            'ingredient_id' => 1,
            'quantity' => 'abc',
        ]);
        $this->assertNotEmpty($entity->getError('quantity'));
    }

    public function testValidationRejectsZeroQuantity(): void
    {
        $entity = $this->ProductIngredients->newEntity([
            'product_id' => 1,
            'ingredient_id' => 3,
            'quantity' => '0',
        ]);
        $this->assertNotEmpty($entity->getError('quantity'));
    }

    public function testValidationRejectsNegativeQuantity(): void
    {
        $entity = $this->ProductIngredients->newEntity([
            'product_id' => 1,
            'ingredient_id' => 3,
            'quantity' => '-1',
        ]);
        $this->assertNotEmpty($entity->getError('quantity'));
    }

    public function testValidationRejectsQuantityAboveMax(): void
    {
        $entity = $this->ProductIngredients->newEntity([
            'product_id' => 1,
            'ingredient_id' => 3,
            'quantity' => '1000000',
        ]);
        $this->assertNotEmpty($entity->getError('quantity'));
    }

    public function testRulesRejectsMissingProduct(): void
    {
        $entity = $this->ProductIngredients->newEntity([
            'product_id' => 999,
            'ingredient_id' => 3,
            'quantity' => '5',
        ]);
        $this->assertFalse((bool)$this->ProductIngredients->save($entity));
        $this->assertNotEmpty($entity->getError('product_id'));
    }

    public function testRulesRejectsMissingIngredient(): void
    {
        $entity = $this->ProductIngredients->newEntity([
            'product_id' => 3,
            'ingredient_id' => 999,
            'quantity' => '5',
        ]);
        $this->assertFalse((bool)$this->ProductIngredients->save($entity));
        $this->assertNotEmpty($entity->getError('ingredient_id'));
    }

    public function testRulesRejectsDuplicatePair(): void
    {
        // Fixture already has (product 1, ingredient 1).
        $entity = $this->ProductIngredients->newEntity([
            'product_id' => 1,
            'ingredient_id' => 1,
            'quantity' => '50',
        ]);
        $this->assertFalse((bool)$this->ProductIngredients->save($entity));
        $errors = $entity->getErrors();
        $flat = [];
        array_walk_recursive($errors, function ($m) use (&$flat): void {
            $flat[] = $m;
        });
        $found = false;
        foreach ($flat as $msg) {
            if (is_string($msg) && str_contains($msg, 'ya está en la receta')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected Spanish unique-violation message');
    }

    public function testFindForProductFiltersAndOrders(): void
    {
        $results = $this->ProductIngredients->find('forProduct', product_id: 1)->toArray();
        $this->assertCount(2, $results);
        // Ordered by ingredient name ASC: Aceite (id 2), Carne molida (id 1).
        $this->assertSame(2, $results[0]->ingredient_id);
        $this->assertSame(1, $results[1]->ingredient_id);
        $this->assertNotNull($results[0]->ingredient);
        $this->assertSame('Aceite', $results[0]->ingredient->name);
    }

    public function testFindForIngredientFiltersAndOrders(): void
    {
        $results = $this->ProductIngredients->find('forIngredient', ingredient_id: 1)->toArray();
        // Two products use ingredient 1 (Hamburguesa, Pizza).
        $this->assertCount(2, $results);
        // Ordered by product name ASC: Hamburguesa, Pizza.
        $this->assertSame('Hamburguesa', $results[0]->product->name);
        $this->assertSame('Pizza', $results[1]->product->name);
    }
}
