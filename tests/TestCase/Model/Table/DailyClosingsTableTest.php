<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\DailyClosingsTable;
use Cake\TestSuite\TestCase;

class DailyClosingsTableTest extends TestCase
{
    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.Roles',
        'app.Users',
        'app.DailyClosings',
    ];

    private DailyClosingsTable $DailyClosings;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var \App\Model\Table\DailyClosingsTable $table */
        $table = $this->getTableLocator()->get('DailyClosings');
        $this->DailyClosings = $table;
    }

    public function testValidationRequiresDate(): void
    {
        $c = $this->DailyClosings->newEntity([
            'initial_balance' => '0.00',
            'actual_amount' => '100.00',
        ]);
        $this->assertArrayHasKey('closing_date', $c->getErrors());
    }

    public function testValidationRejectsNegativeActual(): void
    {
        $c = $this->DailyClosings->newEntity([
            'closing_date' => '2026-05-25',
            'initial_balance' => '0.00',
            'actual_amount' => '-10.00',
        ]);
        $this->assertArrayHasKey('actual_amount', $c->getErrors());
    }

    public function testUniqueClosingDate(): void
    {
        $c = $this->DailyClosings->newEntity([
            'closing_date' => '2026-05-20', // Already in fixture.
            'initial_balance' => '0.00',
            'actual_amount' => '100.00',
        ]);
        $result = $this->DailyClosings->save($c);
        $this->assertFalse((bool)$result);
    }

    public function testFindInDateRange(): void
    {
        $rows = $this->DailyClosings->find('inDateRange', from: '2026-05-21', to: '2026-05-22')
            ->all()
            ->toArray();
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]->id);
    }
}
