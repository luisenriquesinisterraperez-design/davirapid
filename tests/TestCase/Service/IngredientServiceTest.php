<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\IngredientService;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * IngredientService test case.
 */
class IngredientServiceTest extends TestCase
{
    use LocatorAwareTrait;

    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.Ingredients',
    ];

    private IngredientService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new IngredientService();
    }

    protected function tearDown(): void
    {
        unset($this->service);
        parent::tearDown();
    }

    public function testCreateSucceedsWithValidData(): void
    {
        $result = $this->service->create([
            'name' => 'Pollo',
            'unit' => 'gr',
            'stock_quantity' => '500',
            'unit_cost' => '18',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('ingredient', $result);
        $this->assertSame('Pollo', $result['ingredient']->name);
    }

    public function testCreateFailsWithDuplicateName(): void
    {
        $result = $this->service->create([
            'name' => 'Carne molida',
            'unit' => 'gr',
            'stock_quantity' => '10',
            'unit_cost' => '5',
        ]);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('ingrediente', strtolower($result['errors'][0]));
    }

    public function testCreateNormalizesName(): void
    {
        $result = $this->service->create([
            'name' => '   Tomate   pera   ',
            'unit' => 'unidad',
            'stock_quantity' => '12',
            'unit_cost' => '3',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('Tomate pera', $result['ingredient']->name);
    }

    public function testUpdateChangesUnitAndStock(): void
    {
        /** @var \App\Model\Entity\Ingredient $ingredient */
        $ingredient = $this->fetchTable('Ingredients')->get(1);
        $result = $this->service->update($ingredient, [
            'unit' => 'kg',
            'stock_quantity' => '2.5',
        ]);

        $this->assertTrue($result['success']);
        $reloaded = $this->fetchTable('Ingredients')->get(1);
        $this->assertSame('kg', $reloaded->unit);
        $this->assertSame('2.500', $reloaded->stock_quantity);
    }

    public function testDeleteRemovesRow(): void
    {
        $table = $this->fetchTable('Ingredients');
        /** @var \App\Model\Entity\Ingredient $ingredient */
        $ingredient = $table->get(3);
        $result = $this->service->delete($ingredient);

        $this->assertTrue($result['success']);
        $this->assertFalse($table->exists(['id' => 3]));
    }

    public function testAdjustStockAddsPositiveDelta(): void
    {
        if (!function_exists('bcadd')) {
            $this->markTestSkipped('bcmath extension not available');
        }

        $table = $this->fetchTable('Ingredients');
        /** @var \App\Model\Entity\Ingredient $ingredient */
        $ingredient = $table->get(2); // Aceite, stock 3.500
        $result = $this->service->adjustStock($ingredient, '2.000', 'compra');

        $this->assertTrue($result['success']);
        $this->assertSame('5.500', $result['new_stock']);
    }

    public function testAdjustStockSubtractsNegativeDelta(): void
    {
        if (!function_exists('bcadd')) {
            $this->markTestSkipped('bcmath extension not available');
        }

        $table = $this->fetchTable('Ingredients');
        /** @var \App\Model\Entity\Ingredient $ingredient */
        $ingredient = $table->get(2); // Aceite, stock 3.500
        $result = $this->service->adjustStock($ingredient, '-1.500', 'merma');

        $this->assertTrue($result['success']);
        $this->assertSame('2.000', $result['new_stock']);
    }

    public function testAdjustStockRejectsResultBelowZero(): void
    {
        if (!function_exists('bcadd')) {
            $this->markTestSkipped('bcmath extension not available');
        }

        $table = $this->fetchTable('Ingredients');
        /** @var \App\Model\Entity\Ingredient $ingredient */
        $ingredient = $table->get(2); // Aceite, stock 3.500
        $result = $this->service->adjustStock($ingredient, '-10.000', 'venta');

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Stock insuficiente', $result['errors'][0]);

        // Stock did not change.
        $reloaded = $table->get(2);
        $this->assertSame('3.500', $reloaded->stock_quantity);
    }
}
