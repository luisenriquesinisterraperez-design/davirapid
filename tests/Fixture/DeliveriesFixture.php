<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

class DeliveriesFixture extends TestFixture
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $records = [
        [
            'id' => 1,
            'first_name' => 'Carlos',
            'last_name' => 'Ruiz',
            'phone' => '3105551111',
            'is_active' => 1,
            'created' => '2026-05-23 10:00:00',
            'modified' => '2026-05-23 10:00:00',
        ],
        [
            'id' => 2,
            'first_name' => 'Ana',
            'last_name' => 'Martínez',
            'phone' => '3115552222',
            'is_active' => 1,
            'created' => '2026-05-23 10:00:00',
            'modified' => '2026-05-23 10:00:00',
        ],
    ];
}
