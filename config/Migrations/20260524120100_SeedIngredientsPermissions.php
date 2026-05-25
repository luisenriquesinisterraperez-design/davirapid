<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class SeedIngredientsPermissions extends BaseMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // Non-admin roles: default view+create+edit, no delete.
        // (The spec §10 allows delete with cascade, but we default to a
        // non-destructive matrix; the operator can grant delete explicitly.)
        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'ingredients', 1, 1, 1, 0, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 0
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'ingredients'
               )"
        );

        // Administrador: full matrix (bypass already covers this, but we seed
        // it for consistency with the SeedProductsPermissions pattern so the
        // permissions screen reflects the actual state).
        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'ingredients', 1, 1, 1, 1, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 1
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'ingredients'
               )"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'ingredients'");
    }
}
