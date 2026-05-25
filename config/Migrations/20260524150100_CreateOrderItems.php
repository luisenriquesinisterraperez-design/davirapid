<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateOrderItems extends BaseMigration
{
    /**
     * Create `order_items` — line items for each order.
     *
     * order_id is SIGNED to match orders.id (default signed PK).
     * product_id is UNSIGNED to match products.id.
     * Snapshots (product_name, price_at_sale, line_subtotal) preserve historical
     * accuracy independent of product mutations.
     */
    public function up(): void
    {
        if ($this->hasTable('order_items')) {
            return;
        }

        $this->table('order_items', ['collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('order_id', 'integer', ['null' => false])
            ->addColumn('product_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('product_name', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('quantity', 'decimal', [
                'precision' => 10,
                'scale' => 3,
                'null' => false,
            ])
            ->addColumn('price_at_sale', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'null' => false,
            ])
            ->addColumn('line_subtotal', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'null' => false,
            ])
            ->addColumn('notes', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['order_id'], ['name' => 'idx_order_items_order_id'])
            ->addIndex(['product_id'], ['name' => 'idx_order_items_product_id'])
            ->addForeignKey('order_id', 'orders', 'id', [
                'delete' => 'CASCADE',
                'update' => 'RESTRICT',
                'constraint' => 'fk_oi_order',
            ])
            ->addForeignKey('product_id', 'products', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'RESTRICT',
                'constraint' => 'fk_oi_product',
            ])
            ->create();
    }

    /**
     * Down migration: drop the order_items table.
     */
    public function down(): void
    {
        if ($this->hasTable('order_items')) {
            $this->table('order_items')->drop()->update();
        }
    }
}
