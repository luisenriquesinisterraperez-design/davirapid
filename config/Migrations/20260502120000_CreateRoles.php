<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateRoles extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('roles')) {
            return;
        }

        $this->table('roles', [
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('name', 'string', ['limit' => 60, 'null' => false])
            ->addColumn('is_admin', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['name'], ['unique' => true, 'name' => 'uniq_roles_name'])
            ->create();
    }

    public function down(): void
    {
        $this->table('roles')->drop()->save();
    }
}
