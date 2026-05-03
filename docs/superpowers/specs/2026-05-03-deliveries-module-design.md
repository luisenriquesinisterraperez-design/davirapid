# Deliveries Module — Design Spec

- **Date:** 2026-05-03
- **Phase:** 2 — Operación (paso 1: Repartidores)
- **Module:** Repartidores (`davirapid.md` §7)
- **Status:** Approved, ready for implementation plan

## 1. Purpose and scope

The **Repartidores** (Deliveries) module manages the delivery staff who handle domicilio orders. It exists before Pedidos because every order of type `domicilio` will reference a delivery record, and because a delivery may be linked to a system user — which determines whose dashboard scopes to a single rider.

In scope:

- CRUD of deliveries with first name, last name, phone.
- Soft delete via `is_active` (no hard delete exposed). Reactivable.
- Optional 1:1 link to a system user via `users.delivery_id`. The link is managed from the **Users** form (FK lives on `users`).
- RBAC integration with the existing `permissions` matrix.
- Listing with text search and active-state filter, pagination at 15.

Out of scope (deferred):

- Selecting a delivery from the order form (lives in the Pedidos spec).
- Filtering orders/dashboard by `currentUser->delivery_id` (also Pedidos / Dashboard).
- Earnings reports based on accumulated `costo_envio` (Dashboard concern; no schema impact here).
- Schedules, shifts, vehicle data, document uploads. YAGNI — `davirapid.md` §7 only specifies name/phone.

## 2. Schema

### 2.1 Table `deliveries`

| Column | Type | Notes |
|---|---|---|
| `id` | int PK, auto-increment, signed | matches existing FK conventions |
| `first_name` | string(60) | not null |
| `last_name` | string(60) | not null |
| `phone` | string(20) | not null |
| `is_active` | boolean | not null, default `true` |
| `created` | datetime | nullable, populated by Timestamp behavior |
| `modified` | datetime | nullable, populated by Timestamp behavior |

Indexes:

- `INDEX idx_deliveries_active_name (is_active, last_name, first_name)` — supports the default listing (active first, alphabetical) and the active-only finder used by selectors.

No outbound foreign keys. Inbound FK from `users.delivery_id` (next migration). Future inbound FK from `orders.delivery_id` will be added by the Pedidos spec with `ON DELETE RESTRICT`.

Migration filename: `20260503HHMMSS_CreateDeliveries.php` (extends `Migrations\BaseMigration`, protected with `hasTable('deliveries')`).

### 2.2 Migration `AddDeliveryIdToUsers`

Extends the existing `users` table:

| Column | Type | Notes |
|---|---|---|
| `delivery_id` | int signed, nullable | FK → `deliveries(id)` |

Constraints:

- `FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE SET NULL ON UPDATE CASCADE` — defensive: if a delivery is ever hard-deleted (out of band), the user account survives unbroken. The application never exposes hard delete.
- `UNIQUE INDEX uq_users_delivery_id (delivery_id)` — at most one user per delivery (1:1 optional). MySQL allows multiple NULLs in a UNIQUE column, which is exactly the behavior we want (many users without a delivery, one user per delivery if linked).

Migration filename: `20260503HHMMSS+1_AddDeliveryIdToUsers.php`. Must run after `CreateDeliveries`. Protected by checking `hasColumn('users', 'delivery_id')` before adding.

## 3. Layers

### 3.1 Constants

`src/Constants/DeliveryConstants.php`:

```php
final class DeliveryConstants
{
    public const NAME_MAX_LENGTH = 60;
    public const PHONE_MAX_LENGTH = 20;
}
```

No status enum (active is a boolean, no state machine).

### 3.2 Entity — `Delivery`

```php
class Delivery extends Entity
{
    protected array $_accessible = [
        'first_name' => true,
        'last_name'  => true,
        'phone'      => true,
        'is_active'  => true,
    ];

    public function isActive(): bool
    {
        return (bool)$this->is_active;
    }

    protected function _getFullName(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
    }
}
```

### 3.3 Entity — `User` (touch)

- Add `'delivery_id' => true` to `$_accessible`.
- No virtual fields.

### 3.4 Table — `DeliveriesTable`

- `setTable('deliveries')`, `setPrimaryKey('id')`, `addBehavior('Timestamp')`.
- Associations:
  - `hasOne('Users', ['foreignKey' => 'delivery_id'])` — the optional system-user side of the 1:1.
  - `hasMany('Orders', ['foreignKey' => 'delivery_id'])` — **deferred** to the Pedidos spec. Not declared in this phase to avoid `FactoryLocator` errors (the `OrdersTable` class won't exist yet).
- `validationDefault()`:
  - `first_name`: `notEmptyString` (with Spanish message), `maxLength` 60.
  - `last_name`: same.
  - `phone`: `notEmptyString`, `maxLength` 20, regex `/^[0-9 +\-()]+$/` (digits, spaces, `+`, `-`, parentheses — permissive enough for any local format).
  - `is_active`: `boolean`.
- Custom finders:
  - `findActive(SelectQuery $q): SelectQuery` — `where(['is_active' => true])`.
  - `findFullNameList(SelectQuery $q): SelectQuery` — formatted as `id => "Apellido, Nombre"` for use in dropdowns.
- Do **not** override `findList()` (CakePHP 5 incompatibility — see ARQUITECTURE §4.4).

### 3.5 Table — `UsersTable` (touch)

- Declare `belongsTo('Deliveries', ['foreignKey' => 'delivery_id', 'joinType' => 'LEFT'])`.
- Update `validationDefault()` to allow `delivery_id` as an optional positive integer when present (`allowEmptyString` + `naturalNumber`).
- The uniqueness of `delivery_id` in `users` is enforced by the DB UNIQUE index. Add `buildRules()` rule `add($rules->isUnique(['delivery_id'], ['allowMultipleNulls' => true], 'Este repartidor ya está vinculado a otro usuario.'))` so the user gets a friendly error before hitting the DB constraint.

### 3.6 Service — `DeliveryService`

Single service. No `PipelineService` (no state machine), no `FilterService` (search is two columns), no `HistoryService` (no field-level audit requirement).

```php
class DeliveryService
{
    public function __construct(?DeliveriesTable $deliveries = null)
    {
        $this->deliveries = $deliveries
            ?? TableRegistry::getTableLocator()->get('Deliveries');
    }

    public function create(array $data): array;            // ['success' => bool, 'entity' => Delivery|null, 'errors' => string[]]
    public function update(Delivery $d, array $data): array;
    public function deactivate(Delivery $d): array;        // sets is_active = false; "delete" from UI
    public function activate(Delivery $d): array;          // reactivation
    public function toggleActive(Delivery $d): array;      // dispatch helper for the inline toggle in index
}
```

- `create()` and `update()`: `patchEntity` + `save`; on failure return errors flattened from `$entity->getErrors()`.
- `deactivate()` / `activate()`: flip `is_active`, save, return structured result.
- No transactions — single-table operations.
- No real `delete()` method exposed. Hard delete is not part of the surface area (decision in §1).

### 3.7 Service — `UserService` (touch)

When `delivery_id` is present in the input for `create()` / `update()`:

1. Validate the referenced delivery exists.
2. Validate it is active (`is_active = true`).
3. Validate it is not already taken by another user (when editing, exclude the current user's id from the check).

On any failure, return a business-level error in Spanish (e.g., `"El repartidor seleccionado está inactivo."`, `"El repartidor seleccionado ya está vinculado a otro usuario."`). These checks live in the service so both `add` and `edit` flows get them and they don't leak into the controller.

## 4. Controller — `DeliveriesController`

- Extends `AppController`. `paginate = ['limit' => 15, 'maxLimit' => 15]`.
- Instantiates `DeliveryService` in `initialize()`.
- Actions:
  - `index()` — applies filters via `_buildDeliveriesQuery()`, paginates, sets `$deliveries`, `$q`, `$status`.
  - `view($id)` — `$this->Deliveries->get($id, contain: ['Users'])` (404 propagated). Sets `$delivery`. Includes a placeholder section in the view for orders (filled in by the Pedidos spec).
  - `add()` — POST delegates to `DeliveryService::create`. Flash + redirect on success; rerender form with `$delivery->getErrors()` on failure.
  - `edit($id)` — same shape, POST delegates to `DeliveryService::update`.
  - `toggleActive($id)` — POST only, delegates to `DeliveryService::toggleActive`. Flash + redirect to referer or index.
- No `delete` action (decision in §1).
- Private `_buildDeliveriesQuery(array $conditions = [])`: base query with `contain(['Users'])`, applies `$q` against `first_name`/`last_name`/`phone` with `LIKE`, applies `$status` (`active`/`inactive`/`all`), default sort by `is_active DESC, last_name ASC, first_name ASC`.

## 5. Routes

CRUD covered by `$builder->fallbacks()`. Add **before** the fallback in `config/routes.php`:

```php
$builder->connect(
    '/deliveries/toggle-active/{id}',
    ['controller' => 'Deliveries', 'action' => 'toggleActive'],
    ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST']
);
```

## 6. RBAC

Three updates plus a seed migration, parallel to Customers and Products:

1. **`AppController::$controllerModuleMap`** — add `'Deliveries' => 'deliveries'`.
2. **`AuthorizationService::MODULES`** — add `'deliveries' => 'Repartidores'`.
3. **`AuthorizationService::isAllowed()`** action mapping — `toggleActive` resolves to the `edit` permission column (same approach as Productos / Customers).
4. **Seed migration** `SeedDeliveriesPermissions` — inserts permission rows for every existing non-Administrator role with a conservative default: `can_view = 1`, the rest `0`. The Administrator user bypasses the matrix by design; the human admin can broaden permissions per role from the UI later.

## 7. UI

Templates under `templates/Deliveries/`. All views use the authenticated `default` layout. Visual rules per `.claude/rules/DESIGN.md`.

### 7.1 `index.php`

- Page header: title "Repartidores", primary button **"Nuevo repartidor"** (right-aligned, only one primary on screen).
- Filter bar (single row, 40px controls): text input (search by name OR phone), `status` select (Todos / Activos / Inactivos), submit button-secondary "Filtrar".
- Table columns: **Nombre completo**, **Teléfono** (mono font), **Usuario vinculado** (badge with user's name if linked, "—" otherwise), **Estado** (badge `badge-success-soft` / `badge-neutral-soft`), **Acciones**.
- Inline toggle on `is_active` column (POST form to `toggleActive` route, same pattern as Productos / Customers).
- Row actions: button-icon view, edit. **No delete icon** — deactivation is the toggle.
- Pagination via `<?= $this->element('pagination') ?>`.

### 7.2 `_form.php` (partial, used by add/edit)

- Single column. Fields in order: `first_name`, `last_name`, `phone`, `is_active` (only on edit; on add defaults to true and is hidden).
- Helper text on `phone`: "Solo dígitos, espacios, `+`, `-` y paréntesis."
- Inline validation errors below each field.
- Footer: button-tertiary "Cancelar" (back link), button-primary "Guardar".
- The link with a system user is **not** here (decision in Q4 — managed from the Users form).

### 7.3 `add.php` / `edit.php`

Thin wrappers around `_form.php` with the appropriate page title and breadcrumb.

### 7.4 `view.php`

- Two-column layout on desktop, stacked on mobile.
- Left card: Datos del repartidor (full name, phone, status badge).
- Right column: two cards.
  - **"Cuenta de sistema"**: shows the linked user (name + role + "Editar usuario" link), or a muted "Sin cuenta de sistema asignada" with a link to create one.
  - **"Pedidos asignados"**: placeholder card with a muted "Disponible cuando se habilite el módulo Pedidos" note. Intentional anchor so the page already has its final shape.
- Header actions: button-secondary "Editar", button-tertiary "Desactivar"/"Activar" (label depends on current state). No "Eliminar".

### 7.5 Users form (touch)

- The shared `_form.php` partial in `templates/Users/` gains a "Repartidor vinculado (opcional)" select.
- Options come from `Deliveries->find('fullNameList')->find('active')`, **minus** deliveries already linked to another user (when editing, the current user's existing `delivery_id` is included so it stays selectable).
- Empty option: "— Ninguno —".
- `templates/Users/view.php` shows the linked delivery in the user's detail card if present.

### 7.6 Sidebar

`SidebarHelper` registers a "Repartidores" entry under the **Operación** group, with a delivery/scooter icon, ordered after Clientes (Operación group order: Productos → Clientes → Repartidores → Pedidos when it ships). Active-state styling per the existing helper.

## 8. Errors and validation

- **Format-level** (Table): not-empty / max-length on names and phone, regex on phone, boolean on `is_active`.
- **Business-level** (Service):
  - `DeliveryService` — none beyond format. (Activation/deactivation is unconditional from the service side; the controller is what decides who can call it via RBAC.)
  - `UserService` — the three checks on `delivery_id` (exists, active, not taken by another user).
- **Controller**: Flash + redirect. Validation errors keep the user on the form with messages inline.
- **HTTP**: `get($id)` raises `NotFoundException` automatically.

## 9. Logging

Logged via `Cake\Log\Log`:

- Failed creates/updates (with `entity->getErrors()`).
- Toggling `is_active` (info level, with `delivery_id` and the new state) — useful operational telemetry.
- `UserService` rejecting a `delivery_id` link with the reason (info level).

Not logged: every successful CRUD (noise), pagination queries, validation errors at the field level (already surfaced to the user).

## 10. Decisions and rationale

| Decision | Choice | Reason |
|---|---|---|
| Sequencing | Build modules in strict dependency order (option **A**) | User accepted A in brainstorming. Repartidores comes before Pedidos because every domicilio order references a delivery. |
| FK direction for User↔Delivery | `users.delivery_id` (option **A** of Q2) | Already implied by ARQUITECTURE §5.3 (`currentUser->delivery_id` for scoping). Keeps the per-user filter trivial. |
| Delete policy | Soft delete via `is_active` (option **B** of Q3) | Preserves historical traceability; aligns with `is_active` convention used in Products and Customers; avoids "cannot delete because…" UX dead-ends. |
| Where to manage the link | Users form only (option **B** of Q4) | FK lives on users, so "this user IS delivery X" is the natural reading; one place to maintain consistency. |
| Service shape | Single `DeliveryService` | No state machine, no field history, no complex filters → no service family. |
| `hasMany Orders` declared now | No, deferred | `OrdersTable` class doesn't exist yet; `FactoryLocator` fallback is disabled (`Application::bootstrap()`), so referencing it would crash. Added when Pedidos ships. |
| Delete cascade on `users.delivery_id` | `ON DELETE SET NULL` | Defensive only — UI never hard-deletes deliveries. If a DBA ever does it out of band, user accounts survive. |

## 11. Manual verification checklist

(No automated tests — project preference recorded in memory.)

1. Crear repartidor con datos válidos → aparece en index, activo.
2. Editar repartidor → cambios persisten.
3. Desactivar repartidor desde el toggle del index → estado cambia, sigue visible con filtro "Inactivos".
4. Reactivar repartidor → vuelve a estado activo.
5. Crear usuario y vincularlo a un repartidor activo → vínculo persiste; en `view.php` del repartidor se ve el usuario.
6. Editar otro usuario y abrir el selector de repartidor → el repartidor ya vinculado **no** aparece como opción.
7. Editar el usuario originalmente vinculado → su repartidor sigue apareciendo seleccionado.
8. Intentar vincular un usuario a un repartidor inactivo → error de negocio en español, formulario rerenderea con el mensaje.
9. Desactivar un repartidor que tiene usuario vinculado → permitido; el vínculo se mantiene; el usuario sigue activo.
10. Login con un usuario vinculado a repartidor → la sesión expone `currentUser->delivery_id` (preparando el filtrado del módulo Pedidos; verificar con `dd()` o log temporal).
11. RBAC: con un rol sin `can_view` para `deliveries`, el item del sidebar no aparece y `/deliveries` devuelve forbidden/redirect.
12. RBAC: con un rol con `can_view` pero sin `can_edit`, el botón "Editar" y el toggle no aparecen / no se pueden ejecutar.

## 12. Out of scope (explicit)

- Earnings reports per delivery (Dashboard).
- Order assignment UI (Pedidos).
- Per-delivery scoping of the orders list / dashboard (applied in Pedidos and Dashboard specs using `currentUser->delivery_id`).
- Vehicle data, schedules, document uploads.
- Bulk import / export.
