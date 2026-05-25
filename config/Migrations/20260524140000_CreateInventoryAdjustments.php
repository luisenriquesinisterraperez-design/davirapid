<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateInventoryAdjustments extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('inventory_adjustments')) {
            return;
        }

        $this->table('inventory_adjustments', [
            'collation' => 'utf8mb4_unicode_ci',
            'signed' => false,
        ])
            ->addColumn('ingredient_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('type', 'string', ['limit' => 10, 'null' => false])
            ->addColumn('quantity', 'decimal', [
                'precision' => 12,
                'scale' => 3,
                'null' => false,
            ])
            ->addColumn('reason', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('notes', 'text', ['null' => true])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['ingredient_id', 'created'], ['name' => 'idx_ia_ingredient_created'])
            ->addIndex(['created'], ['name' => 'idx_ia_created_desc'])
            ->addIndex(['type'], ['name' => 'idx_ia_type'])
            ->addIndex(['user_id'], ['name' => 'idx_ia_user_id'])
            ->addForeignKey('ingredient_id', 'ingredients', 'id', [
                'delete' => 'CASCADE',
                'update' => 'RESTRICT',
                'constraint' => 'fk_ia_ingredient',
            ])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'RESTRICT',
                'constraint' => 'fk_ia_user',
            ])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('inventory_adjustments')) {
            $this->table('inventory_adjustments')->drop()->update();
        }
    }
}
