<?php
declare(strict_types=1);

namespace App\Constants;

/**
 * Domain constants for AccountPayments (Abonos).
 *
 * Payment methods are a strict subset of OrderConstants::PAYMENT_METHODS
 * EXCLUDING `credito` — you cannot pay a debt by generating more debt.
 *
 * Values mirror the Spanish business vocabulary used in the UI; PHP
 * identifiers remain in English per project convention.
 */
final class AccountPaymentConstants
{
    /**
     * Valid payment methods for abonos. Mirrors OrderConstants payment
     * codes so cross-module reports (Cierre Diario) can group trivially.
     *
     * @var list<string>
     */
    public const PAYMENT_METHODS = [
        OrderConstants::PAYMENT_CASH,
        OrderConstants::PAYMENT_NEQUI,
        OrderConstants::PAYMENT_DAVIPLATA,
        OrderConstants::PAYMENT_TRANSFER,
    ];

    /** @var array<string, string> */
    public const PAYMENT_LABELS = [
        OrderConstants::PAYMENT_CASH => 'Efectivo',
        OrderConstants::PAYMENT_NEQUI => 'Nequi',
        OrderConstants::PAYMENT_DAVIPLATA => 'Daviplata',
        OrderConstants::PAYMENT_TRANSFER => 'Transferencia',
    ];

    /** Tolerance for equality comparisons on decimal(12,2) values. */
    public const EPSILON = 0.005;

    /** Notes field is TEXT; 65000 keeps margin below the MySQL TEXT cap. */
    public const NOTES_MAX_LENGTH = 65000;

    /**
     * Static utility class — instantiation disallowed.
     */
    private function __construct()
    {
    }
}
