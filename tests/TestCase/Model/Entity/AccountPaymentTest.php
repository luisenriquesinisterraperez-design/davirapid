<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Model\Entity\AccountPayment;
use Cake\TestSuite\TestCase;

/**
 * AccountPayment entity test case.
 */
class AccountPaymentTest extends TestCase
{
    public function testGetFormattedAmountSimpleValue(): void
    {
        $p = new AccountPayment(['amount' => '1500.00']);
        $this->assertSame('$1.500,00', $p->getFormattedAmount());
    }

    public function testGetFormattedAmountWithDecimals(): void
    {
        $p = new AccountPayment(['amount' => '1234567.89']);
        $this->assertSame('$1.234.567,89', $p->getFormattedAmount());
    }

    public function testGetFormattedAmountZero(): void
    {
        $p = new AccountPayment(['amount' => '0.00']);
        $this->assertSame('$0,00', $p->getFormattedAmount());
    }

    public function testGetMethodLabelKnownMethod(): void
    {
        $p = new AccountPayment(['payment_method' => 'efectivo']);
        $this->assertSame('Efectivo', $p->getMethodLabel());

        $p2 = new AccountPayment(['payment_method' => 'nequi']);
        $this->assertSame('Nequi', $p2->getMethodLabel());

        $p3 = new AccountPayment(['payment_method' => 'daviplata']);
        $this->assertSame('Daviplata', $p3->getMethodLabel());

        $p4 = new AccountPayment(['payment_method' => 'transferencia']);
        $this->assertSame('Transferencia', $p4->getMethodLabel());
    }

    public function testGetMethodLabelUnknownMethodFallsBackToCapitalized(): void
    {
        $p = new AccountPayment(['payment_method' => 'bitcoin']);
        $this->assertSame('Bitcoin', $p->getMethodLabel());
    }

    public function testAccessibleFields(): void
    {
        $p = new AccountPayment([
            'receivable_id' => 5,
            'amount' => '100.00',
            'payment_method' => 'efectivo',
            'notes' => 'hello',
            'created_by' => 1,
            'id' => 99, // should NOT be assigned (not in $_accessible)
        ]);

        $this->assertSame(5, $p->receivable_id);
        $this->assertSame('100.00', $p->amount);
        $this->assertSame('efectivo', $p->payment_method);
        $this->assertSame('hello', $p->notes);
        $this->assertSame(1, $p->created_by);
        $this->assertNull($p->id);
    }
}
