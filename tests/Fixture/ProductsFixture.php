<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Minimal seed for Recipes module test cases.
 */
class ProductsFixture extends TestFixture
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $records = [
        [
            'id' => 1,
            'code' => 'HAM',
            'name' => 'Hamburguesa',
            'description' => null,
            'price' => '5000',
            'image_path' => null,
            'is_active' => 1,
            'created' => '2026-05-24 10:00:00',
            'modified' => '2026-05-24 10:00:00',
        ],
        [
            'id' => 2,
            'code' => 'PIZ',
            'name' => 'Pizza',
            'description' => null,
            'price' => '8000',
            'image_path' => null,
            'is_active' => 1,
            'created' => '2026-05-24 10:00:00',
            'modified' => '2026-05-24 10:00:00',
        ],
        [
            'id' => 3,
            'code' => 'EMP',
            'name' => 'Empanada',
            'description' => null,
            'price' => '1500',
            'image_path' => null,
            'is_active' => 1,
            'created' => '2026-05-24 10:00:00',
            'modified' => '2026-05-24 10:00:00',
        ],
    ];
}
