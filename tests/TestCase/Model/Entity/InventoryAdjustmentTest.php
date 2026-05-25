<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Model\Entity\Ingredient;
use App\Model\Entity\InventoryAdjustment;
use Cake\TestSuite\TestCase;

/**
 * InventoryAdjustment entity test case.
 */
class InventoryAdjustmentTest extends TestCase
{
    public function testIsEntryTrueWhenTypeEntrada(): void
    {
        $adj = new InventoryAdjustment(['type' => 'entrada', 'quantity' => '1.000']);
        $this->assertTrue($adj->isEntry());
        $this->assertFalse($adj->isBaja());
    }

    public function testIsBajaTrueWhenTypeBaja(): void
    {
        $adj = new InventoryAdjustment(['type' => 'baja', 'quantity' => '1.000']);
        $this->assertTrue($adj->isBaja());
        $this->assertFalse($adj->isEntry());
    }

    public function testGetSignedDeltaForEntry(): void
    {
        $adj = new InventoryAdjustment(['type' => 'entrada', 'quantity' => '2.500']);
        $this->assertSame('+2.500', $adj->getSignedDelta());
    }

    public function testGetSignedDeltaForBaja(): void
    {
        $adj = new InventoryAdjustment(['type' => 'baja', 'quantity' => '0.250']);
        $this->assertSame('-0.250', $adj->getSignedDelta());
    }

    public function testGetReverseDeltaInvertsSign(): void
    {
        $entry = new InventoryAdjustment(['type' => 'entrada', 'quantity' => '1.250']);
        $this->assertSame('-1.250', $entry->getReverseDelta());

        $baja = new InventoryAdjustment(['type' => 'baja', 'quantity' => '0.750']);
        $this->assertSame('+0.750', $baja->getReverseDelta());
    }

    public function testGetFormattedQuantityWithIngredient(): void
    {
        $adj = new InventoryAdjustment(['type' => 'entrada', 'quantity' => '1.250']);
        $adj->ingredient = new Ingredient(['unit' => 'gr']);

        $this->assertSame('+1.250 gr', $adj->getFormattedQuantity());
    }

    public function testGetFormattedQuantityWithoutIngredient(): void
    {
        $adj = new InventoryAdjustment(['type' => 'baja', 'quantity' => '0.500']);
        $this->assertSame('-0.500', $adj->getFormattedQuantity());
    }

    public function testVirtualSignedDeltaAccessible(): void
    {
        $adj = new InventoryAdjustment(['type' => 'entrada', 'quantity' => '3.000']);
        $this->assertSame('+3.000', $adj->signed_delta);
    }

    public function testVirtualFormattedQuantityAccessible(): void
    {
        $adj = new InventoryAdjustment(['type' => 'baja', 'quantity' => '2.000']);
        $adj->ingredient = new Ingredient(['unit' => 'kg']);
        $this->assertSame('-2.000 kg', $adj->formatted_quantity);
    }
}
