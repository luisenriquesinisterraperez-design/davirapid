<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class Delivery extends Entity
{
    protected array $_accessible = [
        'first_name' => true,
        'last_name' => true,
        'phone' => true,
        'is_active' => true,
    ];

    public function isActive(): bool
    {
        return (bool)$this->is_active;
    }

    protected function _getFullName(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
    }
}
