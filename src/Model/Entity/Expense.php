<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\I18n\Date;
use Cake\ORM\Entity;

class Expense extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'description' => true,
        'amount' => true,
        'expense_date' => true,
        'created_by' => true,
        'creator' => true,
    ];

    public function getFormattedAmount(): string
    {
        return '$' . number_format((float)$this->amount, 2, ',', '.');
    }

    public function getFormattedDate(): string
    {
        if (!$this->expense_date instanceof Date) {
            return '—';
        }

        return $this->expense_date->i18nFormat('dd/MM/yyyy') ?? '—';
    }

    public function isFuture(): bool
    {
        if (!$this->expense_date instanceof Date) {
            return false;
        }
        $today = (new Date())->format('Y-m-d');

        return $this->expense_date->format('Y-m-d') > $today;
    }
}
