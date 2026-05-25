<?php
declare(strict_types=1);

namespace App\Constants;

final class RecipeConstants
{
    /** Mínimo exclusivo: la cantidad debe ser > 0. */
    public const QUANTITY_MIN = 0;

    /** Tope alto pero no infinito; red de seguridad contra typos. */
    public const QUANTITY_MAX = 999999.999;

    /** Reusa la precisión de Ingredients (3 decimales). */
    public const QUANTITY_DECIMALS = IngredientConstants::STOCK_DECIMALS;
}
