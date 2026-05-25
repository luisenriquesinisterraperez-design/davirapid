<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateIngredients extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('ingredients')) {
            $this->table('ingredients', [
                'collation' => 'utf8mb4_unicode_ci',
                'signed' => false,
            ])
                ->addColumn('name', 'string', ['limit' => 120, 'null' => false])
                ->addColumn('unit', 'string', ['limit' => 16, 'null' => false])
                ->addColumn('stock_quantity', 'decimal', [
                    'precision' => 12,
                    'scale' => 3,
                    'null' => false,
                    'default' => '0.000',
                ])
                ->addColumn('unit_cost', 'decimal', [
                    'precision' => 12,
                    'scale' => 2,
                    'null' => false,
                    'default' => '0.00',
                ])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addIndex(['name'], ['unique' => true, 'name' => 'uniq_ingredients_name'])
                ->addIndex(['stock_quantity'], ['name' => 'idx_ingredients_low_stock'])
                ->create();

            return;
        }

        // Table already exists from prior work. Align its shape with the plan
        // without dropping data. Each alteration is guarded so re-running is safe.
        $table = $this->table('ingredients');

        if ($table->hasColumn('stock') && !$table->hasColumn('stock_quantity')) {
            $table->renameColumn('stock', 'stock_quantity')->update();
        }

        if ($table->hasColumn('stock_quantity')) {
            $table->changeColumn('stock_quantity', 'decimal', [
                'precision' => 12,
                'scale' => 3,
                'null' => false,
                'default' => '0.000',
            ])->update();
        }

        if ($table->hasColumn('unit_cost')) {
            $table->changeColumn('unit_cost', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'null' => false,
                'default' => '0.00',
            ])->update();
        }

        if ($table->hasColumn('name')) {
            $table->changeColumn('name', 'string', ['limit' => 120, 'null' => false])->update();
        }

        if ($table->hasColumn('unit')) {
            $table->changeColumn('unit', 'string', ['limit' => 16, 'null' => false])->update();
        }

        if (!$table->hasIndexByName('uniq_ingredients_name')) {
            $table->addIndex(['name'], ['unique' => true, 'name' => 'uniq_ingredients_name'])->update();
        }

        if (!$table->hasIndexByName('idx_ingredients_low_stock')) {
            $table->addIndex(['stock_quantity'], ['name' => 'idx_ingredients_low_stock'])->update();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('ingredients')) {
            $this->table('ingredients')->drop()->update();
        }
    }
}
