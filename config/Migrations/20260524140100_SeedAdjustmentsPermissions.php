<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class SeedAdjustmentsPermissions extends BaseMigration
{
    /**
     * Up migration: insert default 'adjustments' permissions per existing role.
     * Append-only module: non-admin roles get view + create (no edit, no delete by default).
     */
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // Non-admin roles: view + create only. NO edit (append-only), NO delete (moves stock).
        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'adjustments', 1, 1, 0, 0, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 0
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'adjustments'
               )",
        );

        // Administrador: full matrix (bypass already covers, kept for consistency).
        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'adjustments', 1, 1, 1, 1, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 1
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'adjustments'
               )",
        );
    }

    /**
     * Down migration: remove every 'adjustments' permission row.
     */
    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'adjustments'");
    }
}
