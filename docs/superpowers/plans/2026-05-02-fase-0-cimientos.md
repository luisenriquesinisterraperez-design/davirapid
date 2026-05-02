# Fase 0 — Cimientos · Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Importante:** Este proyecto se construye **sin tests automatizados** por decisión explícita del usuario (ver memoria `feedback_no_tests.md`). Los pasos de verificación usan `php -l`, comandos de migración, navegación manual al dev server, y herramientas estáticas (`composer cs-check`, `vendor/bin/phpstan`) en lugar de PHPUnit. **No agregar archivos de test ni invocar bake con scaffolding de tests.**

**Goal:** Construir los cimientos del panel administrativo Davi Rapid sobre el skeleton CakePHP 5.x: cleanup del boilerplate, sistema de diseño basado en Bootstrap 5.3 vendored, layout autenticado con sidebar+topbar, autenticación con lockout (5 intentos / 15 min), RBAC con bypass del Administrador, y los módulos Roles y Usuarios funcionando end-to-end.

**Architecture:** Layered (Controller → Service → Table/Entity) con servicios encapsulando lógica de negocio (`AuthorizationService`, `LoginThrottleService`, `RolePermissionService`, `UserService`). Bootstrap 5.3 themeado vía CSS custom properties (sin Sass). Permisos enforceados centralmente en `AppController::beforeFilter`. Catálogo de módulos en código (`AuthorizationService::MODULES`), permisos en DB (`permissions` table), bypass del Administrador vía bandera estructural `roles.is_admin`.

**Tech Stack:** PHP 8.2+, CakePHP 5.x, MariaDB (ya configurada en `.env`), `cakephp/authentication ^3.0`, Bootstrap 5.3 (vendored), Bootstrap Icons 1.11 (vendored), Inter font (vendored como WOFF2). Sin build step de assets.

**Spec de referencia:** `docs/superpowers/specs/2026-05-02-fase-0-cimientos-design.md`.

---

## Resumen de fases del plan

- **Fase A — Cleanup y dependencias** (Tasks 1–3): instalar el plugin de autenticación, borrar archivos del skeleton, configurar `.env`.
- **Fase B — Base de datos** (Tasks 4–7): tres migraciones con la estructura de `roles`, `permissions`, `users`.
- **Fase C — Assets y theming** (Tasks 8–9): vendorear Bootstrap, Bootstrap Icons, Inter; escribir `davirapid.css`.
- **Fase D — Modelos** (Tasks 10–12): entities y tables de Roles, Permissions, Users.
- **Fase E — Servicios core** (Tasks 13–14): `AuthorizationService` y `LoginThrottleService`.
- **Fase F — Application bootstrap** (Tasks 15–16): wiring de Authentication en `Application.php`, RBAC en `AppController`.
- **Fase G — UI compartida** (Tasks 17–21): `SidebarHelper`, layouts `default` y `login`, error templates, placeholder dashboard.
- **Fase H — Routes y seed** (Tasks 22–24): rutas finales, seed del Administrador, primer login funcional.
- **Fase I — Módulo Roles** (Tasks 25–30): controller, service, templates con matriz de permisos.
- **Fase J — Módulo Usuarios** (Tasks 31–37): controller, service, templates de CRUD + login + unlock.
- **Fase K — Smoke test final** (Task 38): verificación end-to-end del flujo completo.

---

## Fase A — Cleanup y dependencias

### Task 1: Instalar el plugin de autenticación

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock` (auto-generado)

- [ ] **Step 1: Agregar la dependencia con Composer**

```bash
cd /home/alexander/Documentos/dev/davirapid
composer require cakephp/authentication:^3.0
```

Expected: instalación exitosa, mensaje "Generating autoload files".

- [ ] **Step 2: Verificar que el plugin quedó registrado**

```bash
grep -A1 '"cakephp/authentication"' composer.json
```

Expected: línea con `"cakephp/authentication": "^3.0"` (o versión compatible) en la sección `require`.

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock
git commit -m "chore(deps): add cakephp/authentication plugin

Required for the login flow, password hashing identifier and
AuthenticationMiddleware in Fase 0."
```

---

### Task 2: Cleanup de archivos del skeleton

**Files:**
- Delete: `templates/cell/.gitkeep`
- Delete: `templates/email/html/` (carpeta completa)
- Delete: `templates/email/text/` (carpeta completa)
- Delete: `templates/layout/email/` (carpeta completa)
- Delete: `webroot/css/cake.css`
- Delete: `webroot/css/home.css`
- Delete: `webroot/css/milligram.min.css`
- Delete: `webroot/css/normalize.min.css`
- Delete: `webroot/css/fonts.css`
- Delete: `webroot/img/cake.icon.png`
- Delete: `webroot/img/cake-logo.png`
- Delete: `webroot/img/cake.logo.svg`
- Delete: `webroot/img/cake.power.gif`
- Delete: `tests/TestCase/Controller/PagesControllerTest.php`
- Delete: `tests/TestCase/ApplicationTest.php`
- Delete: `tests/schema.sql`

- [ ] **Step 1: Borrar archivos del skeleton**

```bash
cd /home/alexander/Documentos/dev/davirapid
rm -f \
    templates/cell/.gitkeep \
    webroot/css/cake.css \
    webroot/css/home.css \
    webroot/css/milligram.min.css \
    webroot/css/normalize.min.css \
    webroot/css/fonts.css \
    webroot/img/cake.icon.png \
    webroot/img/cake-logo.png \
    webroot/img/cake.logo.svg \
    webroot/img/cake.power.gif \
    tests/TestCase/Controller/PagesControllerTest.php \
    tests/TestCase/ApplicationTest.php \
    tests/schema.sql

rm -rf templates/email/html templates/email/text templates/layout/email
```

- [ ] **Step 2: Verificar que solo quedaron los archivos esperados**

```bash
ls webroot/css/ webroot/img/ templates/email/ templates/layout/ 2>&1
ls tests/TestCase/ 2>&1
```

Expected:
- `webroot/css/` queda vacío (los assets nuevos los crearemos en Task 8).
- `webroot/img/` queda vacío.
- `templates/email/` queda vacío o no existe.
- `templates/layout/` contiene `ajax.php`, `default.php`, `error.php` (los modificaremos después).
- `tests/TestCase/` solo contiene `Controller/Component/.gitkeep`, `Model/Behavior/.gitkeep`, `View/Helper/.gitkeep`.

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "chore: remove CakePHP skeleton boilerplate

Removes the welcome page assets, default email templates and skeleton
test files. The CSS and image directories will be repopulated with
Bootstrap 5.3, Bootstrap Icons and Inter fonts in a later task."
```

---

### Task 3: Agregar `APP_FULL_BASE_URL` al `.env`

**Files:**
- Modify: `config/.env`

- [ ] **Step 1: Verificar que la variable no exista ya**

```bash
grep -E '^APP_FULL_BASE_URL' /home/alexander/Documentos/dev/davirapid/config/.env || echo "not present"
```

Expected: "not present".

- [ ] **Step 2: Agregar la línea al final de `.env`**

Editar `config/.env` y agregar (al final del archivo, una línea nueva):
```
APP_FULL_BASE_URL=http://localhost:8765
```

- [ ] **Step 3: Verificar**

```bash
grep -E '^APP_FULL_BASE_URL' /home/alexander/Documentos/dev/davirapid/config/.env
```

Expected: `APP_FULL_BASE_URL=http://localhost:8765`.

- [ ] **Step 4: Commit**

`config/.env` está en `.gitignore` (es git-ignored para no exponer credenciales). **No** se commitea. Verificarlo:

```bash
git check-ignore config/.env
```

Expected: `config/.env` (la línea muestra que está ignorado).

Si por alguna razón `.env` no estuviera ignorado, abortar y avisar al usuario; no se debe commitear este archivo.

---

## Fase B — Base de datos

### Task 4: Migración `CreateRoles`

**Files:**
- Create: `config/Migrations/20260502120000_CreateRoles.php`

- [ ] **Step 1: Crear el archivo de migración**

Crear `config/Migrations/20260502120000_CreateRoles.php` con este contenido:

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateRoles extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('roles')) {
            return;
        }

        $this->table('roles', [
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('name', 'string', ['limit' => 60, 'null' => false])
            ->addColumn('is_admin', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['name'], ['unique' => true, 'name' => 'uniq_roles_name'])
            ->create();
    }

    public function down(): void
    {
        $this->table('roles')->drop()->save();
    }
}
```

- [ ] **Step 2: Verificar la sintaxis PHP**

```bash
php -l /home/alexander/Documentos/dev/davirapid/config/Migrations/20260502120000_CreateRoles.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Verificar que la migración se reconoce (status sin correr)**

```bash
cd /home/alexander/Documentos/dev/davirapid
php bin/cake.php migrations status
```

Expected: la migración `CreateRoles` aparece como `down` (no aplicada todavía).

- [ ] **Step 4: Commit**

```bash
git add config/Migrations/20260502120000_CreateRoles.php
git commit -m "feat(db): add CreateRoles migration

roles table with name (unique) and is_admin structural flag.
The is_admin flag identifies the Administrator role for the bypass
in AuthorizationService — exactly one row will have is_admin=1."
```

---

### Task 5: Migración `CreatePermissions`

**Files:**
- Create: `config/Migrations/20260502120100_CreatePermissions.php`

- [ ] **Step 1: Crear el archivo**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreatePermissions extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('permissions')) {
            return;
        }

        $this->table('permissions', [
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('role_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('module', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('can_view', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('can_create', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('can_edit', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('can_delete', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['role_id', 'module'], [
                'unique' => true,
                'name' => 'uniq_permissions_role_module',
            ])
            ->addForeignKey('role_id', 'roles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_permissions_role',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('permissions')->drop()->save();
    }
}
```

- [ ] **Step 2: Verificar sintaxis**

```bash
php -l /home/alexander/Documentos/dev/davirapid/config/Migrations/20260502120100_CreatePermissions.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add config/Migrations/20260502120100_CreatePermissions.php
git commit -m "feat(db): add CreatePermissions migration

permissions table with one row per (role_id, module) pair.
ON DELETE CASCADE: deleting a role removes its permissions.
module is a free string — the catalog lives in
AuthorizationService::MODULES, not in DB."
```

---

### Task 6: Migración `CreateUsers`

**Files:**
- Create: `config/Migrations/20260502120200_CreateUsers.php`

- [ ] **Step 1: Crear el archivo**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateUsers extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('users')) {
            return;
        }

        $this->table('users', [
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('username', 'string', ['limit' => 60, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('password', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('role_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('failed_login_count', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('locked_until', 'datetime', ['null' => true])
            ->addColumn('last_login_at', 'datetime', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['username'], ['unique' => true, 'name' => 'uniq_users_username'])
            ->addIndex(['locked_until'], ['name' => 'idx_users_locked_until'])
            ->addForeignKey('role_id', 'roles', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_users_role',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('users')->drop()->save();
    }
}
```

- [ ] **Step 2: Verificar sintaxis**

```bash
php -l /home/alexander/Documentos/dev/davirapid/config/Migrations/20260502120200_CreateUsers.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add config/Migrations/20260502120200_CreateUsers.php
git commit -m "feat(db): add CreateUsers migration

users table with username (unique), bcrypt password,
role_id (NOT NULL, ON DELETE RESTRICT — a role with users
cannot be deleted), and lockout columns failed_login_count +
locked_until. delivery_id is intentionally NOT included in
Fase 0 — it will be added in Fase 1 when the deliveries
table is created."
```

---

### Task 7: Correr las migraciones y verificar el schema

**Files:** N/A (solo ejecución)

- [ ] **Step 1: Correr migrations migrate**

```bash
cd /home/alexander/Documentos/dev/davirapid
php bin/cake.php migrations migrate
```

Expected: las 3 migraciones se aplican sin errores. Output incluye `migrated` para cada una.

- [ ] **Step 2: Verificar las tablas en la DB**

```bash
php bin/cake.php migrations status
```

Expected: `roles`, `permissions`, `users` aparecen como `up`.

- [ ] **Step 3: Inspeccionar el schema (opcional pero recomendado)**

Conectarse al MariaDB de easypanel (con las credenciales del `.env`) y correr:

```sql
SHOW TABLES;
DESCRIBE roles;
DESCRIBE permissions;
DESCRIBE users;
```

Expected: tres tablas con las columnas y FKs definidas en el spec §4.

- [ ] **Step 4: No hay commit en este task — solo ejecución de comandos**

Los archivos de migración ya quedaron commiteados en Tasks 4–6.

---

## Fase C — Assets y theming

### Task 8: Vendorear Bootstrap, Bootstrap Icons y la fuente Inter

**Files:**
- Create: `webroot/css/vendor/bootstrap.min.css`
- Create: `webroot/css/vendor/bootstrap-icons.min.css`
- Create: `webroot/js/vendor/bootstrap.bundle.min.js`
- Create: `webroot/fonts/bootstrap-icons.woff2`
- Create: `webroot/fonts/inter-regular.woff2`
- Create: `webroot/fonts/inter-medium.woff2`
- Create: `webroot/fonts/inter-semibold.woff2`
- Create: `webroot/fonts/inter-bold.woff2`

- [ ] **Step 1: Crear los directorios destino**

```bash
cd /home/alexander/Documentos/dev/davirapid
mkdir -p webroot/css/vendor webroot/js/vendor webroot/fonts
```

- [ ] **Step 2: Descargar Bootstrap 5.3.x**

```bash
cd /home/alexander/Documentos/dev/davirapid
curl -sSfL -o webroot/css/vendor/bootstrap.min.css \
    https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css
curl -sSfL -o webroot/js/vendor/bootstrap.bundle.min.js \
    https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js
```

Verificar tamaños razonables:
```bash
ls -lh webroot/css/vendor/bootstrap.min.css webroot/js/vendor/bootstrap.bundle.min.js
```

Expected: `bootstrap.min.css` ~230 KB, `bootstrap.bundle.min.js` ~80 KB.

- [ ] **Step 3: Descargar Bootstrap Icons 1.11.x**

```bash
cd /home/alexander/Documentos/dev/davirapid
curl -sSfL -o webroot/css/vendor/bootstrap-icons.min.css \
    https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css
curl -sSfL -o webroot/fonts/bootstrap-icons.woff2 \
    https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2
```

- [ ] **Step 4: Ajustar el `bootstrap-icons.min.css` para que apunte al woff2 local**

El CSS por defecto referencia `./fonts/bootstrap-icons.woff2`. Como nuestro layout es `webroot/css/vendor/*.css` apuntando a `webroot/fonts/*.woff2`, hay que parchearlo:

```bash
cd /home/alexander/Documentos/dev/davirapid
sed -i 's|url("\./fonts/bootstrap-icons\.woff2|url("/fonts/bootstrap-icons.woff2|g' \
    webroot/css/vendor/bootstrap-icons.min.css
sed -i 's|url("\./fonts/bootstrap-icons\.woff|url("/fonts/bootstrap-icons.woff|g' \
    webroot/css/vendor/bootstrap-icons.min.css
```

Verificar:
```bash
grep -o 'url("[^"]*"' webroot/css/vendor/bootstrap-icons.min.css | head -5
```

Expected: las URLs del woff/woff2 ahora son rutas absolutas `/fonts/bootstrap-icons.woff2` (sin `./fonts/`).

- [ ] **Step 5: Descargar Inter (4 pesos)**

Usamos los WOFF2 oficiales del repo `rsms/inter` (CDN de jsdelivr):

```bash
cd /home/alexander/Documentos/dev/davirapid
curl -sSfL -o webroot/fonts/inter-regular.woff2 \
    https://cdn.jsdelivr.net/npm/@fontsource/inter@5.0.18/files/inter-latin-400-normal.woff2
curl -sSfL -o webroot/fonts/inter-medium.woff2 \
    https://cdn.jsdelivr.net/npm/@fontsource/inter@5.0.18/files/inter-latin-500-normal.woff2
curl -sSfL -o webroot/fonts/inter-semibold.woff2 \
    https://cdn.jsdelivr.net/npm/@fontsource/inter@5.0.18/files/inter-latin-600-normal.woff2
curl -sSfL -o webroot/fonts/inter-bold.woff2 \
    https://cdn.jsdelivr.net/npm/@fontsource/inter@5.0.18/files/inter-latin-700-normal.woff2
```

Verificar:
```bash
ls -lh webroot/fonts/
```

Expected: 5 archivos `.woff2` (4 de Inter + bootstrap-icons), cada uno entre 30 KB y 130 KB.

- [ ] **Step 6: Commit**

```bash
git add webroot/css/vendor/ webroot/js/vendor/ webroot/fonts/
git commit -m "feat(assets): vendor Bootstrap 5.3, Bootstrap Icons and Inter

Vendored as static files in webroot/ — no build step required.
The icon font path inside bootstrap-icons.min.css was patched
to /fonts/bootstrap-icons.woff2 to match our directory layout."
```

---

### Task 9: Crear `davirapid.css` con tokens y overrides

**Files:**
- Create: `webroot/css/davirapid.css`

- [ ] **Step 1: Crear el archivo con el contenido completo**

Crear `webroot/css/davirapid.css` con este contenido íntegro:

```css
/* ============================================================
   Davi Rapid Admin · Theme + tokens + componentes propios
   Carga DESPUÉS de bootstrap.min.css y bootstrap-icons.min.css.
   ============================================================ */

/* ---------- 1. Inter font-face (font-display: swap) ---------- */
@font-face {
    font-family: "Inter";
    font-style: normal;
    font-weight: 400;
    font-display: swap;
    src: url("/fonts/inter-regular.woff2") format("woff2");
}
@font-face {
    font-family: "Inter";
    font-style: normal;
    font-weight: 500;
    font-display: swap;
    src: url("/fonts/inter-medium.woff2") format("woff2");
}
@font-face {
    font-family: "Inter";
    font-style: normal;
    font-weight: 600;
    font-display: swap;
    src: url("/fonts/inter-semibold.woff2") format("woff2");
}
@font-face {
    font-family: "Inter";
    font-style: normal;
    font-weight: 700;
    font-display: swap;
    src: url("/fonts/inter-bold.woff2") format("woff2");
}

/* ---------- 2. Tokens (Bootstrap CSS vars + propios) ---------- */
:root {
    /* Bootstrap overrides */
    --bs-primary: #E63027;
    --bs-primary-rgb: 230, 48, 39;
    --bs-secondary: #F26B1F;
    --bs-secondary-rgb: 242, 107, 31;
    --bs-success: #22A06B;
    --bs-success-rgb: 34, 160, 107;
    --bs-warning: #F2A516;
    --bs-warning-rgb: 242, 165, 22;
    --bs-danger: #D32F2F;
    --bs-danger-rgb: 211, 47, 47;
    --bs-info: #2A6FDB;
    --bs-info-rgb: 42, 111, 219;
    --bs-body-bg: #FAFAFA;
    --bs-body-color: #1F1F1F;
    --bs-border-color: #E5E5E5;
    --bs-border-radius: .5rem;
    --bs-border-radius-sm: .25rem;
    --bs-border-radius-lg: .75rem;
    --bs-border-radius-xl: 1rem;
    --bs-font-sans-serif: "Inter", system-ui, -apple-system, sans-serif;
    --bs-body-font-family: var(--bs-font-sans-serif);
    --bs-body-font-size: .9375rem; /* 15px */
    --bs-body-font-weight: 400;
    --bs-link-color: #E63027;
    --bs-link-hover-color: #B32420;

    /* Davi Rapid propios */
    --dr-primary-soft: #FDE7E5;
    --dr-secondary-soft: #FDEBDD;
    --dr-success-soft: #DDF2E8;
    --dr-warning-soft: #FCEDC9;
    --dr-danger-soft: #FADBDB;
    --dr-info-soft: #D9E5F8;
    --dr-tertiary: #FFB627;
    --dr-tertiary-soft: #FFF1CE;
    --dr-surface: #FFFFFF;
    --dr-surface-alt: #F4F4F4;
    --dr-overlay: rgba(0, 0, 0, .55);
    --dr-shadow-2: 0 4px 12px rgba(0, 0, 0, .08);
    --dr-shadow-3: 0 12px 32px rgba(0, 0, 0, .12);
    --dr-control-h: 40px;
    --dr-sidebar-w: 248px;
    --dr-topbar-h: 64px;
    --dr-content-max: 1440px;
    --dr-text-muted: #6B6B6B;
}

/* ---------- 3. Overrides de componentes Bootstrap ---------- */

body {
    font-family: var(--bs-font-sans-serif);
    font-size: var(--bs-body-font-size);
    color: var(--bs-body-color);
    background-color: var(--bs-body-bg);
}

.btn {
    height: var(--dr-control-h);
    padding-inline: 1rem;
    font-weight: 500;
    line-height: 1;
    border-radius: var(--bs-border-radius);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .5rem;
}
.btn-sm { height: 32px; padding-inline: .75rem; font-size: .8125rem; }
.btn-icon {
    height: 36px;
    width: 36px;
    padding: 0;
    border-radius: var(--bs-border-radius);
}

.form-control,
.form-select {
    height: var(--dr-control-h);
    border-radius: var(--bs-border-radius);
    border-color: var(--bs-border-color);
    font-size: var(--bs-body-font-size);
}
.form-control:focus,
.form-select:focus {
    border-color: var(--bs-primary);
    box-shadow: 0 0 0 .2rem rgba(var(--bs-primary-rgb), .25);
}
.form-label { font-weight: 500; margin-bottom: .25rem; }

.table {
    --bs-table-bg: var(--dr-surface);
    margin-bottom: 0;
}
.table > thead > tr > th {
    background-color: var(--dr-surface-alt);
    font-weight: 600;
    font-size: .75rem;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: var(--dr-text-muted);
    border-bottom-color: var(--bs-border-color);
}
.table > tbody > tr > td {
    height: 56px;
    vertical-align: middle;
    border-color: var(--bs-border-color);
}
.table > tbody > tr:hover { background-color: var(--dr-surface-alt); }

.alert {
    border: 0;
    border-radius: var(--bs-border-radius);
}
.alert-success { background-color: var(--dr-success-soft); color: #145C3D; }
.alert-warning { background-color: var(--dr-warning-soft); color: #6B4602; }
.alert-danger,
.alert-error  { background-color: var(--dr-danger-soft);  color: #7A1818; }
.alert-info   { background-color: var(--dr-info-soft);    color: #16407E; }

.breadcrumb { margin: 0; font-size: .875rem; }
.breadcrumb-item + .breadcrumb-item::before { content: "/"; color: var(--dr-text-muted); }
.breadcrumb-item.active { color: var(--bs-body-color); font-weight: 500; }

.pagination .page-link {
    color: var(--bs-body-color);
    border-color: var(--bs-border-color);
}
.pagination .page-item.active .page-link {
    background-color: var(--bs-primary);
    border-color: var(--bs-primary);
}

.badge { font-weight: 500; padding: .35em .7em; border-radius: 9999px; }
.badge-soft-primary   { background-color: var(--dr-primary-soft);   color: var(--bs-primary); }
.badge-soft-success   { background-color: var(--dr-success-soft);   color: #145C3D; }
.badge-soft-warning   { background-color: var(--dr-warning-soft);   color: #6B4602; }
.badge-soft-danger    { background-color: var(--dr-danger-soft);    color: var(--bs-danger); }
.badge-soft-secondary { background-color: var(--dr-surface-alt);    color: var(--dr-text-muted); }

/* ---------- 4. Componentes propios ---------- */

.dr-app-shell {
    display: grid;
    grid-template-columns: var(--dr-sidebar-w) 1fr;
    grid-template-rows: var(--dr-topbar-h) 1fr;
    grid-template-areas:
        "sidebar topbar"
        "sidebar content";
    min-height: 100vh;
}

.dr-sidebar {
    grid-area: sidebar;
    background-color: var(--dr-surface);
    border-right: 1px solid var(--bs-border-color);
    padding: 1.5rem 0;
    display: flex;
    flex-direction: column;
}
.dr-sidebar-brand {
    padding: 0 1.5rem 1.5rem;
    font-weight: 700;
    font-size: 1.125rem;
    color: var(--bs-primary);
    display: flex;
    align-items: center;
    gap: .5rem;
}
.dr-sidebar-nav { display: flex; flex-direction: column; gap: .125rem; padding: 0 .75rem; }
.dr-sidebar-item {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .625rem .875rem;
    border-radius: var(--bs-border-radius);
    color: var(--bs-body-color);
    text-decoration: none;
    font-weight: 500;
    transition: background-color .15s ease;
}
.dr-sidebar-item:hover { background-color: var(--dr-surface-alt); color: var(--bs-body-color); }
.dr-sidebar-item.active { background-color: var(--dr-primary-soft); color: var(--bs-primary); }
.dr-sidebar-item .bi { font-size: 1.125rem; }

.dr-topbar {
    grid-area: topbar;
    background-color: var(--dr-surface);
    border-bottom: 1px solid var(--bs-border-color);
    padding: 0 2rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}
.dr-topbar-user .dropdown-toggle::after { display: none; }

.dr-content {
    grid-area: content;
    padding: 2rem;
    max-width: var(--dr-content-max);
    width: 100%;
}

.dr-page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.5rem;
    gap: 1rem;
}
.dr-page-title { font-size: 1.5rem; font-weight: 600; margin: 0; }

.dr-login-shell {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem;
    background-color: var(--bs-body-bg);
}
.dr-login-card {
    background-color: var(--dr-surface);
    border-radius: var(--bs-border-radius-lg);
    box-shadow: var(--dr-shadow-2);
    padding: 2.5rem;
    width: 100%;
    max-width: 400px;
}
.dr-login-brand {
    text-align: center;
    font-weight: 700;
    font-size: 1.5rem;
    color: var(--bs-primary);
    margin-bottom: 2rem;
}

.dr-permission-matrix th { text-align: center; }
.dr-permission-matrix td.dr-module-name { font-weight: 500; }
.dr-permission-matrix td.dr-perm-cell { text-align: center; }
.dr-permission-matrix .form-check {
    display: inline-flex;
    margin: 0;
    padding: 0;
    min-height: auto;
}
.dr-permission-matrix .form-check-input {
    margin: 0;
    width: 1.125rem;
    height: 1.125rem;
}

.dr-error-shell {
    min-height: 60vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 3rem 1rem;
}
.dr-error-icon { font-size: 3rem; color: var(--dr-text-muted); margin-bottom: 1rem; }
```

- [ ] **Step 2: Verificar tamaño y validez**

```bash
ls -lh /home/alexander/Documentos/dev/davirapid/webroot/css/davirapid.css
```

Expected: archivo creado, ~6–8 KB.

- [ ] **Step 3: Commit**

```bash
git add webroot/css/davirapid.css
git commit -m "feat(design): add davirapid theme stylesheet

Layered on top of Bootstrap 5.3 — overrides Bootstrap CSS vars
with the Davi Rapid token set from DESIGN.md (brand red as
--bs-primary, Inter typography, 8/12/16px radii, 40px control
height) and adds the layout primitives (.dr-app-shell, sidebar,
topbar, login shell, permission matrix). Component-specific
classes for Pedidos/Dashboard are intentionally deferred to
their respective phases."
```

---

## Fase D — Modelos

### Task 10: Crear Role entity, RolesTable, Permission entity, PermissionsTable

**Files:**
- Create: `src/Model/Entity/Role.php`
- Create: `src/Model/Entity/Permission.php`
- Create: `src/Model/Table/RolesTable.php`
- Create: `src/Model/Table/PermissionsTable.php`

- [ ] **Step 1: Crear `src/Model/Entity/Role.php`**

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class Role extends Entity
{
    protected array $_accessible = [
        'name' => true,
        'permissions' => true,
        // is_admin is intentionally NOT accessible — only set via seed.
    ];

    public function isAdministrator(): bool
    {
        return (bool)$this->is_admin;
    }
}
```

- [ ] **Step 2: Crear `src/Model/Entity/Permission.php`**

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class Permission extends Entity
{
    protected array $_accessible = [
        'role_id' => true,
        'module' => true,
        'can_view' => true,
        'can_create' => true,
        'can_edit' => true,
        'can_delete' => true,
    ];
}
```

- [ ] **Step 3: Crear `src/Model/Table/RolesTable.php`**

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Service\AuthorizationService;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class RolesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('roles');
        $this->setPrimaryKey('id');
        $this->setDisplayField('name');
        $this->addBehavior('Timestamp');

        $this->hasMany('Permissions', [
            'foreignKey' => 'role_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('Users', [
            'foreignKey' => 'role_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->notEmptyString('name', 'El nombre del rol es requerido')
            ->lengthBetween('name', [2, 60], 'El nombre debe tener entre 2 y 60 caracteres')
            ->boolean('is_admin');
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->isUnique(['name'], 'Ya existe un rol con ese nombre'),
            'uniqueName'
        );
        return $rules;
    }

    /**
     * Roles que pueden asignarse a usuarios — excluye el Administrador.
     */
    public function findAssignable(SelectQuery $query): SelectQuery
    {
        return $query->where(['Roles.is_admin' => false])->orderAsc('Roles.name');
    }
}
```

- [ ] **Step 4: Crear `src/Model/Table/PermissionsTable.php`**

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Service\AuthorizationService;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class PermissionsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('permissions');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Roles', [
            'foreignKey' => 'role_id',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->integer('role_id')
            ->notEmptyString('role_id', 'El rol es requerido')
            ->notEmptyString('module', 'El módulo es requerido')
            ->inList('module', array_keys(AuthorizationService::MODULES), 'Módulo desconocido')
            ->boolean('can_view')
            ->boolean('can_create')
            ->boolean('can_edit')
            ->boolean('can_delete');
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->isUnique(['role_id', 'module'], 'Ya existe un registro de permisos para este módulo'),
            'uniqueRoleModule'
        );
        $rules->add($rules->existsIn(['role_id'], 'Roles'));
        return $rules;
    }
}
```

- [ ] **Step 5: Verificar sintaxis de los 4 archivos**

```bash
cd /home/alexander/Documentos/dev/davirapid
for f in src/Model/Entity/Role.php src/Model/Entity/Permission.php \
         src/Model/Table/RolesTable.php src/Model/Table/PermissionsTable.php; do
    php -l "$f"
done
```

Expected: `No syntax errors detected` para los 4. (`PermissionsTable` referencia `AuthorizationService` que se crea en Task 13 — el `php -l` no chequea autoload, así que pasa.)

- [ ] **Step 6: Commit**

```bash
git add src/Model/Entity/Role.php src/Model/Entity/Permission.php \
        src/Model/Table/RolesTable.php src/Model/Table/PermissionsTable.php
git commit -m "feat(model): add Role and Permission entities and tables

RolesTable: hasMany Permissions (cascade delete), hasMany Users
(no cascade — DB enforces RESTRICT). findAssignable excludes the
Administrator role for user-creation selects.
PermissionsTable: validates module against AuthorizationService::MODULES;
unique key (role_id, module) at app + DB level.
The is_admin field is not in Role::\$_accessible — it is only ever
set by the seed migration."
```

---

### Task 11: Crear User entity y UsersTable

**Files:**
- Create: `src/Model/Entity/User.php`
- Create: `src/Model/Table/UsersTable.php`

- [ ] **Step 1: Crear `src/Model/Entity/User.php`**

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Authentication\IdentityInterface;
use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\I18n\DateTime;
use Cake\ORM\Entity;
use ArrayAccess;

class User extends Entity implements IdentityInterface, ArrayAccess
{
    protected array $_accessible = [
        'username' => true,
        'name' => true,
        'password' => true,
        'role_id' => true,
        'active' => true,
        // failed_login_count, locked_until, last_login_at: NOT accessible —
        // only modified by services (LoginThrottleService, UserService).
        'role' => true,
    ];

    protected array $_hidden = ['password'];

    /**
     * Hashes the password automatically. Returns null when the value is empty
     * so that patchEntity() in edit() does not overwrite the existing hash
     * with an empty string.
     */
    protected function _setPassword(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (new DefaultPasswordHasher())->hash($value);
    }

    public function isLocked(): bool
    {
        if ($this->locked_until === null) {
            return false;
        }
        return $this->locked_until > DateTime::now();
    }

    public function isAdministrator(): bool
    {
        return !empty($this->role) && (bool)$this->role->is_admin;
    }

    /* -------- Authentication\IdentityInterface -------- */

    public function getIdentifier(): mixed
    {
        return $this->id;
    }

    public function getOriginalData(): static
    {
        return $this;
    }
}
```

- [ ] **Step 2: Crear `src/Model/Table/UsersTable.php`**

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class UsersTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('users');
        $this->setPrimaryKey('id');
        $this->setDisplayField('username');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Roles', [
            'foreignKey' => 'role_id',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->notEmptyString('username', 'El usuario es requerido')
            ->lengthBetween('username', [3, 60], 'El usuario debe tener entre 3 y 60 caracteres')
            ->add('username', 'format', [
                'rule' => ['custom', '/^[a-zA-Z0-9._-]+$/'],
                'message' => 'Solo letras, números, punto, guion bajo o guion',
            ])
            ->notEmptyString('name', 'El nombre es requerido')
            ->lengthBetween('name', [2, 120], 'El nombre debe tener entre 2 y 120 caracteres')
            ->integer('role_id')
            ->notEmptyString('role_id', 'El rol es requerido')
            ->boolean('active')
            ->add('password', 'minLength', [
                'rule' => ['minLength', 8],
                'message' => 'La contraseña debe tener al menos 8 caracteres',
                'on' => function (array $context): bool {
                    return !empty($context['data']['password']);
                },
            ]);
    }

    public function validationCreate(Validator $validator): Validator
    {
        $validator = $this->validationDefault($validator);
        return $validator->notEmptyString('password', 'La contraseña es requerida');
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->isUnique(['username'], 'Ya existe un usuario con ese nombre'),
            'uniqueUsername'
        );
        $rules->add($rules->existsIn(['role_id'], 'Roles'));
        return $rules;
    }

    /**
     * Custom finder used exclusively by the Authentication identifier:
     * filters out inactive users and eager-loads the Role.
     */
    public function findAuth(SelectQuery $query): SelectQuery
    {
        return $query
            ->where(['Users.active' => true])
            ->contain(['Roles']);
    }
}
```

- [ ] **Step 3: Verificar sintaxis**

```bash
cd /home/alexander/Documentos/dev/davirapid
php -l src/Model/Entity/User.php
php -l src/Model/Table/UsersTable.php
```

Expected: `No syntax errors detected` para ambos.

- [ ] **Step 4: Commit**

```bash
git add src/Model/Entity/User.php src/Model/Table/UsersTable.php
git commit -m "feat(model): add User entity and UsersTable

User implements Authentication\IdentityInterface and auto-hashes
passwords via the _setPassword setter (empty value returns null
so edit() can skip changing the hash).
UsersTable: separate validationDefault (password optional) and
validationCreate (password required). Username regex restricts
to safe URL-friendly characters. findAuth is consumed by the
Authentication ORM resolver to find the user during login."
```

---

## Fase E — Servicios core

### Task 12: Crear `AuthorizationService`

**Files:**
- Create: `src/Service/AuthorizationService.php`

- [ ] **Step 1: Crear el directorio si no existe**

```bash
mkdir -p /home/alexander/Documentos/dev/davirapid/src/Service
```

- [ ] **Step 2: Crear el archivo**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\Locator\LocatorAwareTrait;

final class AuthorizationService
{
    use LocatorAwareTrait;

    /**
     * Catálogo de módulos del sistema. Cada fase agrega entradas acá.
     * La clave es el identificador interno (snake_case sin guión bajo);
     * el valor es la etiqueta visible en la UI.
     */
    public const MODULES = [
        'roles' => 'Roles',
        'users' => 'Usuarios',
    ];

    /** Acciones de permiso almacenadas en DB. */
    public const ACTIONS = ['view', 'create', 'edit', 'delete'];

    /** @var array<int, array<string, array<string, bool>>> Cache por proceso, no persistente. */
    private array $cache = [];

    /**
     * Determina si el usuario tiene permiso para ejecutar la acción dada en el módulo dado.
     *
     * @param array $user Identidad como array (debe contener 'role_id' y 'role.is_admin').
     */
    public function isAllowed(array $user, string $module, string $action): bool
    {
        // 1. Bypass del Administrador.
        if (!empty($user['role']['is_admin'])) {
            return true;
        }

        // 2. Módulo desconocido = denegado.
        if (!array_key_exists($module, self::MODULES)) {
            return false;
        }

        // 3. Roles solo lo gestiona el Administrador (defensa más allá del bypass).
        if ($module === 'roles') {
            return false;
        }

        $perm = $this->loadPermissionsFor((int)($user['role_id'] ?? 0))[$module] ?? null;
        if ($perm === null) {
            return false;
        }

        return match ($action) {
            'view' => (bool)$perm['can_view'],
            'create' => (bool)$perm['can_create'],
            'edit' => (bool)$perm['can_edit'],
            'delete' => (bool)$perm['can_delete'],
            default => false,
        };
    }

    /**
     * Devuelve la matriz completa de permisos para el usuario.
     *
     * @return array<string, array<string, bool>> ['users' => ['view'=>bool, 'create'=>bool, ...], ...]
     */
    public function matrixFor(array $user): array
    {
        $matrix = [];
        foreach (array_keys(self::MODULES) as $module) {
            $matrix[$module] = [];
            foreach (self::ACTIONS as $action) {
                $matrix[$module][$action] = $this->isAllowed($user, $module, $action);
            }
        }
        return $matrix;
    }

    /**
     * Lee de DB todos los permisos del rol (cacheado por proceso).
     *
     * @return array<string, array<string, mixed>> ['users' => ['can_view'=>1, ...], ...]
     */
    private function loadPermissionsFor(int $roleId): array
    {
        if ($roleId <= 0) {
            return [];
        }
        if (isset($this->cache[$roleId])) {
            return $this->cache[$roleId];
        }

        $permissions = $this->fetchTable('Permissions')
            ->find()
            ->where(['Permissions.role_id' => $roleId])
            ->all()
            ->toArray();

        $byModule = [];
        foreach ($permissions as $p) {
            $byModule[$p->module] = [
                'can_view' => $p->can_view,
                'can_create' => $p->can_create,
                'can_edit' => $p->can_edit,
                'can_delete' => $p->can_delete,
            ];
        }

        $this->cache[$roleId] = $byModule;
        return $byModule;
    }
}
```

- [ ] **Step 3: Verificar sintaxis**

```bash
php -l /home/alexander/Documentos/dev/davirapid/src/Service/AuthorizationService.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add src/Service/AuthorizationService.php
git commit -m "feat(rbac): add AuthorizationService

Catálogo de módulos en MODULES, acciones en ACTIONS. isAllowed
implementa: (1) bypass del Administrador, (2) deny para módulos
no catalogados, (3) deny estricto para 'roles' a no-administradores,
(4) lookup en DB cacheado por proceso. matrixFor expone la matriz
completa para alimentar el sidebar y la UI de Roles."
```

---

### Task 13: Crear `LoginThrottleService`

**Files:**
- Create: `src/Service/LoginThrottleService.php`

- [ ] **Step 1: Crear el archivo**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;

final class LoginThrottleService
{
    use LocatorAwareTrait;

    public const MAX_ATTEMPTS = 5;
    public const LOCKOUT_MIN = 15;

    /**
     * Llamado ANTES de validar credenciales.
     *
     * @return array|null Null si el login puede proceder, o ['locked'=>true,'minutes_left'=>int] si está bloqueado.
     */
    public function checkLockout(string $username): ?array
    {
        if ($username === '') {
            return null;
        }

        $user = $this->fetchTable('Users')
            ->find()
            ->where(['Users.username' => $username])
            ->first();

        if ($user === null || $user->locked_until === null) {
            return null;
        }

        $now = DateTime::now();
        if ($user->locked_until > $now) {
            $minutesLeft = (int)ceil(($user->locked_until->getTimestamp() - $now->getTimestamp()) / 60);
            return ['locked' => true, 'minutes_left' => max(1, $minutesLeft)];
        }

        // El bloqueo cumplió: reset on-demand.
        $user->failed_login_count = 0;
        $user->locked_until = null;
        $this->fetchTable('Users')->saveOrFail($user);
        return null;
    }

    /**
     * Llamado tras un login exitoso. Resetea contadores y registra last_login_at.
     */
    public function recordSuccess(int $userId): void
    {
        $usersTable = $this->fetchTable('Users');
        $user = $usersTable->get($userId);
        $user->failed_login_count = 0;
        $user->locked_until = null;
        $user->last_login_at = DateTime::now();
        $usersTable->saveOrFail($user);

        Log::info('User {username} logged in', [
            'username' => $user->username,
            'scope' => ['auth'],
        ]);
    }

    /**
     * Llamado tras un login fallido.
     *
     * @return array ['attempts_left' => int|null, 'locked_until' => DateTime|null]
     *               attempts_left es null cuando el username no existe (no se filtra al atacante).
     */
    public function recordFailure(string $username): array
    {
        if ($username === '') {
            return ['attempts_left' => null, 'locked_until' => null];
        }

        $usersTable = $this->fetchTable('Users');
        $user = $usersTable->find()->where(['Users.username' => $username])->first();

        if ($user === null) {
            Log::info('Login attempt for unknown username {username}', [
                'username' => $username,
                'scope' => ['auth'],
            ]);
            return ['attempts_left' => null, 'locked_until' => null];
        }

        $user->failed_login_count = (int)$user->failed_login_count + 1;

        if ($user->failed_login_count >= self::MAX_ATTEMPTS) {
            $user->locked_until = DateTime::now()->modify('+' . self::LOCKOUT_MIN . ' minutes');
            Log::warning('Account locked for {username} until {until}', [
                'username' => $user->username,
                'until' => $user->locked_until->format('Y-m-d H:i:s'),
                'scope' => ['auth'],
            ]);
        } else {
            Log::warning('Failed login for {username} ({attempts}/{max})', [
                'username' => $user->username,
                'attempts' => $user->failed_login_count,
                'max' => self::MAX_ATTEMPTS,
                'scope' => ['auth'],
            ]);
        }

        $usersTable->saveOrFail($user);

        return [
            'attempts_left' => max(0, self::MAX_ATTEMPTS - (int)$user->failed_login_count),
            'locked_until' => $user->locked_until,
        ];
    }
}
```

- [ ] **Step 2: Verificar sintaxis**

```bash
php -l /home/alexander/Documentos/dev/davirapid/src/Service/LoginThrottleService.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add src/Service/LoginThrottleService.php
git commit -m "feat(auth): add LoginThrottleService

Implementa la lógica de lockout del spec §10:
- checkLockout: resetea contadores on-demand cuando el bloqueo cumplió.
- recordSuccess: limpia contadores + last_login_at.
- recordFailure: incrementa; al llegar a 5 setea locked_until +15 min.
Usernames inexistentes no tocan DB (sin filas falsas) y devuelven
attempts_left=null para no filtrar la diferencia al atacante."
```

---

## Fase F — Application bootstrap

### Task 14: Modificar `Application.php` — middleware y AuthenticationServiceProviderInterface

**Files:**
- Modify: `src/Application.php`

- [ ] **Step 1: Leer el archivo actual para entender qué cambiar**

```bash
cat /home/alexander/Documentos/dev/davirapid/src/Application.php
```

Anotar el contenido actual para preservarlo.

- [ ] **Step 2: Reescribir `src/Application.php` con este contenido**

```php
<?php
declare(strict_types=1);

namespace App;

use App\Middleware\HostHeaderMiddleware;
use Authentication\AuthenticationService;
use Authentication\AuthenticationServiceInterface;
use Authentication\AuthenticationServiceProviderInterface;
use Authentication\Middleware\AuthenticationMiddleware;
use Cake\Core\Configure;
use Cake\Core\ContainerInterface;
use Cake\Datasource\FactoryLocator;
use Cake\Error\Middleware\ErrorHandlerMiddleware;
use Cake\Http\BaseApplication;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\ORM\Locator\TableLocator;
use Cake\Routing\Middleware\AssetMiddleware;
use Cake\Routing\Middleware\RoutingMiddleware;
use Cake\Routing\Router;
use Psr\Http\Message\ServerRequestInterface;

class Application extends BaseApplication implements AuthenticationServiceProviderInterface
{
    public function bootstrap(): void
    {
        parent::bootstrap();

        if (PHP_SAPI === 'cli') {
            $this->bootstrapCli();
        } else {
            FactoryLocator::add(
                'Table',
                (new TableLocator())->allowFallbackClass(false)
            );
        }

        $this->addPlugin('Authentication');

        if (Configure::read('debug')) {
            $this->addPlugin('DebugKit');
        }
    }

    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        $middlewareQueue
            ->add(new ErrorHandlerMiddleware(Configure::read('Error'), $this))
            ->add(new HostHeaderMiddleware())
            ->add(new AssetMiddleware([
                'cacheTime' => Configure::read('Asset.cacheTime'),
            ]))
            ->add(new RoutingMiddleware($this))
            ->add(new AuthenticationMiddleware($this))
            ->add(new BodyParserMiddleware())
            ->add(new CsrfProtectionMiddleware([
                'httponly' => true,
            ]));

        return $middlewareQueue;
    }

    public function services(ContainerInterface $container): void
    {
    }

    public function getAuthenticationService(ServerRequestInterface $request): AuthenticationServiceInterface
    {
        $service = new AuthenticationService([
            'unauthenticatedRedirect' => Router::url([
                'controller' => 'Users',
                'action' => 'login',
            ]),
            'queryParam' => 'redirect',
        ]);

        $service->loadIdentifier('Authentication.Password', [
            'fields' => [
                'username' => 'username',
                'password' => 'password',
            ],
            'resolver' => [
                'className' => 'Authentication.Orm',
                'userModel' => 'Users',
                'finder' => 'auth',
            ],
            'passwordHasher' => [
                'className' => 'Authentication.Default',
            ],
        ]);

        $service->loadAuthenticator('Authentication.Session');
        $service->loadAuthenticator('Authentication.Form', [
            'fields' => [
                'username' => 'username',
                'password' => 'password',
            ],
            'loginUrl' => '/login',
        ]);

        return $service;
    }

    protected function bootstrapCli(): void
    {
        $this->addOptionalPlugin('Bake');
        $this->addPlugin('Migrations');
    }
}
```

- [ ] **Step 3: Verificar sintaxis**

```bash
php -l /home/alexander/Documentos/dev/davirapid/src/Application.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add src/Application.php
git commit -m "feat(app): wire Authentication plugin and middleware

Application now implements AuthenticationServiceProviderInterface.
AuthenticationMiddleware sits between RoutingMiddleware and
BodyParserMiddleware so identity is available before request
bodies are parsed but after routing knows the controller.
HostHeaderMiddleware stays in place — production refuses to
boot without APP_FULL_BASE_URL."
```

---

### Task 15: Reescribir `AppController.php` con RBAC wiring

**Files:**
- Modify: `src/Controller/AppController.php`

- [ ] **Step 1: Reescribir el archivo completo**

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\AuthorizationService;
use App\Service\LoginThrottleService;
use Cake\Controller\Controller;
use Cake\Event\EventInterface;
use Cake\Http\Exception\ForbiddenException;

class AppController extends Controller
{
    /**
     * Mapeo controller => módulo del catálogo AuthorizationService::MODULES.
     * Crece con cada fase nueva.
     */
    protected array $controllerModuleMap = [
        'Roles' => 'roles',
        'Users' => 'users',
    ];

    /**
     * Acciones públicas que NO requieren chequeo de permisos
     * (login, logout, y cualquier endpoint público de fases futuras).
     */
    protected array $publicActions = [
        'Users' => ['login', 'logout'],
    ];

    public AuthorizationService $authorization;

    public LoginThrottleService $throttle;

    public function initialize(): void
    {
        parent::initialize();

        $this->loadComponent('Flash');
        $this->loadComponent('Authentication.Authentication');

        $this->authorization = new AuthorizationService();
        $this->throttle = new LoginThrottleService();
    }

    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);

        $controller = (string)$this->request->getParam('controller');
        $action = (string)$this->request->getParam('action');

        // 1. Bypass de acciones públicas (login, logout, etc.).
        if (in_array($action, $this->publicActions[$controller] ?? [], true)) {
            return null;
        }

        // 2. Identidad obligatoria.
        $identity = $this->Authentication->getIdentity();
        if ($identity === null) {
            return $this->redirect(['controller' => 'Users', 'action' => 'login']);
        }

        $userArray = $this->_identityToArray($identity);

        // 3. Variables para todas las vistas autenticadas.
        $this->set('currentUser', $userArray);
        $this->set('currentRoleName', $userArray['role']['name'] ?? '—');
        $this->set('isAdministrator', !empty($userArray['role']['is_admin']));
        $this->set('userPermissions', $this->authorization->matrixFor($userArray));
        $this->set('sidebarCounters', []);
        $this->set('breadcrumbs', []);

        // 4. Enforce permission para esta request.
        $module = $this->controllerModuleMap[$controller] ?? null;
        if ($module === null) {
            return null;
        }

        $permAction = $this->_actionToPermission($action);
        if (!$this->authorization->isAllowed($userArray, $module, $permAction)) {
            throw new ForbiddenException('No tenés permiso para realizar esta acción.');
        }

        return null;
    }

    /**
     * Mapeo acción del controller => acción de permiso almacenada en DB.
     * Los controllers con acciones custom (ej. 'unlock', 'cancel') sobreescriben
     * este método para sumar entradas.
     */
    protected function _actionToPermission(string $action): string
    {
        return match ($action) {
            'index', 'view' => 'view',
            'add' => 'create',
            'edit' => 'edit',
            'delete' => 'delete',
            default => 'view',
        };
    }

    /**
     * Convierte la identity (entity User) a array plano consumible por AuthorizationService.
     *
     * @param \Authentication\IdentityInterface $identity
     */
    protected function _identityToArray($identity): array
    {
        $data = $identity->getOriginalData();
        if (is_array($data)) {
            return $data;
        }
        if (method_exists($data, 'toArray')) {
            $array = $data->toArray();
            if (isset($array['role']) && is_object($array['role']) && method_exists($array['role'], 'toArray')) {
                $array['role'] = $array['role']->toArray();
            }
            return $array;
        }
        return (array)$data;
    }
}
```

- [ ] **Step 2: Verificar sintaxis**

```bash
php -l /home/alexander/Documentos/dev/davirapid/src/Controller/AppController.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add src/Controller/AppController.php
git commit -m "feat(controller): wire RBAC and identity into AppController

beforeFilter:
  1. allows public actions (login/logout) without checks
  2. requires an authenticated identity
  3. exposes currentUser, role, isAdministrator, userPermissions
     to every authenticated view
  4. enforces permission via AuthorizationService::isAllowed
     and throws ForbiddenException on denial.

_actionToPermission centraliza el mapeo de acciones del controller
a acciones de permiso. Los controllers con acciones custom
(unlock, cancel, etc.) lo sobreescriben sumando matches."
```

---

## Fase G — UI compartida

### Task 16: Crear `SidebarHelper.php`

**Files:**
- Create: `src/View/Helper/SidebarHelper.php`

- [ ] **Step 1: Crear el directorio si no existe**

```bash
mkdir -p /home/alexander/Documentos/dev/davirapid/src/View/Helper
```

- [ ] **Step 2: Crear el archivo**

```php
<?php
declare(strict_types=1);

namespace App\View\Helper;

use Cake\View\Helper;

/**
 * Renderiza el sidebar autenticado. Cada item tiene un módulo asociado
 * y solo se muestra si el usuario tiene permiso 'view' sobre ese módulo.
 */
class SidebarHelper extends Helper
{
    public array $helpers = ['Url', 'Html'];

    /**
     * Catálogo de items del sidebar para Fase 0. Cada fase suma items.
     *
     * @var array<int, array{module:string, label:string, icon:string, url:array}>
     */
    private array $items = [
        [
            'module' => 'users',
            'label' => 'Usuarios',
            'icon' => 'bi-people',
            'url' => ['controller' => 'Users', 'action' => 'index'],
        ],
        [
            'module' => 'roles',
            'label' => 'Roles',
            'icon' => 'bi-shield',
            'url' => ['controller' => 'Roles', 'action' => 'index'],
        ],
    ];

    /**
     * Devuelve los items que el usuario actual puede ver.
     *
     * @param array<string, array<string, bool>> $permissions Matriz de la sesión actual.
     * @param string $currentController Controlador del request (para marcar el item activo).
     */
    public function visibleItems(array $permissions, string $currentController): array
    {
        $visible = [];
        foreach ($this->items as $item) {
            if (empty($permissions[$item['module']]['view'])) {
                continue;
            }
            $itemController = $item['url']['controller'] ?? '';
            $item['active'] = ($itemController === $currentController);
            $visible[] = $item;
        }
        return $visible;
    }
}
```

- [ ] **Step 3: Verificar sintaxis**

```bash
php -l /home/alexander/Documentos/dev/davirapid/src/View/Helper/SidebarHelper.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add src/View/Helper/SidebarHelper.php
git commit -m "feat(view): add SidebarHelper

Returns the navigation items the current user is allowed to see,
based on the userPermissions matrix exposed by AppController.
Sidebar items are declared in code (not DB) and grow with each
phase. Active item is computed by matching the current
controller name."
```

---

### Task 17: Reescribir `templates/layout/default.php`

**Files:**
- Modify: `templates/layout/default.php`

- [ ] **Step 1: Sobreescribir el archivo**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var array $currentUser
 * @var string $currentRoleName
 * @var bool $isAdministrator
 * @var array<string, array<string, bool>> $userPermissions
 * @var array $sidebarCounters
 * @var array $breadcrumbs
 */
$this->loadHelper('Sidebar');
$controller = (string)$this->request->getParam('controller');
$visibleItems = $this->Sidebar->visibleItems($userPermissions ?? [], $controller);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <?= $this->Html->charset() ?>
    <title>
        <?= $this->fetch('title') ? h($this->fetch('title')) . ' · ' : '' ?>Davi Rapid Admin
    </title>
    <?= $this->Html->meta('icon') ?>
    <?= $this->Html->css('vendor/bootstrap.min') ?>
    <?= $this->Html->css('vendor/bootstrap-icons.min') ?>
    <?= $this->Html->css('davirapid') ?>
    <?= $this->fetch('meta') ?>
    <?= $this->fetch('css') ?>
</head>
<body>
<div class="dr-app-shell">

    <aside class="dr-sidebar">
        <div class="dr-sidebar-brand">
            <i class="bi bi-shop"></i>
            <span>Davi Rapid</span>
        </div>
        <nav class="dr-sidebar-nav" aria-label="Navegación principal">
            <?php foreach ($visibleItems as $item): ?>
                <?= $this->Html->link(
                    sprintf('<i class="bi %s"></i><span>%s</span>',
                        h($item['icon']),
                        h($item['label'])
                    ),
                    $item['url'],
                    [
                        'escape' => false,
                        'class' => 'dr-sidebar-item' . ($item['active'] ? ' active' : ''),
                    ]
                ) ?>
            <?php endforeach; ?>
        </nav>
    </aside>

    <header class="dr-topbar">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><?= $this->Html->link('Inicio', '/') ?></li>
                <?php foreach ($breadcrumbs ?? [] as $i => $crumb): ?>
                    <?php $isLast = $i === array_key_last($breadcrumbs); ?>
                    <li class="breadcrumb-item<?= $isLast ? ' active' : '' ?>"<?= $isLast ? ' aria-current="page"' : '' ?>>
                        <?php if (!$isLast && !empty($crumb['url'])): ?>
                            <?= $this->Html->link(h($crumb['label']), $crumb['url']) ?>
                        <?php else: ?>
                            <?= h($crumb['label']) ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </nav>

        <div class="dr-topbar-user dropdown">
            <button class="btn btn-sm btn-light dropdown-toggle d-inline-flex align-items-center gap-2"
                    type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person-circle"></i>
                <span class="d-none d-sm-inline">
                    <?= h($currentUser['name'] ?? 'Usuario') ?>
                </span>
                <small class="text-muted d-none d-md-inline">· <?= h($currentRoleName) ?></small>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li class="dropdown-item-text small text-muted">
                    <?= h($currentUser['username'] ?? '') ?>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <?= $this->Html->link(
                        '<i class="bi bi-box-arrow-right"></i> Cerrar sesión',
                        ['controller' => 'Users', 'action' => 'logout'],
                        ['escape' => false, 'class' => 'dropdown-item']
                    ) ?>
                </li>
            </ul>
        </div>
    </header>

    <main class="dr-content">
        <?= $this->Flash->render() ?>
        <?= $this->fetch('content') ?>
    </main>

</div>

<?= $this->Html->script('vendor/bootstrap.bundle.min', ['defer' => true]) ?>
<?= $this->fetch('script') ?>
</body>
</html>
```

- [ ] **Step 2: Verificar sintaxis**

```bash
php -l /home/alexander/Documentos/dev/davirapid/templates/layout/default.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add templates/layout/default.php
git commit -m "feat(layout): replace default layout with authenticated shell

Sidebar (248px) + topbar (64px) + content area, themed via
davirapid.css. Sidebar items come from SidebarHelper, filtered
by the userPermissions matrix. Topbar shows breadcrumbs + the
current user dropdown with logout. The CakePHP welcome layout
is gone — every authenticated route renders here."
```

---

### Task 18: Crear `templates/layout/login.php`

**Files:**
- Create: `templates/layout/login.php`

- [ ] **Step 1: Crear el archivo**

```php
<?php
/**
 * @var \App\View\AppView $this
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= $this->Html->charset() ?>
    <title><?= $this->fetch('title') ? h($this->fetch('title')) . ' · ' : '' ?>Davi Rapid Admin</title>
    <?= $this->Html->meta('icon') ?>
    <?= $this->Html->css('vendor/bootstrap.min') ?>
    <?= $this->Html->css('vendor/bootstrap-icons.min') ?>
    <?= $this->Html->css('davirapid') ?>
    <?= $this->fetch('meta') ?>
    <?= $this->fetch('css') ?>
</head>
<body>
<div class="dr-login-shell">
    <div class="dr-login-card">
        <div class="dr-login-brand">
            <i class="bi bi-shop"></i> Davi Rapid
        </div>
        <?= $this->Flash->render() ?>
        <?= $this->fetch('content') ?>
    </div>
</div>

<?= $this->Html->script('vendor/bootstrap.bundle.min', ['defer' => true]) ?>
<?= $this->fetch('script') ?>
</body>
</html>
```

- [ ] **Step 2: Verificar sintaxis**

```bash
php -l /home/alexander/Documentos/dev/davirapid/templates/layout/login.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add templates/layout/login.php
git commit -m "feat(layout): add login layout

Centered card on a clean background — no sidebar, no topbar.
Same Bootstrap + davirapid.css stack so the brand red focus-ring
on the login form matches the rest of the app."
```

---

### Task 19: Restyle `templates/Error/error400.php` y `error500.php`

**Files:**
- Modify: `templates/Error/error400.php`
- Modify: `templates/Error/error500.php`

- [ ] **Step 1: Sobreescribir `templates/Error/error400.php`**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \Throwable $error
 * @var int $code
 */
use Cake\Core\Configure;

$this->layout = false;
$message = $error->getMessage();
$code = (int)($code ?? $error->getCode() ?: 400);
$title = match ($code) {
    403 => 'Acceso denegado',
    404 => 'No encontrado',
    default => 'Error',
};
$icon = match ($code) {
    403 => 'bi-shield-lock',
    404 => 'bi-question-circle',
    default => 'bi-exclamation-triangle',
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= h($title) ?> · Davi Rapid Admin</title>
    <?= $this->Html->css('vendor/bootstrap.min') ?>
    <?= $this->Html->css('vendor/bootstrap-icons.min') ?>
    <?= $this->Html->css('davirapid') ?>
</head>
<body>
<div class="dr-error-shell">
    <i class="bi <?= h($icon) ?> dr-error-icon"></i>
    <h1 class="h3 mb-2"><?= h($title) ?></h1>
    <p class="text-muted mb-4"><?= h($message) ?></p>
    <?php if (Configure::read('debug')): ?>
        <details class="mb-4 text-start" style="max-width: 720px;">
            <summary class="text-muted">Detalles técnicos (solo en debug)</summary>
            <pre class="small mt-2 p-3 bg-light rounded"><?= h((string)$error) ?></pre>
        </details>
    <?php endif; ?>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left"></i> Volver al inicio',
        '/',
        ['escape' => false, 'class' => 'btn btn-primary']
    ) ?>
</div>
</body>
</html>
```

- [ ] **Step 2: Sobreescribir `templates/Error/error500.php`**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \Throwable $error
 */
use Cake\Core\Configure;

$this->layout = false;
$debug = Configure::read('debug');
$message = $debug ? $error->getMessage() : 'Algo salió mal del lado del servidor.';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Error · Davi Rapid Admin</title>
    <?= $this->Html->css('vendor/bootstrap.min') ?>
    <?= $this->Html->css('vendor/bootstrap-icons.min') ?>
    <?= $this->Html->css('davirapid') ?>
</head>
<body>
<div class="dr-error-shell">
    <i class="bi bi-exclamation-octagon dr-error-icon text-danger"></i>
    <h1 class="h3 mb-2">Error interno</h1>
    <p class="text-muted mb-4"><?= h($message) ?></p>
    <?php if ($debug): ?>
        <details class="mb-4 text-start" style="max-width: 720px;">
            <summary class="text-muted">Detalles técnicos (solo en debug)</summary>
            <pre class="small mt-2 p-3 bg-light rounded"><?= h((string)$error) ?></pre>
        </details>
    <?php endif; ?>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left"></i> Volver al inicio',
        '/',
        ['escape' => false, 'class' => 'btn btn-primary']
    ) ?>
</div>
</body>
</html>
```

- [ ] **Step 3: Verificar sintaxis de los dos archivos**

```bash
cd /home/alexander/Documentos/dev/davirapid
php -l templates/Error/error400.php
php -l templates/Error/error500.php
```

Expected: `No syntax errors detected` para ambos.

- [ ] **Step 4: Commit**

```bash
git add templates/Error/error400.php templates/Error/error500.php
git commit -m "feat(errors): restyle 4xx/5xx error pages with Bootstrap

Error pages now use davirapid.css and the .dr-error-shell layout.
Stack traces are gated behind Configure::read('debug') so production
shows a clean message and dev shows the full trace.
ForbiddenException (403) renders with the bi-shield-lock icon."
```

---

### Task 20: Reemplazar `PagesController.php` y `templates/Pages/home.php`

**Files:**
- Modify: `src/Controller/PagesController.php`
- Modify: `templates/Pages/home.php`

- [ ] **Step 1: Reescribir `src/Controller/PagesController.php`**

```php
<?php
declare(strict_types=1);

namespace App\Controller;

/**
 * Pages controller — placeholder root that protects the home route
 * and shows a "Dashboard coming in Fase 4" landing page once the user
 * is authenticated. The full Dashboard module ships in Fase 4.
 */
class PagesController extends AppController
{
    public function home(): void
    {
        $this->set('breadcrumbs', []);
    }
}
```

- [ ] **Step 2: Reescribir `templates/Pages/home.php`**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var array $currentUser
 * @var string $currentRoleName
 */
$this->assign('title', 'Inicio');
?>
<div class="dr-page-header">
    <div>
        <h1 class="dr-page-title">Hola, <?= h(explode(' ', (string)($currentUser['name'] ?? 'Usuario'))[0]) ?></h1>
        <p class="text-muted mb-0">Sesión iniciada como <strong><?= h($currentUser['username'] ?? '') ?></strong> · Rol: <?= h($currentRoleName) ?></p>
    </div>
</div>

<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-graph-up-arrow display-4 text-muted mb-3 d-block"></i>
        <h2 class="h4 mb-2">Dashboard</h2>
        <p class="text-muted mb-0">El panel de métricas está disponible a partir de la Fase 4.</p>
    </div>
</div>
```

- [ ] **Step 3: Verificar sintaxis**

```bash
cd /home/alexander/Documentos/dev/davirapid
php -l src/Controller/PagesController.php
php -l templates/Pages/home.php
```

Expected: `No syntax errors detected` para ambos.

- [ ] **Step 4: Commit**

```bash
git add src/Controller/PagesController.php templates/Pages/home.php
git commit -m "feat(pages): replace skeleton PagesController with home placeholder

PagesController now extends AppController (so beforeFilter requires
auth) and only exposes home(). The home template welcomes the
authenticated user and explains that the dashboard arrives in Fase 4."
```

---

### Task 21: Actualizar `config/routes.php`

**Files:**
- Modify: `config/routes.php`

- [ ] **Step 1: Sobreescribir el archivo**

```php
<?php
declare(strict_types=1);

use Cake\Routing\Route\DashedRoute;
use Cake\Routing\RouteBuilder;

return function (RouteBuilder $routes): void {
    $routes->setRouteClass(DashedRoute::class);

    $routes->scope('/', function (RouteBuilder $builder): void {
        // Públicas
        $builder->connect('/login', ['controller' => 'Users', 'action' => 'login']);
        $builder->connect('/logout', ['controller' => 'Users', 'action' => 'logout']);

        // Home (placeholder dashboard, requiere sesión vía AppController::beforeFilter)
        $builder->connect('/', ['controller' => 'Pages', 'action' => 'home']);

        // Acción custom: desbloquear cuenta de usuario.
        $builder->connect(
            '/users/unlock/{id}',
            ['controller' => 'Users', 'action' => 'unlock'],
            ['id' => '\d+', 'pass' => ['id']]
        );

        // CRUD estándar para Roles, Users (index/view/add/edit/delete) y home.
        $builder->fallbacks();
    });
};
```

- [ ] **Step 2: Verificar sintaxis**

```bash
php -l /home/alexander/Documentos/dev/davirapid/config/routes.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add config/routes.php
git commit -m "feat(routes): wire login/logout/home and users/unlock

Public routes (login, logout) live above the fallback so they
match before the generic CRUD pattern. The unlock action is
declared explicitly because it is POST-only and uses a non-standard
URL shape (/users/unlock/{id})."
```

---

## Fase H — Seed y primer login funcional

### Task 22: Migración `SeedAdministratorRoleAndUser`

**Files:**
- Create: `config/Migrations/20260502120300_SeedAdministratorRoleAndUser.php`

- [ ] **Step 1: Crear el archivo**

```php
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
```

- [ ] **Step 2: Verificar sintaxis**

```bash
php -l /home/alexander/Documentos/dev/davirapid/config/Migrations/20260502120300_SeedAdministratorRoleAndUser.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add config/Migrations/20260502120300_SeedAdministratorRoleAndUser.php
git commit -m "feat(db): add SeedAdministratorRoleAndUser migration

Inserts the Administrator role (is_admin=1), its permission rows
for the Fase 0 modules, and the admin user with hashed password
'ca1ced0.DEV'. The hash is generated at run time so each
environment gets a fresh bcrypt with the current cost. The
plaintext password is a development secret — operators must
rotate it via the UI before the first production deploy."
```

---

### Task 23: Correr la migración de seed y verificar el primer login

**Files:** N/A (ejecución).

- [ ] **Step 1: Correr la migración**

```bash
cd /home/alexander/Documentos/dev/davirapid
php bin/cake.php migrations migrate
```

Expected: `SeedAdministratorRoleAndUser` aplicada (`migrated`).

- [ ] **Step 2: Verificar contenido en DB (vía SQL directo)**

Conectarse al MariaDB y correr:
```sql
SELECT id, name, is_admin FROM roles;
SELECT role_id, module, can_view, can_create, can_edit, can_delete FROM permissions;
SELECT id, username, name, role_id, active, failed_login_count FROM users;
```

Expected:
- `roles`: 1 fila `(1, 'Administrador', 1)`.
- `permissions`: 2 filas, ambas `role_id=1`, módulos `users` y `roles`, todos los flags=1.
- `users`: 1 fila con `username='admin'`, `role_id=1`, `active=1`, `failed_login_count=0`.

- [ ] **Step 3: Iniciar el dev server**

```bash
cd /home/alexander/Documentos/dev/davirapid
php bin/cake.php server -p 8765
```

Dejar corriendo en una terminal.

- [ ] **Step 4: En el navegador, verificar manualmente**

Abrir `http://localhost:8765/`. Como no hay sesión, debe redirigir a `/login`.

En `/login`, intentar:
1. Login con `admin` / `password-incorrecto`. Expected: vuelve a `/login` con flash `Credenciales inválidas. Te quedan 4 intentos.`
2. Login con `admin` / `ca1ced0.DEV`. Expected: redirige a `/`, se ve la página de "Hola, Administrador" con el sidebar (Usuarios + Roles), topbar con el dropdown del usuario, y el placeholder de dashboard.
3. Click en "Cerrar sesión" del dropdown. Expected: redirige a `/login`.

⚠ **Si algún paso falla**: parar acá, diagnosticar y corregir antes de seguir. Los módulos Roles y Usuarios todavía no existen como controllers — los links del sidebar van a dar 404 hasta completar las Fases I y J.

- [ ] **Step 5: Detener el dev server (Ctrl+C en la terminal del server)**

- [ ] **Step 6: Commit (si aplican cambios menores tras debug)**

Si todo funcionó sin cambios, no hay commit en este task.

---

## Fase I — Módulo Roles

### Task 24: Crear `RolePermissionService`

**Files:**
- Create: `src/Service/RolePermissionService.php`

- [ ] **Step 1: Crear el archivo**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Sincroniza la matriz de permisos que llega del form de Roles con las filas
 * de la tabla `permissions`. Se invoca desde RolesController::add y ::edit.
 */
final class RolePermissionService
{
    use LocatorAwareTrait;

    /**
     * @param int $roleId Rol al que pertenecen los permisos.
     * @param array<string, array<string, mixed>> $matrix Estructura del form: ['users' => ['can_view'=>'1', 'can_create'=>'0', ...], ...]
     * @return array{success: bool}
     */
    public function syncMatrix(int $roleId, array $matrix): array
    {
        $conn = ConnectionManager::get('default');

        $conn->transactional(function () use ($roleId, $matrix): void {
            $permissionsTable = $this->fetchTable('Permissions');
            $now = DateTime::now();

            $existing = $permissionsTable
                ->find()
                ->where(['Permissions.role_id' => $roleId])
                ->all()
                ->indexBy('module')
                ->toArray();

            foreach (AuthorizationService::MODULES as $module => $_label) {
                $row = $matrix[$module] ?? [];

                $canView = !empty($row['can_view']);
                $canCreate = !empty($row['can_create']);
                $canEdit = !empty($row['can_edit']);
                $canDelete = !empty($row['can_delete']);

                // Jerarquía implícita: sin Ver no hay nada más. Si el form se contradice,
                // el service forza can_view=true cuando hay alguna acción mayor activa.
                if ($canCreate || $canEdit || $canDelete) {
                    $canView = true;
                }

                $hasAny = $canView || $canCreate || $canEdit || $canDelete;
                $existingRow = $existing[$module] ?? null;

                if ($existingRow !== null) {
                    // UPDATE
                    $existingRow->can_view = $canView;
                    $existingRow->can_create = $canCreate;
                    $existingRow->can_edit = $canEdit;
                    $existingRow->can_delete = $canDelete;
                    $permissionsTable->saveOrFail($existingRow);
                } elseif ($hasAny) {
                    // INSERT
                    $entity = $permissionsTable->newEntity([
                        'role_id' => $roleId,
                        'module' => $module,
                        'can_view' => $canView,
                        'can_create' => $canCreate,
                        'can_edit' => $canEdit,
                        'can_delete' => $canDelete,
                    ]);
                    $permissionsTable->saveOrFail($entity);
                }
                // Si no existía y todos los flags son false: skip (no creamos filas vacías).
            }
        });

        return ['success' => true];
    }
}
```

- [ ] **Step 2: Verificar sintaxis**

```bash
php -l /home/alexander/Documentos/dev/davirapid/src/Service/RolePermissionService.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add src/Service/RolePermissionService.php
git commit -m "feat(rbac): add RolePermissionService

Atomic sync of the permission matrix submitted by the Roles form.
Iterates the AuthorizationService::MODULES catalog (form keys
outside the catalog are silently dropped — anti-tampering),
upserts existing rows, inserts new rows only when at least one
flag is true, and enforces the implicit 'no Ver, no nada' rule
by forcing can_view=true whenever a higher action is checked."
```

---

### Task 25: Crear `RolesController`

**Files:**
- Create: `src/Controller/RolesController.php`

- [ ] **Step 1: Crear el archivo**

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Entity\Role;
use App\Service\AuthorizationService;
use App\Service\RolePermissionService;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Http\Exception\ForbiddenException;
use Cake\ORM\Exception\PersistenceFailedException;

class RolesController extends AppController
{
    public array $paginate = [
        'limit' => 15,
        'maxLimit' => 15,
        'order' => ['Roles.is_admin' => 'DESC', 'Roles.name' => 'ASC'],
    ];

    private RolePermissionService $rolePermissions;

    public function initialize(): void
    {
        parent::initialize();
        $this->rolePermissions = new RolePermissionService();
    }

    public function index(): void
    {
        $roles = $this->paginate(
            $this->Roles->find()->contain(['Permissions', 'Users' => fn($q) => $q->select(['id', 'role_id'])])
        );
        $this->set(compact('roles'));
        $this->set('moduleCatalog', AuthorizationService::MODULES);
        $this->set('breadcrumbs', [['label' => 'Roles']]);
    }

    public function view(int $id): void
    {
        $role = $this->Roles->get($id, contain: ['Permissions']);
        $this->set('role', $role);
        $this->set('matrix', $this->_buildMatrixFromRole($role));
        $this->set('moduleCatalog', AuthorizationService::MODULES);
        $this->set('breadcrumbs', [
            ['label' => 'Roles', 'url' => ['action' => 'index']],
            ['label' => $role->name],
        ]);
    }

    public function add()
    {
        $role = $this->Roles->newEmptyEntity();

        if ($this->request->is('post')) {
            $data = $this->request->getData();
            $matrix = $data['permissions'] ?? [];
            unset($data['permissions']);

            $role = $this->Roles->patchEntity($role, $data);
            if ($this->Roles->save($role)) {
                $this->rolePermissions->syncMatrix((int)$role->id, is_array($matrix) ? $matrix : []);
                $this->Flash->success('Rol creado correctamente.');
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error('No se pudo crear el rol. Revisá los datos.');
        }

        $this->set('role', $role);
        $this->set('matrix', $this->_emptyMatrix());
        $this->set('moduleCatalog', AuthorizationService::MODULES);
        $this->set('breadcrumbs', [
            ['label' => 'Roles', 'url' => ['action' => 'index']],
            ['label' => 'Nuevo rol'],
        ]);
    }

    public function edit(int $id)
    {
        $role = $this->Roles->get($id, contain: ['Permissions']);

        if ($role->isAdministrator()) {
            throw new ForbiddenException('El rol Administrador no se puede editar.');
        }

        if ($this->request->is(['put', 'post', 'patch'])) {
            $data = $this->request->getData();
            $matrix = $data['permissions'] ?? [];
            unset($data['permissions']);

            $role = $this->Roles->patchEntity($role, $data);
            if ($this->Roles->save($role)) {
                $this->rolePermissions->syncMatrix((int)$role->id, is_array($matrix) ? $matrix : []);
                $this->Flash->success('Rol actualizado.');
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error('No se pudo actualizar el rol. Revisá los datos.');
        }

        $this->set('role', $role);
        $this->set('matrix', $this->_buildMatrixFromRole($role));
        $this->set('moduleCatalog', AuthorizationService::MODULES);
        $this->set('breadcrumbs', [
            ['label' => 'Roles', 'url' => ['action' => 'index']],
            ['label' => $role->name, 'url' => ['action' => 'view', $role->id]],
            ['label' => 'Editar'],
        ]);
    }

    public function delete(int $id)
    {
        $this->request->allowMethod(['post', 'delete']);

        try {
            $role = $this->Roles->get($id);
        } catch (RecordNotFoundException) {
            $this->Flash->error('El rol ya no existe.');
            return $this->redirect(['action' => 'index']);
        }

        if ($role->isAdministrator()) {
            throw new ForbiddenException('El rol Administrador no se puede eliminar.');
        }

        try {
            $this->Roles->deleteOrFail($role);
            $this->Flash->success('Rol eliminado.');
        } catch (PersistenceFailedException $e) {
            $this->Flash->error('No se puede eliminar este rol porque tiene usuarios asignados.');
            \Cake\Log\Log::warning('Failed to delete role {id}: {msg}', [
                'id' => $id,
                'msg' => $e->getMessage(),
                'scope' => ['rbac'],
            ]);
        }

        return $this->redirect(['action' => 'index']);
    }

    private function _buildMatrixFromRole(Role $role): array
    {
        $matrix = $this->_emptyMatrix();
        foreach ($role->permissions ?? [] as $perm) {
            if (!isset($matrix[$perm->module])) {
                continue;
            }
            $matrix[$perm->module] = [
                'can_view' => (bool)$perm->can_view,
                'can_create' => (bool)$perm->can_create,
                'can_edit' => (bool)$perm->can_edit,
                'can_delete' => (bool)$perm->can_delete,
            ];
        }
        return $matrix;
    }

    private function _emptyMatrix(): array
    {
        $matrix = [];
        foreach (array_keys(AuthorizationService::MODULES) as $module) {
            $matrix[$module] = [
                'can_view' => false,
                'can_create' => false,
                'can_edit' => false,
                'can_delete' => false,
            ];
        }
        return $matrix;
    }
}
```

- [ ] **Step 2: Verificar sintaxis**

```bash
php -l /home/alexander/Documentos/dev/davirapid/src/Controller/RolesController.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add src/Controller/RolesController.php
git commit -m "feat(rbac): add RolesController with permission matrix flow

CRUD with:
- index: paginated list (15/page) + admin role pinned to top.
- add/edit: split 'permissions' from the payload, save the role,
  delegate the matrix sync to RolePermissionService.
- edit/delete: refuse the Administrator role with ForbiddenException.
- delete: catches PersistenceFailedException (FK RESTRICT when
  the role has users) and shows a friendly Flash message.
The whole controller is gated by AuthorizationService — only
the Administrator can reach any action because module 'roles'
is denied to non-admins by isAllowed."
```

---

### Task 26: Crear `templates/Roles/index.php`

**Files:**
- Create: `templates/Roles/index.php`

- [ ] **Step 1: Crear el archivo**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \Cake\ORM\ResultSet|\App\Model\Entity\Role[] $roles
 * @var array<string, string> $moduleCatalog
 */
$this->assign('title', 'Roles');
$totalModules = count($moduleCatalog);
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Roles</h1>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg"></i> Nuevo rol',
        ['action' => 'add'],
        ['escape' => false, 'class' => 'btn btn-primary']
    ) ?>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Permisos</th>
                    <th>Usuarios</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roles as $role): ?>
                    <tr>
                        <td>
                            <?= $this->Html->link(h($role->name), ['action' => 'view', $role->id]) ?>
                            <?php if ($role->isAdministrator()): ?>
                                <span class="badge badge-soft-primary ms-2">
                                    <i class="bi bi-shield-fill-check"></i> Administrador
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $configured = 0;
                            foreach ($role->permissions ?? [] as $p) {
                                if ($p->can_view || $p->can_create || $p->can_edit || $p->can_delete) {
                                    $configured++;
                                }
                            }
                            $configured = $role->isAdministrator() ? $totalModules : $configured;
                            ?>
                            <span class="badge badge-soft-secondary">
                                <?= $configured ?> de <?= $totalModules ?> módulos
                            </span>
                        </td>
                        <td>
                            <span class="text-muted"><?= count($role->users ?? []) ?></span>
                        </td>
                        <td class="text-end">
                            <?= $this->Html->link(
                                '<i class="bi bi-eye"></i>',
                                ['action' => 'view', $role->id],
                                ['escape' => false, 'class' => 'btn btn-icon btn-light', 'title' => 'Ver']
                            ) ?>
                            <?php if ($role->isAdministrator()): ?>
                                <button class="btn btn-icon btn-light" disabled title="El rol Administrador no se edita">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-icon btn-light" disabled title="El rol Administrador no se elimina">
                                    <i class="bi bi-trash"></i>
                                </button>
                            <?php else: ?>
                                <?= $this->Html->link(
                                    '<i class="bi bi-pencil"></i>',
                                    ['action' => 'edit', $role->id],
                                    ['escape' => false, 'class' => 'btn btn-icon btn-light', 'title' => 'Editar']
                                ) ?>
                                <?= $this->Form->postLink(
                                    '<i class="bi bi-trash"></i>',
                                    ['action' => 'delete', $role->id],
                                    [
                                        'escape' => false,
                                        'class' => 'btn btn-icon btn-light text-danger',
                                        'title' => 'Eliminar',
                                        'confirm' => sprintf('¿Eliminar el rol "%s"?', $role->name),
                                    ]
                                ) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($roles->toArray()) === 0): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">No hay roles cargados.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->element('pagination') ?>
```

- [ ] **Step 2: Crear el element de paginación si no existe**

```bash
ls /home/alexander/Documentos/dev/davirapid/templates/element/pagination.php 2>/dev/null || echo "MISSING"
```

Si está MISSING, crear `templates/element/pagination.php`:

```php
<?php
/**
 * @var \App\View\AppView $this
 */
if (!$this->Paginator->total() || $this->Paginator->total() <= $this->Paginator->limit()) {
    return;
}
?>
<nav aria-label="Paginación" class="mt-3">
    <ul class="pagination justify-content-end mb-0">
        <?= $this->Paginator->prev('« Anterior', ['class' => 'page-item', 'tag' => 'li', 'disabledTag' => 'a', 'disabledClass' => 'page-item disabled']) ?>
        <?= $this->Paginator->numbers(['class' => 'page-item', 'tag' => 'li', 'currentClass' => 'page-item active', 'currentTag' => 'a']) ?>
        <?= $this->Paginator->next('Siguiente »', ['class' => 'page-item', 'tag' => 'li', 'disabledTag' => 'a', 'disabledClass' => 'page-item disabled']) ?>
    </ul>
</nav>
```

- [ ] **Step 3: Verificar sintaxis**

```bash
cd /home/alexander/Documentos/dev/davirapid
php -l templates/Roles/index.php
php -l templates/element/pagination.php
```

Expected: `No syntax errors detected` para ambos.

- [ ] **Step 4: Commit**

```bash
git add templates/Roles/index.php templates/element/pagination.php
git commit -m "feat(rbac): add Roles index template + shared pagination element

Roles index lists all roles with their configured-modules count
and assigned-users count. Administrator row pins to the top
(via paginate order) and shows disabled action buttons. Other
roles offer Ver / Editar / Eliminar (postLink with confirm).
The pagination element is reusable across modules."
```

---

### Task 27: Crear `templates/Roles/add.php` y `templates/Roles/edit.php`

**Files:**
- Create: `templates/Roles/add.php`
- Create: `templates/Roles/edit.php`

Los formularios son casi idénticos. Los hacemos como dos archivos separados (no element compartido) para que cada uno sea legible aislado y podamos cambiarlos independientemente si la UX diverge.

- [ ] **Step 1: Crear `templates/Roles/add.php`**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Role $role
 * @var array<string, array<string, bool>> $matrix
 * @var array<string, string> $moduleCatalog
 */
$this->assign('title', 'Nuevo rol');
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Nuevo rol</h1>
</div>

<?= $this->Form->create($role) ?>
<div class="card mb-4">
    <div class="card-body">
        <div class="mb-3">
            <?= $this->Form->control('name', [
                'label' => 'Nombre del rol',
                'class' => 'form-control',
                'autofocus' => true,
                'maxlength' => 60,
                'placeholder' => 'Ej. Cajero, Encargado de turno',
            ]) ?>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h6 text-muted text-uppercase mb-3">Permisos por módulo</h2>
        <table class="table dr-permission-matrix mb-0">
            <thead>
                <tr>
                    <th>Módulo</th>
                    <th>Ver</th>
                    <th>Crear</th>
                    <th>Editar</th>
                    <th>Eliminar</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($moduleCatalog as $moduleKey => $moduleLabel): ?>
                    <?php
                    $row = $matrix[$moduleKey] ?? ['can_view' => false, 'can_create' => false, 'can_edit' => false, 'can_delete' => false];
                    $isRolesModule = $moduleKey === 'roles';
                    ?>
                    <tr<?= $isRolesModule ? ' title="Solo el Administrador puede gestionar Roles" data-bs-toggle="tooltip"' : '' ?>>
                        <td class="dr-module-name"><?= h($moduleLabel) ?></td>
                        <?php foreach (['can_view', 'can_create', 'can_edit', 'can_delete'] as $field): ?>
                            <td class="dr-perm-cell">
                                <input type="hidden" name="permissions[<?= h($moduleKey) ?>][<?= h($field) ?>]" value="0">
                                <input type="checkbox"
                                       class="form-check-input dr-perm-checkbox"
                                       name="permissions[<?= h($moduleKey) ?>][<?= h($field) ?>]"
                                       value="1"
                                       data-module="<?= h($moduleKey) ?>"
                                       data-field="<?= h($field) ?>"
                                       <?= !empty($row[$field]) ? 'checked' : '' ?>
                                       <?= $isRolesModule ? 'disabled' : '' ?>>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="d-flex gap-2">
    <?= $this->Form->button('<i class="bi bi-check-lg"></i> Guardar', ['escapeTitle' => false, 'class' => 'btn btn-primary']) ?>
    <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
</div>
<?= $this->Form->end() ?>

<?php $this->start('script'); ?>
<script>
(function () {
    // Jerarquía implícita: marcar Crear/Editar/Eliminar marca Ver; desmarcar Ver desmarca todos.
    document.querySelectorAll('.dr-perm-checkbox').forEach(function (cb) {
        cb.addEventListener('change', function () {
            if (cb.disabled) return;
            const module = cb.dataset.module;
            const field = cb.dataset.field;
            if (cb.checked && field !== 'can_view') {
                const view = document.querySelector('.dr-perm-checkbox[data-module="' + module + '"][data-field="can_view"]');
                if (view) view.checked = true;
            }
            if (!cb.checked && field === 'can_view') {
                document.querySelectorAll('.dr-perm-checkbox[data-module="' + module + '"]').forEach(function (other) {
                    other.checked = false;
                });
            }
        });
    });
    // Activar tooltips de Bootstrap
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        new bootstrap.Tooltip(el);
    });
})();
</script>
<?php $this->end(); ?>
```

- [ ] **Step 2: Crear `templates/Roles/edit.php` (casi idéntico, solo cambia el título y submit)**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Role $role
 * @var array<string, array<string, bool>> $matrix
 * @var array<string, string> $moduleCatalog
 */
$this->assign('title', 'Editar rol');
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Editar rol: <?= h($role->name) ?></h1>
</div>

<?= $this->Form->create($role) ?>
<div class="card mb-4">
    <div class="card-body">
        <div class="mb-3">
            <?= $this->Form->control('name', [
                'label' => 'Nombre del rol',
                'class' => 'form-control',
                'autofocus' => true,
                'maxlength' => 60,
            ]) ?>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h6 text-muted text-uppercase mb-3">Permisos por módulo</h2>
        <table class="table dr-permission-matrix mb-0">
            <thead>
                <tr>
                    <th>Módulo</th>
                    <th>Ver</th>
                    <th>Crear</th>
                    <th>Editar</th>
                    <th>Eliminar</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($moduleCatalog as $moduleKey => $moduleLabel): ?>
                    <?php
                    $row = $matrix[$moduleKey] ?? ['can_view' => false, 'can_create' => false, 'can_edit' => false, 'can_delete' => false];
                    $isRolesModule = $moduleKey === 'roles';
                    ?>
                    <tr<?= $isRolesModule ? ' title="Solo el Administrador puede gestionar Roles" data-bs-toggle="tooltip"' : '' ?>>
                        <td class="dr-module-name"><?= h($moduleLabel) ?></td>
                        <?php foreach (['can_view', 'can_create', 'can_edit', 'can_delete'] as $field): ?>
                            <td class="dr-perm-cell">
                                <input type="hidden" name="permissions[<?= h($moduleKey) ?>][<?= h($field) ?>]" value="0">
                                <input type="checkbox"
                                       class="form-check-input dr-perm-checkbox"
                                       name="permissions[<?= h($moduleKey) ?>][<?= h($field) ?>]"
                                       value="1"
                                       data-module="<?= h($moduleKey) ?>"
                                       data-field="<?= h($field) ?>"
                                       <?= !empty($row[$field]) ? 'checked' : '' ?>
                                       <?= $isRolesModule ? 'disabled' : '' ?>>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="d-flex gap-2">
    <?= $this->Form->button('<i class="bi bi-check-lg"></i> Guardar cambios', ['escapeTitle' => false, 'class' => 'btn btn-primary']) ?>
    <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
</div>
<?= $this->Form->end() ?>

<?php $this->start('script'); ?>
<script>
(function () {
    document.querySelectorAll('.dr-perm-checkbox').forEach(function (cb) {
        cb.addEventListener('change', function () {
            if (cb.disabled) return;
            const module = cb.dataset.module;
            const field = cb.dataset.field;
            if (cb.checked && field !== 'can_view') {
                const view = document.querySelector('.dr-perm-checkbox[data-module="' + module + '"][data-field="can_view"]');
                if (view) view.checked = true;
            }
            if (!cb.checked && field === 'can_view') {
                document.querySelectorAll('.dr-perm-checkbox[data-module="' + module + '"]').forEach(function (other) {
                    other.checked = false;
                });
            }
        });
    });
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        new bootstrap.Tooltip(el);
    });
})();
</script>
<?php $this->end(); ?>
```

- [ ] **Step 3: Verificar sintaxis**

```bash
cd /home/alexander/Documentos/dev/davirapid
php -l templates/Roles/add.php
php -l templates/Roles/edit.php
```

Expected: `No syntax errors detected` para ambos.

- [ ] **Step 4: Commit**

```bash
git add templates/Roles/add.php templates/Roles/edit.php
git commit -m "feat(rbac): add Roles add and edit templates with permission matrix

Both templates render the same matrix UI: module catalog comes
from \$moduleCatalog (driven by AuthorizationService::MODULES),
each row has four checkboxes wired with hidden inputs to ensure
unchecked boxes still post '0'. Inline JS enforces the
'no Ver, no nada' hierarchy on the client (the service enforces
it on the server too — defense in depth). The 'roles' module
is rendered with disabled checkboxes and a tooltip explaining
that only the Administrator can manage Roles."
```

---

### Task 28: Crear `templates/Roles/view.php`

**Files:**
- Create: `templates/Roles/view.php`

- [ ] **Step 1: Crear el archivo**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Role $role
 * @var array<string, array<string, bool>> $matrix
 * @var array<string, string> $moduleCatalog
 */
$this->assign('title', $role->name);
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">
        <?= h($role->name) ?>
        <?php if ($role->isAdministrator()): ?>
            <span class="badge badge-soft-primary ms-2">
                <i class="bi bi-shield-fill-check"></i> Administrador
            </span>
        <?php endif; ?>
    </h1>
    <div class="d-flex gap-2">
        <?php if (!$role->isAdministrator()): ?>
            <?= $this->Html->link(
                '<i class="bi bi-pencil"></i> Editar',
                ['action' => 'edit', $role->id],
                ['escape' => false, 'class' => 'btn btn-primary']
            ) ?>
        <?php endif; ?>
        <?= $this->Html->link('Volver', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h2 class="h6 text-muted text-uppercase mb-3">Permisos por módulo</h2>
        <table class="table dr-permission-matrix mb-0">
            <thead>
                <tr>
                    <th>Módulo</th>
                    <th>Ver</th>
                    <th>Crear</th>
                    <th>Editar</th>
                    <th>Eliminar</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($moduleCatalog as $moduleKey => $moduleLabel): ?>
                    <?php
                    $isAdmin = $role->isAdministrator();
                    $row = $matrix[$moduleKey] ?? ['can_view' => false, 'can_create' => false, 'can_edit' => false, 'can_delete' => false];
                    ?>
                    <tr>
                        <td class="dr-module-name"><?= h($moduleLabel) ?></td>
                        <?php foreach (['can_view', 'can_create', 'can_edit', 'can_delete'] as $field): ?>
                            <td class="dr-perm-cell">
                                <?php if ($isAdmin || !empty($row[$field])): ?>
                                    <i class="bi bi-check-lg text-success"></i>
                                <?php else: ?>
                                    <i class="bi bi-dash text-muted"></i>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
```

- [ ] **Step 2: Verificar sintaxis**

```bash
php -l /home/alexander/Documentos/dev/davirapid/templates/Roles/view.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add templates/Roles/view.php
git commit -m "feat(rbac): add Roles view template (read-only matrix)

Same matrix layout as edit but with check/dash icons instead of
checkboxes. The Administrator row shows all-checked even though
the underlying permissions table contains no rows for it (the
bypass is the source of truth — the UI reflects that)."
```

---

### Task 29: Verificación intermedia — Roles funciona end-to-end

**Files:** N/A (verificación manual).

- [ ] **Step 1: Iniciar dev server**

```bash
cd /home/alexander/Documentos/dev/davirapid
php bin/cake.php server -p 8765
```

- [ ] **Step 2: Probar Roles en el navegador (logueado como `admin`)**

1. Ir a `/roles`. Expected: tabla con 1 fila ("Administrador" con badge primary-soft, sin botones de editar/eliminar). Botón "Nuevo rol" en el header.
2. Click en "Administrador" (link en la fila). Expected: `/roles/view/1` muestra todos los módulos en verde.
3. Click en "Nuevo rol". Expected: `/roles/add` con form vacío. La fila "Roles" en la matriz tiene checkboxes disabled y tooltip "Solo el Administrador puede gestionar Roles".
4. Crear un rol "Cajero" con `Usuarios: Ver + Crear` marcados. Submit. Expected: redirect a `/roles` con flash success y la nueva fila visible.
5. Click en "Editar" sobre Cajero. Expected: form precargado con los flags marcados. Marcar "Editar" sobre Usuarios. Notar que JS auto-marca Ver (ya estaba). Submit. Expected: redirect, flash success.
6. En `/roles`, click en "Cajero" → `/roles/view/2`. Expected: matriz con Ver/Crear/Editar marcados en Usuarios, todo lo demás vacío.
7. En `/roles`, intentar eliminar Cajero (no tiene usuarios asignados aún). Confirmar diálogo. Expected: redirect con flash success, fila desaparece.

⚠ Si algún paso falla: detener acá, diagnosticar, fixear. La causa típica es un nombre de helper mal escrito, un FK type mismatch, o un evento JS que no se conecta.

- [ ] **Step 3: Detener dev server**

- [ ] **Step 4: No hay commit en este task — solo ejecución**

---

## Fase J — Módulo Usuarios

### Task 30: Crear `UserService`

**Files:**
- Create: `src/Service/UserService.php`

- [ ] **Step 1: Crear el archivo**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;

final class UserService
{
    use LocatorAwareTrait;

    /**
     * @return array{success: bool, user?: \App\Model\Entity\User, errors?: array<string>}
     */
    public function create(array $data): array
    {
        $usersTable = $this->fetchTable('Users');
        $user = $usersTable->newEmptyEntity();

        if (isset($data['role_id']) && $this->_isAdminRole((int)$data['role_id'])) {
            return [
                'success' => false,
                'errors' => ['No se puede asignar el rol Administrador a un usuario nuevo.'],
            ];
        }

        $user = $usersTable->patchEntity($user, $data, ['validate' => 'create']);

        if (!$usersTable->save($user)) {
            return [
                'success' => false,
                'errors' => $this->_flattenErrors($user->getErrors()),
            ];
        }

        Log::info('User created: {username} (role_id={role_id})', [
            'username' => $user->username,
            'role_id' => $user->role_id,
            'scope' => ['users'],
        ]);

        return ['success' => true, 'user' => $user];
    }

    /**
     * @return array{success: bool, user?: \App\Model\Entity\User, errors?: array<string>}
     */
    public function update(int $id, array $data): array
    {
        $usersTable = $this->fetchTable('Users');
        $user = $usersTable->get($id, contain: ['Roles']);

        if ($user->isAdministrator() && isset($data['role_id']) && (int)$data['role_id'] !== (int)$user->role_id) {
            return [
                'success' => false,
                'errors' => ['No se puede cambiar el rol del usuario Administrador.'],
            ];
        }

        if (isset($data['role_id']) && !$user->isAdministrator() && $this->_isAdminRole((int)$data['role_id'])) {
            return [
                'success' => false,
                'errors' => ['No se puede asignar el rol Administrador.'],
            ];
        }

        if ($user->isAdministrator() && array_key_exists('active', $data) && !$data['active']) {
            return [
                'success' => false,
                'errors' => ['El usuario Administrador no se puede desactivar.'],
            ];
        }

        $user = $usersTable->patchEntity($user, $data);

        if (!$usersTable->save($user)) {
            return [
                'success' => false,
                'errors' => $this->_flattenErrors($user->getErrors()),
            ];
        }

        return ['success' => true, 'user' => $user];
    }

    /**
     * @return array{success: bool}
     */
    public function unlock(int $id): array
    {
        $usersTable = $this->fetchTable('Users');
        $user = $usersTable->get($id);
        $user->failed_login_count = 0;
        $user->locked_until = null;
        $usersTable->saveOrFail($user);

        Log::info('Account manually unlocked: {username}', [
            'username' => $user->username,
            'scope' => ['auth', 'users'],
        ]);

        return ['success' => true];
    }

    private function _isAdminRole(int $roleId): bool
    {
        $role = $this->fetchTable('Roles')->find()
            ->where(['Roles.id' => $roleId])
            ->first();
        return $role !== null && (bool)$role->is_admin;
    }

    /**
     * @param array $errors Cake validator/rules error tree.
     * @return array<string>
     */
    private function _flattenErrors(array $errors): array
    {
        $flat = [];
        array_walk_recursive($errors, function ($message) use (&$flat): void {
            if (is_string($message) && $message !== '') {
                $flat[] = $message;
            }
        });
        return $flat ?: ['Datos inválidos.'];
    }
}
```

- [ ] **Step 2: Verificar sintaxis**

```bash
php -l /home/alexander/Documentos/dev/davirapid/src/Service/UserService.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add src/Service/UserService.php
git commit -m "feat(users): add UserService

create/update/unlock with explicit business rules:
- create: rejects role_id pointing to an Administrator role.
- update: rejects changing the admin's role, deactivating the admin,
  or assigning the Administrator role to non-admin users.
- unlock: clears lockout state, logs the action.
Validation errors are flattened to a flat string list for Flash."
```

---

### Task 31: Reescribir `UsersController.php` con todas las acciones

**Files:**
- Create: `src/Controller/UsersController.php`

- [ ] **Step 1: Crear el archivo**

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\UserService;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Http\Exception\ForbiddenException;

class UsersController extends AppController
{
    public array $paginate = [
        'limit' => 15,
        'maxLimit' => 15,
        'order' => ['Users.username' => 'ASC'],
    ];

    private UserService $userService;

    public function initialize(): void
    {
        parent::initialize();
        $this->userService = new UserService();
        // Allow login + logout to render without authentication
        $this->Authentication->addUnauthenticatedActions(['login', 'logout']);
    }

    public function login()
    {
        $this->viewBuilder()->setLayout('login');

        $username = (string)$this->request->getData('username', '');

        if ($this->request->is('post')) {
            $lockInfo = $this->throttle->checkLockout($username);
            if ($lockInfo !== null) {
                $this->Flash->error(
                    sprintf('Cuenta bloqueada. Intentá de nuevo en %d %s.',
                        $lockInfo['minutes_left'],
                        $lockInfo['minutes_left'] === 1 ? 'minuto' : 'minutos'
                    )
                );
                return null;
            }

            $result = $this->Authentication->getResult();
            if ($result !== null && $result->isValid()) {
                $user = $result->getData();
                $this->throttle->recordSuccess((int)$user->id);
                return $this->redirect($this->Authentication->getLoginRedirect() ?? '/');
            }

            $info = $this->throttle->recordFailure($username);
            $msg = ($info['attempts_left'] !== null && $info['attempts_left'] > 0)
                ? sprintf('Credenciales inválidas. Te quedan %d intentos.', $info['attempts_left'])
                : 'Credenciales inválidas.';
            $this->Flash->error($msg);
        }

        $this->set('username', $username);
        return null;
    }

    public function logout()
    {
        $this->Authentication->logout();
        return $this->redirect(['action' => 'login']);
    }

    public function index(): void
    {
        $q = trim((string)$this->request->getQuery('q', ''));
        $query = $this->Users->find()->contain(['Roles']);
        if ($q !== '') {
            $like = '%' . $q . '%';
            $query->where(['OR' => [
                'Users.username LIKE' => $like,
                'Users.name LIKE' => $like,
            ]]);
        }

        $users = $this->paginate($query);
        $this->set(compact('users', 'q'));
        $this->set('breadcrumbs', [['label' => 'Usuarios']]);
    }

    public function view(int $id): void
    {
        $user = $this->Users->get($id, contain: ['Roles']);
        $this->set('user', $user);
        $this->set('breadcrumbs', [
            ['label' => 'Usuarios', 'url' => ['action' => 'index']],
            ['label' => $user->username],
        ]);
    }

    public function add()
    {
        $user = $this->Users->newEmptyEntity();

        if ($this->request->is('post')) {
            $result = $this->userService->create($this->request->getData());
            if ($result['success']) {
                $this->Flash->success('Usuario creado correctamente.');
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(implode(' ', $result['errors'] ?? ['No se pudo crear el usuario.']));
            // Re-pintar el form con los datos enviados (sin password)
            $data = $this->request->getData();
            unset($data['password']);
            $user = $this->Users->patchEntity($user, $data, ['validate' => false]);
        }

        $this->set('user', $user);
        $this->set('roles', $this->Users->Roles->find('assignable')->all());
        $this->set('isEditingAdministrator', false);
        $this->set('breadcrumbs', [
            ['label' => 'Usuarios', 'url' => ['action' => 'index']],
            ['label' => 'Nuevo usuario'],
        ]);
    }

    public function edit(int $id)
    {
        $user = $this->Users->get($id, contain: ['Roles']);
        $isEditingAdministrator = $user->isAdministrator();

        if ($this->request->is(['put', 'post', 'patch'])) {
            $result = $this->userService->update($id, $this->request->getData());
            if ($result['success']) {
                $this->Flash->success('Usuario actualizado.');
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(implode(' ', $result['errors'] ?? ['No se pudo actualizar el usuario.']));
        }

        $rolesQuery = $isEditingAdministrator
            ? $this->Users->Roles->find()->where(['Roles.id' => $user->role_id])
            : $this->Users->Roles->find('assignable');

        $this->set('user', $user);
        $this->set('roles', $rolesQuery->all());
        $this->set('isEditingAdministrator', $isEditingAdministrator);
        $this->set('breadcrumbs', [
            ['label' => 'Usuarios', 'url' => ['action' => 'index']],
            ['label' => $user->username, 'url' => ['action' => 'view', $user->id]],
            ['label' => 'Editar'],
        ]);
    }

    public function delete(int $id)
    {
        $this->request->allowMethod(['post', 'delete']);

        try {
            $user = $this->Users->get($id, contain: ['Roles']);
        } catch (RecordNotFoundException) {
            $this->Flash->error('El usuario ya no existe.');
            return $this->redirect(['action' => 'index']);
        }

        if ($user->isAdministrator()) {
            throw new ForbiddenException('El usuario Administrador no se puede eliminar.');
        }

        $identity = $this->Authentication->getIdentity();
        if ($identity !== null && (int)$identity->getIdentifier() === (int)$user->id) {
            throw new ForbiddenException('No podés eliminar tu propio usuario.');
        }

        $this->Users->deleteOrFail($user);
        $this->Flash->success('Usuario eliminado.');
        return $this->redirect(['action' => 'index']);
    }

    public function unlock(int $id)
    {
        $this->request->allowMethod(['post']);
        $this->userService->unlock($id);
        $this->Flash->success('Cuenta desbloqueada.');
        return $this->redirect(['action' => 'index']);
    }

    /**
     * Sumamos el mapeo de la acción 'unlock' al permiso 'edit'.
     */
    protected function _actionToPermission(string $action): string
    {
        return match ($action) {
            'unlock' => 'edit',
            default => parent::_actionToPermission($action),
        };
    }
}
```

- [ ] **Step 2: Verificar sintaxis**

```bash
php -l /home/alexander/Documentos/dev/davirapid/src/Controller/UsersController.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add src/Controller/UsersController.php
git commit -m "feat(users): add UsersController with login/logout/CRUD/unlock

login: integrates LoginThrottleService — checks lockout before
delegating to Authentication, records success/failure after.
logout: redirects to /login.
index/view/add/edit/delete: standard CRUD with admin-protections
delegated to UserService.
unlock: POST-only, cleared via UserService::unlock; mapped to
the 'edit' permission via _actionToPermission override.
The 'q' query param drives a simple LIKE search on index."
```

---

### Task 32: Crear `templates/Users/login.php`

**Files:**
- Create: `templates/Users/login.php`

- [ ] **Step 1: Crear el archivo**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var string $username
 */
$this->assign('title', 'Iniciar sesión');
?>
<h1 class="h4 text-center mb-4">Iniciar sesión</h1>

<?= $this->Form->create(null, ['url' => ['action' => 'login']]) ?>
<div class="mb-3">
    <label class="form-label" for="username">Usuario</label>
    <input type="text" name="username" id="username" class="form-control" required autofocus
           value="<?= h($username ?? '') ?>" autocomplete="username">
</div>
<div class="mb-4">
    <label class="form-label" for="password">Contraseña</label>
    <input type="password" name="password" id="password" class="form-control" required
           autocomplete="current-password">
</div>
<button type="submit" class="btn btn-primary w-100">
    <i class="bi bi-box-arrow-in-right"></i> Entrar
</button>
<?= $this->Form->end() ?>
```

- [ ] **Step 2: Verificar sintaxis**

```bash
php -l /home/alexander/Documentos/dev/davirapid/templates/Users/login.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add templates/Users/login.php
git commit -m "feat(users): add login template

Minimal form rendered inside the login layout. Username field
is preserved on failed submissions so the user only re-enters
the password. Flash messages (intentos restantes, cuenta
bloqueada) renderizan en el layout antes del fetch('content')."
```

---

### Task 33: Crear `templates/Users/index.php`

**Files:**
- Create: `templates/Users/index.php`

- [ ] **Step 1: Crear el archivo**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \Cake\ORM\ResultSet|\App\Model\Entity\User[] $users
 * @var string $q
 */
$this->assign('title', 'Usuarios');
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Usuarios</h1>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg"></i> Nuevo usuario',
        ['action' => 'add'],
        ['escape' => false, 'class' => 'btn btn-primary']
    ) ?>
</div>

<form method="get" class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex gap-2 align-items-center">
            <i class="bi bi-search text-muted"></i>
            <input type="text" name="q" class="form-control form-control-sm"
                   value="<?= h($q) ?>" placeholder="Buscar por usuario o nombre">
            <?php if ($q !== ''): ?>
                <?= $this->Html->link('Limpiar', ['action' => 'index'], ['class' => 'btn btn-sm btn-light']) ?>
            <?php endif; ?>
        </div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Usuario</th>
                    <th>Nombre</th>
                    <th>Rol</th>
                    <th>Estado</th>
                    <th>Última conexión</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td>
                            <?= $this->Html->link(h($user->username), ['action' => 'view', $user->id]) ?>
                        </td>
                        <td><?= h($user->name) ?></td>
                        <td>
                            <?= h($user->role?->name ?? '—') ?>
                            <?php if ($user->isAdministrator()): ?>
                                <span class="badge badge-soft-primary ms-1">Admin</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$user->active): ?>
                                <span class="badge badge-soft-secondary">Inactivo</span>
                            <?php elseif ($user->isLocked()): ?>
                                <span class="badge badge-soft-warning">
                                    <i class="bi bi-lock-fill"></i> Bloqueado
                                </span>
                            <?php else: ?>
                                <span class="badge badge-soft-success">Activo</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $user->last_login_at ? h($user->last_login_at->i18nFormat('dd/MM HH:mm')) : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td class="text-end">
                            <?php if ($user->isAdministrator()): ?>
                                <span class="text-muted small">—</span>
                            <?php else: ?>
                                <?= $this->Html->link(
                                    '<i class="bi bi-pencil"></i>',
                                    ['action' => 'edit', $user->id],
                                    ['escape' => false, 'class' => 'btn btn-icon btn-light', 'title' => 'Editar']
                                ) ?>
                                <?php if ($user->isLocked()): ?>
                                    <?= $this->Form->postLink(
                                        '<i class="bi bi-unlock"></i>',
                                        ['action' => 'unlock', $user->id],
                                        [
                                            'escape' => false,
                                            'class' => 'btn btn-icon btn-light text-warning',
                                            'title' => 'Desbloquear cuenta',
                                            'confirm' => sprintf('¿Desbloquear la cuenta de %s?', $user->username),
                                        ]
                                    ) ?>
                                <?php endif; ?>
                                <?= $this->Form->postLink(
                                    '<i class="bi bi-trash"></i>',
                                    ['action' => 'delete', $user->id],
                                    [
                                        'escape' => false,
                                        'class' => 'btn btn-icon btn-light text-danger',
                                        'title' => 'Eliminar',
                                        'confirm' => sprintf('¿Eliminar al usuario %s?', $user->username),
                                    ]
                                ) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($users->toArray()) === 0): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            <?= $q !== '' ? 'Sin resultados para la búsqueda.' : 'No hay usuarios cargados.' ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->element('pagination') ?>
```

- [ ] **Step 2: Verificar sintaxis**

```bash
php -l /home/alexander/Documentos/dev/davirapid/templates/Users/index.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add templates/Users/index.php
git commit -m "feat(users): add Users index template

Search bar (?q=) on top, table with username/name/role/status/
last login/actions. Estado computed from active + isLocked() —
shows three states (Activo, Bloqueado, Inactivo). Action column
hides all buttons for the Administrator row (defense in depth
on top of the controller-level rejection)."
```

---

### Task 34: Crear `templates/Users/add.php`

**Files:**
- Create: `templates/Users/add.php`

- [ ] **Step 1: Crear el archivo**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 * @var \Cake\ORM\ResultSet|\App\Model\Entity\Role[] $roles
 * @var bool $isEditingAdministrator
 */
$this->assign('title', 'Nuevo usuario');
$rolesList = [];
foreach ($roles as $r) {
    $rolesList[$r->id] = $r->name;
}
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Nuevo usuario</h1>
</div>

<?= $this->Form->create($user) ?>
<div class="card mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <?= $this->Form->control('username', [
                    'label' => 'Usuario',
                    'class' => 'form-control',
                    'autofocus' => true,
                    'maxlength' => 60,
                    'placeholder' => 'pedro.garcia',
                    'help' => 'Letras, números, punto, guion bajo o guion. Sin espacios.',
                ]) ?>
            </div>
            <div class="col-md-6">
                <?= $this->Form->control('name', [
                    'label' => 'Nombre completo',
                    'class' => 'form-control',
                    'maxlength' => 120,
                ]) ?>
            </div>
            <div class="col-md-6">
                <?= $this->Form->control('password', [
                    'label' => 'Contraseña',
                    'class' => 'form-control',
                    'type' => 'password',
                    'autocomplete' => 'new-password',
                    'help' => 'Mínimo 8 caracteres.',
                ]) ?>
            </div>
            <div class="col-md-6">
                <?= $this->Form->control('role_id', [
                    'label' => 'Rol',
                    'class' => 'form-select',
                    'type' => 'select',
                    'options' => $rolesList,
                    'empty' => 'Seleccionar rol…',
                ]) ?>
            </div>
            <div class="col-12">
                <div class="form-check">
                    <input type="hidden" name="active" value="0">
                    <input type="checkbox" name="active" id="active" class="form-check-input"
                           value="1" <?= $user->active === false ? '' : 'checked' ?>>
                    <label class="form-check-label" for="active">Usuario activo</label>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="d-flex gap-2">
    <?= $this->Form->button('<i class="bi bi-check-lg"></i> Crear usuario', ['escapeTitle' => false, 'class' => 'btn btn-primary']) ?>
    <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
</div>
<?= $this->Form->end() ?>
```

- [ ] **Step 2: Verificar sintaxis**

```bash
php -l /home/alexander/Documentos/dev/davirapid/templates/Users/add.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add templates/Users/add.php
git commit -m "feat(users): add Users add template

Form with username, name, password, role (select excludes
Administrator), active checkbox. Hidden 'active=0' input ensures
unchecked posts the field as 0 — Cake otherwise omits unchecked
checkboxes from the payload."
```

---

### Task 35: Crear `templates/Users/edit.php`

**Files:**
- Create: `templates/Users/edit.php`

- [ ] **Step 1: Crear el archivo**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 * @var \Cake\ORM\ResultSet|\App\Model\Entity\Role[] $roles
 * @var bool $isEditingAdministrator
 */
$this->assign('title', 'Editar usuario');
$rolesList = [];
foreach ($roles as $r) {
    $rolesList[$r->id] = $r->name;
}
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Editar usuario: <?= h($user->username) ?></h1>
</div>

<?= $this->Form->create($user) ?>
<div class="card mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <?= $this->Form->control('username', [
                    'label' => 'Usuario',
                    'class' => 'form-control',
                    'autofocus' => true,
                    'maxlength' => 60,
                ]) ?>
            </div>
            <div class="col-md-6">
                <?= $this->Form->control('name', [
                    'label' => 'Nombre completo',
                    'class' => 'form-control',
                    'maxlength' => 120,
                ]) ?>
            </div>
            <div class="col-md-6">
                <?= $this->Form->control('password', [
                    'label' => 'Contraseña',
                    'class' => 'form-control',
                    'type' => 'password',
                    'value' => '',
                    'autocomplete' => 'new-password',
                    'help' => 'Dejá en blanco para no cambiar. Mínimo 8 caracteres si la cambiás.',
                    'required' => false,
                ]) ?>
            </div>
            <div class="col-md-6">
                <?php if ($isEditingAdministrator): ?>
                    <label class="form-label">Rol</label>
                    <input type="text" class="form-control" disabled
                           value="<?= h($user->role?->name ?? 'Administrador') ?>">
                    <input type="hidden" name="role_id" value="<?= h($user->role_id) ?>">
                    <small class="form-text text-muted">El rol del Administrador no puede cambiarse.</small>
                <?php else: ?>
                    <?= $this->Form->control('role_id', [
                        'label' => 'Rol',
                        'class' => 'form-select',
                        'type' => 'select',
                        'options' => $rolesList,
                        'empty' => 'Seleccionar rol…',
                    ]) ?>
                <?php endif; ?>
            </div>
            <div class="col-12">
                <?php if ($isEditingAdministrator): ?>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" disabled checked>
                        <label class="form-check-label">Usuario activo</label>
                        <input type="hidden" name="active" value="1">
                    </div>
                    <small class="text-muted">El Administrador no se puede desactivar.</small>
                <?php else: ?>
                    <div class="form-check">
                        <input type="hidden" name="active" value="0">
                        <input type="checkbox" name="active" id="active" class="form-check-input"
                               value="1" <?= $user->active === false ? '' : 'checked' ?>>
                        <label class="form-check-label" for="active">Usuario activo</label>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="d-flex gap-2">
    <?= $this->Form->button('<i class="bi bi-check-lg"></i> Guardar cambios', ['escapeTitle' => false, 'class' => 'btn btn-primary']) ?>
    <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
</div>
<?= $this->Form->end() ?>
```

- [ ] **Step 2: Verificar sintaxis**

```bash
php -l /home/alexander/Documentos/dev/davirapid/templates/Users/edit.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add templates/Users/edit.php
git commit -m "feat(users): add Users edit template

Password field is optional with explicit 'leave blank to keep'
copy. When editing the Administrator: rol is rendered disabled
(with a hidden input preserving the current value), active is
locked to 1, and helper text explains the constraints. The
service rejects any attempt to bypass these via direct POST."
```

---

### Task 36: Crear `templates/Users/view.php`

**Files:**
- Create: `templates/Users/view.php`

- [ ] **Step 1: Crear el archivo**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 */
$this->assign('title', $user->username);
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">
        <?= h($user->username) ?>
        <?php if ($user->isAdministrator()): ?>
            <span class="badge badge-soft-primary ms-2">Administrador</span>
        <?php elseif (!$user->active): ?>
            <span class="badge badge-soft-secondary ms-2">Inactivo</span>
        <?php elseif ($user->isLocked()): ?>
            <span class="badge badge-soft-warning ms-2"><i class="bi bi-lock-fill"></i> Bloqueado</span>
        <?php else: ?>
            <span class="badge badge-soft-success ms-2">Activo</span>
        <?php endif; ?>
    </h1>
    <div class="d-flex gap-2">
        <?php if (!$user->isAdministrator()): ?>
            <?php if ($user->isLocked()): ?>
                <?= $this->Form->postLink(
                    '<i class="bi bi-unlock"></i> Desbloquear',
                    ['action' => 'unlock', $user->id],
                    [
                        'escape' => false,
                        'class' => 'btn btn-warning',
                        'confirm' => sprintf('¿Desbloquear la cuenta de %s?', $user->username),
                    ]
                ) ?>
            <?php endif; ?>
        <?php endif; ?>
        <?= $this->Html->link(
            '<i class="bi bi-pencil"></i> Editar',
            ['action' => 'edit', $user->id],
            ['escape' => false, 'class' => 'btn btn-primary']
        ) ?>
        <?= $this->Html->link('Volver', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3 text-muted">Nombre</dt>
            <dd class="col-sm-9"><?= h($user->name) ?></dd>

            <dt class="col-sm-3 text-muted">Rol</dt>
            <dd class="col-sm-9"><?= h($user->role?->name ?? '—') ?></dd>

            <dt class="col-sm-3 text-muted">Última conexión</dt>
            <dd class="col-sm-9">
                <?= $user->last_login_at ? h($user->last_login_at->i18nFormat('dd/MM/yyyy HH:mm')) : '—' ?>
            </dd>

            <dt class="col-sm-3 text-muted">Intentos fallidos</dt>
            <dd class="col-sm-9"><?= (int)$user->failed_login_count ?> de <?= \App\Service\LoginThrottleService::MAX_ATTEMPTS ?></dd>

            <?php if ($user->isLocked()): ?>
                <dt class="col-sm-3 text-muted">Bloqueado hasta</dt>
                <dd class="col-sm-9 text-warning">
                    <?= h($user->locked_until->i18nFormat('dd/MM/yyyy HH:mm')) ?>
                </dd>
            <?php endif; ?>

            <dt class="col-sm-3 text-muted">Creado</dt>
            <dd class="col-sm-9"><?= $user->created ? h($user->created->i18nFormat('dd/MM/yyyy HH:mm')) : '—' ?></dd>
        </dl>
    </div>
</div>
```

- [ ] **Step 2: Verificar sintaxis**

```bash
php -l /home/alexander/Documentos/dev/davirapid/templates/Users/view.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add templates/Users/view.php
git commit -m "feat(users): add Users view template

Read-only summary of the user with status badge in the header,
last login, failed attempts counter (X de 5), and the locked-until
timestamp when applicable. The 'Desbloquear' action is surfaced
as a primary toolbar button when the account is locked."
```

---

## Fase K — Smoke test final

### Task 37: Verificación end-to-end manual del flujo completo

**Files:** N/A (verificación).

- [ ] **Step 1: Iniciar dev server**

```bash
cd /home/alexander/Documentos/dev/davirapid
php bin/cake.php server -p 8765
```

- [ ] **Step 2: Smoke test 1 — Login y navegación básica**

1. `http://localhost:8765/` → redirige a `/login`.
2. Login con `admin` / `ca1ced0.DEV` → redirige a `/`, ve "Hola, Administrador".
3. Sidebar muestra Usuarios y Roles. Topbar muestra el dropdown del usuario.
4. Click en "Roles" → `/roles` con la fila Administrador.
5. Click en "Usuarios" → `/users` con la fila admin.
6. Click en logout → vuelve a `/login`.

- [ ] **Step 3: Smoke test 2 — Lockout**

1. En `/login`, intentar `admin` / `wrong` 5 veces seguidas.
2. Después del 5to intento, el flash debe decir "Credenciales inválidas." (sin "te quedan N intentos" porque 0).
3. Intento 6 con cualquier password: flash "Cuenta bloqueada. Intentá de nuevo en 15 minutos."
4. **Restablecer manualmente** para no esperar 15 min: conectarse a la DB y correr:
   ```sql
   UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE username = 'admin';
   ```
5. Login con `admin` / `ca1ced0.DEV` → entra, en `/users/view/1` mostrar "Intentos fallidos: 0 de 5" tras el login (la lógica resetea on-success).

- [ ] **Step 4: Smoke test 3 — Crear rol con permisos y usuario asignado**

1. Logueado como admin, ir a `/roles/add`.
2. Nombre: `Cajero`. Marcar `Usuarios: Ver + Crear`. Submit.
3. En `/roles`, debe aparecer "Cajero" con badge "1 de 2 módulos".
4. Ir a `/users/add`. Nombre: `Pedro García`, usuario: `pedro`, password: `ped12345`, rol: `Cajero`. Submit.
5. En `/users`, aparece la fila pedro con rol Cajero.
6. Logout. Login como `pedro` / `ped12345`.
7. Sidebar SOLO muestra "Usuarios" (no muestra "Roles" porque pedro no es admin).
8. Visitar `/users` → ve la lista con admin y pedro.
9. Visitar `/roles` directamente en URL → debe mostrar página 403 con icono shield-lock.
10. Visitar `/users/edit/2` (intento de editar a sí mismo) → flash success al guardar (puede editarse).
11. Visitar `/users/delete/1` → debe ser rechazado con 403 ("usuario Administrador no se puede eliminar"). En realidad, intentar borrar el ID 1 desde la UI no es posible (no hay botón). Verificar vía POST manual sería editar el HTML — saltar este paso si es muy invasivo.
12. Logout.

- [ ] **Step 5: Smoke test 4 — Edición admin con restricciones**

1. Login como `admin`.
2. Ir a `/users/edit/1` (admin).
3. El campo "Rol" se muestra disabled. El checkbox "Activo" se muestra disabled.
4. Cambiar el nombre a "Administrador Principal". Submit.
5. Volver a la edición → el cambio quedó.

- [ ] **Step 6: Smoke test 5 — Eliminar rol con usuarios**

1. Logueado como admin, ir a `/roles`. Intentar eliminar el rol "Cajero" (que tiene a pedro asignado).
2. Confirmar el diálogo. Expected: redirect con flash error "No se puede eliminar este rol porque tiene usuarios asignados."
3. Eliminar al usuario pedro primero. Después intentar eliminar el rol Cajero → exitoso.

- [ ] **Step 7: Detener dev server**

- [ ] **Step 8: PHPStan + cs-check (opcional, recomendado)**

```bash
cd /home/alexander/Documentos/dev/davirapid
composer cs-check
vendor/bin/phpstan analyse --memory-limit=1G
```

Si hay warnings de phpstan en código nuestro (no en vendor/), revisarlos. cs-check debe pasar limpio (los archivos creados siguen PSR-12).

- [ ] **Step 9: Commit final con summary**

Si los tests pasaron sin necesidad de cambios, no hay diff. Si hubo fixes durante el smoke test, commitearlos:

```bash
git status
git add .
git commit -m "chore: smoke test fixes for Fase 0

Adjustments discovered during the end-to-end smoke test of the
Fase 0 cimientos (login, lockout, role CRUD with matrix, user
CRUD with admin protections, FK RESTRICT on role delete)."
```

---

## Self-review

**Spec coverage** (Fase 0 design §1–§13):

| Sección spec | Tasks que la implementan |
|---|---|
| §1 Cleanup del skeleton | Task 2 (rm), Task 20 (PagesController replace), Task 17/18/19 (templates) |
| §2 Sistema de diseño + Bootstrap | Tasks 8, 9 |
| §3 Layout autenticado + login | Tasks 16, 17, 18 |
| §4 Esquema de DB | Tasks 4, 5, 6, 7 |
| §5 Autenticación | Tasks 13 (LoginThrottleService), 14 (Application), 31 (UsersController::login/logout), 32 (login template) |
| §6 RBAC | Tasks 12 (AuthorizationService), 15 (AppController) |
| §7 Módulo Roles | Tasks 24, 25, 26, 27, 28 |
| §8 Módulo Usuarios | Tasks 30, 31, 32, 33, 34, 35, 36 |
| §9 Seeds | Tasks 22, 23 |
| §10 Cross-cutting (errores, routes, middleware, .env, composer) | Tasks 1, 3, 14, 19, 21 |
| §11 Catálogo de archivos | Cubierto distribuido en todos los tasks |
| §12 Fuera de Fase 0 | N/A — explícitamente no se implementa |
| §13 Próximo paso (writing-plans) | Este documento |

✓ Cobertura completa.

**Placeholder scan:** Hice una pasada — sin TBD/TODO/"implementar después". Cada step tiene código completo o comando ejecutable. Las pocas elipsis son referencias claras a lo que ya existe (ej. constructor de `ErrorHandlerMiddleware` con args estándar del skeleton).

**Type consistency:** Métodos referenciados entre tasks:
- `AuthorizationService::MODULES`, `::ACTIONS`, `::isAllowed`, `::matrixFor` — consistentes en Tasks 12, 14, 15, 24, 25.
- `LoginThrottleService::MAX_ATTEMPTS`, `::checkLockout`, `::recordSuccess`, `::recordFailure` — consistentes en Tasks 13, 31, 36.
- `RolePermissionService::syncMatrix(int $roleId, array $matrix): array` — consistente en Tasks 24, 25.
- `UserService::create/update/unlock` — consistentes en Tasks 30, 31.
- `Role::isAdministrator()`, `User::isAdministrator()`, `User::isLocked()` — consistentes en todos los templates y controllers.
- `UsersTable::findAuth()`, `RolesTable::findAssignable()` — consistentes en Application config (Task 14) y controller (Task 31).

✓ Sin desajustes detectados.

**Dependencias entre tasks (orden mínimo):**

- Tasks 1–3 (Fase A) deben correr antes de cualquier otra cosa (composer require, cleanup, .env).
- Tasks 4–7 (Fase B) requieren Task 1 (composer install para cake.php).
- Task 8–9 (Fase C) son independientes — pueden correr en cualquier orden tras Task 2.
- Task 10–11 (Fase D, modelos) requieren las migraciones aplicadas (Task 7).
- Tasks 12–13 (Fase E, services) son independientes; usan TableLocator que requiere Task 10–11.
- Task 14 (Application.php) requiere Task 1 (auth plugin) y Task 12–13 (porque AppController los instancia, lo cual será en Task 15).
- Task 15 (AppController) requiere Tasks 12–13.
- Task 16 (SidebarHelper) es independiente tras Task 12.
- Tasks 17–18 (layouts) requieren Task 16 para que `$this->Sidebar` funcione.
- Task 19 (error templates) es independiente.
- Tasks 20–21 (Pages, routes) requieren Tasks 14–18.
- Task 22 (seed migration) requiere Tasks 4–7. Task 23 ejecuta y verifica con todo lo anterior.
- Tasks 24–28 (Roles) requieren Tasks 7, 10, 12, 15, 17.
- Task 29 (verificación intermedia Roles) requiere Tasks 24–28.
- Tasks 30–36 (Usuarios) requieren Tasks 7, 11, 12, 13, 15, 17.
- Task 37 (smoke final) requiere TODO lo anterior.

El orden lineal del plan respeta esto.

---

## Execution Handoff

**Plan completo y guardado en `docs/superpowers/plans/2026-05-02-fase-0-cimientos.md`. Dos opciones de ejecución:**

**1. Subagent-Driven (recommended)** — Un subagent fresco por task, review entre tasks, iteración rápida.

**2. Inline Execution** — Ejecutar los tasks en esta sesión usando executing-plans, batch execution con checkpoints.

**¿Qué enfoque preferís?**
