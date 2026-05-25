<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateAccountPayments extends BaseMigration
{
    /**
     * Create `account_payments` (Abonos) table — append-only ledger of
     * partial/total payments against a Receivable.
     *
     * FK sign rules:
     * - `receivable_id` SIGNED (matches `receivables.id` default-signed PK).
     * - `created_by` UNSIGNED (matches `users.id`).
     *
     * `DELETE CASCADE` on receivable_id: when a CxC is removed (cancel /
     * delete of its originating order) the associated abonos must vanish.
     * `ReceivableService::deleteForOrder` already logs the voided amount.
     */
    public function up(): void
    {
        if ($this->hasTable('account_payments')) {
            return;
        }

        $this->table('account_payments', ['collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('receivable_id', 'integer', ['null' => false])
            ->addColumn('amount', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'null' => false,
            ])
            ->addColumn('payment_method', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('notes', 'text', ['null' => true, 'default' => null])
            ->addColumn('created_by', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(['receivable_id', 'created'], ['name' => 'idx_ap_receivable_created'])
            ->addIndex(['created'], ['name' => 'idx_ap_created'])
            ->addIndex(['payment_method'], ['name' => 'idx_ap_method'])
            ->addIndex(['created_by'], ['name' => 'idx_ap_creator'])
            ->addForeignKey('receivable_id', 'receivables', 'id', [
                'delete' => 'CASCADE',
                'update' => 'RESTRICT',
                'constraint' => 'fk_ap_receivable',
            ])
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'RESTRICT',
                'constraint' => 'fk_ap_creator',
            ])
            ->create();
    }

    /**
     * Drop the account_payments table.
     */
    public function down(): void
    {
        if ($this->hasTable('account_payments')) {
            $this->table('account_payments')->drop()->update();
        }
    }
}
