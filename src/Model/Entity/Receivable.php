<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\ReceivableConstants;
use Cake\ORM\Entity;

/**
 * Receivable (Cuenta por Cobrar) — debt owed by a customer.
 *
 * Originates automatically from credit orders or manually for direct
 * debts. The `paid_amount` field is a denormalized cache maintained by
 * `ReceivableService::recomputeStatus()` once the Abonos module (6) is
 * wired; for now it only changes via `markAsPaid` / `updateAmountForOrder`.
 *
 * @property int $id
 * @property int $customer_id
 * @property int|null $order_id
 * @property string $total_amount
 * @property string $paid_amount
 * @property string $description
 * @property string $status
 * @property int|null $created_by
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property \App\Model\Entity\Customer|null $customer
 * @property \App\Model\Entity\Order|null $order
 * @property \App\Model\Entity\User|null $creator
 */
class Receivable extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'customer_id' => true,
        'order_id' => true,
        'total_amount' => true,
        'paid_amount' => true,
        'description' => true,
        'status' => true,
        'created_by' => true,
        'customer' => true,
        'order' => true,
        'creator' => true,
    ];

    /**
     * @var list<string>
     */
    protected array $_virtual = ['balance', 'progress_percent'];

    /**
     * Whether the receivable is fully paid.
     */
    public function isPaid(): bool
    {
        return $this->status === ReceivableConstants::STATUS_PAGADO;
    }

    /**
     * Whether the receivable still owes money.
     */
    public function isPending(): bool
    {
        return $this->status === ReceivableConstants::STATUS_PENDIENTE;
    }

    /**
     * True when any payment has been registered (or marked as paid).
     */
    public function hasPayments(): bool
    {
        return (float)$this->paid_amount > 0.0;
    }

    /**
     * Remaining balance to pay. Float — Decimal(12,2) fits without precision loss.
     */
    public function getBalance(): float
    {
        return round((float)$this->total_amount - (float)$this->paid_amount, 2);
    }

    /**
     * Progress 0-100 used for the UI bar.
     */
    public function getProgressPercent(): int
    {
        $total = (float)$this->total_amount;
        if ($total <= 0.0) {
            return 100;
        }
        $pct = (float)$this->paid_amount / $total * 100.0;

        return (int)min(100, max(0, round($pct)));
    }

    /**
     * Virtual property accessor for `balance`.
     */
    protected function _getBalance(): float
    {
        return $this->getBalance();
    }

    /**
     * Virtual property accessor for `progress_percent`.
     */
    protected function _getProgressPercent(): int
    {
        return $this->getProgressPercent();
    }
}
