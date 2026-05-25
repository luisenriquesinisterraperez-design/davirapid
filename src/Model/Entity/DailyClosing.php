<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\DailyClosingConstants;
use Cake\I18n\Date;
use Cake\ORM\Entity;

class DailyClosing extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'closing_date' => true,
        'initial_balance' => true,
        'sales_total' => true,
        'payments_total' => true,
        'expenses_total' => true,
        'expected_amount' => true,
        'actual_amount' => true,
        'difference' => true,
        'notes' => true,
        'created_by' => true,
        'creator' => true,
    ];

    public function getFormattedDate(): string
    {
        if (!$this->closing_date instanceof Date) {
            return '—';
        }

        return $this->closing_date->i18nFormat('dd/MM/yyyy') ?? '—';
    }

    public function getFormattedDifference(): string
    {
        $val = (float)$this->difference;
        $sign = $val > 0 ? '+' : ($val < 0 ? '-' : '');

        return $sign . '$' . number_format(abs($val), 2, ',', '.');
    }

    public function isBalanced(): bool
    {
        return abs((float)$this->difference) <= DailyClosingConstants::EPSILON;
    }

    public function isShortage(): bool
    {
        return (float)$this->difference < -DailyClosingConstants::EPSILON;
    }

    public function isSurplus(): bool
    {
        return (float)$this->difference > DailyClosingConstants::EPSILON;
    }
}
