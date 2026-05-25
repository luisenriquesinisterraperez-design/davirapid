<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Model\Entity\Expense;
use Cake\I18n\Date;
use Cake\TestSuite\TestCase;

class ExpenseTest extends TestCase
{
    public function testGetFormattedAmount(): void
    {
        $e = new Expense(['amount' => '15000.00']);
        $this->assertSame('$15.000,00', $e->getFormattedAmount());
    }

    public function testGetFormattedAmountWithDecimals(): void
    {
        $e = new Expense(['amount' => '1234.56']);
        $this->assertSame('$1.234,56', $e->getFormattedAmount());
    }

    public function testGetFormattedAmountZero(): void
    {
        $e = new Expense(['amount' => '0.00']);
        $this->assertSame('$0,00', $e->getFormattedAmount());
    }

    public function testGetFormattedDateReturnsDash(): void
    {
        $e = new Expense();
        $this->assertSame('—', $e->getFormattedDate());
    }

    public function testGetFormattedDateValidDate(): void
    {
        $e = new Expense(['expense_date' => new Date('2026-05-24')]);
        $this->assertSame('24/05/2026', $e->getFormattedDate());
    }

    public function testIsFutureWhenNoDate(): void
    {
        $e = new Expense();
        $this->assertFalse($e->isFuture());
    }

    public function testIsFutureWhenPast(): void
    {
        $e = new Expense(['expense_date' => new Date('2020-01-01')]);
        $this->assertFalse($e->isFuture());
    }

    public function testIsFutureWhenFuture(): void
    {
        $e = new Expense(['expense_date' => (new Date())->modify('+5 days')]);
        $this->assertTrue($e->isFuture());
    }

    public function testIsFutureWhenToday(): void
    {
        $e = new Expense(['expense_date' => new Date()]);
        $this->assertFalse($e->isFuture());
    }
}
