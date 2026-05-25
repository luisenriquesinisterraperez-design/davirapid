<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Align `expenses` schema with the canonical definition in
 * 20260525130000_CreateExpenses.
 *
 * Why this exists: an older `CreateExpenses` migration (removed from the
 * filesystem) created the `expenses` table without `created_by`. The current
 * `CreateExpenses` guards with `if (hasTable) return`, so on environments
 * carrying the stale table the new column is never added.
 *
 * This migration is idempotent: it only applies changes when the target
 * column / index / foreign key is missing, so it is safe on fresh installs
 * (where `CreateExpenses` already produced the correct shape) and on stale
 * installs alike.
 */
class AlignExpensesSchema extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('expenses')) {
            return;
        }

        $table = $this->table('expenses');

        if (!$table->hasColumn('created_by')) {
            $table->addColumn('created_by', 'integer', [
                'signed' => false,
                'null' => true,
                'after' => 'expense_date',
            ])->update();
        }

        if (!$table->hasIndexByName('idx_expenses_creator')) {
            $table->addIndex(['created_by'], ['name' => 'idx_expenses_creator'])->update();
        }

        if (!$table->hasForeignKey('created_by')) {
            $table->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'RESTRICT',
                'constraint' => 'fk_expenses_creator',
            ])->update();
        }
    }

    public function down(): void
    {
        if (!$this->hasTable('expenses')) {
            return;
        }

        $table = $this->table('expenses');

        if ($table->hasForeignKey('created_by')) {
            $table->dropForeignKey('created_by')->update();
        }

        if ($table->hasIndexByName('idx_expenses_creator')) {
            $table->removeIndexByName('idx_expenses_creator')->update();
        }

        if ($table->hasColumn('created_by')) {
            $table->removeColumn('created_by')->update();
        }
    }
}
