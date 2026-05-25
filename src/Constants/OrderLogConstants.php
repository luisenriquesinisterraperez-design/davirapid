<?php
declare(strict_types=1);

namespace App\Constants;

/**
 * Audit log kinds for the Orders module. Stored in `order_logs.kind`.
 */
final class OrderLogConstants
{
    public const KIND_CREATED = 'created';
    public const KIND_STATE_CHANGED = 'state_changed';
    public const KIND_FIELD_CHANGED = 'field_changed';
    public const KIND_ITEM_ADDED = 'item_added';
    public const KIND_ITEM_REMOVED = 'item_removed';
    public const KIND_ITEM_CHANGED = 'item_changed';
    public const KIND_CANCELLED = 'cancelled';
    public const KIND_REACTIVATED = 'reactivated';
    public const KIND_DELETED = 'deleted';

    /** @var list<string> */
    public const KINDS = [
        self::KIND_CREATED,
        self::KIND_STATE_CHANGED,
        self::KIND_FIELD_CHANGED,
        self::KIND_ITEM_ADDED,
        self::KIND_ITEM_REMOVED,
        self::KIND_ITEM_CHANGED,
        self::KIND_CANCELLED,
        self::KIND_REACTIVATED,
        self::KIND_DELETED,
    ];

    /** @var array<string, string> */
    public const KIND_LABELS = [
        self::KIND_CREATED => 'Creado',
        self::KIND_STATE_CHANGED => 'Cambio de estado',
        self::KIND_FIELD_CHANGED => 'Cambio de campo',
        self::KIND_ITEM_ADDED => 'Producto agregado',
        self::KIND_ITEM_REMOVED => 'Producto removido',
        self::KIND_ITEM_CHANGED => 'Producto modificado',
        self::KIND_CANCELLED => 'Cancelado',
        self::KIND_REACTIVATED => 'Reactivado',
        self::KIND_DELETED => 'Eliminado',
    ];

    /** @var array<string, string> */
    public const KIND_ICONS = [
        self::KIND_CREATED => 'bi-plus-circle',
        self::KIND_STATE_CHANGED => 'bi-arrow-right-circle',
        self::KIND_FIELD_CHANGED => 'bi-pencil',
        self::KIND_ITEM_ADDED => 'bi-bag-plus',
        self::KIND_ITEM_REMOVED => 'bi-bag-dash',
        self::KIND_ITEM_CHANGED => 'bi-bag-check',
        self::KIND_CANCELLED => 'bi-x-octagon',
        self::KIND_REACTIVATED => 'bi-arrow-clockwise',
        self::KIND_DELETED => 'bi-trash',
    ];

    /**
     * Static utility class — instantiation disallowed.
     */
    private function __construct()
    {
    }
}
