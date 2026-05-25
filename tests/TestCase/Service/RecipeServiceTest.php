<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\RecipeService;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * RecipeService test case.
 */
class RecipeServiceTest extends TestCase
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

    private RecipeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RecipeService();
    }

    protected function tearDown(): void
    {
        unset($this->service);
        parent::tearDown();
    }

    public function testAddLineSuccessReturnsHydratedLine(): void
    {
        // Product 3 (Empanada) has no recipe yet.
        $result = $this->service->addLine([
            'product_id' => 3,
            'ingredient_id' => 1,
            'quantity' => '50.000',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('line', $result);
        $this->assertNotNull($result['line']->ingredient);
        $this->assertSame('Carne molida', $result['line']->ingredient->name);
    }

    public function testAddLineRejectsMissingProduct(): void
    {
        $result = $this->service->addLine([
            'product_id' => 999,
            'ingredient_id' => 1,
            'quantity' => '10',
        ]);
        $this->assertFalse($result['success']);
    }

    public function testAddLineRejectsMissingIngredient(): void
    {
        $result = $this->service->addLine([
            'product_id' => 3,
            'ingredient_id' => 999,
            'quantity' => '10',
        ]);
        $this->assertFalse($result['success']);
    }

    public function testAddLineRejectsZeroQuantity(): void
    {
        $result = $this->service->addLine([
            'product_id' => 3,
            'ingredient_id' => 1,
            'quantity' => '0',
        ]);
        $this->assertFalse($result['success']);
    }

    public function testAddLineOverwritesExistingPair(): void
    {
        // Fixture already has (product 1, ingredient 1) = 200.000.
        $result = $this->service->addLine([
            'product_id' => 1,
            'ingredient_id' => 1,
            'quantity' => '999.000',
        ]);
        $this->assertTrue($result['success']);

        $table = $this->fetchTable('ProductIngredients');
        $rows = $table->find()
            ->where(['product_id' => 1, 'ingredient_id' => 1])
            ->all()
            ->toArray();
        $this->assertCount(1, $rows);
        $this->assertSame('999.000', $rows[0]->quantity);
    }

    public function testAddLineWithCostUpdateSucceeds(): void
    {
        $result = $this->service->addLine([
            'product_id' => 3,
            'ingredient_id' => 1,
            'quantity' => '10',
            'update_ingredient_cost' => true,
            'new_unit_cost' => '1.25',
        ]);
        $this->assertTrue($result['success']);

        $ing = $this->fetchTable('Ingredients')->get(1);
        $this->assertSame('1.25', $ing->unit_cost);
    }

    public function testAddLineWithCostUpdateMissingNewCostReturnsError(): void
    {
        $result = $this->service->addLine([
            'product_id' => 3,
            'ingredient_id' => 1,
            'quantity' => '10',
            'update_ingredient_cost' => true,
            'new_unit_cost' => '',
        ]);
        $this->assertFalse($result['success']);
        // Line not inserted.
        $this->assertFalse(
            $this->fetchTable('ProductIngredients')->exists([
                'product_id' => 3,
                'ingredient_id' => 1,
            ]),
        );
    }

    public function testAddLineWithCostUpdateNegativeNewCostReturnsError(): void
    {
        $result = $this->service->addLine([
            'product_id' => 3,
            'ingredient_id' => 1,
            'quantity' => '10',
            'update_ingredient_cost' => true,
            'new_unit_cost' => '-5',
        ]);
        $this->assertFalse($result['success']);
        $this->assertFalse(
            $this->fetchTable('ProductIngredients')->exists([
                'product_id' => 3,
                'ingredient_id' => 1,
            ]),
        );
    }

    public function testAddLineCostUpdateRollsBackOnLineFailure(): void
    {
        $originalCost = $this->fetchTable('Ingredients')->get(1)->unit_cost;

        // Force line failure with quantity 0 (validation fails before save).
        $result = $this->service->addLine([
            'product_id' => 3,
            'ingredient_id' => 1,
            'quantity' => '0',
            'update_ingredient_cost' => true,
            'new_unit_cost' => '99.99',
        ]);
        $this->assertFalse($result['success']);

        $costAfter = $this->fetchTable('Ingredients')->get(1)->unit_cost;
        $this->assertSame($originalCost, $costAfter);
    }

    public function testUpdateLineSuccess(): void
    {
        $result = $this->service->updateLine(1, '250.000');
        $this->assertTrue($result['success']);
        $line = $this->fetchTable('ProductIngredients')->get(1);
        $this->assertSame('250.000', $line->quantity);
    }

    public function testUpdateLineRejectsMissingId(): void
    {
        $result = $this->service->updateLine(999, '10');
        $this->assertFalse($result['success']);
    }

    public function testUpdateLineRejectsZeroQuantity(): void
    {
        $result = $this->service->updateLine(1, '0');
        $this->assertFalse($result['success']);
    }

    public function testRemoveLineSuccess(): void
    {
        $result = $this->service->removeLine(1);
        $this->assertTrue($result['success']);
        $this->assertFalse(
            $this->fetchTable('ProductIngredients')->exists(['id' => 1]),
        );
    }

    public function testRemoveLineRejectsMissingId(): void
    {
        $result = $this->service->removeLine(999);
        $this->assertFalse($result['success']);
    }

    public function testGetRecipeForReturnsHydratedListOrderedByName(): void
    {
        $lines = $this->service->getRecipeFor(1);
        $this->assertCount(2, $lines);
        $this->assertSame('Aceite', $lines[0]->ingredient->name);
        $this->assertSame('Carne molida', $lines[1]->ingredient->name);
    }

    public function testGetRecipeForEmptyWhenNoLines(): void
    {
        $this->assertSame([], $this->service->getRecipeFor(3));
    }

    public function testCalculateRecipeCostSums(): void
    {
        // Product 1: Carne 200gr * 25 = 5000, Aceite 1ml * 12 = 12. Total 5012.
        $cost = $this->service->calculateRecipeCost(1);
        $this->assertSame(5012.0, $cost);
    }

    public function testCalculateRecipeCostZeroWhenEmpty(): void
    {
        $this->assertSame(0.0, $this->service->calculateRecipeCost(3));
    }

    public function testHasRecipeTrueFalse(): void
    {
        $this->assertTrue($this->service->hasRecipe(1));
        $this->assertFalse($this->service->hasRecipe(3));
    }

    public function testBuildDecrementPlanScalesByUnitsSold(): void
    {
        // Product 1, 2 units: Carne 200*2=400.000, Aceite 1*2=2.000.
        $plan = $this->service->buildDecrementPlan(1, 2);
        $this->assertCount(2, $plan);

        $byIngredient = [];
        foreach ($plan as $entry) {
            $byIngredient[$entry['ingredient_id']] = $entry['quantity'];
        }
        $this->assertSame('400.000', $byIngredient[1]);
        $this->assertSame('2.000', $byIngredient[2]);
    }

    public function testBuildDecrementPlanEmptyWhenNoRecipe(): void
    {
        $this->assertSame([], $this->service->buildDecrementPlan(3, 5));
    }

    public function testBuildDecrementPlanEmptyWhenUnitsSoldZero(): void
    {
        $this->assertSame([], $this->service->buildDecrementPlan(1, 0));
    }
}
