# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this project is

**Davi Rapid Admin** is the in-development administrative panel for a fast-food business (point-of-sale + delivery) built on the **CakePHP 5.x skeleton** (PHP ≥ 8.2). The complete functional specification — modules, business rules, state machines, integrated flows — lives in `davirapid.md` at the repo root and is the source of truth for *what* must be built. The visual/design system (colors, typography, components, do's & don'ts) lives in `.claude/rules/DESIGN.md`.

The project is greenfield: `src/Model/Entity/` and `src/Model/Table/` are empty. Outside of the `App\Middleware\HostHeaderMiddleware` and the default `Pages`/`Error`/`App` controllers, no domain code has been written yet. Read `davirapid.md` before designing any module — the modules, integrations, and rules described there should not be re-derived from scratch.

## Common commands

CakePHP console (`bin/cake` on Linux/Mac, `bin\cake.bat` on Windows; `bin/cake.php` works on any platform via `php bin/cake.php …`):

```powershell
# Built-in dev server (default :8765)
php bin\cake.php server -p 8765

# Bake (scaffolding for models, controllers, templates, migrations)
php bin\cake.php bake all <Table>
php bin\cake.php bake migration Create<Table>

# Migrations
php bin\cake.php migrations migrate
php bin\cake.php migrations rollback
```

Calidad estática (composer scripts, see `composer.json`):

```powershell
composer cs-check                   # phpcs (CakePHP coding standard, see phpcs.xml)
composer cs-fix                     # phpcbf
vendor\bin\phpstan analyse          # level 8, configured in phpstan.neon
vendor\bin\psalm                    # configured in psalm.xml
```

## Architecture notes specific to this app

- **Framework conventions are CakePHP 5.x.** Controllers in `src/Controller/`, ORM tables in `src/Model/Table/` (singular conventions: `OrdersTable` exposes the `orders` table and returns `Order` entities from `src/Model/Entity/`), templates in `templates/<Controller>/<action>.php`, layouts in `templates/layout/`. PSR-4 root: `App\\` → `src/`, tests `App\Test\\` → `tests/`.
- **`FactoryLocator` fallback is disabled** in `Application::bootstrap()` (`allowFallbackClass(false)`). This means every table you reference must have a concrete `XxxTable` class in `src/Model/Table/`; CakePHP will not silently fabricate one. Bake real classes for every table you use.
- **`HostHeaderMiddleware` is mandatory in production.** When `debug=false`, the app refuses to boot unless `App.fullBaseUrl` is set (env var `APP_FULL_BASE_URL` or `config/app.php`), and rejects requests whose `Host` header does not match. Don't remove it from the middleware queue in `src/Application.php`; if a deployment fails on `InternalErrorException: App.fullBaseUrl is not configured`, the fix is to set the env var, not to delete the middleware.
- **CSRF middleware is enabled globally** with `httponly` cookies (`Application::middleware()`). All non-GET form submissions need the CakePHP CSRF token (`$this->Form->create(...)` injects it automatically).
- **Body parser middleware** is on, so `$request->getData()` works for JSON and form-encoded bodies alike.
- **Routes** currently expose only `Pages::display` and a `fallbacks()` for `/{controller}/{action}/*` (`config/routes.php`). When you add real modules, register explicit routes inside the `'/'` scope rather than relying on fallbacks.
- **Local config** (`config/app_local.php`) reads from env (`DEBUG`, `SECURITY_SALT`, `DATABASE_URL`, `DATABASE_TEST_URL`, `APP_FULL_BASE_URL`, `EMAIL_TRANSPORT_DEFAULT_URL`). It is git-ignored for credentials. Default datasource is MySQL-style (`my_app`/`secret`/`my_app` on `localhost`); test datasource defaults to SQLite at `tmp/tests.sqlite`.

## Domain shape (read `davirapid.md` for the full spec)

The system has five module groups that interact tightly — do not design any one of them in isolation:

- **Operación:** Pedidos (orders, single- or multi-product, local vs domicilio, lifecycle `recibido → preparando → en_camino → entregado`, with `cancelado` branch and manual reactivation), Productos, Clientes, Repartidores, Auditoría de Pedidos.
- **Inventario:** Ingredientes, Recetas (`product × ingredient × qty`), Ajustes de Inventario. **Selling a product auto-decrements ingredient stock via its recipe; cancelling restores it; editing restores old and decrements new.** A product without a recipe causes no inventory movement.
- **Finanzas:** Gastos, Cuentas por Cobrar (created automatically when an order's payment method is `Crédito`), Abonos (partial payments; auto-flip CxC to `pagado` once total abonos ≥ deuda), Cierre Diario (`expected = (non-credit sales + abonos hoy) − gastos hoy`, then physical count and difference).
- **Administración:** RBAC con roles libres + matriz Ver/Crear/Editar/Eliminar por módulo. The fixed **Administrador** user bypasses the matrix, has total access, cannot be deleted, and is the only one who can manage Roles. A user linked to a `Repartidor` only sees their own orders/metrics regardless of role.
- **Análisis:** Dashboard. Cancelled orders are excluded from every metric. **Credit (fiado) is not income until it is abonado** — the dashboard reflects real cash flow, not gross billing. Multi-product orders count as a single transaction.

Authentication: login + lockout (5 fails → 15-min block), counters reset on success.

## Design system

Before adding any UI, read `.claude/rules/DESIGN.md`. Hard rules from it that are easy to violate:

- `primary` (#E63027, brand red) is for the single most important action per screen and brand markers — never as a large background. Destructive actions use `button-danger` (#D32F2F), not `primary`.
- Use `*-soft` tints (~10%) for badge/alert/active-nav backgrounds, not saturated fills.
- Spacing follows an 8px scale with 4px half-step. Buttons, inputs and selects on the same row are all 40px tall.
- Order-lifecycle states use the dedicated `status-pending` / `status-preparing` / `status-on-route` / `status-delivered` / `status-cancelled` family, not the generic `badge-*` semantics.
- One typeface (Inter), max three type sizes per view, max two semantic colors per component.

## Language

Project documentation, business spec, and UI copy are in **Spanish**. Code identifiers stay in English (CakePHP convention). Match the existing convention when adding files.
