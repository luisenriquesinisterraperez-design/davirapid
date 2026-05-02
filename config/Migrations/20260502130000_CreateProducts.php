<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateProducts extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('products')) {
            return;
        }

        $this->table('products', [
            'collation' => 'utf8mb4_unicode_ci',
            'signed' => false,
        ])
            ->addColumn('code', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('name', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('price', 'decimal', [
                'precision' => 12,
                'scale' => 0,
                'null' => false,
            ])
            ->addColumn('image_path', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['code'], ['unique' => true, 'name' => 'uniq_products_code'])
            ->addIndex(['is_active', 'name'], ['name' => 'idx_products_active_name'])
            ->create();
    }

    public function down(): void
    {
        $this->table('products')->drop()->save();
    }
}
