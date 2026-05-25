<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

class CustomersFixture extends TestFixture
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $records = [
        [
            'id' => 1,
            'name' => 'Juan Pérez',
            'phone' => '3001234567',
            'address' => 'Calle 1 #2-3',
            'is_active' => 1,
            'created' => '2026-05-23 10:00:00',
            'modified' => '2026-05-23 10:00:00',
        ],
        [
            'id' => 2,
            'name' => 'María Gómez',
            'phone' => '3019876543',
            'address' => null,
            'is_active' => 1,
            'created' => '2026-05-23 10:00:00',
            'modified' => '2026-05-23 10:00:00',
        ],
    ];
}
