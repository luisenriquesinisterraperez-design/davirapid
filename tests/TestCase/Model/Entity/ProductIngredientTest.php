<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Model\Entity\Ingredient;
use App\Model\Entity\ProductIngredient;
use Cake\TestSuite\TestCase;

/**
 * ProductIngredient entity test case (pure unit; no DB).
 */
class ProductIngredientTest extends TestCase
{
    public function testGetLineCostReturnsZeroWhenIngredientNotHydrated(): void
    {
        $line = new ProductIngredient(['quantity' => '200.000']);
        $this->assertSame(0.0, $line->getLineCost());
    }

    public function testGetLineCostReturnsQuantityTimesUnitCost(): void
    {
        $line = new ProductIngredient([
            'quantity' => '200.000',
            'ingredient' => new Ingredient([
                'unit' => 'gr',
                'unit_cost' => '0.50',
            ]),
        ]);
        $this->assertSame(100.0, $line->getLineCost());
    }

    public function testGetLineCostRoundsToCostDecimals(): void
    {
        $line = new ProductIngredient([
            'quantity' => '0.333',
            'ingredient' => new Ingredient([
                'unit' => 'kg',
                'unit_cost' => '3.00',
            ]),
        ]);
        // 0.333 * 3 = 0.999, rounded to 2 decimals = 1.00.
        $this->assertSame(1.0, $line->getLineCost());
    }

    public function testGetFormattedQuantityWithExactInteger(): void
    {
        $line = new ProductIngredient([
            'quantity' => '200.000',
            'ingredient' => new Ingredient(['unit' => 'gr', 'unit_cost' => '0.50']),
        ]);
        $this->assertSame('200 gr', $line->getFormattedQuantity());
    }

    public function testGetFormattedQuantityWithDecimals(): void
    {
        $line = new ProductIngredient([
            'quantity' => '1.500',
            'ingredient' => new Ingredient(['unit' => 'kg', 'unit_cost' => '10.00']),
        ]);
        $this->assertSame('1,5 kg', $line->getFormattedQuantity());
    }

    public function testGetFormattedQuantityWithoutIngredient(): void
    {
        $line = new ProductIngredient(['quantity' => '2.000']);
        $this->assertSame('2', $line->getFormattedQuantity());
    }

    public function testGetFormattedLineCostFormat(): void
    {
        $line = new ProductIngredient([
            'quantity' => '200.000',
            'ingredient' => new Ingredient(['unit' => 'gr', 'unit_cost' => '0.50']),
        ]);
        $this->assertSame('$100', $line->getFormattedLineCost());
    }

    public function testLineCostVirtualPropertyAccessible(): void
    {
        $line = new ProductIngredient([
            'quantity' => '10.000',
            'ingredient' => new Ingredient(['unit' => 'unidad', 'unit_cost' => '5.00']),
        ]);
        $this->assertSame(50.0, $line->line_cost);
    }
}
