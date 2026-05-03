# Deliveries Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the Repartidores module — first piece of Phase 2 (Operación) — covering CRUD, soft delete via `is_active`, an optional 1:1 link to a system user managed from the Users form, RBAC integration, and the manual verification needed before Pedidos lands.

**Architecture:** Standard CakePHP 5.x layered architecture (Controller → Service → Table). Single `DeliveryService` (no service family — no state machine, no field history, no complex filters). The User↔Delivery 1:1 link is implemented with a nullable `users.delivery_id` FK plus a UNIQUE index; the link UX lives in the Users form, not the Deliveries form. Mirrors the conventions established by the Customers and Products modules.

**Tech Stack:** PHP 8.2+, CakePHP 5.x, MySQL/MariaDB (dev/prod), Bootstrap 5 + Bootstrap Icons, custom `webroot/css/davirapid.css`.

**Spec:** `docs/superpowers/specs/2026-05-03-deliveries-module-design.md` (commit `04fbc49`).

**Note on testing:** Per project preference, no automated tests (PHPUnit, integration, or otherwise) are written or scaffolded. Each task ends with a manual smoke check using `php bin/cake.php server` or migrations CLI, then a commit.

---

## File Structure

### Files to create
- `config/Migrations/20260503130000_CreateDeliveries.php` — deliveries table.
- `config/Migrations/20260503130100_AddDeliveryIdToUsers.php` — adds nullable FK + UNIQUE on `users.delivery_id`.
- `config/Migrations/20260503130200_SeedDeliveriesPermissions.php` — seed permissions for non-admin and admin roles.
- `src/Constants/DeliveryConstants.php` — schema-related limits.
- `src/Model/Entity/Delivery.php` — entity with `isActive()` and virtual `fullName`.
- `src/Model/Table/DeliveriesTable.php` — validation, custom finders, `hasOne` Users.
- `src/Service/DeliveryService.php` — CRUD orchestration plus activate/deactivate/toggle helpers.
- `src/Controller/DeliveriesController.php` — HTTP layer.
- `templates/Deliveries/index.php` — list with filters and inline toggle.
- `templates/Deliveries/add.php` — wraps the form partial.
- `templates/Deliveries/edit.php` — wraps the form partial.
- `templates/Deliveries/view.php` — detail with linked-user card and orders placeholder.
- `templates/element/Deliveries/_form.php` — shared form partial.

### Files to modify
- `src/Controller/AppController.php` — extend `$controllerModuleMap`.
- `src/Service/AuthorizationService.php` — extend `MODULES`.
- `src/View/Helper/SidebarHelper.php` — add Repartidores entry under Operación group.
- `config/routes.php` — register `/deliveries/toggle-active/{id}` before fallback.
- `src/Model/Entity/User.php` — add `delivery_id` to `$_accessible`.
- `src/Model/Table/UsersTable.php` — `belongsTo` Deliveries, validation, uniqueness rule.
- `src/Service/UserService.php` — validate `delivery_id` payload (exists, active, not taken).
- `templates/Users/add.php` — add "Repartidor vinculado" selector.
- `templates/Users/edit.php` — add "Repartidor vinculado" selector.
- `templates/Users/view.php` — show linked delivery if any.
- `src/Controller/UsersController.php` — provide the available-deliveries list to add/edit.

---

## Task 1: Create deliveries migration and run it

**Files:**
- Create: `config/Migrations/20260503130000_CreateDeliveries.php`

- [ ] **Step 1: Create the migration file**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateDeliveries extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('deliveries')) {
            return;
        }

        $this->table('deliveries')
            ->addColumn('first_name', 'string', ['limit' => 60, 'null' => false])
            ->addColumn('last_name', 'string', ['limit' => 60, 'null' => false])
            ->addColumn('phone', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('created', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('modified', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(
                ['is_active', 'last_name', 'first_name'],
                ['name' => 'idx_deliveries_active_name']
            )
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('deliveries')) {
            $this->table('deliveries')->drop()->update();
        }
    }
}
```

- [ ] **Step 2: Run the migration**

Run: `php bin/cake.php migrations migrate`
Expected: output includes `== 20260503130000 CreateDeliveries: migrated`.

- [ ] **Step 3: Verify schema**

Run: `php bin/cake.php migrations status`
Expected: `up` next to `20260503130000`.

- [ ] **Step 4: Commit**

```bash
git add config/Migrations/20260503130000_CreateDeliveries.php
git commit -m "feat(deliveries): add deliveries table migration"
```

---

## Task 2: Add `users.delivery_id` migration and run it

**Files:**
- Create: `config/Migrations/20260503130100_AddDeliveryIdToUsers.php`

- [ ] **Step 1: Create the migration file**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddDeliveryIdToUsers extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('users');

        if (!$table->hasColumn('delivery_id')) {
            $table
                ->addColumn('delivery_id', 'integer', [
                    'signed' => true,
                    'null' => true,
                    'default' => null,
                    'after' => 'role_id',
                ])
                ->addIndex(['delivery_id'], [
                    'unique' => true,
                    'name' => 'uq_users_delivery_id',
                ])
                ->addForeignKey('delivery_id', 'deliveries', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                ])
                ->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('users');

        if ($table->hasForeignKey('delivery_id')) {
            $table->dropForeignKey('delivery_id')->update();
        }
        if ($table->hasIndexByName('uq_users_delivery_id')) {
            $table->removeIndexByName('uq_users_delivery_id')->update();
        }
        if ($table->hasColumn('delivery_id')) {
            $table->removeColumn('delivery_id')->update();
        }
    }
}
```

- [ ] **Step 2: Run the migration**

Run: `php bin/cake.php migrations migrate`
Expected: output includes `== 20260503130100 AddDeliveryIdToUsers: migrated`.

- [ ] **Step 3: Verify schema (manual SQL check)**

Run: `php bin/cake.php migrations status`
Expected: `up` next to `20260503130100`.

Optional sanity check in your DB client:
```sql
SHOW CREATE TABLE users;
```
Expected: `delivery_id` column present, `UNIQUE KEY uq_users_delivery_id (delivery_id)`, FK with `ON DELETE SET NULL`.

- [ ] **Step 4: Commit**

```bash
git add config/Migrations/20260503130100_AddDeliveryIdToUsers.php
git commit -m "feat(deliveries): add nullable users.delivery_id FK with unique index"
```

---

## Task 3: Create `DeliveryConstants`

**Files:**
- Create: `src/Constants/DeliveryConstants.php`

- [ ] **Step 1: Create the constants file**

```php
<?php
declare(strict_types=1);

namespace App\Constants;

final class DeliveryConstants
{
    public const NAME_MAX_LENGTH = 60;
    public const PHONE_MAX_LENGTH = 20;

    /** Permissive: digits, spaces, plus, hyphen, parentheses. */
    public const PHONE_REGEX = '/^[0-9 +\-()]+$/';

    private function __construct() {}
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Constants/DeliveryConstants.php
git commit -m "feat(deliveries): add DeliveryConstants with field limits and phone regex"
```

---

## Task 4: Create `Delivery` entity

**Files:**
- Create: `src/Model/Entity/Delivery.php`

- [ ] **Step 1: Create the entity**

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class Delivery extends Entity
{
    protected array $_accessible = [
        'first_name' => true,
        'last_name' => true,
        'phone' => true,
        'is_active' => true,
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

- [ ] **Step 2: Commit**

```bash
git add src/Model/Entity/Delivery.php
git commit -m "feat(deliveries): add Delivery entity with fullName virtual"
```

---

## Task 5: Create `DeliveriesTable`

**Files:**
- Create: `src/Model/Table/DeliveriesTable.php`

- [ ] **Step 1: Create the table**

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\DeliveryConstants;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class DeliveriesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('deliveries');
        $this->setPrimaryKey('id');
        $this->setDisplayField('last_name');
        $this->addBehavior('Timestamp');

        // 1:1 optional with Users (FK lives on users.delivery_id).
        $this->hasOne('Users', [
            'foreignKey' => 'delivery_id',
            'dependent' => false,
        ]);

        // hasMany Orders is intentionally NOT declared yet:
        // OrdersTable does not exist and FactoryLocator fallback is disabled.
        // Add it in the Pedidos spec.
    }

    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->notEmptyString('first_name', 'El nombre es requerido')
            ->maxLength('first_name', DeliveryConstants::NAME_MAX_LENGTH,
                'El nombre no puede superar 60 caracteres')
            ->notEmptyString('last_name', 'El apellido es requerido')
            ->maxLength('last_name', DeliveryConstants::NAME_MAX_LENGTH,
                'El apellido no puede superar 60 caracteres')
            ->notEmptyString('phone', 'El teléfono es requerido')
            ->maxLength('phone', DeliveryConstants::PHONE_MAX_LENGTH,
                'El teléfono no puede superar 20 caracteres')
            ->add('phone', 'format', [
                'rule' => ['custom', DeliveryConstants::PHONE_REGEX],
                'message' => 'El teléfono solo admite dígitos, espacios, "+", "-" y paréntesis',
            ])
            ->boolean('is_active');
    }

    /** Active deliveries only — used by selectors. */
    public function findActive(SelectQuery $query): SelectQuery
    {
        return $query->where(['Deliveries.is_active' => true]);
    }

    /**
     * Returns id => "Apellido, Nombre" for use in dropdowns.
     * NOTE: do NOT override findList() in CakePHP 5 (incompatible signature).
     */
    public function findFullNameList(SelectQuery $query): SelectQuery
    {
        return $query
            ->select(['id', 'first_name', 'last_name'])
            ->orderBy(['last_name' => 'ASC', 'first_name' => 'ASC'])
            ->formatResults(function ($results) {
                return $results->combine(
                    'id',
                    fn($row) => trim(($row->last_name ?? '') . ', ' . ($row->first_name ?? ''))
                );
            });
    }
}
```

- [ ] **Step 2: Smoke test the table loads**

Run:
```bash
php bin/cake.php bake test --no-test deliveries 2>&1 | head -5 || true
php -r "require 'vendor/autoload.php'; require 'config/bootstrap.php'; \Cake\ORM\TableRegistry::getTableLocator()->get('Deliveries');" 2>&1
```
Expected: no fatal errors. (The first command may fail because we don't bake tests — ignore. The second loads the bootstrap and instantiates the table; clean exit means the class is discoverable and valid.)

If the second command errors with autoload/bootstrap path issues, simply skip it — Task 7 will smoke-test it through the controller.

- [ ] **Step 3: Commit**

```bash
git add src/Model/Table/DeliveriesTable.php
git commit -m "feat(deliveries): add DeliveriesTable with validation and finders"
```

---

## Task 6: Create `DeliveryService`

**Files:**
- Create: `src/Service/DeliveryService.php`

- [ ] **Step 1: Create the service**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Delivery;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;

final class DeliveryService
{
    use LocatorAwareTrait;

    /**
     * @return array{success: bool, delivery?: Delivery, errors?: array<string>}
     */
    public function create(array $data): array
    {
        $table = $this->fetchTable('Deliveries');
        $delivery = $table->newEmptyEntity();
        $delivery = $table->patchEntity($delivery, $data);

        if (!$table->save($delivery)) {
            return [
                'success' => false,
                'errors' => $this->flattenErrors($delivery->getErrors()),
                'delivery' => $delivery,
            ];
        }

        Log::info('Delivery created: id={id} name={name}', [
            'id' => $delivery->id,
            'name' => $delivery->full_name,
            'scope' => ['deliveries'],
        ]);

        return ['success' => true, 'delivery' => $delivery];
    }

    /**
     * @return array{success: bool, delivery?: Delivery, errors?: array<string>}
     */
    public function update(Delivery $delivery, array $data): array
    {
        $table = $this->fetchTable('Deliveries');
        $patched = $table->patchEntity($delivery, $data);

        if (!$table->save($patched)) {
            return [
                'success' => false,
                'errors' => $this->flattenErrors($patched->getErrors()),
                'delivery' => $patched,
            ];
        }

        return ['success' => true, 'delivery' => $patched];
    }

    /**
     * @return array{success: bool, delivery?: Delivery, errors?: array<string>}
     */
    public function activate(Delivery $delivery): array
    {
        return $this->setActive($delivery, true);
    }

    /**
     * @return array{success: bool, delivery?: Delivery, errors?: array<string>}
     */
    public function deactivate(Delivery $delivery): array
    {
        return $this->setActive($delivery, false);
    }

    /**
     * Convenience for the inline toggle in the index view.
     *
     * @return array{success: bool, delivery?: Delivery, errors?: array<string>}
     */
    public function toggleActive(Delivery $delivery): array
    {
        return $this->setActive($delivery, !$delivery->isActive());
    }

    /**
     * @return array{success: bool, delivery?: Delivery, errors?: array<string>}
     */
    private function setActive(Delivery $delivery, bool $active): array
    {
        $table = $this->fetchTable('Deliveries');
        $delivery->is_active = $active;

        if (!$table->save($delivery)) {
            return [
                'success' => false,
                'errors' => $this->flattenErrors($delivery->getErrors()),
                'delivery' => $delivery,
            ];
        }

        Log::info('Delivery {state}: id={id}', [
            'state' => $active ? 'activated' : 'deactivated',
            'id' => $delivery->id,
            'scope' => ['deliveries'],
        ]);

        return ['success' => true, 'delivery' => $delivery];
    }

    /**
     * @param array $errors Cake validator/rules error tree.
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
git add src/Service/DeliveryService.php
git commit -m "feat(deliveries): add DeliveryService with CRUD and activate/deactivate/toggle"
```

---

## Task 7: Create `DeliveriesController`

**Files:**
- Create: `src/Controller/DeliveriesController.php`

- [ ] **Step 1: Create the controller**

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\DeliveryService;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\ORM\Query\SelectQuery;

class DeliveriesController extends AppController
{
    public array $paginate = [
        'limit' => 15,
        'maxLimit' => 15,
        'order' => ['Deliveries.is_active' => 'DESC', 'Deliveries.last_name' => 'ASC', 'Deliveries.first_name' => 'ASC'],
        'sortableFields' => ['first_name', 'last_name', 'phone', 'created'],
    ];

    private DeliveryService $deliveryService;

    public function initialize(): void
    {
        parent::initialize();
        $this->deliveryService = new DeliveryService();
    }

    public function index(): void
    {
        $filters = $this->_currentFilters();
        $query = $this->_buildIndexQuery($filters);
        $deliveries = $this->paginate($query);

        $this->set(compact('deliveries', 'filters'));
        $this->set('breadcrumbs', [['label' => 'Repartidores']]);
    }

    public function view(int $id): void
    {
        $delivery = $this->Deliveries->get($id, contain: ['Users' => ['Roles']]);
        $this->set('delivery', $delivery);
        $this->set('breadcrumbs', [
            ['label' => 'Repartidores', 'url' => ['action' => 'index']],
            ['label' => $delivery->full_name],
        ]);
    }

    public function add()
    {
        $delivery = $this->Deliveries->newEmptyEntity();

        if ($this->request->is('post')) {
            $data = $this->request->getData();
            $data['is_active'] = !empty($data['is_active']);

            $result = $this->deliveryService->create($data);
            if ($result['success']) {
                $this->Flash->success('Repartidor creado.');
                return $this->redirect(['action' => 'index']);
            }
            foreach ($result['errors'] ?? ['No se pudo crear el repartidor.'] as $msg) {
                $this->Flash->error($msg);
            }
            $delivery = $result['delivery'] ?? $delivery;
        }

        $this->set('delivery', $delivery);
        $this->set('breadcrumbs', [
            ['label' => 'Repartidores', 'url' => ['action' => 'index']],
            ['label' => 'Nuevo repartidor'],
        ]);
        return null;
    }

    public function edit(int $id)
    {
        $delivery = $this->Deliveries->get($id);

        if ($this->request->is(['put', 'post', 'patch'])) {
            $data = $this->request->getData();
            $data['is_active'] = !empty($data['is_active']);

            $result = $this->deliveryService->update($delivery, $data);
            if ($result['success']) {
                $this->Flash->success('Repartidor actualizado.');
                return $this->redirect(['action' => 'index']);
            }
            foreach ($result['errors'] ?? ['No se pudo actualizar el repartidor.'] as $msg) {
                $this->Flash->error($msg);
            }
            $delivery = $result['delivery'] ?? $delivery;
        }

        $this->set('delivery', $delivery);
        $this->set('breadcrumbs', [
            ['label' => 'Repartidores', 'url' => ['action' => 'index']],
            ['label' => $delivery->full_name, 'url' => ['action' => 'view', $delivery->id]],
            ['label' => 'Editar'],
        ]);
        return null;
    }

    public function toggleActive(int $id)
    {
        $this->request->allowMethod(['post']);

        try {
            $delivery = $this->Deliveries->get($id);
        } catch (RecordNotFoundException) {
            $this->Flash->error('El repartidor ya no existe.');
            return $this->redirect(['action' => 'index']);
        }

        $result = $this->deliveryService->toggleActive($delivery);
        if ($result['success']) {
            $msg = $result['delivery']->is_active ? 'Repartidor activado.' : 'Repartidor desactivado.';
            $this->Flash->success($msg);
        } else {
            $this->Flash->error($result['errors'][0] ?? 'No se pudo cambiar el estado.');
        }
        return $this->redirect($this->referer(['action' => 'index']));
    }

    protected function _actionToPermission(string $action): string
    {
        return match ($action) {
            'toggleActive' => 'edit',
            default => parent::_actionToPermission($action),
        };
    }

    /**
     * @return array{q: string, status: string, sort: string, direction: string}
     */
    protected function _currentFilters(): array
    {
        $allowedSort = ['first_name', 'last_name', 'phone', 'created'];
        $allowedStatus = ['all', 'active', 'inactive'];
        $allowedDir = ['asc', 'desc'];

        $sort = (string)$this->request->getQuery('sort', 'last_name');
        $direction = strtolower((string)$this->request->getQuery('direction', 'asc'));
        $status = (string)$this->request->getQuery('status', 'all');

        return [
            'q' => trim((string)$this->request->getQuery('q', '')),
            'status' => in_array($status, $allowedStatus, true) ? $status : 'all',
            'sort' => in_array($sort, $allowedSort, true) ? $sort : 'last_name',
            'direction' => in_array($direction, $allowedDir, true) ? $direction : 'asc',
        ];
    }

    protected function _buildIndexQuery(array $filters): SelectQuery
    {
        $query = $this->Deliveries->find()->contain(['Users']);

        if ($filters['q'] !== '') {
            $like = '%' . $filters['q'] . '%';
            $query->where(['OR' => [
                'Deliveries.first_name LIKE' => $like,
                'Deliveries.last_name LIKE' => $like,
                'Deliveries.phone LIKE' => $like,
            ]]);
        }

        if ($filters['status'] === 'active') {
            $query->where(['Deliveries.is_active' => true]);
        } elseif ($filters['status'] === 'inactive') {
            $query->where(['Deliveries.is_active' => false]);
        }

        $query->orderBy([
            'Deliveries.' . $filters['sort'] => strtoupper($filters['direction']),
        ]);

        return $query;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Controller/DeliveriesController.php
git commit -m "feat(deliveries): add DeliveriesController with CRUD and toggleActive"
```

---

## Task 8: Wire RBAC, sidebar, and route

**Files:**
- Modify: `src/Controller/AppController.php`
- Modify: `src/Service/AuthorizationService.php`
- Modify: `src/View/Helper/SidebarHelper.php`
- Modify: `config/routes.php`

- [ ] **Step 1: Add `Deliveries` to `controllerModuleMap`**

In `src/Controller/AppController.php`, locate the `$controllerModuleMap` array and add the `Deliveries` entry between `Customers` and any others:

```php
protected array $controllerModuleMap = [
    'Roles' => 'roles',
    'Users' => 'users',
    'Products' => 'products',
    'Customers' => 'customers',
    'Deliveries' => 'deliveries',
];
```

- [ ] **Step 2: Add `deliveries` to the `MODULES` catalogue**

In `src/Service/AuthorizationService.php`, extend the `MODULES` constant:

```php
public const MODULES = [
    'roles' => 'Roles',
    'users' => 'Usuarios',
    'products' => 'Productos',
    'customers' => 'Clientes',
    'deliveries' => 'Repartidores',
];
```

- [ ] **Step 3: Add the sidebar entry**

In `src/View/Helper/SidebarHelper.php`, add the Deliveries item after Customers in the `$items` array:

```php
[
    'module' => 'deliveries',
    'label' => 'Repartidores',
    'icon' => 'bi-truck',
    'url' => ['controller' => 'Deliveries', 'action' => 'index'],
],
```

The final order in `$items` is: products, customers, deliveries, users, roles.

- [ ] **Step 4: Register the toggle route**

In `config/routes.php`, add the new route **before** `$builder->fallbacks();` (right after the customers toggle route):

```php
// Acción custom: alternar disponibilidad de un repartidor.
$builder->connect(
    '/deliveries/toggle-active/{id}',
    ['controller' => 'Deliveries', 'action' => 'toggleActive'],
    ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST']
);
```

- [ ] **Step 5: Commit**

```bash
git add src/Controller/AppController.php src/Service/AuthorizationService.php src/View/Helper/SidebarHelper.php config/routes.php
git commit -m "feat(deliveries): wire RBAC, sidebar entry, and toggle route"
```

---

## Task 9: Seed deliveries permissions

**Files:**
- Create: `config/Migrations/20260503130200_SeedDeliveriesPermissions.php`

- [ ] **Step 1: Create the seed migration**

Per spec §6, conservative default for non-admin roles is `can_view = 1` only; the human admin user broadens permissions later through the UI.

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class SeedDeliveriesPermissions extends BaseMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // Conservative default for non-admin roles: view only.
        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'deliveries', 1, 0, 0, 0, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 0
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'deliveries'
               )"
        );

        // Admin role row (admin user bypasses by code, but keep matrix consistent).
        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'deliveries', 1, 1, 1, 1, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 1
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'deliveries'
               )"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'deliveries'");
    }
}
```

- [ ] **Step 2: Run the migration**

Run: `php bin/cake.php migrations migrate`
Expected: output includes `== 20260503130200 SeedDeliveriesPermissions: migrated`.

- [ ] **Step 3: Commit**

```bash
git add config/Migrations/20260503130200_SeedDeliveriesPermissions.php
git commit -m "feat(deliveries): seed default permissions for existing roles"
```

---

## Task 10: Create form partial and add/edit templates

**Files:**
- Create: `templates/element/Deliveries/_form.php`
- Create: `templates/Deliveries/add.php`
- Create: `templates/Deliveries/edit.php`

- [ ] **Step 1: Create the form partial**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Delivery $delivery
 * @var bool $isEdit
 */
?>
<?= $this->Form->create($delivery) ?>
<div class="card mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <?= $this->Form->control('first_name', [
                    'label' => 'Nombre',
                    'class' => 'form-control',
                    'autofocus' => true,
                    'maxlength' => 60,
                ]) ?>
            </div>
            <div class="col-md-6">
                <?= $this->Form->control('last_name', [
                    'label' => 'Apellido',
                    'class' => 'form-control',
                    'maxlength' => 60,
                ]) ?>
            </div>
            <div class="col-md-6">
                <?= $this->Form->control('phone', [
                    'label' => 'Teléfono',
                    'class' => 'form-control',
                    'maxlength' => 20,
                    'help' => 'Solo dígitos, espacios, "+", "-" y paréntesis.',
                ]) ?>
            </div>
            <?php if ($isEdit): ?>
                <div class="col-12">
                    <div class="form-check">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" id="is_active" class="form-check-input"
                               value="1" <?= $delivery->is_active === false ? '' : 'checked' ?>>
                        <label class="form-check-label" for="is_active">Repartidor activo</label>
                    </div>
                </div>
            <?php else: ?>
                <input type="hidden" name="is_active" value="1">
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="d-flex gap-2">
    <?= $this->Form->button(
        '<i class="bi bi-check-lg"></i> ' . ($isEdit ? 'Guardar cambios' : 'Crear repartidor'),
        ['escapeTitle' => false, 'class' => 'btn btn-primary']
    ) ?>
    <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
</div>
<?= $this->Form->end() ?>
```

- [ ] **Step 2: Create `add.php`**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Delivery $delivery
 */
$this->assign('title', 'Nuevo repartidor');
$isEdit = false;
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Nuevo repartidor</h1>
</div>

<?= $this->element('Deliveries/_form', compact('delivery', 'isEdit')) ?>
```

- [ ] **Step 3: Create `edit.php`**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Delivery $delivery
 */
$this->assign('title', 'Editar repartidor');
$isEdit = true;
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Editar repartidor: <?= h($delivery->full_name) ?></h1>
</div>

<?= $this->element('Deliveries/_form', compact('delivery', 'isEdit')) ?>
```

- [ ] **Step 4: Commit**

```bash
git add templates/element/Deliveries/_form.php templates/Deliveries/add.php templates/Deliveries/edit.php
git commit -m "feat(deliveries): add form partial with add/edit templates"
```

---

## Task 11: Create index template

**Files:**
- Create: `templates/Deliveries/index.php`

- [ ] **Step 1: Create the index view**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \Cake\ORM\ResultSet|\App\Model\Entity\Delivery[] $deliveries
 * @var array{q:string,status:string,sort:string,direction:string} $filters
 */
$this->assign('title', 'Repartidores');
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Repartidores</h1>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg"></i> Nuevo repartidor',
        ['action' => 'add'],
        ['escape' => false, 'class' => 'btn btn-primary']
    ) ?>
</div>

<form method="get" class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <i class="bi bi-search text-muted"></i>
            <input type="text" name="q" class="form-control form-control-sm" style="max-width: 320px;"
                   value="<?= h($filters['q']) ?>" placeholder="Buscar por nombre, apellido o teléfono">
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
                    <th><?= $this->Paginator->sort('last_name', 'Nombre completo') ?></th>
                    <th><?= $this->Paginator->sort('phone', 'Teléfono') ?></th>
                    <th>Usuario vinculado</th>
                    <th class="text-center" style="width:140px;">Activo</th>
                    <th class="text-end" style="width:120px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($deliveries as $delivery): ?>
                    <tr<?= $delivery->is_active ? '' : ' class="text-muted" style="opacity:.7;"' ?>>
                        <td><?= h($delivery->full_name) ?></td>
                        <td class="font-monospace"><?= h($delivery->phone) ?></td>
                        <td>
                            <?php if (!empty($delivery->user)): ?>
                                <span class="badge badge-soft-primary">
                                    <i class="bi bi-person-badge"></i> <?= h($delivery->user->username) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?= $this->Form->postLink(
                                $delivery->is_active ? 'Sí' : 'No',
                                ['action' => 'toggleActive', $delivery->id],
                                [
                                    'class' => 'btn btn-sm ' . ($delivery->is_active ? 'btn-success' : 'btn-light'),
                                    'title' => 'Cambiar estado',
                                ]
                            ) ?>
                        </td>
                        <td class="text-end">
                            <?= $this->Html->link(
                                '<i class="bi bi-eye"></i>',
                                ['action' => 'view', $delivery->id],
                                ['escape' => false, 'class' => 'btn btn-sm btn-light', 'title' => 'Ver']
                            ) ?>
                            <?= $this->Html->link(
                                '<i class="bi bi-pencil"></i>',
                                ['action' => 'edit', $delivery->id],
                                ['escape' => false, 'class' => 'btn btn-sm btn-light', 'title' => 'Editar']
                            ) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($deliveries) === 0): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No hay repartidores para mostrar.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->element('pagination') ?>
```

- [ ] **Step 2: Commit**

```bash
git add templates/Deliveries/index.php
git commit -m "feat(deliveries): add index template with filters and inline toggle"
```

---

## Task 12: Create view template

**Files:**
- Create: `templates/Deliveries/view.php`

- [ ] **Step 1: Create the view**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Delivery $delivery
 */
$this->assign('title', $delivery->full_name);
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">
        <?= h($delivery->full_name) ?>
        <?php if ($delivery->is_active): ?>
            <span class="badge badge-soft-success ms-2">Activo</span>
        <?php else: ?>
            <span class="badge badge-soft-secondary ms-2">Inactivo</span>
        <?php endif; ?>
    </h1>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-pencil"></i> Editar',
            ['action' => 'edit', $delivery->id],
            ['escape' => false, 'class' => 'btn btn-secondary']
        ) ?>
        <?= $this->Form->postLink(
            $delivery->is_active
                ? '<i class="bi bi-slash-circle"></i> Desactivar'
                : '<i class="bi bi-check-circle"></i> Activar',
            ['action' => 'toggleActive', $delivery->id],
            [
                'escape' => false,
                'class' => 'btn btn-light',
                'confirm' => $delivery->is_active
                    ? '¿Desactivar al repartidor "' . h($delivery->full_name) . '"?'
                    : '¿Activar al repartidor "' . h($delivery->full_name) . '"?',
            ]
        ) ?>
        <?= $this->Html->link('Volver', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header">Datos del repartidor</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted">Nombre</dt>
                    <dd class="col-sm-8"><?= h($delivery->first_name) ?></dd>

                    <dt class="col-sm-4 text-muted">Apellido</dt>
                    <dd class="col-sm-8"><?= h($delivery->last_name) ?></dd>

                    <dt class="col-sm-4 text-muted">Teléfono</dt>
                    <dd class="col-sm-8 font-monospace"><?= h($delivery->phone) ?></dd>

                    <dt class="col-sm-4 text-muted">Estado</dt>
                    <dd class="col-sm-8">
                        <?php if ($delivery->is_active): ?>
                            <span class="badge bg-success-subtle text-success-emphasis">Activo</span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary-emphasis">Inactivo</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-4 text-muted">Registrado</dt>
                    <dd class="col-sm-8"><?= $delivery->created ? h($delivery->created->i18nFormat('dd/MM/yyyy HH:mm')) : '—' ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-7">
        <div class="card mb-3">
            <div class="card-header">Cuenta de sistema</div>
            <div class="card-body">
                <?php if (!empty($delivery->user)): ?>
                    <dl class="row mb-0">
                        <dt class="col-sm-4 text-muted">Usuario</dt>
                        <dd class="col-sm-8 font-monospace"><?= h($delivery->user->username) ?></dd>

                        <dt class="col-sm-4 text-muted">Nombre</dt>
                        <dd class="col-sm-8"><?= h($delivery->user->name) ?></dd>

                        <dt class="col-sm-4 text-muted">Rol</dt>
                        <dd class="col-sm-8"><?= h($delivery->user->role?->name ?? '—') ?></dd>
                    </dl>
                    <div class="mt-3">
                        <?= $this->Html->link(
                            '<i class="bi bi-pencil"></i> Editar usuario',
                            ['controller' => 'Users', 'action' => 'edit', $delivery->user->id],
                            ['escape' => false, 'class' => 'btn btn-sm btn-light']
                        ) ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-2">Sin cuenta de sistema asignada.</p>
                    <?= $this->Html->link(
                        '<i class="bi bi-person-plus"></i> Crear usuario',
                        ['controller' => 'Users', 'action' => 'add'],
                        ['escape' => false, 'class' => 'btn btn-sm btn-light']
                    ) ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="card">
            <div class="card-header">Pedidos asignados</div>
            <div class="card-body text-muted">
                Disponible cuando se habilite el módulo de Pedidos.
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 2: Commit**

```bash
git add templates/Deliveries/view.php
git commit -m "feat(deliveries): add view template with linked-user card and orders placeholder"
```

---

## Task 13: Make `delivery_id` accessible on the User entity and table

**Files:**
- Modify: `src/Model/Entity/User.php`
- Modify: `src/Model/Table/UsersTable.php`

- [ ] **Step 1: Add `delivery_id` to `$_accessible`**

In `src/Model/Entity/User.php`, extend the `$_accessible` array:

```php
protected array $_accessible = [
    'username' => true,
    'name' => true,
    'password' => true,
    'role_id' => true,
    'delivery_id' => true,
    'active' => true,
    'role' => true,
];
```

- [ ] **Step 2: Add `belongsTo Deliveries`, validation, and uniqueness rule**

In `src/Model/Table/UsersTable.php`, modify `initialize()` to add:

```php
$this->belongsTo('Deliveries', [
    'foreignKey' => 'delivery_id',
    'joinType' => 'LEFT',
]);
```

(Place it after the existing `belongsTo('Roles', ...)` block.)

In `validationDefault()`, add the optional `delivery_id` validation **before** the closing `;` (chain it):

```php
->allowEmptyString('delivery_id')
->add('delivery_id', 'naturalNumber', [
    'rule' => ['naturalNumber'],
    'message' => 'Repartidor inválido',
    'on' => function (array $context): bool {
        return !empty($context['data']['delivery_id']);
    },
])
```

In `buildRules()`, add the uniqueness rule before `return $rules;`:

```php
$rules->add(
    $rules->isUnique(
        ['delivery_id'],
        ['allowMultipleNulls' => true],
        'Este repartidor ya está vinculado a otro usuario'
    ),
    'uniqueDeliveryLink'
);
```

The final `buildRules()` ends with `return $rules;` as before.

- [ ] **Step 3: Commit**

```bash
git add src/Model/Entity/User.php src/Model/Table/UsersTable.php
git commit -m "feat(deliveries): wire delivery_id on User entity and UsersTable"
```

---

## Task 14: Validate `delivery_id` in `UserService`

**Files:**
- Modify: `src/Service/UserService.php`

- [ ] **Step 1: Add a private validator and call it from create/update**

Add this private method to `UserService` (just above `_isAdminRole`):

```php
/**
 * Validates the optional delivery_id payload field:
 *   - delivery exists
 *   - delivery is active
 *   - not already taken by another user (excluding $excludeUserId on edit)
 *
 * @param mixed $deliveryId Raw input value (may be '', null, or numeric).
 * @param int|null $excludeUserId The user being edited, or null on create.
 * @return string|null Error message (Spanish) or null if valid / not provided.
 */
private function _validateDeliveryLink(mixed $deliveryId, ?int $excludeUserId): ?string
{
    if ($deliveryId === null || $deliveryId === '' || (int)$deliveryId === 0) {
        return null;
    }

    $deliveriesTable = $this->fetchTable('Deliveries');
    $delivery = $deliveriesTable->find()
        ->where(['Deliveries.id' => (int)$deliveryId])
        ->first();

    if ($delivery === null) {
        return 'El repartidor seleccionado no existe.';
    }
    if (!$delivery->isActive()) {
        return 'El repartidor seleccionado está inactivo.';
    }

    $usersTable = $this->fetchTable('Users');
    $conflictQuery = $usersTable->find()
        ->where(['Users.delivery_id' => (int)$deliveryId]);
    if ($excludeUserId !== null) {
        $conflictQuery->where(['Users.id !=' => $excludeUserId]);
    }
    if ($conflictQuery->first() !== null) {
        return 'El repartidor seleccionado ya está vinculado a otro usuario.';
    }

    return null;
}
```

- [ ] **Step 2: Call the validator from `create()`**

In `create()`, **before** the `patchEntity` line, add the check:

```php
$linkError = $this->_validateDeliveryLink($data['delivery_id'] ?? null, null);
if ($linkError !== null) {
    Log::info('User create rejected delivery link: {msg}', [
        'msg' => $linkError,
        'scope' => ['users', 'deliveries'],
    ]);
    return ['success' => false, 'errors' => [$linkError]];
}
```

- [ ] **Step 3: Call the validator from `update()`**

In `update()`, **before** the `patchEntity` line, add:

```php
$linkError = $this->_validateDeliveryLink($data['delivery_id'] ?? null, $id);
if ($linkError !== null) {
    Log::info('User update rejected delivery link: {msg} (user_id={id})', [
        'msg' => $linkError,
        'id' => $id,
        'scope' => ['users', 'deliveries'],
    ]);
    return ['success' => false, 'errors' => [$linkError]];
}
```

- [ ] **Step 4: Commit**

```bash
git add src/Service/UserService.php
git commit -m "feat(deliveries): validate delivery_id link in UserService"
```

---

## Task 15: Provide deliveries list to Users add/edit and render the selector

**Files:**
- Modify: `src/Controller/UsersController.php`
- Modify: `templates/Users/add.php`
- Modify: `templates/Users/edit.php`
- Modify: `templates/Users/view.php`

- [ ] **Step 1: Build the list helper in `UsersController`**

Add a private helper method to `UsersController` (just above `_actionToPermission`):

```php
/**
 * Returns id => "Apellido, Nombre" for the deliveries selector.
 * Excludes deliveries already linked to other users; if $currentUserId is given,
 * also includes that user's currently linked delivery so it remains selectable.
 *
 * @return array<int, string>
 */
private function _availableDeliveriesList(?int $currentUserId): array
{
    $usersTable = $this->fetchTable('Users');
    $deliveriesTable = $this->fetchTable('Deliveries');

    $takenQuery = $usersTable->find()
        ->select(['delivery_id'])
        ->where(['Users.delivery_id IS NOT' => null]);
    if ($currentUserId !== null) {
        $takenQuery->where(['Users.id !=' => $currentUserId]);
    }
    $takenIds = array_filter(array_map(
        fn($u) => (int)$u->delivery_id,
        $takenQuery->all()->toArray()
    ));

    $query = $deliveriesTable->find('active')->find('fullNameList');
    if (!empty($takenIds)) {
        $query->where(['Deliveries.id NOT IN' => $takenIds]);
    }
    return $query->toArray();
}
```

- [ ] **Step 2: Pass the list from `add()`**

In `UsersController::add()`, add this line just before the closing of the action (alongside the existing `$this->set('roles', ...)`):

```php
$this->set('deliveriesList', $this->_availableDeliveriesList(null));
```

- [ ] **Step 3: Pass the list from `edit()`**

In `UsersController::edit()`, before the existing `$this->set('roles', ...)`, add:

```php
$this->set('deliveriesList', $this->_availableDeliveriesList((int)$user->id));
```

- [ ] **Step 4: Eager-load the linked delivery on `view()`**

Modify `UsersController::view()`:

```php
public function view(int $id): void
{
    $user = $this->Users->get($id, contain: ['Roles', 'Deliveries']);
    $this->set('user', $user);
    $this->set('breadcrumbs', [
        ['label' => 'Usuarios', 'url' => ['action' => 'index']],
        ['label' => $user->username],
    ]);
}
```

- [ ] **Step 5: Add the selector to `templates/Users/add.php`**

Inside the `<div class="row g-3">` block, add a new column after the role selector and before the activo checkbox:

```php
<div class="col-md-6">
    <?= $this->Form->control('delivery_id', [
        'label' => 'Repartidor vinculado (opcional)',
        'class' => 'form-select',
        'type' => 'select',
        'options' => $deliveriesList,
        'empty' => '— Ninguno —',
        'required' => false,
    ]) ?>
</div>
```

Also extend the docblock at the top of the file:

```php
 * @var array<int, string> $deliveriesList
```

- [ ] **Step 6: Add the selector to `templates/Users/edit.php`**

Same change as Step 5 — add the column after the role section (whether the role is editable or not) and before the active checkbox:

```php
<div class="col-md-6">
    <?= $this->Form->control('delivery_id', [
        'label' => 'Repartidor vinculado (opcional)',
        'class' => 'form-select',
        'type' => 'select',
        'options' => $deliveriesList,
        'empty' => '— Ninguno —',
        'required' => false,
        'default' => $user->delivery_id,
    ]) ?>
</div>
```

Also extend the docblock at the top of `edit.php`:

```php
 * @var array<int, string> $deliveriesList
```

- [ ] **Step 7: Show the linked delivery on `templates/Users/view.php`**

Inside the `<dl class="row mb-0">` block in `view.php`, add a new row right after the **Rol** dt/dd pair:

```php
<dt class="col-sm-3 text-muted">Repartidor</dt>
<dd class="col-sm-9">
    <?php if (!empty($user->delivery)): ?>
        <?= $this->Html->link(
            h(trim(($user->delivery->last_name ?? '') . ', ' . ($user->delivery->first_name ?? ''))),
            ['controller' => 'Deliveries', 'action' => 'view', $user->delivery->id]
        ) ?>
    <?php else: ?>
        <span class="text-muted">—</span>
    <?php endif; ?>
</dd>
```

- [ ] **Step 8: Commit**

```bash
git add src/Controller/UsersController.php templates/Users/add.php templates/Users/edit.php templates/Users/view.php
git commit -m "feat(deliveries): add user↔delivery selector to Users add/edit/view"
```

---

## Task 16: Manual smoke test of the full module

**Files:** none (verification only).

- [ ] **Step 1: Boot the dev server**

Run: `php bin/cake.php server -p 8765`
Leave it running while performing the following checks in a browser at `http://localhost:8765`.

- [ ] **Step 2: Log in as Administrador**

Use the seeded administrator credentials. Confirm that the sidebar now shows "Repartidores" between Clientes and Usuarios.

- [ ] **Step 3: Index empty state**

Navigate to `/deliveries`. Expected: page renders with title "Repartidores", empty-state row "No hay repartidores para mostrar.", filter bar visible, primary "Nuevo repartidor" button.

- [ ] **Step 4: Create three repartidores**

Click "Nuevo repartidor". Create:
1. `Carlos` / `Ramírez` / `3001234567`
2. `Diana` / `Salazar` / `300 765 4321`
3. `Esteban` / `Morales` / `+57 (300) 999-1111`

Expected after each: redirect to `/deliveries` with success Flash, row visible.

- [ ] **Step 5: Phone format validation**

Try creating a repartidor with phone `abc123`. Expected: form re-renders with error "El teléfono solo admite dígitos, espacios, "+", "-" y paréntesis".

- [ ] **Step 6: Filters and sort**

- Search `Ram` → only Carlos visible.
- Search `300` → all three.
- Sort by Teléfono ascending; verify order.
- Status `Inactivos` → empty.

- [ ] **Step 7: Toggle active from index**

Click the "Sí" button on Diana's row. Expected: button flips to "No", row dims, Flash "Repartidor desactivado.". Filter `Inactivos` shows Diana only. Click "No" to reactivate; Flash "Repartidor activado.".

- [ ] **Step 8: Edit**

Edit Carlos and change his phone to `3001112222`. Expected: redirect to index with success Flash, view page shows the new phone.

- [ ] **Step 9: View placeholders**

Open Carlos' detail page. Expected: left card with all data, right column with "Cuenta de sistema" (showing "Sin cuenta de sistema asignada" + "Crear usuario" button) and "Pedidos asignados" placeholder.

- [ ] **Step 10: Link a user to a repartidor (create)**

Navigate to `/users/add`. Create a new user with role Cajero (or any non-admin role) and select Carlos in "Repartidor vinculado (opcional)". Expected: user created, Flash success.

Verify on `/deliveries/view/{carlos_id}`: the "Cuenta de sistema" card now shows the new user with role and "Editar usuario" button.

- [ ] **Step 11: Conflict — try linking the same repartidor to a second user**

Edit a different existing user. Open the "Repartidor vinculado" select. Expected: Carlos does **not** appear; Diana and Esteban do.

If you want to brute-force the rule, open the page-source / DOM and inject `<option value="{carlos_id}">Carlos</option>`, then submit. Expected: form re-renders with Flash error "El repartidor seleccionado ya está vinculado a otro usuario." (the service-level guard catches it even if the UI is bypassed.)

- [ ] **Step 12: Conflict — link to an inactive repartidor**

Deactivate Esteban from `/deliveries`. Then edit a user (one without a linked delivery) and try injecting Esteban's id into the select via DOM (Esteban is filtered out of the active options, so this is the only way to test). Submit. Expected: Flash error "El repartidor seleccionado está inactivo.".

Reactivate Esteban afterward.

- [ ] **Step 13: Self-edit keeps current selection visible**

Edit the user linked to Carlos (created in Step 10). Open the "Repartidor vinculado" select. Expected: Carlos appears in the options (because the helper excludes only OTHER users' linked deliveries), and is preselected.

- [ ] **Step 14: Deactivate a repartidor that has a linked user**

Deactivate Carlos from `/deliveries` index toggle. Expected: success, Carlos shown as inactive, his linked user still active in `/users`, the link still visible on `/deliveries/view/{carlos_id}` and `/users/view/{linked_user_id}`.

- [ ] **Step 15: Login scoping placeholder**

Log out. Log in as the user linked to Carlos. Expected: login succeeds. Open `tmp/logs/debug.log` (or use `tail -f tmp/logs/debug.log` in another terminal) and search for any error messages about the missing module — there should be none.

To confirm `currentUser->delivery_id` is exposed, temporarily add `<?= 'delivery_id=' . var_export($currentUser['delivery_id'] ?? null, true) ?>` near the top of `templates/Pages/home.php`, reload `/`, and verify it prints the expected delivery id. **Remove the temporary line before committing.**

- [ ] **Step 16: RBAC — non-admin without view permission**

Log out, log back in as Administrador. From `/roles`, find a non-admin role (e.g., Cajero) and remove `can_view` for module `deliveries` (or directly `UPDATE permissions SET can_view = 0 WHERE module = 'deliveries' AND role_id = <cajero_id>;`).

Log out and log in as a user with that role. Expected: sidebar item "Repartidores" is hidden; visiting `/deliveries` returns the 403 page ("No tenés permiso para realizar esta acción.").

Restore the permission afterward.

- [ ] **Step 17: RBAC — view but not edit**

Set the role to `can_view = 1, can_edit = 0` for `deliveries`. Log in as that role. Expected: index loads, but submitting a POST to `/deliveries/toggle-active/{id}` (via the inline button) returns 403; the "Nuevo repartidor" / "Editar" buttons should not actually trigger access errors only because they hit `add`/`edit` actions (which map to `create`/`edit` permissions). Confirm `add` returns 403 if `can_create = 0`.

Restore permissions.

- [ ] **Step 18: Stop the server and commit any incidental fixes**

Stop the server. If anything required a code fix during the smoke test, commit it now with `fix(deliveries): ...`. Otherwise, no commit needed.

---

## Done

The Repartidores module is complete and operational. The optional 1:1 link with users is in place and `currentUser->delivery_id` is available in the session — ready to be consumed by the Pedidos module for per-rider order scoping.
