<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Model\Entity\OrderItem;
use Cake\TestSuite\TestCase;

/**
 * OrderItem entity test case.
 */
class OrderItemTest extends TestCase
{
    public function testGetLineSubtotalRoundsCorrectly(): void
    {
        $item = new OrderItem(['quantity' => '2.500', 'price_at_sale' => '10000.00']);
        $this->assertSame(25000.00, $item->getLineSubtotal());
    }

    public function testGetLineSubtotalIntegerQuantity(): void
    {
        $item = new OrderItem(['quantity' => '2.000', 'price_at_sale' => '15000.00']);
        $this->assertSame(30000.00, $item->getLineSubtotal());
    }

    public function testGetFormattedQuantityStripsTrailingZeros(): void
    {
        $this->assertSame('2', (new OrderItem(['quantity' => '2.000']))->getFormattedQuantity());
        $this->assertSame('2.5', (new OrderItem(['quantity' => '2.500']))->getFormattedQuantity());
        $this->assertSame('0.25', (new OrderItem(['quantity' => '0.250']))->getFormattedQuantity());
    }

    public function testVirtualComputedSubtotalAccessible(): void
    {
        $item = new OrderItem(['quantity' => '1.000', 'price_at_sale' => '12000.00']);
        $this->assertSame(12000.00, $item->computed_subtotal);
    }
}
