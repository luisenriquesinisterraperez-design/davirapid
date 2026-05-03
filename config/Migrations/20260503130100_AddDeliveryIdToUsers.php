<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddDeliveryIdToUsers extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('users');

        if (!$table->hasColumn('delivery_id')) {
            $table
                ->addColumn('delivery_id', 'integer', [
                    'signed' => true,
                    'null' => true,
                    'default' => null,
                    'after' => 'role_id',
                ])
                ->addIndex(['delivery_id'], [
                    'unique' => true,
                    'name' => 'uq_users_delivery_id',
                ])
                ->addForeignKey('delivery_id', 'deliveries', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                ])
                ->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('users');

        if ($table->hasForeignKey('delivery_id')) {
            $table->dropForeignKey('delivery_id')->update();
        }
        if ($table->hasIndexByName('uq_users_delivery_id')) {
            $table->removeIndexByName('uq_users_delivery_id')->update();
        }
        if ($table->hasColumn('delivery_id')) {
            $table->removeColumn('delivery_id')->update();
        }
    }
}
