<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class Product extends Entity
{
    protected array $_accessible = [
        'code' => true,
        'name' => true,
        'description' => true,
        'price' => true,
        'image_path' => true,
        'is_active' => true,
    ];

    public function getImageUrl(): string
    {
        if (empty($this->image_path)) {
            return '/img/product-placeholder.svg';
        }

        return '/' . ltrim((string)$this->image_path, '/');
    }

    public function getFormattedPrice(): string
    {
        return '$' . number_format((int)$this->price, 0, ',', '.');
    }
}
