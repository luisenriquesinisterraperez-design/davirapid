<?php
declare(strict_types=1);

namespace App\Constants;

final class IngredientConstants
{
    public const UNIT_GRAM = 'gr';
    public const UNIT_KILOGRAM = 'kg';
    public const UNIT_MILLILITER = 'ml';
    public const UNIT_LITER = 'l';
    public const UNIT_UNIT = 'unidad';

    /** @var list<string> */
    public const UNITS = [
        self::UNIT_GRAM,
        self::UNIT_KILOGRAM,
        self::UNIT_MILLILITER,
        self::UNIT_LITER,
        self::UNIT_UNIT,
    ];

    /** @var array<string, string> */
    public const UNIT_LABELS = [
        self::UNIT_GRAM => 'Gramos (gr)',
        self::UNIT_KILOGRAM => 'Kilogramos (kg)',
        self::UNIT_MILLILITER => 'Mililitros (ml)',
        self::UNIT_LITER => 'Litros (l)',
        self::UNIT_UNIT => 'Unidad',
    ];

    public const LOW_STOCK_THRESHOLD = 5;
    public const STOCK_DECIMALS = 3;
    public const COST_DECIMALS = 2;
    public const NAME_MAX_LENGTH = 120;
}
