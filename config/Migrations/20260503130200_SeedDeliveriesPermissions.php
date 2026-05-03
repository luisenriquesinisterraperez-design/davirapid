<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class SeedDeliveriesPermissions extends BaseMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'deliveries', 1, 0, 0, 0, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 0
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'deliveries'
               )"
        );

        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'deliveries', 1, 1, 1, 1, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 1
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'deliveries'
               )"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'deliveries'");
    }
}
