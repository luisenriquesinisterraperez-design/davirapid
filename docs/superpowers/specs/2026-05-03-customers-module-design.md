# Customers Module — Design Spec

- **Date:** 2026-05-03
- **Phase:** 1 — Catálogos base
- **Module:** Clientes (`davirapid.md` §6)
- **Status:** Approved, ready for implementation plan

## 1. Purpose and scope

The **Customers** module is the second catálogo base after Productos. It provides a directory of recurring customers used to (a) speed up order taking via phone autocomplete and (b) anchor every account receivable (CxC) to a real customer record.

In scope:

- CRUD of customers with name, phone, free-text address.
- Phone-based identity: phone is unique and acts as the de facto lookup key.
- Toggle `is_active` from the listing without deleting.
- Hard delete only when the customer has no dependent records (orders, CxC); soft delete (deactivate) otherwise.
- RBAC integration with the existing `permissions` matrix.
- Service method `findOrCreateByPhone()` ready to be consumed by Pedidos + CxC when those modules exist.

Out of scope (deferred):

- Structured address (street, neighborhood, references). Free-text covers the operational reality; a richer schema can be introduced later if reports demand it.
- Customer-side pages (Pedidos del cliente, Cuenta por cobrar tab). Placeholders only in `view.php`; real data lands when those modules ship.
- Auto-creation flow from the order form. Service is ready; the wiring belongs to the Pedidos spec.
- Bulk operations, exports, imports. YAGNI.

## 2. Schema

Table `customers`:

| Column | Type | Notes |
|---|---|---|
| `id` | int PK, auto-increment, signed | matches existing FK conventions |
| `name` | string(150) | not null |
| `phone` | string(30) | not null, **UNIQUE** |
| `address` | string(255) | nullable (some customers are walk-ins) |
| `is_active` | boolean | not null, default `true` |
| `created` | datetime | nullable, populated by Timestamp behavior |
| `modified` | datetime | nullable, populated by Timestamp behavior |

Indexes:

- `UNIQUE(phone)` — enforces phone-as-identifier; required for `findOrCreateByPhone` to be deterministic.
- (Optional) `INDEX(is_active)` — only if listing performance becomes an issue. Skip for now (YAGNI).

No outbound foreign keys. Inbound FKs will be added by future migrations (`orders.customer_id`, `accounts_receivable.customer_id`) with `ON DELETE RESTRICT` so the application-level guard is reinforced at DB level.

Migration filename: `20260503HHMMSS_CreateCustomers.php` (extends `Migrations\BaseMigration`, protected with `hasTable('customers')`).

## 3. Layers

### 3.1 Constants

`src/Constants/CustomerConstants.php` — created only if labels are needed in views. Initial content:

```php
final class CustomerConstants
{
    public const PHONE_MAX_LENGTH = 30;
    public const NAME_MAX_LENGTH = 150;
    public const ADDRESS_MAX_LENGTH = 255;
}
```

No status enum needed (active is just a boolean).

### 3.2 Entity — `Customer`

```php
class Customer extends Entity
{
    protected array $_accessible = [
        'name' => true,
        'phone' => true,
        'address' => true,
        'is_active' => true,
    ];

    public function isActive(): bool { return (bool)$this->is_active; }

    protected function _getDisplayName(): string
    {
        return trim(($this->name ?? '') . ' — ' . ($this->phone ?? ''));
    }
}
```

`displayName` virtual is what the future Orders autocomplete will surface.

### 3.3 Table — `CustomersTable`

- `setTable('customers')`, `setPrimaryKey('id')`, `addBehavior('Timestamp')`.
- No associations declared in this phase. (When `Orders` and `AccountsReceivable` exist, `hasMany` declarations are added then.)
- `validationDefault()`:
  - `name`: notEmptyString, maxLength 150.
  - `phone`: notEmptyString, maxLength 30, scalar string. No format regex (numbers, spaces, dashes, country prefixes all valid).
  - `address`: allowEmptyString, maxLength 255.
  - `is_active`: boolean.
- `buildRules()`: `add($rules->isUnique(['phone'], 'Ya existe un cliente con este teléfono.'))`.
- Custom finders:
  - `findActive(SelectQuery $q): SelectQuery` — `where(['is_active' => true])`.
  - `findByPhone(SelectQuery $q, array $options): SelectQuery` — `where(['phone' => $options['phone']])`. Used by service.
- Do **not** override `findList()` (CakePHP 5 incompatibility — see ARQUITECTURE §4.4).

### 3.4 Service — `CustomerService`

Single service, no family needed (no state machine, no filters complex enough to extract, no field-level history requirement).

```php
class CustomerService
{
    public function __construct(?CustomersTable $customers = null)
    {
        $this->customers = $customers ?? TableRegistry::getTableLocator()->get('Customers');
    }

    public function create(array $data): array;            // returns ['success' => bool, 'entity' => Customer|null, 'errors' => array]
    public function update(Customer $c, array $data): array;
    public function delete(Customer $c): array;            // guard: refuse if has orders or CxC
    public function toggleActive(Customer $c): array;
    public function findOrCreateByPhone(array $data): Customer;  // for Pedidos integration
}
```

- `delete()` guard: queries `orders` and `accounts_receivable` for FKs to this customer. Because those tables may not exist yet, the guard inspects `$connection->getSchemaCollection()->listTables()` first and skips checks for absent tables. When tables exist but have no rows for this customer, hard delete proceeds. When at least one dependent exists, returns `['success' => false, 'errors' => ['No se puede eliminar: tiene N pedidos / M cuentas asociadas. Desactive el cliente en su lugar.']]`.
- `toggleActive()`: flips `is_active`, saves, returns structured result.
- `findOrCreateByPhone()`: finds existing customer by phone (custom finder); if absent, creates with provided `name`, `phone`, `address`. Used later by the order-creation flow when payment method is Crédito and the entered phone is unknown. Throws on persistence failure (truly exceptional — DB down or schema mismatch).
- No transactions: all operations touch a single table. CakePHP's internal save handling is sufficient.

### 3.5 Controller — `CustomersController`

- Extends `AppController`. `paginate = ['limit' => 15, 'maxLimit' => 15]`.
- Instantiates `CustomerService` in `initialize()`.
- Actions:
  - `index()` — applies filters via `_buildCustomersQuery()`, paginates.
  - `view($id)` — `$this->Customers->get($id)` (404 propagated). Sets placeholders `$ordersPlaceholder = true`, `$cxcPlaceholder = true` until those modules ship.
  - `add()` / `edit($id)` — POST delegates to `CustomerService::create/update`. Flash + redirect on success; rerender form with errors otherwise.
  - `delete($id)` — POST only, delegates to `CustomerService::delete`. Flash success/error.
  - `toggleActive($id)` — POST only, delegates to `CustomerService::toggleActive`. Custom route required.
- Private `_buildCustomersQuery(array $conditions = [])` — base query with active+search filters applied.

### 3.6 Routes

CRUD covered by `$builder->fallbacks()`. Add **before** the fallback in `config/routes.php`:

```php
$builder->connect(
    '/customers/toggle-active/{id}',
    ['controller' => 'Customers', 'action' => 'toggleActive'],
    ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST']
);
```

## 4. RBAC

Three updates, parallel to Productos:

1. **`AppController::$controllerModuleMap`** — add `'Customers' => 'customers'`.
2. **`AuthorizationService::MODULES`** — add `'customers' => 'Clientes'`.
3. **`AuthorizationService::isAllowed()`** action mapping — `toggleActive` resolves to the `edit` permission column (same approach used for Productos' toggle).
4. **Seed migration** `SeedCustomersPermissions` — inserts permission rows for every existing non-Administrator role with the same default policy used for Productos (Cajero: view + create; supervisor roles: view + create + edit; deletion only for the seeded admin-level role if any). Administrator user bypasses by design.

## 5. UI

Templates under `templates/Customers/`. All views use the authenticated `default` layout. Visual rules per `.claude/rules/DESIGN.md`.

### 5.1 `index.php`

- Page header: title "Clientes", primary button **"Nuevo cliente"** (right-aligned, only one primary on screen).
- Filter bar (single row, 40px controls): text input (search by name OR phone), `is_active` select (Todos / Activos / Inactivos), submit button-secondary "Filtrar".
- Table columns: **Nombre**, **Teléfono** (mono font), **Dirección** (truncated to 1 line with title attr for full text), **Estado** (badge `badge-success-soft` / `badge-neutral-soft`), **Acciones**.
- Inline toggle on `is_active` column (POST form to `toggleActive` route, same pattern as Productos).
- Row actions: button-icon view, edit, delete (delete uses confirmation modal).
- Pagination via `<?= $this->element('pagination') ?>`.

### 5.2 `_form.php` (partial, used by add/edit)

- Single column. Fields in order: `name`, `phone`, `address`, `is_active` (only on edit; on add defaults to true and is hidden).
- `address` is a textarea of 2 rows (still backed by string(255); it's just to make multi-line free typing comfortable).
- Helper text on `phone`: "Único. Se usará para identificar al cliente al cobrar a crédito."
- Inline validation errors below each field.
- Footer: button-tertiary "Cancelar" (back link), button-primary "Guardar".

### 5.3 `add.php` / `edit.php`

Thin wrappers around `_form.php` with the appropriate page title and breadcrumb.

### 5.4 `view.php`

- Two-column layout on desktop, stacked on mobile.
- Left card: Datos del cliente (name, phone, address, status badge).
- Right column: two placeholder cards labeled "Pedidos del cliente" and "Cuenta por cobrar" with a muted "Disponible cuando se habilite el módulo" note. These are intentional anchors so the page already has its final shape.
- Header actions: button-secondary "Editar", button-danger "Eliminar" (with the same confirmation pattern as Productos).

### 5.5 Sidebar

`SidebarHelper` registers a "Clientes" entry under the **Operación** group, with icon `bi-people`, ordered between Productos and Repartidores (when Repartidores ships). Active-state styling per the existing helper.

## 6. Errors and validation

- **Format-level** (Table): not-empty / max-length on name and phone, max-length on address, unique rule on phone.
- **Business-level** (Service): the delete guard. No other business rules in this module — customer state is just `is_active`, which has no transition rules.
- **Controller**: Flash + redirect. Validation errors keep the user on the form with messages inline.
- **HTTP**: `get($id)` raises `NotFoundException` automatically.

## 7. Logging

Logged via `Cake\Log\Log`:

- Failed creates/updates (with `entity->getErrors()` and the input fingerprint, **excluding** sensitive fields — though customers have none today).
- Delete attempts that hit the dependency guard (info level — useful telemetry on operational patterns).
- `findOrCreateByPhone` creating a new record (info, with the phone), so the future Pedidos flow leaves an audit trail.

Not logged: every successful CRUD (noise), pagination queries, validation errors at the field level (already surfaced to the user).

## 8. Decisions and rationale

| Decision | Choice | Reason |
|---|---|---|
| Address shape | Single free-text field | Simpler form, matches what gets printed on the ticket verbatim. Users said (a) in brainstorming. |
| Phone uniqueness | Unique + required | Phone is the de facto identifier (auto-creation, autocomplete). Uniqueness makes lookup deterministic. Users said (a). |
| Deletion policy | Hard delete with guard + soft delete via `is_active` | Mirrors Productos. Real customers with history don't get deleted; clean records that never operated can be removed. Users said (c). |
| Service shape | Single `CustomerService` | No state machine, no field history, no complex filters → no service family. |
| `findOrCreateByPhone` ahead of Pedidos | Yes, included now | Trivial to add now and avoids a follow-up edit when Pedidos lands. |
| Constants class | Created but minimal | Keeps the convention of "no domain literals in PHP" even if the values are mostly schema metadata. |

## 9. Out of scope (explicit)

- Bulk import / export.
- Address geolocation.
- Customer tagging or segmentation.
- "Frequent customer" badges or loyalty.
- Merging duplicate customers (becomes a question if phone uniqueness is ever relaxed; for now, can't happen).
