<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateDailyClosings extends BaseMigration
{
    /**
     * Create `daily_closings` (Cierre Diario de Caja).
     *
     * Snapshot columns (sales_total, payments_total, expenses_total) capture
     * the per-day aggregate at the moment the close was committed, so the
     * audit trail survives later edits/deletes of orders, payments, expenses.
     */
    public function up(): void
    {
        if ($this->hasTable('daily_closings')) {
            return;
        }

        $this->table('daily_closings', ['collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('closing_date', 'date', ['null' => false])
            ->addColumn('initial_balance', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'null' => false,
                'default' => '0.00',
            ])
            ->addColumn('sales_total', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'null' => false,
                'default' => '0.00',
            ])
            ->addColumn('payments_total', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'null' => false,
                'default' => '0.00',
            ])
            ->addColumn('expenses_total', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'null' => false,
                'default' => '0.00',
            ])
            ->addColumn('expected_amount', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'null' => false,
                'default' => '0.00',
            ])
            ->addColumn('actual_amount', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'null' => false,
                'default' => '0.00',
            ])
            ->addColumn('difference', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'null' => false,
                'default' => '0.00',
            ])
            ->addColumn('notes', 'text', ['null' => true, 'default' => null])
            ->addColumn('created_by', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('modified', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(['closing_date'], ['unique' => true, 'name' => 'uniq_closing_date'])
            ->addIndex(['created_by'], ['name' => 'idx_closing_creator'])
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'RESTRICT',
                'constraint' => 'fk_closing_creator',
            ])
            ->create();
    }

    /**
     * Drop the daily_closings table.
     */
    public function down(): void
    {
        if ($this->hasTable('daily_closings')) {
            $this->table('daily_closings')->drop()->update();
        }
    }
}
