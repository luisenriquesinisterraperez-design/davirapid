<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreatePermissions extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('permissions')) {
            return;
        }

        $this->table('permissions', [
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('role_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('module', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('can_view', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('can_create', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('can_edit', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('can_delete', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['role_id', 'module'], [
                'unique' => true,
                'name' => 'uniq_permissions_role_module',
            ])
            ->addForeignKey('role_id', 'roles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_permissions_role',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('permissions')->drop()->save();
    }
}
