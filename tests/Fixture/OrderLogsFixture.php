<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

class OrderLogsFixture extends TestFixture
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $records = [
        [
            'id' => 1,
            'order_id' => 1,
            'order_id_snapshot' => 1,
            'user_id' => 1,
            'user_name_snapshot' => 'Administrador',
            'kind' => 'created',
            'description' => 'Pedido creado por Administrador.',
            'created' => '2026-05-23 12:00:00',
        ],
        [
            'id' => 2,
            'order_id' => 1,
            'order_id_snapshot' => 1,
            'user_id' => 1,
            'user_name_snapshot' => 'Administrador',
            'kind' => 'state_changed',
            'description' => "Estado: de 'Recibido' a 'Entregado'",
            'created' => '2026-05-23 12:15:00',
        ],
        [
            'id' => 3,
            'order_id' => 2,
            'order_id_snapshot' => 2,
            'user_id' => 1,
            'user_name_snapshot' => 'Administrador',
            'kind' => 'created',
            'description' => 'Pedido creado por Administrador.',
            'created' => '2026-05-23 13:00:00',
        ],
        [
            'id' => 4,
            'order_id' => 3,
            'order_id_snapshot' => 3,
            'user_id' => 1,
            'user_name_snapshot' => 'Administrador',
            'kind' => 'cancelled',
            'description' => 'Pedido cancelado',
            'created' => '2026-05-23 13:30:00',
        ],
        // Orphan log: referenced order was deleted.
        [
            'id' => 5,
            'order_id' => null,
            'order_id_snapshot' => 999,
            'user_id' => 1,
            'user_name_snapshot' => 'Administrador',
            'kind' => 'deleted',
            'description' => 'Pedido eliminado',
            'created' => '2026-05-23 14:00:00',
        ],
    ];
}
