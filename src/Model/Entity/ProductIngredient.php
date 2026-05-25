<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\IngredientConstants;
use Cake\ORM\Entity;

/**
 * ProductIngredient — pivote enriquecido entre Products e Ingredients.
 *
 * @property int $id
 * @property int $product_id
 * @property int $ingredient_id
 * @property string $quantity
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property \App\Model\Entity\Product|null $product
 * @property \App\Model\Entity\Ingredient|null $ingredient
 * @property float $line_cost
 */
class ProductIngredient extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'product_id' => true,
        'ingredient_id' => true,
        'quantity' => true,
        'product' => true,
        'ingredient' => true,
    ];

    /**
     * @var list<string>
     */
    protected array $_virtual = ['line_cost'];

    /**
     * Costo aportado por esta línea = quantity * ingredient.unit_cost.
     *
     * Si la entity se serializa sin hidratar el ingrediente (ej. respuesta JSON
     * sin contain), retorna 0.0 — comportamiento conservador para no romper,
     * pero consumidores deben asegurar el contain('Ingredients').
     */
    public function getLineCost(): float
    {
        if (empty($this->ingredient)) {
            return 0.0;
        }

        return round(
            (float)$this->quantity * (float)$this->ingredient->unit_cost,
            IngredientConstants::COST_DECIMALS,
        );
    }

    /**
     * Virtual property accessor for `line_cost`.
     */
    protected function _getLineCost(): float
    {
        return $this->getLineCost();
    }

    /**
     * Cantidad formateada con la unidad del ingrediente (ej. "200 gr", "1,5 kg").
     */
    public function getFormattedQuantity(): string
    {
        $value = (float)$this->quantity;
        $formatted = number_format($value, IngredientConstants::STOCK_DECIMALS, ',', '.');
        if (str_ends_with($formatted, ',' . str_repeat('0', IngredientConstants::STOCK_DECIMALS))) {
            $formatted = substr($formatted, 0, -(IngredientConstants::STOCK_DECIMALS + 1));
        }
        $unit = '';
        if (!empty($this->ingredient) && !empty($this->ingredient->unit)) {
            $unit = (string)$this->ingredient->unit;
        }

        return $unit === '' ? $formatted : $formatted . ' ' . $unit;
    }

    /**
     * Costo de la línea formateado como moneda local (ej. "$100").
     */
    public function getFormattedLineCost(): string
    {
        return '$' . number_format($this->getLineCost(), 0, ',', '.');
    }
}
