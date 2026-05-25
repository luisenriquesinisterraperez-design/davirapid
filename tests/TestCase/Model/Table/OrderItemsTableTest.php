<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * OrderItemsTable test case.
 */
class OrderItemsTableTest extends TestCase
{
    use LocatorAwareTrait;

    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.Roles',
        'app.Users',
        'app.Customers',
        'app.Deliveries',
        'app.Products',
        'app.Ingredients',
        'app.ProductIngredients',
        'app.Orders',
        'app.OrderItems',
    ];

    public function testValidationRejectsZeroQuantity(): void
    {
        $items = $this->fetchTable('OrderItems');
        $entity = $items->newEntity([
            'order_id' => 1,
            'product_name' => 'Test',
            'quantity' => 0,
            'price_at_sale' => 100,
            'line_subtotal' => 0,
        ]);
        $this->assertArrayHasKey('quantity', $entity->getErrors());
    }

    public function testValidationRejectsNegativePrice(): void
    {
        $items = $this->fetchTable('OrderItems');
        $entity = $items->newEntity([
            'order_id' => 1,
            'product_name' => 'Test',
            'quantity' => 1,
            'price_at_sale' => -1,
            'line_subtotal' => -1,
        ]);
        $this->assertArrayHasKey('price_at_sale', $entity->getErrors());
    }

    public function testValidationRejectsTooLongNotes(): void
    {
        $items = $this->fetchTable('OrderItems');
        $entity = $items->newEntity([
            'order_id' => 1,
            'product_name' => 'Test',
            'quantity' => 1,
            'price_at_sale' => 100,
            'line_subtotal' => 100,
            'notes' => str_repeat('a', 300),
        ]);
        $this->assertArrayHasKey('notes', $entity->getErrors());
    }

    public function testFindTopProductsExcludesCancelledOrders(): void
    {
        $items = $this->fetchTable('OrderItems');
        // Cancelled order #3 has one empanada line — should not appear in totals.
        $rows = $items->find('topProducts', limit: 10)->all()->toArray();
        // Empanada appears in cancelled (order 3, qty=1) AND active (order 2, qty=2).
        // Without cancelled, units should be 2.
        $empanada = null;
        foreach ($rows as $r) {
            $name = $r->product_name ?? null;
            if ($name === 'Empanada') {
                $empanada = $r;
                break;
            }
        }
        $this->assertNotNull($empanada);
        $this->assertSame('2.000', (string)$empanada->units);
    }
}
