# Customers Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the Customers module — second catálogo base of Phase 1 — covering CRUD, phone-as-identifier with uniqueness, free-text address, hard-delete-with-guard plus `is_active` toggle, RBAC integration, and a `findOrCreateByPhone` service method ready for future Pedidos consumption.

**Architecture:** Standard CakePHP 5.x layered architecture (Controller → Service → Table). Single `CustomerService` (no service family — no state machine, no field history, no complex filters). Templates use the existing `default.php` layout with the project's Bootstrap 5 + `dr-*` design classes. Mirrors the conventions established by the Products module.

**Tech Stack:** PHP 8.2+, CakePHP 5.x, MySQL/MariaDB (dev/prod), Bootstrap 5 + Bootstrap Icons, custom `webroot/css/davirapid.css`.

**Spec:** `docs/superpowers/specs/2026-05-03-customers-module-design.md` (commit `59b8178`).

**Note on testing:** Per project preference, no automated tests (PHPUnit, integration, or otherwise) are written or scaffolded. Each task ends with a manual smoke check using `php bin/cake.php server` or migrations CLI, then a commit.

---

## File Structure

### Files to create
- `config/Migrations/20260503120000_CreateCustomers.php` — customers table.
- `config/Migrations/20260503120100_SeedCustomersPermissions.php` — seed permissions for non-admin and admin roles.
- `src/Constants/CustomerConstants.php` — schema-related limits (max lengths).
- `src/Model/Entity/Customer.php` — entity with `isActive()` and virtual `displayName`.
- `src/Model/Table/CustomersTable.php` — validation, unique-phone rule, custom finders.
- `src/Service/CustomerService.php` — CRUD orchestration, dependency-tolerant delete guard, `findOrCreateByPhone`.
- `src/Controller/CustomersController.php` — HTTP layer.
- `templates/Customers/index.php` — list with filters and inline toggle.
- `templates/Customers/add.php` — wraps the form partial.
- `templates/Customers/edit.php` — wraps the form partial.
- `templates/Customers/view.php` — detail with placeholder slots for future Orders/CxC.
- `templates/element/Customers/_form.php` — shared form partial.

### Files to modify
- `src/Controller/AppController.php` — extend `$controllerModuleMap`.
- `src/Service/AuthorizationService.php` — extend `MODULES`.
- `src/View/Helper/SidebarHelper.php` — add Customers entry.
- `config/routes.php` — register `/customers/toggle-active/{id}` before fallback.

---

## Task 1: Create customers migration and run it

**Files:**
- Create: `config/Migrations/20260503120000_CreateCustomers.php`

- [ ] **Step 1: Create the migration file**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateCustomers extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('customers')) {
            return;
        }

        $this->table('customers')
            ->addColumn('name', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('phone', 'string', ['limit' => 30, 'null' => false])
            ->addColumn('address', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('created', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('modified', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(['phone'], ['unique' => true, 'name' => 'uniq_customers_phone'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('customers')) {
            $this->table('customers')->drop()->update();
        }
    }
}
```

- [ ] **Step 2: Run the migration**

Run: `php bin/cake.php migrations migrate`
Expected: output includes `== 20260503120000 CreateCustomers: migrated`.

- [ ] **Step 3: Verify schema**

Run: `php bin/cake.php migrations status`
Expected: `up` next to `20260503120000`.

- [ ] **Step 4: Commit**

```bash
git add config/Migrations/20260503120000_CreateCustomers.php
git commit -m "feat(customers): add customers table migration"
```

---

## Task 2: Create CustomerConstants

**Files:**
- Create: `src/Constants/CustomerConstants.php`

- [ ] **Step 1: Create the constants class**

```php
<?php
declare(strict_types=1);

namespace App\Constants;

final class CustomerConstants
{
    public const NAME_MAX_LENGTH = 150;
    public const PHONE_MAX_LENGTH = 30;
    public const ADDRESS_MAX_LENGTH = 255;
}
```

- [ ] **Step 2: Verify autoloading**

Run: `php -r "require 'vendor/autoload.php'; var_dump(App\Constants\CustomerConstants::PHONE_MAX_LENGTH);"`
Expected: `int(30)`.

- [ ] **Step 3: Commit**

```bash
git add src/Constants/CustomerConstants.php
git commit -m "feat(customers): add CustomerConstants"
```

---

## Task 3: Create Customer entity

**Files:**
- Create: `src/Model/Entity/Customer.php`

- [ ] **Step 1: Create the entity**

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class Customer extends Entity
{
    protected array $_accessible = [
        'name' => true,
        'phone' => true,
        'address' => true,
        'is_active' => true,
    ];

    public function isActive(): bool
    {
        return (bool)$this->is_active;
    }

    protected function _getDisplayName(): string
    {
        $name = (string)($this->name ?? '');
        $phone = (string)($this->phone ?? '');
        if ($name === '' && $phone === '') {
            return '';
        }
        if ($phone === '') {
            return $name;
        }
        if ($name === '') {
            return $phone;
        }
        return $name . ' — ' . $phone;
    }
}
```

- [ ] **Step 2: Smoke check via tinker-style boot**

Run: `php -r "require 'vendor/autoload.php'; \$c = new App\Model\Entity\Customer(['name' => 'Ana', 'phone' => '300']); echo \$c->display_name, PHP_EOL;"`
Expected: `Ana — 300`.

- [ ] **Step 3: Commit**

```bash
git add src/Model/Entity/Customer.php
git commit -m "feat(customers): add Customer entity"
```

---

## Task 4: Create CustomersTable with validation and unique-phone rule

**Files:**
- Create: `src/Model/Table/CustomersTable.php`

- [ ] **Step 1: Create the table class**

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\CustomerConstants;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class CustomersTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('customers');
        $this->setPrimaryKey('id');
        $this->setDisplayField('name');
        $this->addBehavior('Timestamp');

        // Future associations declared when their tables exist:
        // $this->hasMany('Orders');
        // $this->hasMany('AccountsReceivable');
    }

    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->notEmptyString('name', 'El nombre es requerido')
            ->maxLength('name', CustomerConstants::NAME_MAX_LENGTH, 'El nombre puede tener hasta 150 caracteres')
            ->notEmptyString('phone', 'El teléfono es requerido')
            ->maxLength('phone', CustomerConstants::PHONE_MAX_LENGTH, 'El teléfono puede tener hasta 30 caracteres')
            ->scalar('phone')
            ->allowEmptyString('address')
            ->maxLength('address', CustomerConstants::ADDRESS_MAX_LENGTH, 'La dirección puede tener hasta 255 caracteres')
            ->boolean('is_active');
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->isUnique(
                ['phone'],
                ['message' => 'Ya existe un cliente con este teléfono']
            ),
            'uniquePhone'
        );
        return $rules;
    }

    public function findActive(SelectQuery $query): SelectQuery
    {
        return $query->where(['Customers.is_active' => true]);
    }

    public function findByPhone(SelectQuery $query, array $options = []): SelectQuery
    {
        $phone = (string)($options['phone'] ?? '');
        return $query->where(['Customers.phone' => $phone]);
    }
}
```

- [ ] **Step 2: Verify class loads**

Run: `php -r "require 'vendor/autoload.php'; class_exists('App\\Model\\Table\\CustomersTable') ? print('OK') : print('FAIL');"`
Expected: `OK`.

- [ ] **Step 3: Commit**

```bash
git add src/Model/Table/CustomersTable.php
git commit -m "feat(customers): add CustomersTable with validation and unique-phone rule"
```

---

## Task 5: Create CustomerService

**Files:**
- Create: `src/Service/CustomerService.php`

- [ ] **Step 1: Create the service**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Customer;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;

final class CustomerService
{
    use LocatorAwareTrait;

    /**
     * @return array{success: bool, customer?: Customer, errors?: array<string>}
     */
    public function create(array $data): array
    {
        $table = $this->fetchTable('Customers');
        $customer = $table->newEmptyEntity();
        $customer = $table->patchEntity($customer, $data);

        if (!$table->save($customer)) {
            return [
                'success' => false,
                'errors' => $this->flattenErrors($customer->getErrors()),
                'customer' => $customer,
            ];
        }

        Log::info('Customer created: id={id} phone={phone}', [
            'id' => $customer->id,
            'phone' => $customer->phone,
            'scope' => ['customers'],
        ]);

        return ['success' => true, 'customer' => $customer];
    }

    /**
     * @return array{success: bool, customer?: Customer, errors?: array<string>}
     */
    public function update(Customer $customer, array $data): array
    {
        $table = $this->fetchTable('Customers');
        $patched = $table->patchEntity($customer, $data);

        if (!$table->save($patched)) {
            return [
                'success' => false,
                'errors' => $this->flattenErrors($patched->getErrors()),
                'customer' => $patched,
            ];
        }

        return ['success' => true, 'customer' => $patched];
    }

    /**
     * @return array{success: bool, errors?: array<string>}
     */
    public function delete(Customer $customer): array
    {
        $deps = $this->countDependencies($customer);
        $msgs = [];
        if ($deps['orders'] > 0) {
            $msgs[] = "tiene {$deps['orders']} pedido(s)";
        }
        if ($deps['receivables'] > 0) {
            $msgs[] = "tiene {$deps['receivables']} cuenta(s) por cobrar";
        }
        if (!empty($msgs)) {
            return [
                'success' => false,
                'errors' => [
                    'No se puede eliminar el cliente: ' . implode(' y ', $msgs)
                        . '. Desactivalo en su lugar.',
                ],
            ];
        }

        $table = $this->fetchTable('Customers');
        if (!$table->delete($customer)) {
            return ['success' => false, 'errors' => ['No se pudo eliminar el cliente.']];
        }

        Log::info('Customer deleted: id={id} phone={phone}', [
            'id' => $customer->id,
            'phone' => $customer->phone,
            'scope' => ['customers'],
        ]);

        return ['success' => true];
    }

    /**
     * @return array{success: bool, customer?: Customer, errors?: array<string>}
     */
    public function toggleActive(Customer $customer): array
    {
        $customer->is_active = !$customer->is_active;
        $table = $this->fetchTable('Customers');
        if (!$table->save($customer)) {
            return [
                'success' => false,
                'errors' => $this->flattenErrors($customer->getErrors()),
                'customer' => $customer,
            ];
        }
        return ['success' => true, 'customer' => $customer];
    }

    /**
     * Looks up a customer by phone; creates one if absent. Used by the future
     * Orders flow when payment method is Crédito and the entered phone is unknown.
     *
     * @param array{phone: string, name?: string, address?: ?string} $data
     */
    public function findOrCreateByPhone(array $data): Customer
    {
        $phone = (string)($data['phone'] ?? '');
        if ($phone === '') {
            throw new \InvalidArgumentException('phone is required');
        }

        $table = $this->fetchTable('Customers');
        $existing = $table->find('byPhone', ['phone' => $phone])->first();
        if ($existing instanceof Customer) {
            return $existing;
        }

        $customer = $table->newEntity([
            'name' => (string)($data['name'] ?? ''),
            'phone' => $phone,
            'address' => $data['address'] ?? null,
            'is_active' => true,
        ]);

        if (!$table->save($customer)) {
            throw new \RuntimeException(
                'Could not auto-create customer for phone ' . $phone
                    . ': ' . json_encode($customer->getErrors(), JSON_UNESCAPED_UNICODE)
            );
        }

        Log::info('Customer auto-created via findOrCreateByPhone: id={id} phone={phone}', [
            'id' => $customer->id,
            'phone' => $customer->phone,
            'scope' => ['customers'],
        ]);

        return $customer;
    }

    /**
     * Counts dependent records in tables that may not yet exist (Phase 1).
     * Returns 0 for any table not present in the schema.
     *
     * @return array{orders: int, receivables: int}
     */
    private function countDependencies(Customer $customer): array
    {
        $connection = ConnectionManager::get('default');
        $existing = $connection->getSchemaCollection()->listTables();

        $orders = 0;
        if (in_array('orders', $existing, true)) {
            try {
                $orders = (int)$connection
                    ->execute('SELECT COUNT(*) AS c FROM orders WHERE customer_id = :id', ['id' => $customer->id])
                    ->fetch('assoc')['c'];
            } catch (\Throwable) {
                $orders = 0;
            }
        }

        $receivables = 0;
        if (in_array('accounts_receivable', $existing, true)) {
            try {
                $receivables = (int)$connection
                    ->execute('SELECT COUNT(*) AS c FROM accounts_receivable WHERE customer_id = :id', ['id' => $customer->id])
                    ->fetch('assoc')['c'];
            } catch (\Throwable) {
                $receivables = 0;
            }
        }

        return ['orders' => $orders, 'receivables' => $receivables];
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

- [ ] **Step 2: Verify class loads**

Run: `php -r "require 'vendor/autoload.php'; class_exists('App\\Service\\CustomerService') ? print('OK') : print('FAIL');"`
Expected: `OK`.

- [ ] **Step 3: Commit**

```bash
git add src/Service/CustomerService.php
git commit -m "feat(customers): add CustomerService with delete-guard and findOrCreateByPhone"
```

---

## Task 6: Create CustomersController skeleton with index and view

**Files:**
- Create: `src/Controller/CustomersController.php`

- [ ] **Step 1: Create the controller**

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\CustomerService;
use Cake\ORM\Query\SelectQuery;

class CustomersController extends AppController
{
    public array $paginate = [
        'limit' => 15,
        'maxLimit' => 15,
        'order' => ['Customers.name' => 'ASC'],
        'sortableFields' => ['name', 'phone', 'created'],
    ];

    private CustomerService $customerService;

    public function initialize(): void
    {
        parent::initialize();
        $this->customerService = new CustomerService();
    }

    public function index(): void
    {
        $filters = $this->_currentFilters();
        $query = $this->_buildIndexQuery($filters);
        $customers = $this->paginate($query);

        $this->set(compact('customers', 'filters'));
        $this->set('breadcrumbs', [['label' => 'Clientes']]);
    }

    public function view(int $id): void
    {
        $customer = $this->Customers->get($id);
        $this->set('customer', $customer);
        $this->set('breadcrumbs', [
            ['label' => 'Clientes', 'url' => ['action' => 'index']],
            ['label' => $customer->name],
        ]);
    }

    /**
     * @return array{q: string, status: string, sort: string, direction: string}
     */
    protected function _currentFilters(): array
    {
        $allowedSort = ['name', 'phone', 'created'];
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
        $query = $this->Customers->find();

        if ($filters['q'] !== '') {
            $like = '%' . $filters['q'] . '%';
            $query->where(['OR' => [
                'Customers.name LIKE' => $like,
                'Customers.phone LIKE' => $like,
            ]]);
        }

        if ($filters['status'] === 'active') {
            $query->where(['Customers.is_active' => true]);
        } elseif ($filters['status'] === 'inactive') {
            $query->where(['Customers.is_active' => false]);
        }

        $query->orderBy(['Customers.' . $filters['sort'] => strtoupper($filters['direction'])]);

        return $query;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Controller/CustomersController.php
git commit -m "feat(customers): add CustomersController skeleton with index/view and filters"
```

---

## Task 7: Implement `add` action

**Files:**
- Modify: `src/Controller/CustomersController.php`

- [ ] **Step 1: Add the `add` method after `view`**

Insert this method into `CustomersController` directly below the `view` action:

```php
    public function add()
    {
        $customer = $this->Customers->newEmptyEntity();

        if ($this->request->is('post')) {
            $data = $this->request->getData();
            $data['is_active'] = !empty($data['is_active']);

            $result = $this->customerService->create($data);
            if ($result['success']) {
                $this->Flash->success('Cliente creado.');
                return $this->redirect(['action' => 'index']);
            }
            foreach ($result['errors'] ?? ['No se pudo crear el cliente.'] as $msg) {
                $this->Flash->error($msg);
            }
            $customer = $result['customer'] ?? $customer;
        }

        $this->set('customer', $customer);
        $this->set('breadcrumbs', [
            ['label' => 'Clientes', 'url' => ['action' => 'index']],
            ['label' => 'Nuevo cliente'],
        ]);
        return null;
    }
```

- [ ] **Step 2: Commit**

```bash
git add src/Controller/CustomersController.php
git commit -m "feat(customers): implement add action"
```

---

## Task 8: Implement `edit` action

**Files:**
- Modify: `src/Controller/CustomersController.php`

- [ ] **Step 1: Add the `edit` method after `add`**

Insert below the `add` action:

```php
    public function edit(int $id)
    {
        $customer = $this->Customers->get($id);

        if ($this->request->is(['put', 'post', 'patch'])) {
            $data = $this->request->getData();
            $data['is_active'] = !empty($data['is_active']);

            $result = $this->customerService->update($customer, $data);
            if ($result['success']) {
                $this->Flash->success('Cliente actualizado.');
                return $this->redirect(['action' => 'index']);
            }
            foreach ($result['errors'] ?? ['No se pudo actualizar el cliente.'] as $msg) {
                $this->Flash->error($msg);
            }
            $customer = $result['customer'] ?? $customer;
        }

        $this->set('customer', $customer);
        $this->set('breadcrumbs', [
            ['label' => 'Clientes', 'url' => ['action' => 'index']],
            ['label' => $customer->name, 'url' => ['action' => 'view', $customer->id]],
            ['label' => 'Editar'],
        ]);
        return null;
    }
```

- [ ] **Step 2: Commit**

```bash
git add src/Controller/CustomersController.php
git commit -m "feat(customers): implement edit action"
```

---

## Task 9: Implement `delete`, `toggleActive`, custom route, and permission mapping

**Files:**
- Modify: `src/Controller/CustomersController.php`
- Modify: `config/routes.php`

- [ ] **Step 1: Add `delete`, `toggleActive`, and `_actionToPermission` to the controller**

Append these methods to `CustomersController` after `edit`:

```php
    public function delete(int $id)
    {
        $this->request->allowMethod(['post', 'delete']);

        try {
            $customer = $this->Customers->get($id);
        } catch (\Cake\Datasource\Exception\RecordNotFoundException) {
            $this->Flash->error('El cliente ya no existe.');
            return $this->redirect(['action' => 'index']);
        }

        $result = $this->customerService->delete($customer);
        if ($result['success']) {
            $this->Flash->success('Cliente eliminado.');
        } else {
            $this->Flash->error($result['errors'][0] ?? 'No se pudo eliminar el cliente.');
        }
        return $this->redirect(['action' => 'index']);
    }

    public function toggleActive(int $id)
    {
        $this->request->allowMethod(['post']);

        try {
            $customer = $this->Customers->get($id);
        } catch (\Cake\Datasource\Exception\RecordNotFoundException) {
            $this->Flash->error('El cliente ya no existe.');
            return $this->redirect(['action' => 'index']);
        }

        $result = $this->customerService->toggleActive($customer);
        if ($result['success']) {
            $msg = $result['customer']->is_active ? 'Cliente activado.' : 'Cliente desactivado.';
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

- [ ] **Step 2: Register the custom route in `config/routes.php`**

Open `config/routes.php`. Locate the existing `connect()` call for `/products/toggle-active/{id}` (currently around line 25) and insert this block immediately after it, **before** `$builder->fallbacks();`:

```php
        // Acción custom: alternar disponibilidad de un cliente.
        $builder->connect(
            '/customers/toggle-active/{id}',
            ['controller' => 'Customers', 'action' => 'toggleActive'],
            ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST']
        );
```

- [ ] **Step 3: Commit**

```bash
git add src/Controller/CustomersController.php config/routes.php
git commit -m "feat(customers): implement delete + toggleActive with custom route"
```

---

## Task 10: Wire RBAC (controller map, modules catalog, sidebar)

**Files:**
- Modify: `src/Controller/AppController.php`
- Modify: `src/Service/AuthorizationService.php`
- Modify: `src/View/Helper/SidebarHelper.php`

- [ ] **Step 1: Extend `$controllerModuleMap` in `AppController`**

In `src/Controller/AppController.php`, modify the `$controllerModuleMap` property to add the `Customers` entry:

```php
    protected array $controllerModuleMap = [
        'Roles' => 'roles',
        'Users' => 'users',
        'Products' => 'products',
        'Customers' => 'customers',
    ];
```

- [ ] **Step 2: Extend `MODULES` in `AuthorizationService`**

In `src/Service/AuthorizationService.php`, modify the `MODULES` constant to add the `customers` entry:

```php
    public const MODULES = [
        'roles' => 'Roles',
        'users' => 'Usuarios',
        'products' => 'Productos',
        'customers' => 'Clientes',
    ];
```

- [ ] **Step 3: Add the Customers item to `SidebarHelper`**

In `src/View/Helper/SidebarHelper.php`, modify the `$items` property to insert the Customers entry **after** Products and **before** Users:

```php
    private array $items = [
        [
            'module' => 'products',
            'label' => 'Productos',
            'icon' => 'bi-box-seam',
            'url' => ['controller' => 'Products', 'action' => 'index'],
        ],
        [
            'module' => 'customers',
            'label' => 'Clientes',
            'icon' => 'bi-people',
            'url' => ['controller' => 'Customers', 'action' => 'index'],
        ],
        [
            'module' => 'users',
            'label' => 'Usuarios',
            'icon' => 'bi-person-badge',
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

Note: the Users icon is changed from `bi-people` to `bi-person-badge` to free `bi-people` for Customers (see the original `SidebarHelper`).

- [ ] **Step 4: Commit**

```bash
git add src/Controller/AppController.php src/Service/AuthorizationService.php src/View/Helper/SidebarHelper.php
git commit -m "feat(customers): wire RBAC and sidebar entry"
```

---

## Task 11: Seed permissions for existing roles

**Files:**
- Create: `config/Migrations/20260503120100_SeedCustomersPermissions.php`

- [ ] **Step 1: Create the seed migration**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class SeedCustomersPermissions extends BaseMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // Default policy for non-admin roles: view + create + edit, no delete.
        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'customers', 1, 1, 1, 0, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 0
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'customers'
               )"
        );

        // Administrador (matrix-display row; bypass is functionally redundant).
        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'customers', 1, 1, 1, 1, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 1
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'customers'
               )"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'customers'");
    }
}
```

- [ ] **Step 2: Run the migration**

Run: `php bin/cake.php migrations migrate`
Expected: output includes `== 20260503120100 SeedCustomersPermissions: migrated`.

- [ ] **Step 3: Commit**

```bash
git add config/Migrations/20260503120100_SeedCustomersPermissions.php
git commit -m "feat(customers): seed default customers permissions for existing roles"
```

---

## Task 12: Create form partial and add/edit templates

**Files:**
- Create: `templates/element/Customers/_form.php`
- Create: `templates/Customers/add.php`
- Create: `templates/Customers/edit.php`

- [ ] **Step 1: Create the form partial**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Customer $customer
 * @var bool $isEdit
 */
?>
<?= $this->Form->create($customer, ['type' => 'post']) ?>
<div class="card">
    <div class="card-body">
        <div class="mb-3">
            <label class="form-label" for="customer-name">Nombre <span class="text-danger">*</span></label>
            <?= $this->Form->control('name', [
                'type' => 'text',
                'label' => false,
                'class' => 'form-control' . ($customer->getError('name') ? ' is-invalid' : ''),
                'id' => 'customer-name',
                'maxlength' => 150,
                'required' => true,
            ]) ?>
            <?php if ($customer->getError('name')): ?>
                <div class="invalid-feedback d-block"><?= h(implode(' ', $customer->getError('name'))) ?></div>
            <?php endif; ?>
        </div>

        <div class="mb-3">
            <label class="form-label" for="customer-phone">Teléfono <span class="text-danger">*</span></label>
            <?= $this->Form->control('phone', [
                'type' => 'text',
                'label' => false,
                'class' => 'form-control' . ($customer->getError('phone') ? ' is-invalid' : ''),
                'id' => 'customer-phone',
                'maxlength' => 30,
                'required' => true,
            ]) ?>
            <div class="form-text">Único. Se usará para identificar al cliente al cobrar a crédito.</div>
            <?php if ($customer->getError('phone')): ?>
                <div class="invalid-feedback d-block"><?= h(implode(' ', $customer->getError('phone'))) ?></div>
            <?php endif; ?>
        </div>

        <div class="mb-3">
            <label class="form-label" for="customer-address">Dirección</label>
            <?= $this->Form->control('address', [
                'type' => 'textarea',
                'label' => false,
                'rows' => 2,
                'class' => 'form-control' . ($customer->getError('address') ? ' is-invalid' : ''),
                'id' => 'customer-address',
                'maxlength' => 255,
            ]) ?>
            <?php if ($customer->getError('address')): ?>
                <div class="invalid-feedback d-block"><?= h(implode(' ', $customer->getError('address'))) ?></div>
            <?php endif; ?>
        </div>

        <?php if ($isEdit): ?>
            <div class="form-check mb-3">
                <?= $this->Form->checkbox('is_active', [
                    'class' => 'form-check-input',
                    'id' => 'customer-active',
                    'checked' => (bool)$customer->is_active,
                ]) ?>
                <label class="form-check-label" for="customer-active">Cliente activo</label>
            </div>
        <?php else: ?>
            <?= $this->Form->hidden('is_active', ['value' => '1']) ?>
        <?php endif; ?>
    </div>
    <div class="card-footer d-flex justify-content-between">
        <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
        <button type="submit" class="btn btn-primary">Guardar</button>
    </div>
</div>
<?= $this->Form->end() ?>
```

- [ ] **Step 2: Create `templates/Customers/add.php`**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Customer $customer
 */
$this->assign('title', 'Nuevo cliente');
$isEdit = false;
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Nuevo cliente</h1>
</div>
<?= $this->element('Customers/_form', ['customer' => $customer, 'isEdit' => $isEdit]) ?>
```

- [ ] **Step 3: Create `templates/Customers/edit.php`**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Customer $customer
 */
$this->assign('title', 'Editar cliente');
$isEdit = true;
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Editar cliente</h1>
</div>
<?= $this->element('Customers/_form', ['customer' => $customer, 'isEdit' => $isEdit]) ?>
```

- [ ] **Step 4: Commit**

```bash
git add templates/element/Customers/_form.php templates/Customers/add.php templates/Customers/edit.php
git commit -m "feat(customers): add form partial with add/edit templates"
```

---

## Task 13: Create index template

**Files:**
- Create: `templates/Customers/index.php`

- [ ] **Step 1: Create the index view**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \Cake\ORM\ResultSet|\App\Model\Entity\Customer[] $customers
 * @var array{q:string,status:string,sort:string,direction:string} $filters
 */
$this->assign('title', 'Clientes');
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Clientes</h1>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg"></i> Nuevo cliente',
        ['action' => 'add'],
        ['escape' => false, 'class' => 'btn btn-primary']
    ) ?>
</div>

<form method="get" class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <i class="bi bi-search text-muted"></i>
            <input type="text" name="q" class="form-control form-control-sm" style="max-width: 320px;"
                   value="<?= h($filters['q']) ?>" placeholder="Buscar por nombre o teléfono">
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
                    <th><?= $this->Paginator->sort('name', 'Nombre') ?></th>
                    <th><?= $this->Paginator->sort('phone', 'Teléfono') ?></th>
                    <th>Dirección</th>
                    <th class="text-center" style="width:140px;">Activo</th>
                    <th class="text-end" style="width:160px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $customer): ?>
                    <tr<?= $customer->is_active ? '' : ' class="text-muted" style="opacity:.7;"' ?>>
                        <td><?= h($customer->name) ?></td>
                        <td class="font-monospace"><?= h($customer->phone) ?></td>
                        <td>
                            <?php if (!empty($customer->address)): ?>
                                <span title="<?= h($customer->address) ?>" class="d-inline-block text-truncate" style="max-width: 280px;">
                                    <?= h($customer->address) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?= $this->Form->postLink(
                                $customer->is_active ? 'Sí' : 'No',
                                ['action' => 'toggleActive', $customer->id],
                                [
                                    'class' => 'btn btn-sm ' . ($customer->is_active ? 'btn-success' : 'btn-light'),
                                    'title' => 'Cambiar estado',
                                ]
                            ) ?>
                        </td>
                        <td class="text-end">
                            <?= $this->Html->link(
                                '<i class="bi bi-eye"></i>',
                                ['action' => 'view', $customer->id],
                                ['escape' => false, 'class' => 'btn btn-sm btn-light', 'title' => 'Ver']
                            ) ?>
                            <?= $this->Html->link(
                                '<i class="bi bi-pencil"></i>',
                                ['action' => 'edit', $customer->id],
                                ['escape' => false, 'class' => 'btn btn-sm btn-light', 'title' => 'Editar']
                            ) ?>
                            <?= $this->Form->postLink(
                                '<i class="bi bi-trash"></i>',
                                ['action' => 'delete', $customer->id],
                                [
                                    'escape' => false,
                                    'class' => 'btn btn-sm btn-light text-danger',
                                    'title' => 'Eliminar',
                                    'confirm' => '¿Eliminar el cliente "' . h($customer->name) . '"?',
                                ]
                            ) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($customers) === 0): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No hay clientes para mostrar.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->element('pagination') ?>
```

- [ ] **Step 2: Commit**

```bash
git add templates/Customers/index.php
git commit -m "feat(customers): add index template with filters and inline toggle"
```

---

## Task 14: Create view template

**Files:**
- Create: `templates/Customers/view.php`

- [ ] **Step 1: Create the view**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Customer $customer
 */
$this->assign('title', $customer->name);
?>
<div class="dr-page-header">
    <h1 class="dr-page-title"><?= h($customer->name) ?></h1>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-pencil"></i> Editar',
            ['action' => 'edit', $customer->id],
            ['escape' => false, 'class' => 'btn btn-secondary']
        ) ?>
        <?= $this->Form->postLink(
            '<i class="bi bi-trash"></i> Eliminar',
            ['action' => 'delete', $customer->id],
            [
                'escape' => false,
                'class' => 'btn btn-danger',
                'confirm' => '¿Eliminar el cliente "' . h($customer->name) . '"?',
            ]
        ) ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header">Datos del cliente</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted">Nombre</dt>
                    <dd class="col-sm-8"><?= h($customer->name) ?></dd>

                    <dt class="col-sm-4 text-muted">Teléfono</dt>
                    <dd class="col-sm-8 font-monospace"><?= h($customer->phone) ?></dd>

                    <dt class="col-sm-4 text-muted">Dirección</dt>
                    <dd class="col-sm-8">
                        <?= !empty($customer->address) ? h($customer->address) : '<span class="text-muted">—</span>' ?>
                    </dd>

                    <dt class="col-sm-4 text-muted">Estado</dt>
                    <dd class="col-sm-8">
                        <?php if ($customer->is_active): ?>
                            <span class="badge bg-success-subtle text-success-emphasis">Activo</span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary-emphasis">Inactivo</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-4 text-muted">Registrado</dt>
                    <dd class="col-sm-8"><?= $customer->created ? h($customer->created->i18nFormat('dd/MM/yyyy HH:mm')) : '—' ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-7">
        <div class="card mb-3">
            <div class="card-header">Pedidos del cliente</div>
            <div class="card-body text-muted">
                Disponible cuando se habilite el módulo de Pedidos.
            </div>
        </div>
        <div class="card">
            <div class="card-header">Cuenta por cobrar</div>
            <div class="card-body text-muted">
                Disponible cuando se habilite el módulo de Cuentas por Cobrar.
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 2: Commit**

```bash
git add templates/Customers/view.php
git commit -m "feat(customers): add view template with placeholders for orders and CxC"
```

---

## Task 15: Manual smoke test of full module

**Files:** none (verification only).

- [ ] **Step 1: Boot the dev server**

Run: `php bin/cake.php server -p 8765`
Leave it running while performing the following checks in a browser at `http://localhost:8765`.

- [ ] **Step 2: Log in as Administrador**

Use the seeded administrator credentials. Confirm that the sidebar now shows "Clientes" between Productos and Usuarios.

- [ ] **Step 3: Index empty state**

Navigate to `/customers`. Expected: page renders with title "Clientes", empty-state row "No hay clientes para mostrar.", filter bar visible, primary "Nuevo cliente" button.

- [ ] **Step 4: Create three customers**

Click "Nuevo cliente". Create:
1. `Ana Pérez` / `3001112233` / `Cra 10 #5-20`
2. `Juan Gómez` / `3014445566` / (empty address)
3. `María López` / `3007778899` / `Calle 50 #12-30, casa esquinera`

Expected after each: redirect to `/customers` with success Flash, row visible.

- [ ] **Step 5: Phone uniqueness**

Try creating a customer with phone `3001112233` (already in use). Expected: form re-renders with error "Ya existe un cliente con este teléfono".

- [ ] **Step 6: Filters**

- Search `Ana` → only Ana visible.
- Search `300` → all three.
- Status `Inactivos` → empty.
- Sort by Teléfono ascending → 3001112233, 3007778899, 3014445566.

- [ ] **Step 7: Toggle active**

Click the "Sí" button on Juan's row. Expected: button flips to "No", row dims, Flash "Cliente desactivado." Filter `Inactivos` shows Juan only.

- [ ] **Step 8: Edit**

Edit Ana's address to `Cra 10 #5-25`. Expected: redirect to index with success Flash, view page shows the new address.

- [ ] **Step 9: View placeholders**

Open Ana's detail page (`/customers/view/{id}`). Expected: left card with all data, two right-side cards showing the "Disponible cuando se habilite..." messages.

- [ ] **Step 10: Delete (no dependencies)**

Delete María. Confirm dialog. Expected: success Flash "Cliente eliminado.", row gone.

- [ ] **Step 11: RBAC check (non-admin role)**

Log out, log in with a non-admin seeded user (any role created in earlier phases that has no Customers permissions yet should NOT be able to see the sidebar entry). For roles that received the seed default (view + create + edit), confirm:
- Sidebar entry visible.
- Index loads.
- Edit allowed; delete forbidden (button still appears but POST receives 403). To verify, manually POST to `/customers/delete/{id}` and expect a `ForbiddenException` rendered as the project's 403 page.

- [ ] **Step 12: Stop the server and commit any incidental fixes**

If anything required a code fix during the smoke test, commit it now with `fix(customers): ...`. Otherwise, no commit needed.

---

## Done

The Customers module is complete and operational. The `findOrCreateByPhone` method on `CustomerService` is ready to be consumed when the Pedidos module is implemented.
