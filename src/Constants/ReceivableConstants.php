<?php
declare(strict_types=1);

namespace App\Constants;

/**
 * Domain constants for Receivables (Cuentas por Cobrar).
 *
 * Values mirror the Spanish business vocabulary used in the UI; PHP
 * identifiers remain in English per project convention.
 */
final class ReceivableConstants
{
    public const STATUS_PENDIENTE = 'pendiente';
    public const STATUS_PAGADO = 'pagado';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDIENTE,
        self::STATUS_PAGADO,
    ];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_PENDIENTE => 'Pendiente',
        self::STATUS_PAGADO => 'Pagado',
    ];

    /** Format used by OrderService when creating an automatic CxC from an order. */
    public const AUTO_DESCRIPTION_TEMPLATE = 'Pedido #%d - %s';

    public const DESCRIPTION_MAX_LENGTH = 255;
}
