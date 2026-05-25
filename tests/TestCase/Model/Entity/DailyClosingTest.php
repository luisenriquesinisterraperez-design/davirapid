<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Model\Entity\DailyClosing;
use Cake\I18n\Date;
use Cake\TestSuite\TestCase;

class DailyClosingTest extends TestCase
{
    public function testGetFormattedDate(): void
    {
        $c = new DailyClosing(['closing_date' => new Date('2026-05-25')]);
        $this->assertSame('25/05/2026', $c->getFormattedDate());
    }

    public function testGetFormattedDateNull(): void
    {
        $c = new DailyClosing();
        $this->assertSame('—', $c->getFormattedDate());
    }

    public function testGetFormattedDifferenceZero(): void
    {
        $c = new DailyClosing(['difference' => '0.00']);
        $this->assertSame('$0,00', $c->getFormattedDifference());
    }

    public function testGetFormattedDifferencePositive(): void
    {
        $c = new DailyClosing(['difference' => '1500.00']);
        $this->assertSame('+$1.500,00', $c->getFormattedDifference());
    }

    public function testGetFormattedDifferenceNegative(): void
    {
        $c = new DailyClosing(['difference' => '-2500.50']);
        $this->assertSame('-$2.500,50', $c->getFormattedDifference());
    }

    public function testIsBalancedExact(): void
    {
        $c = new DailyClosing(['difference' => '0.00']);
        $this->assertTrue($c->isBalanced());
        $this->assertFalse($c->isSurplus());
        $this->assertFalse($c->isShortage());
    }

    public function testIsBalancedWithinEpsilon(): void
    {
        $c = new DailyClosing(['difference' => '0.004']);
        $this->assertTrue($c->isBalanced());
    }

    public function testIsSurplus(): void
    {
        $c = new DailyClosing(['difference' => '500.00']);
        $this->assertTrue($c->isSurplus());
        $this->assertFalse($c->isBalanced());
        $this->assertFalse($c->isShortage());
    }

    public function testIsShortage(): void
    {
        $c = new DailyClosing(['difference' => '-100.00']);
        $this->assertTrue($c->isShortage());
        $this->assertFalse($c->isBalanced());
        $this->assertFalse($c->isSurplus());
    }
}
