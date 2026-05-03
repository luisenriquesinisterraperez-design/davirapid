<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateCustomers extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('customers')) {
            return;
        }

        $this->table('customers')
            ->addColumn('name', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('phone', 'string', ['limit' => 30, 'null' => false])
            ->addColumn('address', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('created', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('modified', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(['phone'], ['unique' => true, 'name' => 'uniq_customers_phone'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('customers')) {
            $this->table('customers')->drop()->update();
        }
    }
}
