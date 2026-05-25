<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateExpenses extends BaseMigration
{
    /**
     * Create `expenses` table (general business outflows).
     * - `expense_date` (date) is the business day used by Cierre Diario.
     * - `created` (datetime) is the technical insert timestamp.
     * - `created_by` UNSIGNED FK to users (matches users.id).
     */
    public function up(): void
    {
        if ($this->hasTable('expenses')) {
            return;
        }

        $this->table('expenses', ['collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('amount', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'null' => false,
            ])
            ->addColumn('expense_date', 'date', ['null' => false])
            ->addColumn('created_by', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('modified', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(['expense_date'], ['name' => 'idx_expenses_date'])
            ->addIndex(['created_by'], ['name' => 'idx_expenses_creator'])
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'RESTRICT',
                'constraint' => 'fk_expenses_creator',
            ])
            ->create();
    }

    /**
     * Drop the expenses table.
     */
    public function down(): void
    {
        if ($this->hasTable('expenses')) {
            $this->table('expenses')->drop()->update();
        }
    }
}
