<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateOrders extends BaseMigration
{
    /**
     * Create `orders` table — central operational entity of Davi Rapid.
     *
     * Customer/Delivery FKs are SIGNED to match the SIGNED PKs of `customers`
     * and `deliveries`. User FKs are UNSIGNED to match users.id. PK is SIGNED
     * by default so order_items.order_id can match without sign mismatch.
     *
     * Snapshots (customer_name/phone/address) are explicit nullable strings,
     * populated by OrderService — never auto-managed by behaviors.
     */
    public function up(): void
    {
        // Clean up any orphan `orders` table left from previous attempts.
        if ($this->hasTable('orders')) {
            // Drop dependent tables first if they exist (defensive — FK could prevent drop).
            if ($this->hasTable('order_items')) {
                $this->table('order_items')->drop()->update();
            }
            if ($this->hasTable('order_logs')) {
                $this->table('order_logs')->drop()->update();
            }
            $this->table('orders')->drop()->update();
        }

        $this->table('orders', ['collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('customer_id', 'integer', ['null' => true])
            ->addColumn('delivery_id', 'integer', ['null' => true])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('type', 'string', ['limit' => 10, 'null' => false])
            ->addColumn('status', 'string', ['limit' => 15, 'null' => false, 'default' => 'recibido'])
            ->addColumn('payment_method', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('customer_name', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('customer_phone', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('customer_address', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('shipping_cost', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'null' => false,
                'default' => '0.00',
            ])
            ->addColumn('subtotal', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'null' => false,
                'default' => '0.00',
            ])
            ->addColumn('total', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'null' => false,
                'default' => '0.00',
            ])
            ->addColumn('notes', 'text', ['null' => true])
            ->addColumn('delivered_at', 'datetime', ['null' => true])
            ->addColumn('cancelled_at', 'datetime', ['null' => true])
            ->addColumn('cancelled_by', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['status', 'created'], ['name' => 'idx_orders_status_created'])
            ->addIndex(['created'], ['name' => 'idx_orders_created'])
            ->addIndex(['delivery_id'], ['name' => 'idx_orders_delivery_id'])
            ->addIndex(['customer_id'], ['name' => 'idx_orders_customer_id'])
            ->addIndex(['type'], ['name' => 'idx_orders_type'])
            ->addIndex(['payment_method'], ['name' => 'idx_orders_payment_method'])
            ->addIndex(['delivered_at'], ['name' => 'idx_orders_delivered_at'])
            ->addForeignKey('customer_id', 'customers', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'RESTRICT',
                'constraint' => 'fk_orders_customer',
            ])
            ->addForeignKey('delivery_id', 'deliveries', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'RESTRICT',
                'constraint' => 'fk_orders_delivery',
            ])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'RESTRICT',
                'constraint' => 'fk_orders_user',
            ])
            ->addForeignKey('cancelled_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'RESTRICT',
                'constraint' => 'fk_orders_cancelled_by',
            ])
            ->create();
    }

    /**
     * Down migration: drop the orders table.
     */
    public function down(): void
    {
        if ($this->hasTable('orders')) {
            $this->table('orders')->drop()->update();
        }
    }
}
