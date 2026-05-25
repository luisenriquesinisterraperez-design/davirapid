<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class SeedReceivablesPermissions extends BaseMigration
{
    /**
     * Insert default 'receivables' permissions per existing role.
     * Non-admin roles get view + create only (no edit/delete by default —
     * markAsPaid and delete are sensitive operations).
     */
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // Non-admin: view + create only.
        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'receivables', 1, 1, 0, 0, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 0
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'receivables'
               )",
        );

        // Administrador: full matrix (bypass already covers, kept for consistency).
        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'receivables', 1, 1, 1, 1, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 1
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'receivables'
               )",
        );
    }

    /**
     * Remove every 'receivables' permission row.
     */
    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'receivables'");
    }
}
