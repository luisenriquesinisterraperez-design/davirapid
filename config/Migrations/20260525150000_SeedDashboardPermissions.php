<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class SeedDashboardPermissions extends BaseMigration
{
    /**
     * Seed default 'dashboard' permissions.
     * Non-admin: view only.
     * Admin: full matrix (bypass already covers).
     */
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'dashboard', 1, 0, 0, 0, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 0
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'dashboard'
               )",
        );

        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'dashboard', 1, 1, 1, 1, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 1
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'dashboard'
               )",
        );
    }

    /**
     * Remove every 'dashboard' permission row.
     */
    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'dashboard'");
    }
}
