<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Model\Entity\InventoryAdjustment;
use App\Service\IngredientService;
use App\Service\InventoryAdjustmentService;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * InventoryAdjustmentService test case.
 */
class InventoryAdjustmentServiceTest extends TestCase
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

    private InventoryAdjustmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InventoryAdjustmentService();
    }

    protected function tearDown(): void
    {
        unset($this->service);
        parent::tearDown();
    }

    public function testCreateSuccessPersistsRowAndMovesStock(): void
    {
        $ingredients = $this->fetchTable('Ingredients');
        $adjustments = $this->fetchTable('InventoryAdjustments');

        $beforeCount = $adjustments->find()->count();
        $beforeStock = (string)$ingredients->get(1)->stock_quantity; // 1500.000

        $result = $this->service->create([
            'ingredient_id' => 1,
            'type' => 'entrada',
            'quantity' => '500',
            'reason' => 'Compra a proveedor',
        ], 1);

        $this->assertTrue($result['success']);
        $this->assertSame($beforeCount + 1, $adjustments->find()->count());

        $afterStock = (string)$ingredients->get(1)->stock_quantity;
        $this->assertSame('2000.000', $afterStock);
        $this->assertNotSame($beforeStock, $afterStock);
    }

    public function testCreateWithBajaMovesStockDown(): void
    {
        $result = $this->service->create([
            'ingredient_id' => 1,
            'type' => 'baja',
            'quantity' => '200',
            'reason' => 'Merma',
        ], 1);

        $this->assertTrue($result['success']);
        $this->assertSame(
            '1300.000',
            (string)$this->fetchTable('Ingredients')->get(1)->stock_quantity,
        );
    }

    public function testCreateRejectsMissingIngredient(): void
    {
        $result = $this->service->create([
            'ingredient_id' => 0,
            'type' => 'entrada',
            'quantity' => '1.000',
            'reason' => 'Test',
        ], 1);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testCreateRejectsInvalidType(): void
    {
        $result = $this->service->create([
            'ingredient_id' => 1,
            'type' => 'inventado',
            'quantity' => '1.000',
            'reason' => 'Test',
        ], 1);

        $this->assertFalse($result['success']);
    }

    public function testCreateRejectsZeroQuantity(): void
    {
        $result = $this->service->create([
            'ingredient_id' => 1,
            'type' => 'entrada',
            'quantity' => '0',
            'reason' => 'Test',
        ], 1);

        $this->assertFalse($result['success']);
    }

    public function testCreateRejectsNegativeQuantity(): void
    {
        $result = $this->service->create([
            'ingredient_id' => 1,
            'type' => 'entrada',
            'quantity' => '-1',
            'reason' => 'Test',
        ], 1);

        $this->assertFalse($result['success']);
    }

    public function testCreateRejectsEmptyReason(): void
    {
        $result = $this->service->create([
            'ingredient_id' => 1,
            'type' => 'entrada',
            'quantity' => '1.000',
            'reason' => '',
        ], 1);

        $this->assertFalse($result['success']);
    }

    public function testCreateRejectsWhitespaceOnlyReason(): void
    {
        $result = $this->service->create([
            'ingredient_id' => 1,
            'type' => 'entrada',
            'quantity' => '1.000',
            'reason' => '   ',
        ], 1);

        $this->assertFalse($result['success']);
    }

    public function testCreateBajaInsufficientStockRollsBack(): void
    {
        $ingredients = $this->fetchTable('Ingredients');
        $adjustments = $this->fetchTable('InventoryAdjustments');

        // Aceite (id=2) has stock 3.500 — request baja of 100 → should fail.
        $beforeCount = $adjustments->find()->count();
        $beforeStock = (string)$ingredients->get(2)->stock_quantity;

        $result = $this->service->create([
            'ingredient_id' => 2,
            'type' => 'baja',
            'quantity' => '100',
            'reason' => 'Merma exagerada',
        ], 1);

        $this->assertFalse($result['success']);
        $this->assertSame($beforeCount, $adjustments->find()->count());
        $this->assertSame($beforeStock, (string)$ingredients->get(2)->stock_quantity);
    }

    public function testCreateWithMockedAdjustStockFailureRollsBack(): void
    {
        $adjustments = $this->fetchTable('InventoryAdjustments');
        $beforeCount = $adjustments->find()->count();

        $mock = $this->createMock(IngredientService::class);
        $mock->method('adjustStock')->willReturn([
            'success' => false,
            'errors' => ['boom'],
        ]);

        $service = new InventoryAdjustmentService($mock);
        $result = $service->create([
            'ingredient_id' => 1,
            'type' => 'entrada',
            'quantity' => '10',
            'reason' => 'Test',
        ], 1);

        $this->assertFalse($result['success']);
        // adjustments row must NOT persist after the rolled-back transaction.
        $this->assertSame($beforeCount, $adjustments->find()->count());
    }

    public function testDeleteRevertsStockAndDeletesRow(): void
    {
        // Apply the fixture entrada first so stock reflects it: 1500 + 500 = 2000.
        $ingredients = $this->fetchTable('Ingredients');
        $adjustments = $this->fetchTable('InventoryAdjustments');

        $ing = $ingredients->get(1);
        $ing->stock_quantity = '2000.000';
        $ingredients->save($ing);

        /** @var \App\Model\Entity\InventoryAdjustment $adj */
        $adj = $adjustments->get(1, contain: ['Ingredients']); // entrada 500
        $result = $this->service->delete($adj);

        $this->assertTrue($result['success']);
        $this->assertFalse($adjustments->exists(['id' => 1]));
        $this->assertSame('1500.000', (string)$ingredients->get(1)->stock_quantity);
    }

    public function testDeleteWithReverseGoingNegativeFails(): void
    {
        // Fixture entrada qty=500 on ingredient 1, but stock is currently 1500 with
        // nothing consumed. Drop stock to 200 to simulate the entrada already used up.
        $ingredients = $this->fetchTable('Ingredients');
        $adjustments = $this->fetchTable('InventoryAdjustments');
        $ing = $ingredients->get(1);
        $ing->stock_quantity = '200.000';
        $ingredients->save($ing);

        /** @var \App\Model\Entity\InventoryAdjustment $adj */
        $adj = $adjustments->get(1, contain: ['Ingredients']); // entrada 500 → reverse -500
        $result = $this->service->delete($adj);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('No se puede eliminar', $result['errors'][0]);
        $this->assertTrue($adjustments->exists(['id' => 1]));
        $this->assertSame('200.000', (string)$ingredients->get(1)->stock_quantity);
    }

    public function testDeleteIngredientMissingReturnsError(): void
    {
        // Build an in-memory adjustment pointing at a non-existent ingredient.
        $adj = new InventoryAdjustment([
            'id' => 999,
            'ingredient_id' => 999,
            'type' => 'entrada',
            'quantity' => '1.000',
            'reason' => 'orphan',
        ]);

        $result = $this->service->delete($adj);
        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testNoUpdateMethodExists(): void
    {
        $this->assertFalse(method_exists($this->service, 'update'));
    }
}
