<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\IngredientConstants;
use App\Constants\InventoryAdjustmentConstants;
use Cake\ORM\Entity;

/**
 * InventoryAdjustment — append-only stock movement event.
 *
 * @property int $id
 * @property int $ingredient_id
 * @property string $type
 * @property string $quantity
 * @property string $reason
 * @property string|null $notes
 * @property int|null $user_id
 * @property \Cake\I18n\DateTime|null $created
 * @property \App\Model\Entity\Ingredient|null $ingredient
 * @property \App\Model\Entity\User|null $user
 */
class InventoryAdjustment extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'ingredient_id' => true,
        'type' => true,
        'quantity' => true,
        'reason' => true,
        'notes' => true,
        'user_id' => true,
        'ingredient' => true,
        'user' => true,
    ];

    /**
     * @var list<string>
     */
    protected array $_virtual = ['signed_delta', 'formatted_quantity'];

    /**
     * Whether the adjustment is a stock entry (positive movement).
     */
    public function isEntry(): bool
    {
        return $this->type === InventoryAdjustmentConstants::TYPE_ENTRY;
    }

    /**
     * Whether the adjustment is a stock removal (negative movement).
     */
    public function isBaja(): bool
    {
        return $this->type === InventoryAdjustmentConstants::TYPE_BAJA;
    }

    /**
     * Returns the quantity with its applied sign (e.g. "+2.500" or "-0.250").
     * Consumed by `IngredientService::adjustStock()` as the signed delta.
     */
    public function getSignedDelta(): string
    {
        $sign = $this->isEntry() ? '+' : '-';

        return $sign . number_format(
            (float)$this->quantity,
            IngredientConstants::STOCK_DECIMALS,
            '.',
            '',
        );
    }

    /**
     * Returns the quantity with the opposite sign — used to revert the adjustment on delete.
     */
    public function getReverseDelta(): string
    {
        $sign = $this->isEntry() ? '-' : '+';

        return $sign . number_format(
            (float)$this->quantity,
            IngredientConstants::STOCK_DECIMALS,
            '.',
            '',
        );
    }

    /**
     * Returns the signed quantity with the ingredient unit appended (e.g. "+1.250 gr").
     * Falls back to bare signed delta when ingredient is not hydrated.
     */
    public function getFormattedQuantity(): string
    {
        $unit = (string)($this->ingredient?->unit ?? '');

        return trim($this->getSignedDelta() . ' ' . $unit);
    }

    /**
     * Virtual property accessor for `signed_delta`.
     */
    protected function _getSignedDelta(): string
    {
        return $this->getSignedDelta();
    }

    /**
     * Virtual property accessor for `formatted_quantity`.
     */
    protected function _getFormattedQuantity(): string
    {
        return $this->getFormattedQuantity();
    }
}
