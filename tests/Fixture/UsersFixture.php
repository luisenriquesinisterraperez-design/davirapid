<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\TestSuite\Fixture\TestFixture;

class UsersFixture extends TestFixture
{
    public function init(): void
    {
        $hash = (new DefaultPasswordHasher())->hash('test-password');
        $this->records = [
            [
                'id' => 1,
                'username' => 'admin',
                'name' => 'Administrador',
                'password' => $hash,
                'role_id' => 1,
                'delivery_id' => null,
                'active' => 1,
                'failed_login_count' => 0,
                'locked_until' => null,
                'last_login_at' => null,
                'created' => '2026-05-24 09:00:00',
                'modified' => '2026-05-24 09:00:00',
            ],
            [
                'id' => 2,
                'username' => 'cajero',
                'name' => 'Cajero Test',
                'password' => $hash,
                'role_id' => 2,
                'delivery_id' => null,
                'active' => 1,
                'failed_login_count' => 0,
                'locked_until' => null,
                'last_login_at' => null,
                'created' => '2026-05-24 09:00:00',
                'modified' => '2026-05-24 09:00:00',
            ],
            [
                'id' => 3,
                'username' => 'lector',
                'name' => 'Lector Test',
                'password' => $hash,
                'role_id' => 3,
                'delivery_id' => null,
                'active' => 1,
                'failed_login_count' => 0,
                'locked_until' => null,
                'last_login_at' => null,
                'created' => '2026-05-24 09:00:00',
                'modified' => '2026-05-24 09:00:00',
            ],
        ];

        parent::init();
    }
}
