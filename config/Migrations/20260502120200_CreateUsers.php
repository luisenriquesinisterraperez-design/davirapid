<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateUsers extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('users')) {
            return;
        }

        $this->table('users', [
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('username', 'string', ['limit' => 60, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('password', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('role_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('failed_login_count', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('locked_until', 'datetime', ['null' => true])
            ->addColumn('last_login_at', 'datetime', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['username'], ['unique' => true, 'name' => 'uniq_users_username'])
            ->addIndex(['locked_until'], ['name' => 'idx_users_locked_until'])
            ->addForeignKey('role_id', 'roles', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_users_role',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('users')->drop()->save();
    }
}
