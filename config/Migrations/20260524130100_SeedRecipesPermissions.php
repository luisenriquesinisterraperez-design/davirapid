<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class SeedRecipesPermissions extends BaseMigration
{
    /**
     * Up migration: insert default 'recipes' permissions per existing role.
     */
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // Non-admin roles: default view+create+edit, no delete.
        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'recipes', 1, 1, 1, 0, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 0
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'recipes'
               )",
        );

        // Administrador: full matrix (bypass already covers, but kept for consistency).
        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'recipes', 1, 1, 1, 1, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 1
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'recipes'
               )",
        );
    }

    /**
     * Down migration: remove every 'recipes' permission row.
     */
    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'recipes'");
    }
}
