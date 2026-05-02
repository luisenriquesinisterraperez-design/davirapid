<?php
declare(strict_types=1);

use Authentication\PasswordHasher\DefaultPasswordHasher;
use Migrations\BaseMigration;

class SeedAdministratorRoleAndUser extends BaseMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // 1. Rol Administrador (id=1 por orden de inserción; is_admin=1 es la verdad estructural).
        $this->execute(
            "INSERT INTO roles (id, name, is_admin, created, modified)
             VALUES (1, 'Administrador', 1, '{$now}', '{$now}')"
        );

        // 2. Permisos del rol Administrador (no son necesarios por bypass; seedeados para
        //    consistencia visual cuando se vea la matriz en /roles).
        foreach (['users', 'roles'] as $module) {
            $this->execute(
                "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
                 VALUES (1, '{$module}', 1, 1, 1, 1, '{$now}', '{$now}')"
            );
        }

        // 3. Usuario admin con password hasheado en runtime (bcrypt cost del DefaultPasswordHasher actual).
        $hash = (new DefaultPasswordHasher())->hash('ca1ced0.DEV');
        $this->execute(
            "INSERT INTO users (username, name, password, role_id, active, failed_login_count, created, modified)
             VALUES ('admin', 'Administrador', '{$hash}', 1, 1, 0, '{$now}', '{$now}')"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM users WHERE username = 'admin'");
        $this->execute("DELETE FROM permissions WHERE role_id = 1");
        $this->execute("DELETE FROM roles WHERE is_admin = 1");
    }
}
