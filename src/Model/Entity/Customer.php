<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class Customer extends Entity
{
    protected array $_accessible = [
        'name' => true,
        'phone' => true,
        'address' => true,
        'is_active' => true,
    ];

    public function isActive(): bool
    {
        return (bool)$this->is_active;
    }

    protected function _getDisplayName(): string
    {
        $name = (string)($this->name ?? '');
        $phone = (string)($this->phone ?? '');
        if ($name === '' && $phone === '') {
            return '';
        }
        if ($phone === '') {
            return $name;
        }
        if ($name === '') {
            return $phone;
        }
        return $name . ' — ' . $phone;
    }
}
