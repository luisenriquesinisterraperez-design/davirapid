# Products Module — Design Spec

- **Date:** 2026-05-02
- **Phase:** 1 — Catálogos base (módulo piloto)
- **Module:** Productos (`davirapid.md` §5)
- **Status:** Approved, ready for implementation plan

## 1. Purpose and scope

This spec covers the design of the **Products** module — the first of four catálogos base (Productos, Clientes, Repartidores, Ingredientes) and the chosen **pilot module** for Phase 1. Decisions made here set the project-wide conventions that the next three CRUDs will replicate, especially around:

- File uploads (first module of the project to require them).
- Monetary fields (first module to model money; the chosen format propagates to all financial tables).
- Soft-delete vs deactivation policy.
- Service decomposition and validation split between `Table` and `Service` layers.

In scope:

- CRUD of products with image upload.
- Toggle availability without deleting.
- Refuse hard delete when the product has sales.
- RBAC integration with the existing `permissions` matrix.

Out of scope (deferred to later phases or modules):

- **Categorías.** `davirapid.md` does not require them. They will be evaluated in Phase 3 (Pedidos) when there is real UX context for grouping the menu. If needed, they will be added then.
- **Recetas.** Belong to Phase 2; the `ProductsTable` will be ready to declare `hasMany ProductIngredients` when the table exists, but the relationship is not wired in this phase.
- **Operaciones por lote** (multi-edit, masivo). Out of scope; YAGNI.

## 2. Schema

### 2.1 Table `products`

| Column | Type | Constraints |
|---|---|---|
| `id` | `int unsigned` | PK auto increment |
| `code` | `varchar(20)` | Nullable. Unique when not null. Allowed: `[A-Za-z0-9-]+` |
| `name` | `varchar(120)` | Not null |
| `description` | `text` | Nullable |
| `price` | `decimal(12,0)` | Not null. Business rule: `>= 1` (enforced in validator and via DB `CHECK (price >= 1)` where the engine supports it) |
| `image_path` | `varchar(255)` | Nullable. Path relative to `webroot/`, e.g. `uploads/products/42/product_68a9bc.jpg` |
| `is_active` | `boolean` | Not null. Default `true` |
| `created` | `datetime` | Nullable. Managed by `Timestamp` behavior |
| `modified` | `datetime` | Nullable. Managed by `Timestamp` behavior |

### 2.2 Indexes

- `UNIQUE (code)` — MySQL ignores `NULL` rows in unique indexes, so multiple products without a code coexist without collision.
- Index `(is_active, name)` — accelerates listing filtered by status and ordered by name.

### 2.3 No soft-delete column

`davirapid.md` §5 already resolves the problem with `is_active`: products with sales are not deleted, they are deactivated. A `deleted_at` column would be redundant. If a future case requires it, it will be added then.

### 2.4 Currency convention (system-wide decision made here)

All monetary fields across Davi Rapid use **`decimal(12,0)`** — whole Colombian pesos (COP), no decimals. (`unsigned` on `decimal` is intentionally not used: MySQL 8.0.17+ deprecates it and SQLite — the test datasource — does not support it. Non-negativity is enforced by validators and, where the engine supports it, a `CHECK` constraint.) This decision propagates to:

- `order_items.price`
- `expenses.amount`
- `account_payments.amount`
- `accounts_receivable.total`
- `cash_closings.*` (every monetary column)

Rationale: COP operates in whole pesos in real fast-food businesses; rounding is done at $100 or $500. Decimals are unused and only introduce a class of rounding bugs. If a currency with decimals appears, that specific column is migrated.

### 2.5 Migration

- File: `config/Migrations/YYYYMMDDHHMMSS_CreateProducts.php`
- Base class: `Migrations\BaseMigration` (NOT `AbstractMigration`)
- Idempotent: guarded with `$this->hasTable('products')`
- No foreign keys outward — Products depends on nothing.
- Future tables (`product_ingredients`, `order_items`) will declare FKs toward `products.id` from their own migrations.

## 3. Constants

`src/Constants/ProductConstants.php`:

```php
final class ProductConstants
{
    public const IMAGE_MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB
    public const IMAGE_ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];
    public const IMAGE_TARGET_WIDTH = 800;
    public const IMAGE_TARGET_HEIGHT = 800;
    public const IMAGE_JPEG_QUALITY = 85;
    public const PRICE_MIN = 1;
    public const CODE_MAX_LENGTH = 20;
    public const CODE_PATTERN = '/^[A-Za-z0-9-]+$/';
}
```

There is no `STATUS_*` family because `is_active` is a binary toggle, not an enum.

## 4. Entity and Table

### 4.1 `Product` entity

```php
class Product extends Entity
{
    protected array $_accessible = [
        'code' => true,
        'name' => true,
        'description' => true,
        'price' => true,
        'image_path' => true,
        'is_active' => true,
    ];

    public function getImageUrl(): string
    {
        return $this->image_path
            ? '/' . ltrim($this->image_path, '/')
            : '/img/product-placeholder.svg';
    }

    public function getFormattedPrice(): string
    {
        return '$' . number_format((int) $this->price, 0, ',', '.');
    }
}
```

The placeholder SVG ships in `webroot/img/product-placeholder.svg`.

### 4.2 `ProductsTable`

```php
class ProductsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('products');
        $this->setPrimaryKey('id');
        $this->setDisplayField('name');
        $this->addBehavior('Timestamp');
        // Future associations (declared when their tables exist):
        // $this->hasMany('ProductIngredients');
        // $this->hasMany('OrderItems');
    }

    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->notEmptyString('name', 'El nombre es requerido')
            ->maxLength('name', 120)
            ->notEmptyDecimal('price', 'El precio es requerido')
            ->greaterThanOrEqual('price', ProductConstants::PRICE_MIN, 'El precio debe ser mayor a 0')
            ->allowEmptyString('code')
            ->maxLength('code', ProductConstants::CODE_MAX_LENGTH)
            ->add('code', 'codePattern', [
                'rule' => fn($v) => (bool) preg_match(ProductConstants::CODE_PATTERN, $v),
                'message' => 'El código solo permite letras, números y guiones',
            ])
            ->boolean('is_active');
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(
            ['code'],
            ['allowMultipleNulls' => true, 'message' => 'Ese código ya está en uso']
        ));
        return $rules;
    }
}
```

`findList()` is **not** overridden (CakePHP 5 signature is incompatible per ARQUITECTURE.md §3.4). If a formatted list is needed downstream, a custom finder with another name (e.g. `findCodeList`) is added.

## 5. Services

Per ARQUITECTURE.md §4.13 (opt-in service families), the Products domain needs **two** of the four possible types:

| Service | Included | Why |
|---|---|---|
| `ProductService` | Yes | Core operations and business rules across tables |
| `ProductImageService` | Yes | Filesystem ops; isolated to be reused by future modules with uploads |
| `ProductPipelineService` | No | No state machine; `is_active` is a binary toggle |
| `ProductFilterService` | No | Listing filters fit in 2-3 `where()` clauses; extract if/when they grow |
| `ProductHistoryService` | No | `davirapid.md` only requires audit for Pedidos |

### 5.1 `ProductService`

```php
final class ProductService
{
    public function __construct(?ProductImageService $images = null)
    {
        $this->images = $images ?? new ProductImageService();
    }

    public function create(array $data, ?UploadedFileInterface $image): array;
    public function update(Product $product, array $data, ?UploadedFileInterface $image): array;
    public function delete(Product $product): array;
    public function toggleActive(Product $product): array;
    public function hasSales(Product $product): bool;
}
```

**Return contract** (consistent with ARQUITECTURE.md §4.8):

```php
[
    'success' => bool,
    'product' => ?Product,
    'errors'  => string[],
    'warnings'=> string[],
]
```

**Behavior:**

- `create()` and `update()` use `Connection::transactional()` when an image is involved, so a failed upload does not leave an orphan product (or vice versa).
- `delete()` calls `hasSales()` first; if true, returns `success => false` with the message `"No se puede eliminar un producto con ventas. Desactivalo en su lugar."`. **No exception** — this is expected flow, not exceptional.
- `delete()` invokes `ProductImageService::deleteFile()` before deleting the DB row, so the upload directory does not pile up orphan files.
- `toggleActive()` flips `is_active` and saves; returns the updated product so the controller can report the new state.
- `hasSales()` checks the existence of `order_items` rows referencing the product. **It is tolerant of `OrderItems` not yet existing**: uses `TableRegistry::getTableLocator()->exists('OrderItems')` and returns `false` if the table has not been migrated. This lets Phase 1 build Products before Pedidos without conditional branches in the controller.

### 5.2 `ProductImageService`

```php
final class ProductImageService
{
    public function store(UploadedFileInterface $file, int $productId): array;
    public function replace(UploadedFileInterface $file, Product $product): array;
    public function deleteFile(Product $product): void;

    private function validate(UploadedFileInterface $file): array;
    private function resize(string $sourcePath, string $targetPath): void;
    private function buildPath(int $productId, string $extension): string;
}
```

**Conventions (heritable to future upload modules):**

- Destination folder: `webroot/uploads/products/{id}/`. Created recursively at `0755` if missing.
- Filename: `product_` + `bin2hex(random_bytes(8))` + `.jpg`. Always rewritten to JPEG after resize, regardless of source format. The original user-supplied filename is never used.
- MIME validation by **content** via `finfo`, not by the extension the browser sends.
- Resize uses GD (ships with standard PHP). Image is **contained** within 800×800 (aspect ratio preserved, never upscaled), then encoded as JPEG quality 85.
- If validation or resize fails, the service returns a structured error and **leaves no half-written files on disk**.
- `replace()` deletes the old file from disk in the same operation that writes the new one.
- `deleteFile()` only touches the filesystem; it does not modify the DB row. Callers are responsible for clearing `image_path` when applicable.

### 5.3 Why two services and not one

`ProductImageService` does not know anything about "Product" as a business concept — it only receives a `productId` and a file. If a future module needs uploads (`BusinessLogoService`, `CustomerPhotoService`), the filesystem logic is reused as-is. Folding it into `ProductService` would couple it to the domain and force the next module to copy-paste 80% of the code.

The service is intentionally not generic (`ImageStorageService` with parametrized entityType): YAGNI until there are at least two real consumers.

## 6. Controller and routes

### 6.1 `ProductsController`

Extends `AppController`. Permissions are enforced automatically via `$controllerModuleMap['Products'] = 'products'` (see §8). Pagination fixed at 15 per project convention.

| Action | HTTP | Path | Permission (`_actionToPermission`) |
|---|---|---|---|
| `index` | GET | `/products` | `view` |
| `view` | GET | `/products/view/{id}` | `view` |
| `add` | GET / POST | `/products/add` | `add` |
| `edit` | GET / POST | `/products/edit/{id}` | `edit` |
| `delete` | POST | `/products/delete/{id}` | `delete` |
| `toggleActive` | POST | `/products/toggle-active/{id}` | `edit` |

`view`, `add`, `edit`, `delete` are covered by `$builder->fallbacks()` in `config/routes.php`. Only `toggleActive` needs an explicit route, registered **before** the fallback:

```php
$builder->connect(
    '/products/toggle-active/{id}',
    ['controller' => 'Products', 'action' => 'toggleActive'],
    ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST']
);
```

### 6.2 Action shape

The controller only handles HTTP. It delegates to the service and translates the result to Flash + redirect. Skeletons:

```php
public function initialize(): void
{
    parent::initialize();
    $this->productService = new ProductService();
}

public function index()
{
    $query = $this->_buildIndexQuery();
    $products = $this->paginate($query);
    $this->set(compact('products'));
    $this->set('filters', $this->_currentFilters());
}

public function add()
{
    $product = $this->Products->newEmptyEntity();
    if ($this->request->is('post')) {
        $image = $this->request->getUploadedFile('image');
        $result = $this->productService->create($this->request->getData(), $image);
        if ($result['success']) {
            $this->Flash->success('Producto creado.');
            return $this->redirect(['action' => 'index']);
        }
        $product = $result['product'] ?? $product;
        foreach ($result['errors'] as $msg) {
            $this->Flash->error($msg);
        }
    }
    $this->set(compact('product'));
}

public function delete(int $id)
{
    $this->request->allowMethod(['post', 'delete']);
    $product = $this->Products->get($id);
    $result = $this->productService->delete($product);
    if ($result['success']) {
        $this->Flash->success('Producto eliminado.');
    } else {
        $this->Flash->error($result['errors'][0] ?? 'No se pudo eliminar.');
    }
    return $this->redirect(['action' => 'index']);
}

public function toggleActive(int $id)
{
    $this->request->allowMethod(['post']);
    $product = $this->Products->get($id);
    $result = $this->productService->toggleActive($product);
    if ($result['success']) {
        $this->Flash->success($result['product']->is_active ? 'Producto activado.' : 'Producto desactivado.');
    } else {
        $this->Flash->error($result['errors'][0] ?? 'No se pudo cambiar el estado.');
    }
    return $this->redirect($this->referer(['action' => 'index']));
}
```

### 6.3 List filters

`_buildIndexQuery()` translates query params into `where()` and `order()` clauses.

| Query param | Effect | Default |
|---|---|---|
| `q` | `name LIKE %q%` OR `code LIKE %q%` | empty |
| `status` | `active`, `inactive`, `all` | `all` |
| `sort` | column to sort by (`name`, `price`, `created`) | `name` |
| `direction` | `asc` / `desc` | `asc` |

`sort` is whitelisted (CakePHP `Paginator` `sortableFields`) to prevent SQL injection via arbitrary column names.

The admin listing shows **all products by default**; `is_active = false` is not hidden. The Pedidos screen (Phase 3) will filter to `is_active = true` automatically.

## 7. Templates

```
templates/
├── Products/
│   ├── index.php
│   ├── add.php
│   ├── edit.php
│   └── view.php
└── element/
    └── Products/
        └── _form.php
```

All templates use the `default.php` layout (sidebar + topbar) and obey DESIGN.md.

### 7.1 `index.php` — listing

1. **Header**: title "Productos" left, single `button-primary` "Nuevo producto" right (the only `primary` instance on the screen).
2. **Filter bar**: 40px row with search input (`q`), status select (`Todos / Activos / Inactivos`), `button-tertiary` "Limpiar". Inputs and selects share height.
3. **Table** with columns: thumbnail (64×64) · code · name (link to edit) · formatted price · status switch · row icon-buttons (edit / delete).
   - 56px row height.
   - Header in `surface-alt` with `label-sm` uppercase.
   - Inactive row: opacity 0.6 + `badge-neutral` "Inactivo".
   - No-image case: gray placeholder thumb.
4. **Pagination**: `<?= $this->element('pagination') ?>`.
5. **Empty state**: centered card "Aún no hay productos. Creá el primero." with primary CTA.

The status switch posts to `/products/toggle-active/{id}` via `Form->postLink()`. CSRF token is injected automatically. **No JS required for MVP**; an enhancement can avoid the page reload later.

The delete button uses a native `confirm()` via `Form->postLink()` — no custom modal in MVP.

### 7.2 `_form.php` — partial shared by add/edit

Fields in order:

- **Image** (top): preview of current file + `<input type="file" accept="image/jpeg,image/png,image/webp">`. Helper: "JPG/PNG/WebP, hasta 10 MB. Se recortará a 800×800."
- **Código** (optional). Helper: "Atajo para la pantalla de pedidos. Opcional."
- **Nombre** (required).
- **Precio** (required, `type="number" min="1" step="1"`, `$` prefix; on blur formats with thousand separators).
- **Descripción** (textarea, optional, 3 lines).
- **Disponible** (toggle, default `true`).
- **Buttons**: `button-tertiary` "Cancelar" (link to index) + `button-primary` "Guardar".

Validation errors render inline below each field as `input-error-message` (per DESIGN.md), not as a banner.

### 7.3 `view.php` — read-only detail

Renders the same data as a `<dl>` definition list. Footer with two `button-secondary`: "Editar" and "Volver". Used when a user has `view` but not `edit`.

### 7.4 DESIGN.md alignment

- `primary` color used **once** per screen: "Nuevo producto" on `index`, "Guardar" on `add`/`edit`.
- Delete uses `button-danger` (`#D32F2F`), never `primary`.
- Status badges use `badge-success` / `badge-neutral` for active/inactive — the `status-*` family is reserved for the order lifecycle (Phase 3).
- Max three type sizes per view (page title, table header, body).

## 8. RBAC, validation, and business rules

### 8.1 RBAC wiring (three update points per ARQUITECTURE.md §5.4)

1. **`AppController::$controllerModuleMap`**:
   ```php
   protected array $controllerModuleMap = [
       // ...existing
       'Products' => 'products',
   ];
   ```
2. **`AuthorizationService::MODULES`**:
   ```php
   public const MODULES = [
       // ...existing
       'products' => 'Productos',
   ];
   ```
3. **Seed migration** `SeedProductsPermissions.php`:
   ```php
   $this->execute("
       INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
       SELECT id, 'products', 1, 1, 1, 0, NOW(), NOW()
       FROM roles WHERE name <> 'Administrador'
   ");
   ```
   Idempotent: if no non-Administrador roles exist yet, inserts nothing. The Administrador bypasses the matrix per `davirapid.md` §3.6.

### 8.2 Validation split (ARQUITECTURE.md §4.11)

| Level | Where | What it validates |
|---|---|---|
| Format | `ProductsTable::validationDefault` + `buildRules` | Required fields, lengths, regex for `code`, decimal range, unique `code` |
| Business | `ProductService` methods | Cross-table rules (refuse delete with sales), image upload integrity |

### 8.3 Business rules (consolidated)

1. **Toggle availability ≠ delete.** `is_active = false` hides the product from the future Pedidos flow but preserves all relationships.
2. **Delete requires no sales.** `ProductService::hasSales()` is tolerant of `OrderItems` not yet existing in Phase 1.
3. **Image is optional.** Listings show a placeholder when missing.
4. **Replacing the image deletes the previous file** in the same operation.
5. **Deleting the product deletes its image folder** — `ProductService::delete()` calls `ProductImageService::deleteFile()` before the DB delete. Acceptable failure mode: only entered when `hasSales() === false`.
6. **Price is integer ≥ 1** (COP, no centavos).
7. **`code` is optional and unique when present.** Multiple products without code coexist.

### 8.4 Out of scope: delivery filtering

`ARQUITECTURE.md` §5.3 describes automatic order filtering when a user is linked to a Repartidor. Products **does not apply** that rule — the catalog belongs to the business, not to a specific delivery person. Standard RBAC fully covers this module.

## 9. Acceptance criteria

A user with full permissions on `products` can:

- See a paginated list of products (15 per page) with thumb, code, name, price, status switch, and row actions.
- Filter the list by `q` (name or code) and `status` (all / active / inactive).
- Sort by name, price, or creation date.
- Create a new product with optional image; the image is stored under `webroot/uploads/products/{id}/` resized to 800×800 JPEG-85.
- Edit a product; uploading a new image replaces the previous file on disk.
- Toggle availability inline from the listing without entering edit mode.
- Be prevented from deleting a product that has associated sales (`hasSales() === true`), with a clear message instructing to deactivate instead. *(Verifiable once Phase 3 is done; in Phase 1 the check returns `false` because `order_items` does not exist yet.)*
- Be prevented from creating two products with the same `code` (when `code` is provided).
- Create multiple products without a `code` without uniqueness collisions.

A user without permissions on `products` does not see the module in the sidebar and is redirected with a Flash message if they hit a Products URL directly. The Administrador user bypasses the matrix and has full access.

## 10. Conventions established by this pilot

The next three Phase 1 modules (Clientes, Repartidores, Ingredientes) inherit:

- Service decomposition (opt-in: only the services the domain needs).
- Return contract `['success', 'entity', 'errors', 'warnings']` from services.
- Validation split: format in `Table`, business in `Service`.
- Pagination fixed at 15.
- Filter query params: `q`, `status`, `sort`, `direction`.
- Toggle action as POST + custom route registered before `fallbacks()`.
- DESIGN.md compliance (single `primary`, `button-danger` for destructive).
- Currency: `decimal(12,0) unsigned`, integer COP.
- File uploads (when applicable): `webroot/uploads/{entity}/{id}/`, `entity_` + 16 hex chars + `.jpg`, MIME validated by `finfo`, resize to a single 800×800 JPEG-85 via GD, replace deletes old, no half-written files on disk.

## 11. Out-of-scope, explicitly deferred

- Categorías de producto.
- Recetas (Phase 2).
- Multi-image per product, image gallery, image cropping UI.
- Bulk operations (multi-select delete, bulk price edit).
- Product variants (sizes, options).
- Soft delete.
- Audit log for product changes (only Pedidos requires one).
