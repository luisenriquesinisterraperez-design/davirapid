<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Constants\ReceivableConstants;
use App\Model\Entity\Receivable;
use Cake\TestSuite\TestCase;

/**
 * Receivable entity test case.
 */
class ReceivableTest extends TestCase
{
    public function testIsPaidAndIsPending(): void
    {
        $rec = new Receivable(['status' => ReceivableConstants::STATUS_PAGADO]);
        $this->assertTrue($rec->isPaid());
        $this->assertFalse($rec->isPending());

        $rec = new Receivable(['status' => ReceivableConstants::STATUS_PENDIENTE]);
        $this->assertFalse($rec->isPaid());
        $this->assertTrue($rec->isPending());
    }

    public function testGetBalanceReturnsDifference(): void
    {
        $rec = new Receivable([
            'total_amount' => '100000.00',
            'paid_amount' => '30000.00',
        ]);
        $this->assertSame(70000.0, $rec->getBalance());
    }

    public function testGetBalanceZeroWhenPaid(): void
    {
        $rec = new Receivable([
            'total_amount' => '50000.00',
            'paid_amount' => '50000.00',
        ]);
        $this->assertSame(0.0, $rec->getBalance());
    }

    public function testGetProgressPercentForPartialPayment(): void
    {
        $rec = new Receivable([
            'total_amount' => '100.00',
            'paid_amount' => '40.00',
        ]);
        $this->assertSame(40, $rec->getProgressPercent());
    }

    public function testGetProgressPercentReturns100WhenPaid(): void
    {
        $rec = new Receivable([
            'total_amount' => '100.00',
            'paid_amount' => '100.00',
        ]);
        $this->assertSame(100, $rec->getProgressPercent());
    }

    public function testGetProgressPercentReturns0ForZeroPaid(): void
    {
        $rec = new Receivable([
            'total_amount' => '100.00',
            'paid_amount' => '0.00',
        ]);
        $this->assertSame(0, $rec->getProgressPercent());
    }

    public function testGetProgressPercentCapsAt100(): void
    {
        // Defensive: even with garbage data, the bar never overflows.
        $rec = new Receivable([
            'total_amount' => '100.00',
            'paid_amount' => '150.00',
        ]);
        $this->assertSame(100, $rec->getProgressPercent());
    }

    public function testHasPaymentsBoolean(): void
    {
        $rec = new Receivable(['paid_amount' => '0.00']);
        $this->assertFalse($rec->hasPayments());

        $rec = new Receivable(['paid_amount' => '0.01']);
        $this->assertTrue($rec->hasPayments());
    }

    public function testVirtualBalanceAccessor(): void
    {
        $rec = new Receivable([
            'total_amount' => '100.00',
            'paid_amount' => '25.00',
        ]);
        $this->assertSame(75.0, $rec->balance);
    }

    public function testVirtualProgressAccessor(): void
    {
        $rec = new Receivable([
            'total_amount' => '200.00',
            'paid_amount' => '50.00',
        ]);
        $this->assertSame(25, $rec->progress_percent);
    }
}
