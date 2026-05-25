<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateReceivables extends BaseMigration
{
    /**
     * Create `receivables` (Cuentas por Cobrar) table.
     *
     * FK sign rules:
     * - `customer_id` SIGNED (matches `customers.id` default-signed PK).
     * - `order_id` SIGNED nullable (matches `orders.id`).
     * - `created_by` UNSIGNED (matches `users.id`).
     *
     * UNIQUE on `order_id` enforces idempotency for credit-payment orders
     * (one CxC per order). MySQL treats NULLs as distinct by default, so
     * manual CxC (no order) can coexist freely.
     */
    public function up(): void
    {
        if ($this->hasTable('receivables')) {
            return;
        }

        $this->table('receivables', ['collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('customer_id', 'integer', ['null' => false])
            ->addColumn('order_id', 'integer', ['null' => true, 'default' => null])
            ->addColumn('total_amount', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'null' => false,
            ])
            ->addColumn('paid_amount', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'null' => false,
                'default' => '0.00',
            ])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('status', 'string', ['limit' => 16, 'null' => false, 'default' => 'pendiente'])
            ->addColumn('created_by', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('modified', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(['status', 'created'], ['name' => 'idx_rec_status_created'])
            ->addIndex(['customer_id'], ['name' => 'idx_rec_customer'])
            ->addIndex(['order_id'], ['unique' => true, 'name' => 'uniq_rec_order_id'])
            ->addForeignKey('customer_id', 'customers', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'RESTRICT',
                'constraint' => 'fk_rec_customer',
            ])
            ->addForeignKey('order_id', 'orders', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'RESTRICT',
                'constraint' => 'fk_rec_order',
            ])
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'RESTRICT',
                'constraint' => 'fk_rec_creator',
            ])
            ->create();
    }

    /**
     * Drop the receivables table.
     */
    public function down(): void
    {
        if ($this->hasTable('receivables')) {
            $this->table('receivables')->drop()->update();
        }
    }
}
