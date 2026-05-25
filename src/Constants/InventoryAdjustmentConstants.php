<?php
declare(strict_types=1);

namespace App\Constants;

final class InventoryAdjustmentConstants
{
    public const TYPE_ENTRY = 'entrada';
    public const TYPE_BAJA = 'baja';

    /** @var list<string> */
    public const TYPES = [self::TYPE_ENTRY, self::TYPE_BAJA];

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_ENTRY => 'Entrada',
        self::TYPE_BAJA => 'Baja',
    ];

    /**
     * Sugerencias presentadas en el datalist del formulario.
     * Campo de texto libre (spec §12) — solo guía, no enum cerrado.
     *
     * @var list<string>
     */
    public const REASON_SUGGESTIONS = [
        'Compra a proveedor',
        'Merma',
        'Daño',
        'Conteo físico',
        'Devolución',
        'Robo',
    ];

    public const REASON_MAX_LENGTH = 120;
}
