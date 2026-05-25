<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\InventoryAdjustmentsTable;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * InventoryAdjustmentsTable test case.
 */
class InventoryAdjustmentsTableTest extends TestCase
{
    use LocatorAwareTrait;

    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.Roles',
        'app.Users',
        'app.Ingredients',
        'app.InventoryAdjustments',
    ];

    protected InventoryAdjustmentsTable $InventoryAdjustments;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var \App\Model\Table\InventoryAdjustmentsTable $table */
        $table = $this->fetchTable('InventoryAdjustments');
        $this->InventoryAdjustments = $table;
    }

    protected function tearDown(): void
    {
        unset($this->InventoryAdjustments);
        parent::tearDown();
    }

    public function testValidationRejectsTypeOutOfList(): void
    {
        $entity = $this->InventoryAdjustments->newEntity([
            'ingredient_id' => 1,
            'type' => 'inventado',
            'quantity' => '1.000',
            'reason' => 'Test',
        ]);
        $this->assertNotEmpty($entity->getError('type'));
    }

    public function testValidationRejectsZeroQuantity(): void
    {
        $entity = $this->InventoryAdjustments->newEntity([
            'ingredient_id' => 1,
            'type' => 'entrada',
            'quantity' => '0',
            'reason' => 'Test',
        ]);
        $this->assertNotEmpty($entity->getError('quantity'));
    }

    public function testValidationRejectsNegativeQuantity(): void
    {
        $entity = $this->InventoryAdjustments->newEntity([
            'ingredient_id' => 1,
            'type' => 'entrada',
            'quantity' => '-5.000',
            'reason' => 'Test',
        ]);
        $this->assertNotEmpty($entity->getError('quantity'));
    }

    public function testValidationRejectsEmptyReason(): void
    {
        $entity = $this->InventoryAdjustments->newEntity([
            'ingredient_id' => 1,
            'type' => 'entrada',
            'quantity' => '1.000',
            'reason' => '',
        ]);
        $this->assertNotEmpty($entity->getError('reason'));
    }

    public function testValidationRejectsTooLongReason(): void
    {
        $entity = $this->InventoryAdjustments->newEntity([
            'ingredient_id' => 1,
            'type' => 'entrada',
            'quantity' => '1.000',
            'reason' => str_repeat('x', 121),
        ]);
        $this->assertNotEmpty($entity->getError('reason'));
    }

    public function testRulesRejectsMissingIngredient(): void
    {
        $entity = $this->InventoryAdjustments->newEntity([
            'ingredient_id' => 999,
            'type' => 'entrada',
            'quantity' => '1.000',
            'reason' => 'Test',
            'user_id' => 1,
        ]);
        $this->assertFalse((bool)$this->InventoryAdjustments->save($entity));
        $this->assertNotEmpty($entity->getError('ingredient_id'));
    }

    public function testRulesAllowsNullUserId(): void
    {
        $entity = $this->InventoryAdjustments->newEntity([
            'ingredient_id' => 1,
            'type' => 'entrada',
            'quantity' => '1.000',
            'reason' => 'Test',
            'user_id' => null,
        ]);
        $this->assertNotFalse($this->InventoryAdjustments->save($entity));
    }

    public function testFindChronologicalOrdersByCreatedDescIdDesc(): void
    {
        $results = $this->InventoryAdjustments->find('chronological')->toArray();
        $this->assertCount(2, $results);
        // Fixture: id=2 is 2026-05-21, id=1 is 2026-05-20.
        $this->assertSame(2, $results[0]->id);
        $this->assertSame(1, $results[1]->id);
    }

    public function testFindByIngredientFiltersCorrectly(): void
    {
        $results = $this->InventoryAdjustments->find('byIngredient', ingredient_id: 1)->toArray();
        $this->assertCount(2, $results);

        $empty = $this->InventoryAdjustments->find('byIngredient', ingredient_id: 999)->toArray();
        $this->assertCount(0, $empty);
    }

    public function testFindByTypeFiltersEntrada(): void
    {
        $results = $this->InventoryAdjustments->find('byType', type: 'entrada')->toArray();
        $this->assertCount(1, $results);
        $this->assertSame('entrada', $results[0]->type);
    }

    public function testFindByTypeReturnsAllWhenTypeAll(): void
    {
        $results = $this->InventoryAdjustments->find('byType', type: 'all')->toArray();
        $this->assertCount(2, $results);
    }

    public function testFindInDateRangeAppliesInclusiveBounds(): void
    {
        // Both fixture rows fall on 2026-05-20 and 2026-05-21.
        $results = $this->InventoryAdjustments
            ->find('inDateRange', from: '2026-05-21', to: '2026-05-21')
            ->toArray();
        $this->assertCount(1, $results);
        $this->assertSame('baja', $results[0]->type);
    }

    public function testTimestampBehaviorSetsCreatedOnNew(): void
    {
        $entity = $this->InventoryAdjustments->newEntity([
            'ingredient_id' => 1,
            'type' => 'entrada',
            'quantity' => '2.000',
            'reason' => 'Compra',
            'user_id' => 1,
        ]);
        $saved = $this->InventoryAdjustments->save($entity);
        $this->assertNotFalse($saved);
        $this->assertNotNull($entity->created);
    }

    public function testNoModifiedColumnWriteOnSecondSave(): void
    {
        // Saving twice must not blow up trying to write a non-existent `modified` column.
        $entity = $this->InventoryAdjustments->newEntity([
            'ingredient_id' => 1,
            'type' => 'entrada',
            'quantity' => '1.000',
            'reason' => 'Compra inicial',
            'user_id' => 1,
        ]);
        $this->assertNotFalse($this->InventoryAdjustments->save($entity));

        // Touch a field and save again — must succeed without column errors.
        $entity->reason = 'Compra corregida';
        $this->assertNotFalse($this->InventoryAdjustments->save($entity));
    }
}
