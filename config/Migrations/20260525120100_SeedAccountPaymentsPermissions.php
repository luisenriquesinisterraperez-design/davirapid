<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class SeedAccountPaymentsPermissions extends BaseMigration
{
    /**
     * Insert default 'account_payments' permissions per existing role.
     * Non-admin roles get view + create only — delete affects Cierre
     * Diario retroactively if a same-day abono is removed.
     */
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // Non-admin: view + create only. No edit (append-only). No delete (sensitive).
        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'account_payments', 1, 1, 0, 0, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 0
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'account_payments'
               )",
        );

        // Administrador: full matrix (bypass already covers, kept for consistency).
        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'account_payments', 1, 1, 1, 1, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 1
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'account_payments'
               )",
        );
    }

    /**
     * Remove every 'account_payments' permission row.
     */
    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'account_payments'");
    }
}
