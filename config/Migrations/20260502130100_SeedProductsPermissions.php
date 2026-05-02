<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class SeedProductsPermissions extends BaseMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // Insert a default products permission row for every role that doesn't already have one.
        // is_admin=1 (Administrador) is excluded because it bypasses the matrix; seeding it for
        // the Administrador (id=1) is also done elsewhere on a per-need basis.
        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'products', 1, 1, 1, 0, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 0
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'products'
               )"
        );

        // Also seed for Administrador (consistent with the existing pattern in
        // SeedAdministratorRoleAndUser, where roles+users get a permissions row each
        // for matrix display, even though the bypass makes it functionally redundant).
        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'products', 1, 1, 1, 1, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 1
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'products'
               )"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'products'");
    }
}
