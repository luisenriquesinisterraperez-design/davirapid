<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class SeedAuditPermissions extends BaseMigration
{
    /**
     * Up migration: insert placeholder 'audit' permissions.
     *
     * audit is admin-only structurally (AuthorizationService::isAllowed
     * hardcodes a bypass-to-false for non-admins). The placeholder rows
     * exist so the Roles edit matrix shows the column with the toggles
     * (which will not grant access even if toggled to 1).
     */
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // Non-admin: all-zero placeholder.
        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'audit', 0, 0, 0, 0, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 0
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'audit'
               )",
        );

        // Admin: view=1 for consistency (bypass covers anyway).
        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'audit', 1, 0, 0, 0, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 1
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'audit'
               )",
        );
    }

    /**
     * Down migration: remove every 'audit' permission row.
     */
    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'audit'");
    }
}
