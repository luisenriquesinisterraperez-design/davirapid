<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateProductIngredients extends BaseMigration
{
    /**
     * Up migration: create product_ingredients pivot with FK cascades.
     */
    public function up(): void
    {
        if ($this->hasTable('product_ingredients')) {
            return;
        }

        $this->table('product_ingredients', [
            'collation' => 'utf8mb4_unicode_ci',
            'signed' => false,
        ])
            ->addColumn('product_id', 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('ingredient_id', 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('quantity', 'decimal', [
                'precision' => 12,
                'scale' => 3,
                'null' => false,
            ])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(
                ['product_id', 'ingredient_id'],
                ['unique' => true, 'name' => 'uniq_pi_product_ingredient'],
            )
            ->addIndex(['ingredient_id'], ['name' => 'idx_pi_ingredient_id'])
            ->addForeignKey('product_id', 'products', 'id', [
                'delete' => 'CASCADE',
                'update' => 'RESTRICT',
            ])
            ->addForeignKey('ingredient_id', 'ingredients', 'id', [
                'delete' => 'CASCADE',
                'update' => 'RESTRICT',
            ])
            ->create();
    }

    /**
     * Down migration: drop the table if present.
     */
    public function down(): void
    {
        if ($this->hasTable('product_ingredients')) {
            $this->table('product_ingredients')->drop()->update();
        }
    }
}
