<?php
declare(strict_types=1);

namespace App\Constants;

/**
 * Domain constants for the Orders module.
 *
 * All values are referenced by services, entities, validation, and templates
 * — never hardcoded inline as literals (ARQUITECTURE §4.1).
 */
final class OrderConstants
{
    // -------------------- Types --------------------
    public const TYPE_LOCAL = 'local';
    public const TYPE_DOMICILIO = 'domicilio';

    /** @var list<string> */
    public const TYPES = [self::TYPE_LOCAL, self::TYPE_DOMICILIO];

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_LOCAL => 'Local',
        self::TYPE_DOMICILIO => 'Domicilio',
    ];

    // -------------------- Statuses --------------------
    public const STATUS_RECEIVED = 'recibido';
    public const STATUS_PREPARING = 'preparando';
    public const STATUS_ON_ROUTE = 'en_camino';
    public const STATUS_DELIVERED = 'entregado';
    public const STATUS_CANCELLED = 'cancelado';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_RECEIVED,
        self::STATUS_PREPARING,
        self::STATUS_ON_ROUTE,
        self::STATUS_DELIVERED,
        self::STATUS_CANCELLED,
    ];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_RECEIVED => 'Recibido',
        self::STATUS_PREPARING => 'Preparando',
        self::STATUS_ON_ROUTE => 'En camino',
        self::STATUS_DELIVERED => 'Entregado',
        self::STATUS_CANCELLED => 'Cancelado',
    ];

    /**
     * CSS class from the dedicated status-* family defined in DESIGN.md.
     * NOT badge-* — order lifecycle has its own visual family.
     *
     * @var array<string, string>
     */
    public const STATUS_CSS_CLASS = [
        self::STATUS_RECEIVED => 'status-pending',
        self::STATUS_PREPARING => 'status-preparing',
        self::STATUS_ON_ROUTE => 'status-on-route',
        self::STATUS_DELIVERED => 'status-delivered',
        self::STATUS_CANCELLED => 'status-cancelled',
    ];

    /** @var list<string> */
    public const TERMINAL_STATUSES = [self::STATUS_DELIVERED];

    /** @var list<string> */
    public const EDITABLE_STATUSES = [self::STATUS_RECEIVED, self::STATUS_PREPARING];

    /** @var list<string> */
    public const CANCELLABLE_FROM = [
        self::STATUS_RECEIVED,
        self::STATUS_PREPARING,
        self::STATUS_ON_ROUTE,
    ];

    // -------------------- Payment methods --------------------
    public const PAYMENT_CASH = 'efectivo';
    public const PAYMENT_NEQUI = 'nequi';
    public const PAYMENT_DAVIPLATA = 'daviplata';
    public const PAYMENT_TRANSFER = 'transferencia';
    public const PAYMENT_CREDIT = 'credito';

    /** @var list<string> */
    public const PAYMENT_METHODS = [
        self::PAYMENT_CASH,
        self::PAYMENT_NEQUI,
        self::PAYMENT_DAVIPLATA,
        self::PAYMENT_TRANSFER,
        self::PAYMENT_CREDIT,
    ];

    /** @var array<string, string> */
    public const PAYMENT_LABELS = [
        self::PAYMENT_CASH => 'Efectivo',
        self::PAYMENT_NEQUI => 'Nequi',
        self::PAYMENT_DAVIPLATA => 'Daviplata',
        self::PAYMENT_TRANSFER => 'Transferencia',
        self::PAYMENT_CREDIT => 'Crédito (Fiado)',
    ];

    /** @var list<string> Cash-like methods count as real income in the day's close. */
    public const PAYMENT_METHODS_CASH_LIKE = [
        self::PAYMENT_CASH,
        self::PAYMENT_NEQUI,
        self::PAYMENT_DAVIPLATA,
        self::PAYMENT_TRANSFER,
    ];

    // -------------------- Numeric precision --------------------
    public const MONEY_DECIMALS = 2;
    public const QUANTITY_DECIMALS = 3;

    // -------------------- Limits --------------------
    public const NOTES_MAX_LENGTH = 65000;
    public const LINE_NOTES_MAX_LENGTH = 255;
    public const MAX_ITEMS_PER_ORDER = 50;

    /**
     * Static utility class — instantiation disallowed.
     */
    private function __construct()
    {
    }
}
