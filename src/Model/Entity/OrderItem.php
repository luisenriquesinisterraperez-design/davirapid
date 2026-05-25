<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\OrderConstants;
use Cake\ORM\Entity;

/**
 * @property int $id
 * @property int $order_id
 * @property int|null $product_id
 * @property string $product_name
 * @property string $quantity
 * @property string $price_at_sale
 * @property string $line_subtotal
 * @property string|null $notes
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property \App\Model\Entity\Order|null $order
 * @property \App\Model\Entity\Product|null $product
 */
class OrderItem extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'order_id' => true,
        'product_id' => true,
        'product_name' => true,
        'quantity' => true,
        'price_at_sale' => true,
        'line_subtotal' => true,
        'notes' => true,
        'product' => true,
    ];

    /**
     * @var list<string>
     */
    protected array $_virtual = ['computed_subtotal'];

    /**
     * Line subtotal recomputed from quantity × price (anti-tampering defense).
     */
    public function getLineSubtotal(): float
    {
        return round(
            ((float)$this->quantity) * ((float)$this->price_at_sale),
            OrderConstants::MONEY_DECIMALS,
        );
    }

    /**
     * Returns "2", "2.5", "0.25" — trailing zeros stripped for clean display.
     */
    public function getFormattedQuantity(): string
    {
        return rtrim(rtrim(number_format(
            (float)$this->quantity,
            OrderConstants::QUANTITY_DECIMALS,
            '.',
            '',
        ), '0'), '.');
    }

    /**
     * Virtual property accessor for `computed_subtotal`.
     */
    protected function _getComputedSubtotal(): float
    {
        return $this->getLineSubtotal();
    }
}
