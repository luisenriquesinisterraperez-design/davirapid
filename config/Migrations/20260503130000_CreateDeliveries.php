<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateDeliveries extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('deliveries')) {
            return;
        }

        $this->table('deliveries')
            ->addColumn('first_name', 'string', ['limit' => 60, 'null' => false])
            ->addColumn('last_name', 'string', ['limit' => 60, 'null' => false])
            ->addColumn('phone', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('created', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('modified', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(
                ['is_active', 'last_name', 'first_name'],
                ['name' => 'idx_deliveries_active_name']
            )
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('deliveries')) {
            $this->table('deliveries')->drop()->update();
        }
    }
}
