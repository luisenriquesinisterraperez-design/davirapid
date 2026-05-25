<?php
declare(strict_types=1);

namespace App\Constants;

final class DailyClosingConstants
{
    /** Money comparison tolerance. */
    public const EPSILON = 0.005;

    /** Decimal places for amounts. */
    public const MONEY_DECIMALS = 2;

    private function __construct()
    {
    }
}
