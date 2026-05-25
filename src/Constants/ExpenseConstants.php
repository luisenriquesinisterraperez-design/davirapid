<?php
declare(strict_types=1);

namespace App\Constants;

final class ExpenseConstants
{
    public const DESCRIPTION_MAX_LENGTH = 255;

    /** Tolerance for decimal(12,2) comparisons. */
    public const EPSILON = 0.005;

    /** @var list<string> */
    public const DESCRIPTION_SUGGESTIONS = [
        'Compra de insumos',
        'Pago de servicios',
        'Pago de arriendo',
        'Mantenimiento',
        'Transporte',
        'Sueldos',
        'Otros',
    ];

    private function __construct()
    {
    }
}
