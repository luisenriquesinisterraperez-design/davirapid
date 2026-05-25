<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\IngredientConstants;
use Cake\ORM\Entity;

class Ingredient extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'name' => true,
        'unit' => true,
        'stock_quantity' => true,
        'unit_cost' => true,
    ];

    /**
     * @var list<string>
     */
    protected array $_virtual = ['is_low_stock'];

    /**
     * Whether stock is at or below the low-stock threshold.
     */
    public function isLowStock(): bool
    {
        return (float)$this->stock_quantity <= (float)IngredientConstants::LOW_STOCK_THRESHOLD;
    }

    /**
     * Whether the ingredient currently has no stock available.
     */
    public function isOutOfStock(): bool
    {
        return (float)$this->stock_quantity <= 0.0;
    }

    /**
     * Virtual property accessor for `is_low_stock`.
     */
    protected function _getIsLowStock(): bool
    {
        return $this->isLowStock();
    }

    /**
     * Returns a localised stock string (e.g. "1.250 gr", "3 kg").
     */
    public function getFormattedStock(): string
    {
        $value = (float)$this->stock_quantity;
        $formatted = number_format($value, IngredientConstants::STOCK_DECIMALS, ',', '.');
        // Trim trailing ",000" when the value is an exact integer.
        if (str_ends_with($formatted, ',' . str_repeat('0', IngredientConstants::STOCK_DECIMALS))) {
            $formatted = substr($formatted, 0, -(IngredientConstants::STOCK_DECIMALS + 1));
        }
        $unit = (string)($this->unit ?? '');

        return $unit === '' ? $formatted : $formatted . ' ' . $unit;
    }

    /**
     * Returns the unit cost as a localised currency string (e.g. "$1.500").
     */
    public function getFormattedUnitCost(): string
    {
        return '$' . number_format((float)$this->unit_cost, 0, ',', '.');
    }
}
