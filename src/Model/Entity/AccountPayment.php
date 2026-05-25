<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\AccountPaymentConstants;
use Cake\ORM\Entity;

/**
 * AccountPayment (Abono) — append-only event recording a partial or full
 * payment against a Receivable. Once persisted it is immutable; mistakes
 * are corrected by deleting and re-creating.
 *
 * @property int $id
 * @property int $receivable_id
 * @property string $amount
 * @property string $payment_method
 * @property string|null $notes
 * @property int|null $created_by
 * @property \Cake\I18n\DateTime|null $created
 * @property \App\Model\Entity\Receivable|null $receivable
 * @property \App\Model\Entity\User|null $creator
 */
class AccountPayment extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'receivable_id' => true,
        'amount' => true,
        'payment_method' => true,
        'notes' => true,
        'created_by' => true,
        'receivable' => true,
        'creator' => true,
    ];

    /**
     * Money formatted with thousands separator and 2 decimals (e.g. "$1.500,00").
     */
    public function getFormattedAmount(): string
    {
        return '$' . number_format((float)$this->amount, 2, ',', '.');
    }

    /**
     * Human-readable label for the payment method. Falls back to the raw
     * value capitalized when the method is unknown (forward-compatibility).
     */
    public function getMethodLabel(): string
    {
        $method = (string)$this->payment_method;

        return AccountPaymentConstants::PAYMENT_LABELS[$method] ?? ucfirst($method);
    }
}
