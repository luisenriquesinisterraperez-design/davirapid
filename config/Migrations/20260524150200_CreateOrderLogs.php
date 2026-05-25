<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateOrderLogs extends BaseMigration
{
    /**
     * Create `order_logs` — append-only audit trail.
     *
     * `order_id` is FK ON DELETE SET NULL so logs SURVIVE order deletion.
     * `order_id_snapshot` preserves the original id even after the FK is nulled.
     * No `modified` column — append-only by design.
     */
    public function up(): void
    {
        if ($this->hasTable('order_logs')) {
            return;
        }

        $this->table('order_logs', ['collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('order_id', 'integer', ['null' => true])
            ->addColumn('order_id_snapshot', 'integer', ['null' => false])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('user_name_snapshot', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('kind', 'string', ['limit' => 30, 'null' => false])
            ->addColumn('description', 'string', ['limit' => 500, 'null' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(
                ['order_id_snapshot', 'created'],
                ['name' => 'idx_order_logs_order_id_snapshot_created'],
            )
            ->addIndex(['created'], ['name' => 'idx_order_logs_created'])
            ->addIndex(['user_id'], ['name' => 'idx_order_logs_user_id'])
            ->addForeignKey('order_id', 'orders', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'RESTRICT',
                'constraint' => 'fk_ol_order',
            ])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'RESTRICT',
                'constraint' => 'fk_ol_user',
            ])
            ->create();
    }

    /**
     * Down migration: drop the order_logs table.
     */
    public function down(): void
    {
        if ($this->hasTable('order_logs')) {
            $this->table('order_logs')->drop()->update();
        }
    }
}
