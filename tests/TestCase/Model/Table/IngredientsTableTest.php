<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\IngredientsTable;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * IngredientsTable test case.
 */
class IngredientsTableTest extends TestCase
{
    use LocatorAwareTrait;

    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.Ingredients',
    ];

    protected IngredientsTable $Ingredients;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var \App\Model\Table\IngredientsTable $table */
        $table = $this->fetchTable('Ingredients');
        $this->Ingredients = $table;
    }

    protected function tearDown(): void
    {
        unset($this->Ingredients);
        parent::tearDown();
    }

    public function testValidationRejectsEmptyName(): void
    {
        $entity = $this->Ingredients->newEntity([
            'name' => '',
            'unit' => 'gr',
            'stock_quantity' => '10',
            'unit_cost' => '5',
        ]);
        $this->assertNotEmpty($entity->getError('name'));
    }

    public function testValidationRejectsUnitOutsideAllowedList(): void
    {
        $entity = $this->Ingredients->newEntity([
            'name' => 'Test ingredient',
            'unit' => 'galon',
            'stock_quantity' => '10',
            'unit_cost' => '5',
        ]);
        $this->assertNotEmpty($entity->getError('unit'));
    }

    public function testValidationRejectsNegativeStock(): void
    {
        $entity = $this->Ingredients->newEntity([
            'name' => 'Test ingredient',
            'unit' => 'gr',
            'stock_quantity' => '-1',
            'unit_cost' => '5',
        ]);
        $this->assertNotEmpty($entity->getError('stock_quantity'));
    }

    public function testValidationRejectsNegativeUnitCost(): void
    {
        $entity = $this->Ingredients->newEntity([
            'name' => 'Test ingredient',
            'unit' => 'gr',
            'stock_quantity' => '10',
            'unit_cost' => '-1',
        ]);
        $this->assertNotEmpty($entity->getError('unit_cost'));
    }

    public function testIsUniqueRejectsDuplicateName(): void
    {
        $duplicate = $this->Ingredients->newEntity([
            'name' => 'Carne molida',
            'unit' => 'gr',
            'stock_quantity' => '10',
            'unit_cost' => '5',
        ]);
        $this->assertFalse((bool)$this->Ingredients->save($duplicate));
        $this->assertNotEmpty($duplicate->getError('name'));
    }

    public function testFindLowStockReturnsOnlyAtOrBelowThreshold(): void
    {
        $results = $this->Ingredients->find('lowStock')->toArray();
        $names = array_map(fn($r) => $r->name, $results);
        $this->assertContains('Aceite', $names);
        $this->assertContains('Sal', $names);
        $this->assertNotContains('Carne molida', $names);
    }

    public function testFindSearchAppliesLikeOnName(): void
    {
        $results = $this->Ingredients->find('search', ['q' => 'carne'])->toArray();
        $this->assertCount(1, $results);
        $this->assertSame('Carne molida', $results[0]->name);
    }

    public function testFindSearchWithEmptyQueryReturnsAll(): void
    {
        $results = $this->Ingredients->find('search', ['q' => ''])->toArray();
        $this->assertCount(3, $results);
    }

    public function testFindNameListFormatsNameWithUnit(): void
    {
        $list = $this->Ingredients->find('nameList')->toArray();
        $this->assertArrayHasKey(1, $list);
        $this->assertSame('Carne molida (gr)', $list[1]);
        $this->assertSame('Aceite (ml)', $list[2]);
    }
}
