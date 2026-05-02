# Products Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the Products module — first pilot of Phase 1 — covering CRUD, image upload with resize, availability toggle, and RBAC integration. Establishes project-wide conventions for currency, file uploads, and service decomposition.

**Architecture:** Standard CakePHP 5.x layered architecture (Controller → Service → Table). Two services: `ProductService` (business rules across tables, including delete-guard tolerant of `OrderItems` not yet existing) and `ProductImageService` (filesystem isolation for uploads with GD-based resize to 800×800 JPEG). Templates use the existing `default.php` layout, Bootstrap 5 + project's `dr-*` design classes.

**Tech Stack:** PHP 8.2+, CakePHP 5.x, MySQL/MariaDB (dev/prod), SQLite (tests), GD for image resize, Bootstrap 5 + Bootstrap Icons, custom `webroot/css/davirapid.css`.

**Spec:** `docs/superpowers/specs/2026-05-02-products-module-design.md` (commit `73bcd67`).

**Note on testing:** Per project preference, no automated tests (PHPUnit, integration, or otherwise) are written or scaffolded. Each task ends with a manual smoke check using `bin/cake.php server` or migrations CLI, then a commit.

---

## File Structure

### Files to create
- `config/Migrations/20260502130000_CreateProducts.php` — products table.
- `config/Migrations/20260502130100_SeedProductsPermissions.php` — seed permissions for non-admin roles.
- `src/Constants/ProductConstants.php` — image limits, allowed MIME, price/code rules.
- `src/Model/Entity/Product.php` — entity with `getImageUrl()` and `getFormattedPrice()`.
- `src/Model/Table/ProductsTable.php` — validation, unique-when-present rule.
- `src/Service/ProductImageService.php` — filesystem ops for uploads.
- `src/Service/ProductService.php` — CRUD orchestration and business rules.
- `src/Controller/ProductsController.php` — HTTP layer.
- `templates/Products/index.php` — list with filters and inline toggle.
- `templates/Products/add.php` — wraps the form partial.
- `templates/Products/edit.php` — wraps the form partial.
- `templates/Products/view.php` — read-only detail.
- `templates/element/Products/_form.php` — shared form partial.
- `webroot/img/product-placeholder.svg` — fallback image.

### Files to modify
- `src/Controller/AppController.php` — extend `$controllerModuleMap`.
- `src/Service/AuthorizationService.php` — extend `MODULES`.
- `src/View/Helper/SidebarHelper.php` — add Products entry.
- `config/routes.php` — register `/products/toggle-active/{id}` before fallback.

---

## Task 1: Create products migration and run it

**Files:**
- Create: `config/Migrations/20260502130000_CreateProducts.php`

- [ ] **Step 1: Create the migration file**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateProducts extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('products')) {
            return;
        }

        $this->table('products', [
            'collation' => 'utf8mb4_unicode_ci',
            'signed' => false,
        ])
            ->addColumn('code', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('name', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('price', 'decimal', [
                'precision' => 12,
                'scale' => 0,
                'null' => false,
            ])
            ->addColumn('image_path', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['code'], ['unique' => true, 'name' => 'uniq_products_code'])
            ->addIndex(['is_active', 'name'], ['name' => 'idx_products_active_name'])
            ->create();
    }

    public function down(): void
    {
        $this->table('products')->drop()->save();
    }
}
```

> **Note on `unique` + nullable code:** MySQL/MariaDB allow multiple `NULL` values in a `UNIQUE` index. SQLite does too. No `WHERE` filter needed.

- [ ] **Step 2: Run the migration**

```bash
php bin/cake.php migrations migrate
```

Expected output: lines indicating `CreateProducts: migrating` and `CreateProducts: migrated`. The schema-dump-default.lock file may also be regenerated.

- [ ] **Step 3: Verify the table exists**

```bash
php bin/cake.php migrations status
```

Expected: a row for `CreateProducts` with status `up`.

- [ ] **Step 4: Commit**

```bash
git add config/Migrations/20260502130000_CreateProducts.php config/Migrations/schema-dump-default.lock
git commit -m "feat(products): add products table migration"
```

---

## Task 2: Create ProductConstants

**Files:**
- Create: `src/Constants/ProductConstants.php`

- [ ] **Step 1: Create `src/Constants/` directory if missing**

```bash
mkdir -p src/Constants
```

- [ ] **Step 2: Create the constants file**

```php
<?php
declare(strict_types=1);

namespace App\Constants;

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

- [ ] **Step 3: Commit**

```bash
git add src/Constants/ProductConstants.php
git commit -m "feat(products): add ProductConstants"
```

---

## Task 3: Create Product entity

**Files:**
- Create: `src/Model/Entity/Product.php`

- [ ] **Step 1: Create the entity**

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

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
        if (empty($this->image_path)) {
            return '/img/product-placeholder.svg';
        }
        return '/' . ltrim((string)$this->image_path, '/');
    }

    public function getFormattedPrice(): string
    {
        return '$' . number_format((int)$this->price, 0, ',', '.');
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Model/Entity/Product.php
git commit -m "feat(products): add Product entity"
```

---

## Task 4: Create ProductsTable

**Files:**
- Create: `src/Model/Table/ProductsTable.php`

- [ ] **Step 1: Create the table**

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\ProductConstants;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class ProductsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('products');
        $this->setPrimaryKey('id');
        $this->setDisplayField('name');
        $this->addBehavior('Timestamp');

        // Future associations declared when their tables exist:
        // $this->hasMany('ProductIngredients');
        // $this->hasMany('OrderItems');
    }

    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->notEmptyString('name', 'El nombre es requerido')
            ->maxLength('name', 120, 'El nombre puede tener hasta 120 caracteres')
            ->notEmptyString('price', 'El precio es requerido')
            ->numeric('price', 'El precio debe ser numérico')
            ->greaterThanOrEqual(
                'price',
                ProductConstants::PRICE_MIN,
                'El precio debe ser mayor o igual a 1'
            )
            ->allowEmptyString('code')
            ->maxLength('code', ProductConstants::CODE_MAX_LENGTH, 'El código puede tener hasta 20 caracteres')
            ->add('code', 'codePattern', [
                'rule' => function ($value) {
                    if ($value === null || $value === '') {
                        return true;
                    }
                    return (bool)preg_match(ProductConstants::CODE_PATTERN, (string)$value);
                },
                'message' => 'El código solo permite letras, números y guiones',
            ])
            ->allowEmptyString('description')
            ->boolean('is_active');
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->isUnique(
                ['code'],
                ['allowMultipleNulls' => true, 'message' => 'Ese código ya está en uso']
            ),
            'uniqueCode'
        );
        return $rules;
    }
}
```

- [ ] **Step 2: Smoke check via Cake REPL**

```bash
php bin/cake.php console
```

In the prompt:
```php
$t = \Cake\ORM\TableRegistry::getTableLocator()->get('Products');
echo get_class($t);
exit;
```

Expected: `App\Model\Table\ProductsTable`. If you see `Cake\ORM\Table`, `FactoryLocator` fallback fired — that means our class wasn't found. Verify the namespace and filename.

- [ ] **Step 3: Commit**

```bash
git add src/Model/Table/ProductsTable.php
git commit -m "feat(products): add ProductsTable with validation and unique-code rule"
```

---

## Task 5: Create ProductImageService

**Files:**
- Create: `src/Service/ProductImageService.php`

- [ ] **Step 1: Create the service**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\ProductConstants;
use App\Model\Entity\Product;
use Cake\Log\Log;
use Psr\Http\Message\UploadedFileInterface;

final class ProductImageService
{
    /**
     * Stores a new uploaded image for a product. Returns the relative path
     * (from webroot/) on success.
     *
     * @return array{success: bool, path?: string, errors?: array<string>}
     */
    public function store(UploadedFileInterface $file, int $productId): array
    {
        $errors = $this->validate($file);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $dir = WWW_ROOT . 'uploads' . DS . 'products' . DS . $productId;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['success' => false, 'errors' => ['No se pudo crear el directorio de imagen.']];
        }

        $filename = 'product_' . bin2hex(random_bytes(8)) . '.jpg';
        $absolute = $dir . DS . $filename;

        $tmp = tempnam(sys_get_temp_dir(), 'prod_');
        $file->moveTo($tmp);

        try {
            $this->resize($tmp, $absolute);
        } catch (\Throwable $e) {
            @unlink($tmp);
            @unlink($absolute);
            Log::error('Failed to resize product image: {msg}', ['msg' => $e->getMessage(), 'scope' => ['products']]);
            return ['success' => false, 'errors' => ['No se pudo procesar la imagen.']];
        }
        @unlink($tmp);

        $relative = 'uploads/products/' . $productId . '/' . $filename;
        return ['success' => true, 'path' => $relative];
    }

    /**
     * Replaces a product's existing image. Deletes the old file on success.
     *
     * @return array{success: bool, path?: string, errors?: array<string>}
     */
    public function replace(UploadedFileInterface $file, Product $product): array
    {
        $oldPath = $product->image_path;
        $result = $this->store($file, (int)$product->id);
        if (!$result['success']) {
            return $result;
        }

        if (!empty($oldPath)) {
            $absolute = WWW_ROOT . ltrim((string)$oldPath, '/');
            if (is_file($absolute)) {
                @unlink($absolute);
            }
        }

        return $result;
    }

    /**
     * Deletes the product's image file from disk (if any). Does not modify the entity.
     */
    public function deleteFile(Product $product): void
    {
        if (empty($product->image_path)) {
            return;
        }
        $absolute = WWW_ROOT . ltrim((string)$product->image_path, '/');
        if (is_file($absolute)) {
            @unlink($absolute);
        }
        $dir = dirname($absolute);
        if (is_dir($dir) && (new \FilesystemIterator($dir))->valid() === false) {
            @rmdir($dir);
        }
    }

    /**
     * @return array<string>
     */
    private function validate(UploadedFileInterface $file): array
    {
        $errors = [];

        if ($file->getError() !== UPLOAD_ERR_OK) {
            return ['Error al subir el archivo (código ' . $file->getError() . ').'];
        }

        if ($file->getSize() > ProductConstants::IMAGE_MAX_SIZE_BYTES) {
            $errors[] = 'La imagen supera el tamaño máximo permitido (10 MB).';
        }

        $stream = $file->getStream();
        $stream->rewind();
        $head = $stream->read(4096);
        $stream->rewind();

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($head) ?: '';
        if (!in_array($mime, ProductConstants::IMAGE_ALLOWED_MIME, true)) {
            $errors[] = 'Formato de imagen no permitido. Usá JPG, PNG o WebP.';
        }

        return $errors;
    }

    /**
     * Resizes the source image into a contained 800x800 JPEG-85 at the target path.
     * Uses GD. The aspect ratio is preserved; smaller images are NOT upscaled.
     */
    private function resize(string $source, string $target): void
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('GD extension is not available.');
        }

        $data = file_get_contents($source);
        if ($data === false) {
            throw new \RuntimeException('Cannot read source image.');
        }

        $src = @imagecreatefromstring($data);
        if ($src === false) {
            throw new \RuntimeException('Cannot decode source image.');
        }

        $sw = imagesx($src);
        $sh = imagesy($src);
        $maxW = ProductConstants::IMAGE_TARGET_WIDTH;
        $maxH = ProductConstants::IMAGE_TARGET_HEIGHT;

        $ratio = min($maxW / $sw, $maxH / $sh, 1.0);
        $tw = max(1, (int)round($sw * $ratio));
        $th = max(1, (int)round($sh * $ratio));

        $dst = imagecreatetruecolor($tw, $th);
        // Fill with white in case the source has alpha (will be flattened to JPEG).
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $tw, $th, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);

        if (!imagejpeg($dst, $target, ProductConstants::IMAGE_JPEG_QUALITY)) {
            imagedestroy($src);
            imagedestroy($dst);
            throw new \RuntimeException('Failed to write JPEG output.');
        }

        imagedestroy($src);
        imagedestroy($dst);
    }
}
```

- [ ] **Step 2: Smoke check GD is present**

```bash
php -m | grep -i gd
```

Expected: `gd` appears in the list. If not, install (`sudo apt-get install php-gd` on Debian/Ubuntu) and restart any running PHP-FPM/server.

- [ ] **Step 3: Commit**

```bash
git add src/Service/ProductImageService.php
git commit -m "feat(products): add ProductImageService with GD resize and MIME validation"
```

---

## Task 6: Create ProductService

**Files:**
- Create: `src/Service/ProductService.php`

- [ ] **Step 1: Create the service**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Product;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Psr\Http\Message\UploadedFileInterface;

final class ProductService
{
    use LocatorAwareTrait;

    private ProductImageService $images;

    public function __construct(?ProductImageService $images = null)
    {
        $this->images = $images ?? new ProductImageService();
    }

    /**
     * @return array{success: bool, product?: Product, errors?: array<string>}
     */
    public function create(array $data, ?UploadedFileInterface $image = null): array
    {
        $table = $this->fetchTable('Products');
        unset($data['image_path']); // never trust client-supplied path
        $hasImage = $this->isUploadPresent($image);

        $connection = ConnectionManager::get('default');
        $resultBox = ['success' => false, 'errors' => ['Error desconocido.']];

        $connection->transactional(function () use ($table, $data, $image, $hasImage, &$resultBox): bool {
            $product = $table->newEmptyEntity();
            $product = $table->patchEntity($product, $data);

            if (!$table->save($product)) {
                $resultBox = ['success' => false, 'errors' => $this->flattenErrors($product->getErrors()), 'product' => $product];
                return false;
            }

            if ($hasImage) {
                $imgResult = $this->images->store($image, (int)$product->id);
                if (!$imgResult['success']) {
                    $resultBox = ['success' => false, 'errors' => $imgResult['errors'] ?? ['No se pudo guardar la imagen.'], 'product' => $product];
                    return false;
                }
                $product->image_path = $imgResult['path'];
                if (!$table->save($product)) {
                    $resultBox = ['success' => false, 'errors' => $this->flattenErrors($product->getErrors()), 'product' => $product];
                    return false;
                }
            }

            Log::info('Product created: id={id} name={name}', [
                'id' => $product->id,
                'name' => $product->name,
                'scope' => ['products'],
            ]);

            $resultBox = ['success' => true, 'product' => $product];
            return true;
        });

        return $resultBox;
    }

    /**
     * @return array{success: bool, product?: Product, errors?: array<string>}
     */
    public function update(Product $product, array $data, ?UploadedFileInterface $image = null): array
    {
        $table = $this->fetchTable('Products');
        unset($data['image_path']);
        $hasImage = $this->isUploadPresent($image);

        $connection = ConnectionManager::get('default');
        $resultBox = ['success' => false, 'errors' => ['Error desconocido.']];

        $connection->transactional(function () use ($table, $product, $data, $image, $hasImage, &$resultBox): bool {
            $patched = $table->patchEntity($product, $data);

            if (!$table->save($patched)) {
                $resultBox = ['success' => false, 'errors' => $this->flattenErrors($patched->getErrors()), 'product' => $patched];
                return false;
            }

            if ($hasImage) {
                $imgResult = $this->images->replace($image, $patched);
                if (!$imgResult['success']) {
                    $resultBox = ['success' => false, 'errors' => $imgResult['errors'] ?? ['No se pudo guardar la imagen.'], 'product' => $patched];
                    return false;
                }
                $patched->image_path = $imgResult['path'];
                if (!$table->save($patched)) {
                    $resultBox = ['success' => false, 'errors' => $this->flattenErrors($patched->getErrors()), 'product' => $patched];
                    return false;
                }
            }

            $resultBox = ['success' => true, 'product' => $patched];
            return true;
        });

        return $resultBox;
    }

    /**
     * @return array{success: bool, errors?: array<string>}
     */
    public function delete(Product $product): array
    {
        if ($this->hasSales($product)) {
            return [
                'success' => false,
                'errors' => ['No se puede eliminar un producto con ventas. Desactivalo en su lugar.'],
            ];
        }

        $this->images->deleteFile($product);

        $table = $this->fetchTable('Products');
        if (!$table->delete($product)) {
            return ['success' => false, 'errors' => ['No se pudo eliminar el producto.']];
        }

        Log::info('Product deleted: id={id} name={name}', [
            'id' => $product->id,
            'name' => $product->name,
            'scope' => ['products'],
        ]);

        return ['success' => true];
    }

    /**
     * @return array{success: bool, product?: Product, errors?: array<string>}
     */
    public function toggleActive(Product $product): array
    {
        $product->is_active = !$product->is_active;
        $table = $this->fetchTable('Products');
        if (!$table->save($product)) {
            return ['success' => false, 'errors' => $this->flattenErrors($product->getErrors()), 'product' => $product];
        }
        return ['success' => true, 'product' => $product];
    }

    /**
     * Checks whether the product has associated sales. Tolerant of OrderItems
     * not yet existing — returns false in Phase 1.
     */
    public function hasSales(Product $product): bool
    {
        $locator = $this->getTableLocator();
        if (!$locator->exists('OrderItems')) {
            try {
                $locator->get('OrderItems');
            } catch (\Throwable) {
                return false;
            }
        }

        try {
            $count = $locator->get('OrderItems')
                ->find()
                ->where(['OrderItems.product_id' => $product->id])
                ->count();
            return $count > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function isUploadPresent(?UploadedFileInterface $image): bool
    {
        return $image !== null && $image->getError() === UPLOAD_ERR_OK && $image->getSize() > 0;
    }

    /**
     * @return array<string>
     */
    private function flattenErrors(array $errors): array
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

- [ ] **Step 2: Commit**

```bash
git add src/Service/ProductService.php
git commit -m "feat(products): add ProductService with transactional create/update and delete-guard"
```

---

## Task 7: Create ProductsController with index action

**Files:**
- Create: `src/Controller/ProductsController.php`

- [ ] **Step 1: Create the controller with `index`, `view`, and helpers**

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\ProductService;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\ORM\Query\SelectQuery;

class ProductsController extends AppController
{
    public array $paginate = [
        'limit' => 15,
        'maxLimit' => 15,
        'order' => ['Products.name' => 'ASC'],
        'sortableFields' => ['name', 'price', 'created', 'code'],
    ];

    private ProductService $productService;

    public function initialize(): void
    {
        parent::initialize();
        $this->productService = new ProductService();
    }

    public function index(): void
    {
        $filters = $this->_currentFilters();
        $query = $this->_buildIndexQuery($filters);
        $products = $this->paginate($query);

        $this->set(compact('products', 'filters'));
        $this->set('breadcrumbs', [['label' => 'Productos']]);
    }

    public function view(int $id): void
    {
        $product = $this->Products->get($id);
        $this->set('product', $product);
        $this->set('breadcrumbs', [
            ['label' => 'Productos', 'url' => ['action' => 'index']],
            ['label' => $product->name],
        ]);
    }

    public function add()
    {
        // implemented in Task 8
    }

    public function edit(int $id)
    {
        // implemented in Task 9
    }

    public function delete(int $id)
    {
        // implemented in Task 10
    }

    public function toggleActive(int $id)
    {
        // implemented in Task 10
    }

    /**
     * @return array{q: string, status: string, sort: string, direction: string}
     */
    protected function _currentFilters(): array
    {
        $allowedSort = ['name', 'price', 'created', 'code'];
        $allowedStatus = ['all', 'active', 'inactive'];
        $allowedDir = ['asc', 'desc'];

        $sort = (string)$this->request->getQuery('sort', 'name');
        $direction = strtolower((string)$this->request->getQuery('direction', 'asc'));
        $status = (string)$this->request->getQuery('status', 'all');

        return [
            'q' => trim((string)$this->request->getQuery('q', '')),
            'status' => in_array($status, $allowedStatus, true) ? $status : 'all',
            'sort' => in_array($sort, $allowedSort, true) ? $sort : 'name',
            'direction' => in_array($direction, $allowedDir, true) ? $direction : 'asc',
        ];
    }

    protected function _buildIndexQuery(array $filters): SelectQuery
    {
        $query = $this->Products->find();

        if ($filters['q'] !== '') {
            $like = '%' . $filters['q'] . '%';
            $query->where(['OR' => [
                'Products.name LIKE' => $like,
                'Products.code LIKE' => $like,
            ]]);
        }

        if ($filters['status'] === 'active') {
            $query->where(['Products.is_active' => true]);
        } elseif ($filters['status'] === 'inactive') {
            $query->where(['Products.is_active' => false]);
        }

        $query->orderBy(['Products.' . $filters['sort'] => strtoupper($filters['direction'])]);

        return $query;
    }
}
```

> The `add`, `edit`, `delete`, `toggleActive` bodies are stubs here so the controller compiles. They get filled in Tasks 8–10. The RBAC `beforeFilter` in `AppController` will block access to this controller until Task 11 wires the module — that's fine; we'll smoke-test after that task.

- [ ] **Step 2: Commit**

```bash
git add src/Controller/ProductsController.php
git commit -m "feat(products): add ProductsController skeleton with index/view and filters"
```

---

## Task 8: Implement `add` action

**Files:**
- Modify: `src/Controller/ProductsController.php` (replace the `add` stub)

- [ ] **Step 1: Replace the `add` method body**

Locate the `public function add()` method and replace its body with:

```php
public function add()
{
    $product = $this->Products->newEmptyEntity();

    if ($this->request->is('post')) {
        $image = $this->request->getUploadedFile('image');
        $data = $this->request->getData();
        // Hidden input default; convert form value to bool.
        $data['is_active'] = !empty($data['is_active']);

        $result = $this->productService->create($data, $image);
        if ($result['success']) {
            $this->Flash->success('Producto creado.');
            return $this->redirect(['action' => 'index']);
        }
        foreach ($result['errors'] ?? ['No se pudo crear el producto.'] as $msg) {
            $this->Flash->error($msg);
        }
        $product = $result['product'] ?? $product;
    }

    $this->set('product', $product);
    $this->set('breadcrumbs', [
        ['label' => 'Productos', 'url' => ['action' => 'index']],
        ['label' => 'Nuevo producto'],
    ]);
    return null;
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Controller/ProductsController.php
git commit -m "feat(products): implement add action"
```

---

## Task 9: Implement `edit` action

**Files:**
- Modify: `src/Controller/ProductsController.php` (replace the `edit` stub)

- [ ] **Step 1: Replace the `edit` method body**

```php
public function edit(int $id)
{
    $product = $this->Products->get($id);

    if ($this->request->is(['put', 'post', 'patch'])) {
        $image = $this->request->getUploadedFile('image');
        $data = $this->request->getData();
        $data['is_active'] = !empty($data['is_active']);

        $result = $this->productService->update($product, $data, $image);
        if ($result['success']) {
            $this->Flash->success('Producto actualizado.');
            return $this->redirect(['action' => 'index']);
        }
        foreach ($result['errors'] ?? ['No se pudo actualizar el producto.'] as $msg) {
            $this->Flash->error($msg);
        }
        $product = $result['product'] ?? $product;
    }

    $this->set('product', $product);
    $this->set('breadcrumbs', [
        ['label' => 'Productos', 'url' => ['action' => 'index']],
        ['label' => $product->name, 'url' => ['action' => 'view', $product->id]],
        ['label' => 'Editar'],
    ]);
    return null;
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Controller/ProductsController.php
git commit -m "feat(products): implement edit action"
```

---

## Task 10: Implement `delete`, `toggleActive`, custom route, and permission mapping

**Files:**
- Modify: `src/Controller/ProductsController.php` (replace `delete` and `toggleActive` stubs, add `_actionToPermission`)
- Modify: `config/routes.php`

- [ ] **Step 1: Replace `delete` and `toggleActive` and add `_actionToPermission`**

```php
public function delete(int $id)
{
    $this->request->allowMethod(['post', 'delete']);

    try {
        $product = $this->Products->get($id);
    } catch (RecordNotFoundException) {
        $this->Flash->error('El producto ya no existe.');
        return $this->redirect(['action' => 'index']);
    }

    $result = $this->productService->delete($product);
    if ($result['success']) {
        $this->Flash->success('Producto eliminado.');
    } else {
        $this->Flash->error($result['errors'][0] ?? 'No se pudo eliminar el producto.');
    }
    return $this->redirect(['action' => 'index']);
}

public function toggleActive(int $id)
{
    $this->request->allowMethod(['post']);

    try {
        $product = $this->Products->get($id);
    } catch (RecordNotFoundException) {
        $this->Flash->error('El producto ya no existe.');
        return $this->redirect(['action' => 'index']);
    }

    $result = $this->productService->toggleActive($product);
    if ($result['success']) {
        $msg = $result['product']->is_active ? 'Producto activado.' : 'Producto desactivado.';
        $this->Flash->success($msg);
    } else {
        $this->Flash->error($result['errors'][0] ?? 'No se pudo cambiar el estado.');
    }
    return $this->redirect($this->referer(['action' => 'index']));
}

/**
 * Sumamos el mapeo de la acción 'toggleActive' al permiso 'edit'.
 */
protected function _actionToPermission(string $action): string
{
    return match ($action) {
        'toggleActive' => 'edit',
        default => parent::_actionToPermission($action),
    };
}
```

- [ ] **Step 2: Register the custom route**

Edit `config/routes.php`. Insert the new route inside the `'/'` scope, **before** `$builder->fallbacks();` (next to the existing `/users/unlock/{id}` block):

```php
        // Acción custom: alternar disponibilidad de un producto.
        $builder->connect(
            '/products/toggle-active/{id}',
            ['controller' => 'Products', 'action' => 'toggleActive'],
            ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST']
        );
```

- [ ] **Step 3: Commit**

```bash
git add src/Controller/ProductsController.php config/routes.php
git commit -m "feat(products): implement delete + toggleActive with custom route"
```

---

## Task 11: Wire RBAC (controller map, modules catalog, sidebar)

**Files:**
- Modify: `src/Controller/AppController.php`
- Modify: `src/Service/AuthorizationService.php`
- Modify: `src/View/Helper/SidebarHelper.php`

- [ ] **Step 1: Add Products to the controller-module map**

In `src/Controller/AppController.php`, locate the `$controllerModuleMap` property and add the entry:

```php
    protected array $controllerModuleMap = [
        'Roles' => 'roles',
        'Users' => 'users',
        'Products' => 'products',
    ];
```

- [ ] **Step 2: Add Products to the modules catalog**

In `src/Service/AuthorizationService.php`, locate `MODULES` and add the entry:

```php
    public const MODULES = [
        'roles' => 'Roles',
        'users' => 'Usuarios',
        'products' => 'Productos',
    ];
```

- [ ] **Step 3: Add Products to the sidebar**

In `src/View/Helper/SidebarHelper.php`, locate `$items` and add the entry. Place it first (Productos is the most-used operational module):

```php
    private array $items = [
        [
            'module' => 'products',
            'label' => 'Productos',
            'icon' => 'bi-box-seam',
            'url' => ['controller' => 'Products', 'action' => 'index'],
        ],
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
```

- [ ] **Step 4: Commit**

```bash
git add src/Controller/AppController.php src/Service/AuthorizationService.php src/View/Helper/SidebarHelper.php
git commit -m "feat(products): wire RBAC and sidebar entry"
```

---

## Task 12: Seed permissions for non-administrator roles

**Files:**
- Create: `config/Migrations/20260502130100_SeedProductsPermissions.php`

- [ ] **Step 1: Create the seed migration**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class SeedProductsPermissions extends BaseMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // Insert a default products permission row for every role that doesn't already have one.
        // is_admin=1 (Administrador) is excluded because it bypasses the matrix; seeding it for
        // the Administrador (id=1) is also done elsewhere on a per-need basis.
        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'products', 1, 1, 1, 0, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 0
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'products'
               )"
        );

        // Also seed for Administrador (consistent with the existing pattern in
        // SeedAdministratorRoleAndUser, where roles+users get a permissions row each
        // for matrix display, even though the bypass makes it functionally redundant).
        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'products', 1, 1, 1, 1, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 1
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'products'
               )"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'products'");
    }
}
```

- [ ] **Step 2: Run the migration**

```bash
php bin/cake.php migrations migrate
```

Expected: lines indicating `SeedProductsPermissions: migrating` and `migrated`.

- [ ] **Step 3: Verify the rows exist**

```bash
php bin/cake.php console
```

In the prompt:
```php
$rows = \Cake\ORM\TableRegistry::getTableLocator()->get('Permissions')
    ->find()->where(['module' => 'products'])->all()->toArray();
echo count($rows), PHP_EOL;
foreach ($rows as $r) { echo " role_id={$r->role_id} v={$r->can_view} c={$r->can_create} e={$r->can_edit} d={$r->can_delete}\n"; }
exit;
```

Expected: at least one row (the Administrador). If no other roles exist yet, that's the only one.

- [ ] **Step 4: Commit**

```bash
git add config/Migrations/20260502130100_SeedProductsPermissions.php config/Migrations/schema-dump-default.lock
git commit -m "feat(products): seed default products permissions for existing roles"
```

---

## Task 13: Create form partial and add/edit templates

**Files:**
- Create: `templates/element/Products/_form.php`
- Create: `templates/Products/add.php`
- Create: `templates/Products/edit.php`

- [ ] **Step 1: Create the form partial**

`templates/element/Products/_form.php`:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Product $product
 * @var string $submitLabel
 */
?>
<?= $this->Form->create($product, ['type' => 'file']) ?>
<div class="card mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Imagen actual</label>
                <div class="border rounded d-flex align-items-center justify-content-center"
                     style="aspect-ratio: 1/1; background: #f8f9fa; overflow: hidden;">
                    <img src="<?= h($product->getImageUrl()) ?>"
                         alt="Imagen del producto"
                         style="max-width: 100%; max-height: 100%; object-fit: contain;">
                </div>
            </div>
            <div class="col-md-9">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label" for="image">Imagen</label>
                        <input type="file" name="image" id="image" class="form-control"
                               accept="image/jpeg,image/png,image/webp">
                        <div class="form-text">JPG, PNG o WebP. Hasta 10 MB. Se redimensiona a 800×800.</div>
                    </div>
                    <div class="col-md-4">
                        <?= $this->Form->control('code', [
                            'label' => 'Código',
                            'class' => 'form-control',
                            'maxlength' => 20,
                            'placeholder' => 'Ej. H2',
                            'help' => 'Atajo opcional para la pantalla de pedidos.',
                            'value' => $product->code,
                        ]) ?>
                    </div>
                    <div class="col-md-8">
                        <?= $this->Form->control('name', [
                            'label' => 'Nombre',
                            'class' => 'form-control',
                            'maxlength' => 120,
                            'autofocus' => $product->isNew(),
                        ]) ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="price">Precio</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" name="price" id="price" class="form-control"
                                   min="1" step="1"
                                   value="<?= h($product->price ?? '') ?>" required>
                        </div>
                        <div class="form-text">Pesos colombianos, enteros.</div>
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <div class="form-check">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" id="is_active" class="form-check-input"
                                   value="1" <?= ($product->isNew() || $product->is_active !== false) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">Disponible para la venta</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <?= $this->Form->control('description', [
                            'label' => 'Descripción',
                            'type' => 'textarea',
                            'rows' => 3,
                            'class' => 'form-control',
                        ]) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="d-flex gap-2">
    <?= $this->Form->button(
        '<i class="bi bi-check-lg"></i> ' . h($submitLabel),
        ['escapeTitle' => false, 'class' => 'btn btn-primary']
    ) ?>
    <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
</div>
<?= $this->Form->end() ?>
```

- [ ] **Step 2: Create `add.php`**

`templates/Products/add.php`:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Product $product
 */
$this->assign('title', 'Nuevo producto');
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Nuevo producto</h1>
</div>

<?= $this->element('Products/_form', ['product' => $product, 'submitLabel' => 'Crear producto']) ?>
```

- [ ] **Step 3: Create `edit.php`**

`templates/Products/edit.php`:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Product $product
 */
$this->assign('title', 'Editar producto');
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Editar: <?= h($product->name) ?></h1>
</div>

<?= $this->element('Products/_form', ['product' => $product, 'submitLabel' => 'Guardar cambios']) ?>
```

- [ ] **Step 4: Commit**

```bash
git add templates/element/Products/_form.php templates/Products/add.php templates/Products/edit.php
git commit -m "feat(products): add form partial with add/edit templates"
```

---

## Task 14: Create index template

**Files:**
- Create: `templates/Products/index.php`

- [ ] **Step 1: Create the listing template**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \Cake\ORM\ResultSet|\App\Model\Entity\Product[] $products
 * @var array{q:string,status:string,sort:string,direction:string} $filters
 */
$this->assign('title', 'Productos');
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Productos</h1>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg"></i> Nuevo producto',
        ['action' => 'add'],
        ['escape' => false, 'class' => 'btn btn-primary']
    ) ?>
</div>

<form method="get" class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <i class="bi bi-search text-muted"></i>
            <input type="text" name="q" class="form-control form-control-sm" style="max-width: 320px;"
                   value="<?= h($filters['q']) ?>" placeholder="Buscar por nombre o código">
            <select name="status" class="form-select form-select-sm" style="max-width: 180px;">
                <?php foreach (['all' => 'Todos', 'active' => 'Activos', 'inactive' => 'Inactivos'] as $val => $label): ?>
                    <option value="<?= h($val) ?>" <?= $filters['status'] === $val ? 'selected' : '' ?>>
                        <?= h($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-secondary">Filtrar</button>
            <?php if ($filters['q'] !== '' || $filters['status'] !== 'all'): ?>
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
                    <th style="width:80px;"></th>
                    <th><?= $this->Paginator->sort('code', 'Código') ?></th>
                    <th><?= $this->Paginator->sort('name', 'Nombre') ?></th>
                    <th class="text-end"><?= $this->Paginator->sort('price', 'Precio') ?></th>
                    <th class="text-center" style="width:140px;">Disponible</th>
                    <th class="text-end" style="width:140px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $product): ?>
                    <tr<?= $product->is_active ? '' : ' class="text-muted" style="opacity:.7;"' ?>>
                        <td>
                            <div class="border rounded overflow-hidden d-flex align-items-center justify-content-center"
                                 style="width:64px; height:64px; background:#f8f9fa;">
                                <img src="<?= h($product->getImageUrl()) ?>" alt="" style="max-width:100%; max-height:100%; object-fit:cover;">
                            </div>
                        </td>
                        <td><?= $product->code ? h($product->code) : '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <?= $this->Html->link(h($product->name), ['action' => 'edit', $product->id]) ?>
                            <?php if (!$product->is_active): ?>
                                <span class="badge badge-soft-secondary ms-1">Inactivo</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= h($product->getFormattedPrice()) ?></td>
                        <td class="text-center">
                            <?= $this->Form->postLink(
                                $product->is_active
                                    ? '<i class="bi bi-toggle-on text-success fs-4"></i>'
                                    : '<i class="bi bi-toggle-off text-muted fs-4"></i>',
                                ['action' => 'toggleActive', $product->id],
                                [
                                    'escape' => false,
                                    'class' => 'btn btn-sm btn-link p-0',
                                    'title' => $product->is_active ? 'Desactivar' : 'Activar',
                                ]
                            ) ?>
                        </td>
                        <td class="text-end">
                            <?= $this->Html->link(
                                '<i class="bi bi-pencil"></i>',
                                ['action' => 'edit', $product->id],
                                ['escape' => false, 'class' => 'btn btn-icon btn-light', 'title' => 'Editar']
                            ) ?>
                            <?= $this->Form->postLink(
                                '<i class="bi bi-trash"></i>',
                                ['action' => 'delete', $product->id],
                                [
                                    'escape' => false,
                                    'class' => 'btn btn-icon btn-light text-danger',
                                    'title' => 'Eliminar',
                                    'confirm' => sprintf('¿Eliminar el producto "%s"?', $product->name),
                                ]
                            ) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($products->toArray()) === 0): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">
                            <?php if ($filters['q'] !== '' || $filters['status'] !== 'all'): ?>
                                Sin resultados para los filtros aplicados.
                            <?php else: ?>
                                Aún no hay productos.
                                <?= $this->Html->link('Crear el primero', ['action' => 'add'], ['class' => 'ms-1']) ?>.
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->element('pagination') ?>
```

- [ ] **Step 2: Commit**

```bash
git add templates/Products/index.php
git commit -m "feat(products): add index template with filters and inline toggle"
```

---

## Task 15: Create view template and placeholder image

**Files:**
- Create: `templates/Products/view.php`
- Create: `webroot/img/product-placeholder.svg`

- [ ] **Step 1: Create the view template**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Product $product
 */
$this->assign('title', $product->name);
?>
<div class="dr-page-header">
    <h1 class="dr-page-title"><?= h($product->name) ?></h1>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-pencil"></i> Editar',
            ['action' => 'edit', $product->id],
            ['escape' => false, 'class' => 'btn btn-secondary']
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left"></i> Volver',
            ['action' => 'index'],
            ['escape' => false, 'class' => 'btn btn-light']
        ) ?>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="row g-4">
            <div class="col-md-4">
                <div class="border rounded overflow-hidden d-flex align-items-center justify-content-center"
                     style="aspect-ratio:1/1; background:#f8f9fa;">
                    <img src="<?= h($product->getImageUrl()) ?>" alt="" style="max-width:100%; max-height:100%; object-fit:contain;">
                </div>
            </div>
            <div class="col-md-8">
                <dl class="row mb-0">
                    <dt class="col-sm-3">Código</dt>
                    <dd class="col-sm-9"><?= $product->code ? h($product->code) : '<span class="text-muted">Sin código</span>' ?></dd>

                    <dt class="col-sm-3">Precio</dt>
                    <dd class="col-sm-9"><?= h($product->getFormattedPrice()) ?></dd>

                    <dt class="col-sm-3">Estado</dt>
                    <dd class="col-sm-9">
                        <?php if ($product->is_active): ?>
                            <span class="badge badge-soft-success">Disponible</span>
                        <?php else: ?>
                            <span class="badge badge-soft-secondary">No disponible</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-3">Descripción</dt>
                    <dd class="col-sm-9"><?= $product->description ? nl2br(h($product->description)) : '<span class="text-muted">—</span>' ?></dd>

                    <dt class="col-sm-3">Creado</dt>
                    <dd class="col-sm-9"><?= $product->created ? h($product->created->i18nFormat('dd/MM/yyyy HH:mm')) : '—' ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 2: Create the placeholder SVG**

`webroot/img/product-placeholder.svg`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" width="200" height="200">
  <rect width="200" height="200" fill="#f1f3f5"/>
  <g fill="#adb5bd">
    <path d="M50 70h100v80H50z" fill="none" stroke="#adb5bd" stroke-width="3"/>
    <circle cx="80" cy="100" r="8"/>
    <path d="M55 145l30-30 25 25 20-15 20 20v5H55z"/>
  </g>
  <text x="100" y="180" text-anchor="middle" fill="#868e96" font-family="sans-serif" font-size="14">Sin imagen</text>
</svg>
```

- [ ] **Step 3: Commit**

```bash
git add templates/Products/view.php webroot/img/product-placeholder.svg
git commit -m "feat(products): add view template and placeholder image"
```

---

## Task 16: Manual smoke test of full module

This is the integration check. No code changes — just walk through every feature in the browser and confirm it behaves.

- [ ] **Step 1: Start the dev server**

```bash
php bin/cake.php server -p 8765
```

Leave it running and open <http://localhost:8765> in a browser.

- [ ] **Step 2: Login and navigate to Products**

Log in as `admin` / `ca1ced0.DEV`. Verify "Productos" appears in the sidebar with a box-seam icon. Click it.

Expected: empty list with "Aún no hay productos. Crear el primero." message.

- [ ] **Step 3: Create a product without image**

Click "Nuevo producto". Fill in:
- Nombre: `Hamburguesa Doble`
- Código: `H2`
- Precio: `15000`
- Descripción: `Doble carne, queso, lechuga, tomate`
- Disponible: checked

Submit. Expected: redirect to listing, Flash success message, the new row appears with the placeholder thumbnail and price formatted as `$15.000`.

- [ ] **Step 4: Create a product with image**

Click "Nuevo producto" again. Fill in the same way (use `Papas Grandes`, `P1`, `8000`) and upload a JPG/PNG photo (ideally something larger than 800×800 to verify resize). Submit.

Expected: redirect, the listing shows the resized thumbnail. Verify on disk:

```bash
ls -lh webroot/uploads/products/
```

There should be a directory matching the new product's id, with one `product_*.jpg` file inside under 200 KB (proof the resize/JPEG-85 worked).

- [ ] **Step 5: Edit and replace image**

Click the pencil on `Papas Grandes`. Upload a different image and click "Guardar cambios".

Expected: redirect, listing shows the new image. Verify the old file is gone:

```bash
ls webroot/uploads/products/<id>/
```

Only one file should remain.

- [ ] **Step 6: Toggle availability inline**

In the listing, click the toggle switch on `Hamburguesa Doble`. Expected: page reloads, Flash "Producto desactivado.", row appears with reduced opacity and `Inactivo` badge. Click again to reactivate.

- [ ] **Step 7: Filters**

- Type `ham` in the search box and hit "Filtrar". Only `Hamburguesa Doble` should show.
- Clear, set status to `Inactivos`. The list should reflect only inactive products.
- Click column headers (`Código`, `Nombre`, `Precio`) and confirm sorting toggles.
- Click "Limpiar" to reset.

- [ ] **Step 8: Validation errors**

Try to create a product with:
- Empty name → expected: error "El nombre es requerido".
- Price = 0 → expected: error "El precio debe ser mayor o igual a 1".
- Code with spaces (e.g. `H 2`) → expected: error "El código solo permite letras, números y guiones".
- Duplicate code (`H2`) → expected: error "Ese código ya está en uso".

- [ ] **Step 9: Delete**

Click the trash icon on a product without sales. Confirm the prompt. Expected: row disappears, Flash success, the upload directory for that product is gone:

```bash
ls webroot/uploads/products/
```

The directory of the deleted product should not be there.

- [ ] **Step 10: Image rejection**

Try uploading a non-image file (e.g. a `.txt`) renamed to `.jpg`. Expected: Flash error "Formato de imagen no permitido. Usá JPG, PNG o WebP." and the product is not created (or its image is not saved on edit).

- [ ] **Step 11: Stop the server and commit nothing**

`Ctrl+C` the server. No code changes were made in this task — nothing to commit.

If any of the smoke checks above fail, return to the corresponding earlier task, fix the issue with a follow-up commit, and re-run that smoke step.

---

## Spec coverage check

Going section by section through `docs/superpowers/specs/2026-05-02-products-module-design.md`:

- §2 Schema → Task 1.
- §3 Constants → Task 2.
- §4 Entity & Table → Tasks 3, 4.
- §5 Services (`ProductService` + `ProductImageService`) → Tasks 5, 6. `hasSales()` tolerance for missing `OrderItems` → Task 6.
- §6 Controller & routes (index, view, add, edit, delete, toggleActive, list filters) → Tasks 7, 8, 9, 10.
- §7 Templates (index, _form, add, edit, view, placeholder) → Tasks 13, 14, 15.
- §8.1 RBAC wiring (3 points + sidebar) → Task 11. Seed migration → Task 12.
- §8.2 Validation split (Table format + Service business) → covered in Tasks 4 and 6.
- §8.3 Business rules → covered transversally by Tasks 4, 5, 6, 14.
- §9 Acceptance criteria → walked through in Task 16.
- §10 Conventions established → reflected throughout.
- §11 Out-of-scope → not implemented (intentional).
