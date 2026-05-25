<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

class RolesFixture extends TestFixture
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $records = [
        [
            'id' => 1,
            'name' => 'Administrador',
            'is_admin' => 1,
            'created' => '2026-05-24 09:00:00',
            'modified' => '2026-05-24 09:00:00',
        ],
        [
            'id' => 2,
            'name' => 'Cajero',
            'is_admin' => 0,
            'created' => '2026-05-24 09:00:00',
            'modified' => '2026-05-24 09:00:00',
        ],
        [
            'id' => 3,
            'name' => 'Solo lectura',
            'is_admin' => 0,
            'created' => '2026-05-24 09:00:00',
            'modified' => '2026-05-24 09:00:00',
        ],
    ];
}
