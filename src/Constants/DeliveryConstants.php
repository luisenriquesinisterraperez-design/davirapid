<?php
declare(strict_types=1);

namespace App\Constants;

final class DeliveryConstants
{
    public const NAME_MAX_LENGTH = 60;
    public const PHONE_MAX_LENGTH = 20;

    /** Permissive: digits, spaces, plus, hyphen, parentheses. */
    public const PHONE_REGEX = '/^[0-9 +\-()]+$/';

    private function __construct() {}
}
