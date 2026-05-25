# Plan de implementación — Módulo Ajustes de Inventario

> Plan ordenado, paso a paso, para implementar el módulo **Ajustes de Inventario**
> según el diseño aprobado en `.claude/designs/03-ajustes-inventario.md`.
>
> Referencias obligatorias antes de implementar:
> - `.claude/designs/03-ajustes-inventario.md` (spec definitivo)
> - `.claude/plans/01-ingredientes.md` y `02-recetas.md` (formato/estilo)
> - `.claude/rules/ARQUITECTURE.md` §3 (receta de módulo) y §4 (patrones)
> - `.claude/rules/DESIGN.md` (sistema visual)
> - Módulos predecesores: **Ingredientes** y **Recetas** (clonar estructura).
> - `src/Service/IngredientService.php` — contrato de `adjustStock(Ingredient, string $deltaSigned, string $reason)`.
> - `src/Controller/AppController.php` — `$controllerModuleMap` (línea 19),
>   `$actionModuleMap` (línea 47), `_actionToPermission()` (línea 113).
> - `src/Service/AuthorizationService.php` — array `MODULES` (línea 17).
> - `src/View/Helper/SidebarHelper.php` — array `$items` (línea 21).

---

## 1. File manifest

Orden en el que tocar los archivos. Cada línea = archivo + cambio puntual.
Timestamps de migración: **posteriores** a `20260524130100_SeedRecipesPermissions`
para mantener orden cronológico.

### Migraciones

1. `[CREATE] config/Migrations/20260524140000_CreateInventoryAdjustments.php`
   — schema `inventory_adjustments` (design §1.1 + §5.4).
2. `[CREATE] config/Migrations/20260524140100_SeedAdjustmentsPermissions.php`
   — fila `permissions` por rol para módulo `adjustments`, sin `can_edit`
   (design §5.3).

### Constantes y modelo

3. `[CREATE] src/Constants/InventoryAdjustmentConstants.php` — `TYPE_ENTRY`,
   `TYPE_BAJA`, `TYPES`, `TYPE_LABELS`, `REASON_SUGGESTIONS`, `REASON_MAX_LENGTH`
   (design §2).
4. `[CREATE] src/Model/Entity/InventoryAdjustment.php` — `$_accessible`,
   `$_virtual`, `isEntry()`, `isBaja()`, `getSignedDelta()`, `getReverseDelta()`,
   `getFormattedQuantity()`, virtuals `_getSignedDelta()`, `_getFormattedQuantity()`
   (design §1.2).
5. `[CREATE] src/Model/Table/InventoryAdjustmentsTable.php` — `initialize()`
   con `setTable('inventory_adjustments')` y `Timestamp` solo para `created`,
   asociaciones `belongsTo Ingredients` (INNER) y `belongsTo Users` (LEFT),
   `validationDefault()`, `buildRules()`, finders `findChronological`,
   `findByIngredient`, `findByType`, `findInDateRange` (design §1.3).

### Servicio

6. `[CREATE] src/Service/InventoryAdjustmentService.php` — `create`, `delete`
   (sin `update`), helpers privados `flattenErrors`, `validateInput`.
   Constructor con `IngredientService` inyectable (design §3).

### Controller

7. `[CREATE] src/Controller/AdjustmentsController.php` — `index`, `add`,
   `delete` (no `view`, no `edit`). Carga manual de `InventoryAdjustmentsTable`
   vía `fetchTable('InventoryAdjustments')` porque el nombre del controller
   no inflexiona al nombre de la tabla (design §4 + Risks).

### RBAC y navegación

8. `[MODIFY] src/Controller/AppController.php` — agregar
   `'Adjustments' => 'adjustments'` al array `$controllerModuleMap`
   (después de `'Recipes' => 'recipes'`, línea ~26).
9. `[MODIFY] src/Service/AuthorizationService.php` — agregar
   `'adjustments' => 'Ajustes de Inventario'` al array `MODULES` (después de
   `'recipes'`, línea ~23).
10. `[MODIFY] src/View/Helper/SidebarHelper.php` — insertar item `adjustments`
    en `$items` después del item `recipes` (icono `bi-arrow-left-right`).

### Templates

11. `[CREATE] templates/Adjustments/index.php` — listado cronológico con
    filtros (ingrediente, tipo, rango fechas) y tabla (design §6.1).
12. `[CREATE] templates/Adjustments/add.php` — form de alta en dos columnas,
    radios "Entrada/Baja", select de ingredientes con datalist de motivos
    (design §6.2). **No** existe `edit.php` ni `view.php` (append-only).

### Tests (REQUERIDOS — design §8 dice "no", pero el prompt SOBRESCRIBE)

13. `[CREATE] tests/Fixture/InventoryAdjustmentsFixture.php` — 2 filas:
    una `entrada` y una `baja` sobre el mismo ingrediente.
14. `[CREATE] tests/TestCase/Model/Entity/InventoryAdjustmentTest.php`.
15. `[CREATE] tests/TestCase/Model/Table/InventoryAdjustmentsTableTest.php`.
16. `[CREATE] tests/TestCase/Service/InventoryAdjustmentServiceTest.php`.
17. `[CREATE] tests/TestCase/Controller/AdjustmentsControllerTest.php`.

### Cierre

18. `[RUN] php bin/cake.php migrations migrate`.
19. `[RUN] php bin/cake.php migrations dump`.
20. `[RUN] composer cs-check` (limpio sobre los nuevos archivos).
21. `[RUN] php -l` sobre cada archivo PHP nuevo.
22. `[RUN] php bin/cake.php routes | grep -i adjust` para validar rutas
    autoresolvidas por fallback.

---

## 2. Step-by-step execution

### Paso 1 — Migración `CreateInventoryAdjustments`

**Archivo:** `config/Migrations/20260524140000_CreateInventoryAdjustments.php`

Migración con `Migrations\BaseMigration` (NO `AbstractMigration`). Proteger
con `if ($this->hasTable('inventory_adjustments')) { return; }`. Tabla con
`'signed' => false` (PK unsigned, consistente con `ingredients.id` y `users.id`,
ambas unsigned — verificado en `20260502120200_CreateUsers.php` línea 16 y
`20260524120000_CreateIngredients.php` línea 13).

Columnas:
```
ingredient_id  integer        signed=false, null=false
type           varchar(10)    null=false
quantity       decimal(12,3)  null=false
reason         varchar(120)   null=false
notes          text           nullable
user_id        integer        signed=false, nullable
created        datetime       null=false     // NO modified
```

Índices:
- `idx_ia_ingredient_created` (`ingredient_id`, `created`).
- `idx_ia_created_desc` (`created`).
- `idx_ia_type` (`type`).
- `idx_ia_user_id` (`user_id`).

Foreign keys:
- `addForeignKey('ingredient_id', 'ingredients', 'id',
   ['delete' => 'CASCADE', 'update' => 'RESTRICT', 'constraint' => 'fk_ia_ingredient'])`.
- `addForeignKey('user_id', 'users', 'id',
   ['delete' => 'SET_NULL', 'update' => 'RESTRICT', 'constraint' => 'fk_ia_user'])`.

`down()`: `if ($this->hasTable('inventory_adjustments')) { $this->table('inventory_adjustments')->drop()->update(); }`.

**Acceptance:** `php bin/cake.php migrations migrate` corre sin errores y
`SHOW CREATE TABLE inventory_adjustments` muestra:
- PK `id` `int(10) unsigned`.
- FK `ingredient_id` → `ingredients(id)` ON DELETE CASCADE.
- FK `user_id` → `users(id)` ON DELETE SET NULL.
- Ningún índice/columna `modified` (única tabla del proyecto sin esa columna).

---

### Paso 2 — Migración `SeedAdjustmentsPermissions`

**Archivo:** `config/Migrations/20260524140100_SeedAdjustmentsPermissions.php`

Calcado de `SeedRecipesPermissions`. Dos `INSERT ... SELECT ... WHERE NOT
EXISTS` con `module = 'adjustments'`. **Diferencia respecto a Recetas:**
`can_edit = 0` para no-admin (append-only, design §5.3).

```sql
-- Roles no-admin: view + create + delete. SIN edit (módulo append-only).
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
SELECT r.id, 'adjustments', 1, 1, 0, 0, '{$now}', '{$now}'
FROM roles r
WHERE r.is_admin = 0
  AND NOT EXISTS (SELECT 1 FROM permissions p WHERE p.role_id = r.id AND p.module = 'adjustments');

-- Administrador: matriz completa (bypass cubre igual, can_edit=1 por consistencia).
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
SELECT r.id, 'adjustments', 1, 1, 1, 1, '{$now}', '{$now}'
FROM roles r
WHERE r.is_admin = 1
  AND NOT EXISTS (SELECT 1 FROM permissions p WHERE p.role_id = r.id AND p.module = 'adjustments');
```

`down()`: `$this->execute("DELETE FROM permissions WHERE module = 'adjustments'");`

**Acceptance:** tras `migrations migrate`, `SELECT DISTINCT module FROM
permissions` incluye `adjustments`. Para roles no-admin, `can_delete = 0` por
default (criterio conservador — borrar un ajuste mueve stock).

---

### Paso 3 — Constantes `InventoryAdjustmentConstants`

**Archivo:** `src/Constants/InventoryAdjustmentConstants.php`

Clase `final` en `App\Constants`. Definir exactamente:

```php
public const TYPE_ENTRY = 'entrada';
public const TYPE_BAJA  = 'baja';

/** @var list<string> */
public const TYPES = [self::TYPE_ENTRY, self::TYPE_BAJA];

/** @var array<string, string> */
public const TYPE_LABELS = [
    self::TYPE_ENTRY => 'Entrada',
    self::TYPE_BAJA  => 'Baja',
];

/** @var list<string> */
public const REASON_SUGGESTIONS = [
    'Compra a proveedor',
    'Merma',
    'Daño',
    'Conteo físico',
    'Devolución',
    'Robo',
];

public const REASON_MAX_LENGTH = 120;
```

Valores literales en español (terminología del negocio visible en UI), mismo
criterio que `OrderConstants::STATUS_CANCELLED`. Sugerencias en formato display
(no slugs) porque se renderizan tal cual en `<datalist>`.

**Acceptance:** `composer cs-check` limpio sobre el archivo.

---

### Paso 4 — Entity `InventoryAdjustment`

**Archivo:** `src/Model/Entity/InventoryAdjustment.php`

Extiende `Cake\ORM\Entity`. `$_accessible` con whitelist:
`ingredient_id`, `type`, `quantity`, `reason`, `notes`, `user_id`,
`ingredient`, `user`. **NO** incluir `created` (asignado por behavior, no por
form).

`$_virtual = ['signed_delta', 'formatted_quantity']`.

Imports:
- `App\Constants\IngredientConstants` (para `STOCK_DECIMALS`).
- `App\Constants\InventoryAdjustmentConstants`.

Métodos:

- `isEntry(): bool` → `$this->type === InventoryAdjustmentConstants::TYPE_ENTRY`.
- `isBaja(): bool` → `$this->type === InventoryAdjustmentConstants::TYPE_BAJA`.
- `getSignedDelta(): string`:
  ```php
  $sign = $this->isEntry() ? '+' : '-';
  return $sign . number_format(
      (float)$this->quantity,
      IngredientConstants::STOCK_DECIMALS,
      '.',
      '',
  );
  ```
- `getReverseDelta(): string` — idéntico pero `$sign` invertido (entrada → `-`,
  baja → `+`).
- `getFormattedQuantity(): string`:
  ```php
  $unit = $this->ingredient?->unit ?? '';
  return trim($this->getSignedDelta() . ' ' . $unit);
  ```
- `_getSignedDelta(): string` → delega a `getSignedDelta()`.
- `_getFormattedQuantity(): string` → delega a `getFormattedQuantity()`.

**Acceptance:** instancia manual con `new InventoryAdjustment(['type' => 'entrada', 'quantity' => '2.500'])`:
- `isEntry()` → `true`.
- `getSignedDelta()` → `'+2.500'`.
- `getReverseDelta()` → `'-2.500'`.

---

### Paso 5 — Table `InventoryAdjustmentsTable`

**Archivo:** `src/Model/Table/InventoryAdjustmentsTable.php`

Extiende `Cake\ORM\Table`. `initialize()`:

```php
parent::initialize($config);
$this->setTable('inventory_adjustments');
$this->setPrimaryKey('id');
$this->setDisplayField('reason');

// Timestamp behavior solo para 'created' — NO existe columna 'modified'.
$this->addBehavior('Timestamp', [
    'events' => [
        'Model.beforeSave' => ['created' => 'new'],
    ],
]);

$this->belongsTo('Ingredients', [
    'foreignKey' => 'ingredient_id',
    'joinType' => 'INNER',
]);
$this->belongsTo('Users', [
    'foreignKey' => 'user_id',
    'joinType' => 'LEFT', // user_id puede ser null (ON DELETE SET NULL).
]);
```

> **Crítico (Risks §3):** el bloque `Timestamp` debe declarar solo `created`,
> sin `modified`. Si se omite la config y se deja `addBehavior('Timestamp')`
> default, el behavior intentará escribir `modified` en cada save y fallará
> con error de columna inexistente.

`validationDefault(Validator $v)`:

```php
return $v
    ->notEmptyString('type', 'El tipo es requerido')
    ->inList('type', InventoryAdjustmentConstants::TYPES, 'Tipo inválido')
    ->notEmptyString('reason', 'El motivo es requerido')
    ->maxLength('reason', InventoryAdjustmentConstants::REASON_MAX_LENGTH,
        'El motivo no puede exceder 120 caracteres')
    ->numeric('quantity', 'La cantidad debe ser numérica')
    ->greaterThan('quantity', 0, 'La cantidad debe ser mayor a 0')
    ->requirePresence('ingredient_id', 'create')
    ->integer('ingredient_id')
    ->allowEmptyString('notes');
```

`buildRules(RulesChecker $rules)`:

```php
$rules->add($rules->existsIn(['ingredient_id'], 'Ingredients',
    ['message' => 'El ingrediente no existe']), 'ingredientExists');
$rules->add($rules->existsIn(['user_id'], 'Users',
    ['allowNullableNulls' => true]), 'userExists');
return $rules;
```

Custom finders (nombres nuevos, NO sobrescribir `findList()`):

- `findChronological(SelectQuery $query): SelectQuery`
  → `$query->orderBy(['InventoryAdjustments.created' => 'DESC', 'InventoryAdjustments.id' => 'DESC']);`

- `findByIngredient(SelectQuery $query, array $options): SelectQuery`
  → `$id = (int)($options['ingredient_id'] ?? 0); $query->where(['InventoryAdjustments.ingredient_id' => $id]);`

- `findByType(SelectQuery $query, array $options): SelectQuery`
  → `$type = (string)($options['type'] ?? ''); if ($type !== '' && $type !== 'all') { $query->where(['InventoryAdjustments.type' => $type]); }`

- `findInDateRange(SelectQuery $query, array $options): SelectQuery`
  → `from`/`to` opcionales (`'YYYY-MM-DD'`), aplicar
  `created >= "{$from} 00:00:00"` y `created <= "{$to} 23:59:59"`.

**Acceptance:** `vendor/bin/phpstan analyse src/Model/Table/InventoryAdjustmentsTable.php`
limpio nivel 8.

---

### Paso 6 — Service `InventoryAdjustmentService`

**Archivo:** `src/Service/InventoryAdjustmentService.php`

Clase `final` en `App\Service`. `use LocatorAwareTrait`. Constructor con
`IngredientService` opcional inyectable (design §3.1).

**Imports clave:**
- `App\Constants\InventoryAdjustmentConstants`
- `App\Model\Entity\InventoryAdjustment`
- `App\Service\IngredientService`
- `Cake\Datasource\ConnectionManager`
- `Cake\Log\Log`
- `Cake\ORM\Locator\LocatorAwareTrait`

```php
public function __construct(?IngredientService $ingredients = null)
{
    $this->ingredients = $ingredients ?? new IngredientService();
}
```

Forma de retorno estándar:
`array{success: bool, adjustment?: InventoryAdjustment, errors?: array<int, string>}`.

**Métodos públicos:**

1. **`create(array $data, int $userId): array`** — design §3.2.
   - **Pre-validación rápida** (defensa antes de tocar DB):
     - Si falta `ingredient_id` o no es entero positivo → error
       "El ingrediente es requerido".
     - Si falta `type` o no está en `TYPES` → error "Tipo inválido".
     - Si falta `quantity` o `!is_numeric($quantity)` o `(float)$quantity <= 0`
       → error "La cantidad debe ser mayor a 0".
     - Si falta `reason` o `trim($reason) === ''` → error "El motivo es requerido".
   - Tablas: `$adjustmentsTable = $this->fetchTable('InventoryAdjustments');`
     `$ingredientsTable = $this->fetchTable('Ingredients');`
   - `$ingredient = $ingredientsTable->find()->where(['id' => $ingredientId])->first();`
     Si null → `['success' => false, 'errors' => ['Ingrediente no encontrado.']]`.
   - **Transacción** (savepoint-safe — `adjustStock` abre su propia transacción
     anidada con FOR UPDATE):
     ```php
     $resultBox = ['success' => false, 'errors' => ['Error desconocido.']];
     $conn = ConnectionManager::get('default');
     $conn->transactional(function () use (...): bool {
         $adjustment = $adjustmentsTable->newEntity([
             'ingredient_id' => $ingredientId,
             'type' => $type,
             'quantity' => (string)$quantity,
             'reason' => trim($reason),
             'notes' => $data['notes'] ?? null,
             'user_id' => $userId > 0 ? $userId : null,
         ]);
         if (!$adjustmentsTable->save($adjustment)) {
             $resultBox = [
                 'success' => false,
                 'errors' => $this->flattenErrors($adjustment->getErrors()),
                 'adjustment' => $adjustment,
             ];
             return false;
         }
         // Hidratar la asociación para que getSignedDelta() funcione.
         $adjustment->ingredient = $ingredient;
         $stockResult = $this->ingredients->adjustStock(
             $ingredient,
             $adjustment->getSignedDelta(),
             "Ajuste #{$adjustment->id}: {$adjustment->reason}",
         );
         if (!$stockResult['success']) {
             $resultBox = [
                 'success' => false,
                 'errors' => $stockResult['errors'] ?? ['No se pudo mover el stock.'],
             ];
             return false;
         }
         Log::info('Inventory adjustment created: id={id} ingredient={ing} type={t} qty={q}', [
             'id' => $adjustment->id,
             'ing' => $ingredient->id,
             't' => $adjustment->type,
             'q' => $adjustment->quantity,
             'scope' => ['adjustments'],
         ]);
         $resultBox = ['success' => true, 'adjustment' => $adjustment];
         return true;
     });
     return $resultBox;
     ```

2. **`delete(InventoryAdjustment $adj): array`** — design §3.2.
   - Cargar ingrediente fresco:
     `$ingredient = $this->fetchTable('Ingredients')->find()->where(['id' => $adj->ingredient_id])->first();`
     Si null → `['success' => false, 'errors' => ['Ingrediente no encontrado.']]`.
   - **Transacción:**
     ```php
     $adj->ingredient = $ingredient; // necesario para getReverseDelta
     $stockResult = $this->ingredients->adjustStock(
         $ingredient,
         $adj->getReverseDelta(),
         "Reversión del ajuste #{$adj->id}",
     );
     if (!$stockResult['success']) {
         // Mensaje específico al usuario.
         $newStock = $stockResult['new_stock'] ?? '?';
         $msg = sprintf(
             'No se puede eliminar el ajuste: revertir bajaría el stock de %s a %s. Registrá un ajuste compensatorio primero.',
             $ingredient->name,
             $newStock,
         );
         $resultBox = ['success' => false, 'errors' => [$msg]];
         return false;
     }
     if (!$adjustmentsTable->delete($adj)) {
         $resultBox = ['success' => false, 'errors' => ['No se pudo eliminar el ajuste.']];
         return false;
     }
     Log::warning('Inventory adjustment reversed: id={id} ingredient={ing} reverse_delta={d}', [
         'id' => $adj->id,
         'ing' => $ingredient->id,
         'd' => $adj->getReverseDelta(),
         'scope' => ['adjustments'],
     ]);
     $resultBox = ['success' => true];
     return true;
     ```

3. **No `update`.** Append-only por diseño (design §3.4). Si en el futuro se
   pide editar, redirige a `delete + add`. **No** crear un método `update`
   "porque está fácil".

**Helper privado:** `flattenErrors(array $errors): array` — copiar de
`IngredientService::flattenErrors()`.

> **Crítico (Risks §5):** `adjustStock` ya abre `Connection::transactional()`
> internamente con `SELECT ... FOR UPDATE`. Cuando se llama desde otra
> transacción del caller (este service), CakePHP usa savepoints anidados —
> el rollback externo revierte el adjustStock. Verificado contra
> `IngredientService::adjustStock` líneas 113–179.

**Acceptance:** `vendor/bin/phpstan analyse src/Service/InventoryAdjustmentService.php`
limpio nivel 8.

---

### Paso 7 — Controller `AdjustmentsController`

**Archivo:** `src/Controller/AdjustmentsController.php`

Extiende `AppController`.

> **Crítico (Risks §3):** el controller se llama `AdjustmentsController` (URL
> `/adjustments`, corto y legible), pero la tabla real se llama
> `inventory_adjustments`. CakePHP inflexionaría `Adjustments → adjustments`,
> que NO matchea. Solución: cargar la tabla manualmente vía
> `$this->fetchTable('InventoryAdjustments')` y exponerla como propiedad
> tipada. La carpeta de templates va a `templates/Adjustments/` (matchea
> controller). El módulo RBAC se llama `adjustments`.

**Estructura:**

```php
namespace App\Controller;

use App\Constants\InventoryAdjustmentConstants;
use App\Service\InventoryAdjustmentService;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\ORM\Query\SelectQuery;

class AdjustmentsController extends AppController
{
    public array $paginate = [
        'limit' => 15,
        'maxLimit' => 15,
        'order' => [
            'InventoryAdjustments.created' => 'DESC',
            'InventoryAdjustments.id' => 'DESC',
        ],
        'sortableFields' => ['created', 'type'],
    ];

    private InventoryAdjustmentService $adjustmentService;
    private \App\Model\Table\InventoryAdjustmentsTable $InventoryAdjustments;
    private \App\Model\Table\IngredientsTable $Ingredients;

    public function initialize(): void
    {
        parent::initialize();
        $this->adjustmentService = new InventoryAdjustmentService();
        /** @var \App\Model\Table\InventoryAdjustmentsTable $ia */
        $ia = $this->fetchTable('InventoryAdjustments');
        $this->InventoryAdjustments = $ia;
        /** @var \App\Model\Table\IngredientsTable $ing */
        $ing = $this->fetchTable('Ingredients');
        $this->Ingredients = $ing;
    }

    public function index(): void { /* ... */ }
    public function add() { /* ... */ }
    public function delete(int $id) { /* ... */ }
}
```

**Acción `index()`:**
```php
$filters = $this->_currentFilters();
$query = $this->_buildIndexQuery($filters);
$adjustments = $this->paginate($query);
$ingredients = $this->Ingredients->find('nameList')->toArray();
$this->set(compact('adjustments', 'filters', 'ingredients'));
$this->set('breadcrumbs', [['label' => 'Ajustes de Inventario']]);
```

**Acción `add()`:**
```php
$adjustment = $this->InventoryAdjustments->newEmptyEntity();
$preselectId = (int)$this->request->getQuery('ingredient_id', 0);
if ($preselectId > 0) {
    $adjustment->ingredient_id = $preselectId;
}

if ($this->request->is(['post', 'put'])) {
    $userId = (int)$this->Authentication->getIdentity()?->get('id');
    $data = (array)$this->request->getData();
    $result = $this->adjustmentService->create($data, $userId);
    if ($result['success']) {
        $this->Flash->success('Ajuste registrado correctamente.');
        return $this->redirect(['action' => 'index']);
    }
    foreach ($result['errors'] ?? ['No se pudo registrar el ajuste.'] as $msg) {
        $this->Flash->error($msg);
    }
    $adjustment = $result['adjustment'] ?? $this->InventoryAdjustments->patchEntity($adjustment, $data);
}

$ingredients = $this->Ingredients->find('nameList')->toArray();
$ingredientsMeta = $this->Ingredients->find()
    ->select(['id', 'unit', 'stock_quantity'])
    ->all()
    ->indexBy('id')
    ->toArray();
$this->set(compact('adjustment', 'ingredients', 'ingredientsMeta'));
$this->set('breadcrumbs', [
    ['label' => 'Ajustes de Inventario', 'url' => ['action' => 'index']],
    ['label' => 'Nuevo ajuste'],
]);
```

> **Crítico (gotcha §5):** el ID del usuario actual se obtiene como
> `$this->Authentication->getIdentity()?->get('id')` — la identidad expone
> `get($key)` (interfaz `IdentityInterface`). Si fuera null (anónimo) el
> beforeFilter ya redirigió, así que el cast a `(int)` es seguro y nunca
> persiste un 0 (validamos `$userId > 0` antes de asignar).

**Acción `delete(int $id)`:**
```php
$this->request->allowMethod(['post', 'delete']);
try {
    $adj = $this->InventoryAdjustments->get($id, contain: ['Ingredients']);
} catch (RecordNotFoundException) {
    $this->Flash->error('El ajuste ya no existe.');
    return $this->redirect(['action' => 'index']);
}
$result = $this->adjustmentService->delete($adj);
if ($result['success']) {
    $this->Flash->success('Ajuste revertido y eliminado.');
} else {
    $this->Flash->error($result['errors'][0] ?? 'No se pudo eliminar el ajuste.');
}
return $this->redirect(['action' => 'index']);
```

**No** sobrescribir `_actionToPermission` — todas las acciones (`index`, `add`,
`delete`) calzan en el mapeo base.

**No** definir `view()` ni `edit()`. Si alguien navega a `/adjustments/view/N`
o `/adjustments/edit/N`, CakePHP responde 404 al no encontrar la acción
(comportamiento correcto para append-only).

`_currentFilters()`:
```php
$allowedType = ['all', InventoryAdjustmentConstants::TYPE_ENTRY, InventoryAdjustmentConstants::TYPE_BAJA];
$allowedSort = ['created', 'type'];
$allowedDir = ['asc', 'desc'];
$rawFrom = trim((string)$this->request->getQuery('from', ''));
$rawTo = trim((string)$this->request->getQuery('to', ''));

// Normalizar rango: si to < from, intercambiar y flashear warning (design §7).
if ($rawFrom !== '' && $rawTo !== '' && strcmp($rawTo, $rawFrom) < 0) {
    $this->Flash->warning('El rango de fechas estaba invertido; se reordenó automáticamente.');
    [$rawFrom, $rawTo] = [$rawTo, $rawFrom];
}

return [
    'ingredient_id' => (int)$this->request->getQuery('ingredient_id', 0),
    'type' => in_array((string)$this->request->getQuery('type', 'all'), $allowedType, true)
        ? (string)$this->request->getQuery('type', 'all') : 'all',
    'from' => $rawFrom,
    'to' => $rawTo,
    'sort' => in_array((string)$this->request->getQuery('sort', 'created'), $allowedSort, true)
        ? (string)$this->request->getQuery('sort', 'created') : 'created',
    'direction' => in_array((string)$this->request->getQuery('direction', 'desc'), $allowedDir, true)
        ? (string)$this->request->getQuery('direction', 'desc') : 'desc',
];
```

`_buildIndexQuery(array $filters): SelectQuery`:
```php
$query = $this->InventoryAdjustments->find()
    ->contain(['Ingredients', 'Users']);
if ($filters['ingredient_id'] > 0) {
    $query->where(['InventoryAdjustments.ingredient_id' => $filters['ingredient_id']]);
}
if ($filters['type'] !== 'all') {
    $query->where(['InventoryAdjustments.type' => $filters['type']]);
}
if ($filters['from'] !== '') {
    $query->where(['InventoryAdjustments.created >=' => $filters['from'] . ' 00:00:00']);
}
if ($filters['to'] !== '') {
    $query->where(['InventoryAdjustments.created <=' => $filters['to'] . ' 23:59:59']);
}
return $query;
```

**Acceptance:** `composer cs-check` y `vendor/bin/phpstan analyse` limpios
sobre el controller. Hit manual `GET /adjustments` (anónimo) redirige a
`/login`.

---

### Paso 8 — RBAC: `AppController::$controllerModuleMap`

**Archivo:** `src/Controller/AppController.php`

En el array `$controllerModuleMap` (línea ~19), agregar **una línea** tras
`'Recipes' => 'recipes',`:

```php
'Adjustments' => 'adjustments',
```

**Acceptance:** sin otros cambios en el archivo.

---

### Paso 9 — RBAC: `AuthorizationService::MODULES`

**Archivo:** `src/Service/AuthorizationService.php`

En el array `MODULES` (línea ~17), agregar **una línea** tras
`'recipes' => 'Recetas',`:

```php
'adjustments' => 'Ajustes de Inventario',
```

**Acceptance:** `composer cs-check` limpio.

---

### Paso 10 — Sidebar

**Archivo:** `src/View/Helper/SidebarHelper.php`

Insertar **después** del item `recipes` (línea ~37) en `$items`:

```php
[
    'module' => 'adjustments',
    'label' => 'Ajustes',
    'icon' => 'bi-arrow-left-right',
    'url' => ['controller' => 'Adjustments', 'action' => 'index'],
],
```

(Icono alternativo `bi-clipboard-data`. `bi-arrow-left-right` sugiere
"movimiento bidireccional" — encaja con entradas/bajas.)

El helper ya filtra por `$permissions[$item['module']]['view']`, así que el
item aparece automáticamente para Administrador y para roles con
`can_view = 1` en `adjustments`.

**Acceptance:** loguearse como Administrador → ver "Ajustes" en el sidebar
después de "Recetas" y antes de "Clientes".

---

### Paso 11 — Template `templates/Adjustments/index.php`

Design §6.1.

**Header (`dr-page-header`):**
- `h1.dr-page-title` "Ajustes de Inventario".
- `button-primary` único: "Nuevo ajuste" → `['action' => 'add']`.

**Card de filtros** (form GET con altura 40px en todos los controles):
- Select `ingredient_id` con `<option value="0">Todos los ingredientes</option>`
  + loop sobre `$ingredients` (de `findNameList`). max-width 240px.
- Select `type` con opciones `all="Todos"`, `entrada="Entradas"`,
  `baja="Bajas"`. max-width 140px.
- Input `from` `type="date"`, label "Desde".
- Input `to` `type="date"`, label "Hasta".
- Botón "Filtrar" `btn-secondary`.
- Link "Limpiar" si hay filtros activos
  (`ingredient_id > 0 || type !== 'all' || from !== '' || to !== ''`).

**Tabla (`card` + `table`):**

| Columna | Width | Align | Contenido |
|---|---|---|---|
| Fecha | 160px | left | `$adj->created->i18nFormat('dd/MM/yyyy HH:mm')` |
| Ingrediente | auto | left | `Html->link(h($adj->ingredient->name), ['controller'=>'Ingredients','action'=>'view',$adj->ingredient_id])` |
| Tipo | 110px | center | Badge `badge-soft-success` "Entrada" / `badge-soft-warning` "Baja" (NO `status-*` — reservados para pedidos) |
| Cantidad | 140px | right | `h($adj->getFormattedQuantity())`, color verde si entrada, naranja si baja |
| Motivo | 220px | left | `h($adj->reason)` + ícono `bi-chat-square-text` con tooltip si `$adj->notes` |
| Autor | 160px | left | `h($adj->user->name ?? '')` o `<span class="text-muted">Usuario eliminado</span>` si null |
| Acciones | 80px | right | `Form->postLink` con icono `bi-trash`, class `btn-icon text-danger`, `confirm` con copy específico (design §6.3) |

**Empty state:**
- Sin filtros: "Aún no hay ajustes registrados. [Registrar el primero]".
- Con filtros: "Sin ajustes para los filtros aplicados".

**Pie:** `<?= $this->element('pagination') ?>`.

**Acceptance:** render sin warnings; columnas alineadas; badges con colores
correctos.

---

### Paso 12 — Template `templates/Adjustments/add.php`

Design §6.2. Layout en card único, dos columnas en `md+`.

**Header:** `h1.dr-page-title` "Nuevo ajuste".

**`Form->create($adjustment, ['url' => ['action' => 'add']])`.**

**Columna izquierda — Datos del ajuste:**

- `Form->select('ingredient_id', $ingredients, [
    'empty' => 'Seleccionar ingrediente...',
    'class' => 'form-select',
    'required' => true,
    'label' => 'Ingrediente',
    'default' => $adjustment->ingredient_id ?? null,
  ])`.
  Generar manualmente las `<option>` para inyectar `data-unit` y `data-stock`
  desde `$ingredientsMeta[$id]` (para sufijo dinámico y stat-card).
- Stat-card debajo: "Stock actual: 1.250 gr" del ingrediente seleccionado.
  Server-side cuando hay `$preselectId`; vía JS cuando se cambia el select.
- `type`: dos radio buttons en formato "chips" (`btn-group` `dr-toggle`):
  - "Entrada" con icono `bi-arrow-down-circle`.
  - "Baja" con icono `bi-arrow-up-circle`.
  - Sin default (forzar decisión).
- `Form->control('quantity', [
    'type' => 'number',
    'step' => '0.001',
    'min' => '0.001',
    'label' => 'Cantidad',
    'required' => true,
  ])`. Sufijo visual con unidad (JS o re-render server-side tras submit).

**Columna derecha — Contexto:**

- `Form->control('reason', [
    'type' => 'text',
    'maxlength' => 120,
    'required' => true,
    'list' => 'reason-suggestions',
    'label' => 'Motivo',
    'help' => 'Texto libre — las sugerencias son guía.',
  ])`.
- `<datalist id="reason-suggestions">` con loop sobre
  `InventoryAdjustmentConstants::REASON_SUGGESTIONS`.
- `Form->control('notes', [
    'type' => 'textarea',
    'rows' => 4,
    'label' => 'Notas',
    'help' => 'Detalle opcional para contexto futuro.',
  ])`.

**Pie del form:**
- `button-primary` "Registrar ajuste".
- `Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-light'])`.

**Sin botón Eliminar** (es alta, no edit).

**Acceptance:** render sin warnings; preselección por `?ingredient_id=N`
funciona; submit válido redirige a `index` con flash success.

---

### Paso 13 — Fixture `InventoryAdjustmentsFixture`

**Archivo:** `tests/Fixture/InventoryAdjustmentsFixture.php`

Reusar `IngredientsFixture` y `UsersFixture` ya existentes (verificar primero
si `tests/Fixture/IngredientsFixture.php` y `UsersFixture.php` están en repo;
si falta `Ingredients`, crear seed mínimo con `id=1 'Carne molida'` unit `gr`,
stock `1500.000`, cost `25.00`).

Filas mínimas (2 — una `entrada` y una `baja`):
```php
public array $records = [
    [
        'id' => 1,
        'ingredient_id' => 1,
        'type' => 'entrada',
        'quantity' => '500.000',
        'reason' => 'Compra a proveedor',
        'notes' => null,
        'user_id' => 1, // admin
        'created' => '2026-05-20 10:00:00',
    ],
    [
        'id' => 2,
        'ingredient_id' => 1,
        'type' => 'baja',
        'quantity' => '100.000',
        'reason' => 'Merma',
        'notes' => 'Producto vencido',
        'user_id' => 1,
        'created' => '2026-05-21 15:30:00',
    ],
];
```

**Acceptance:** `vendor/bin/phpunit --list-tests tests/TestCase/Model/Table/InventoryAdjustmentsTableTest.php`
no falla por fixture missing.

---

### Paso 14 — `InventoryAdjustmentTest` (Entity)

**Archivo:** `tests/TestCase/Model/Entity/InventoryAdjustmentTest.php`

`use Cake\TestSuite\TestCase`. Sin fixtures (entity puro).

Casos:
- `testIsEntryTrueWhenTypeEntrada`.
- `testIsBajaTrueWhenTypeBaja`.
- `testGetSignedDeltaForEntry` — `type='entrada', quantity='2.500'` → `'+2.500'`.
- `testGetSignedDeltaForBaja` — `type='baja', quantity='0.250'` → `'-0.250'`.
- `testGetReverseDeltaInvertsSign`.
- `testGetFormattedQuantityWithIngredient` — agregar
  `ingredient` con `unit='gr'`, `quantity='1.250'`, type entrada → `'+1.250 gr'`.
- `testGetFormattedQuantityWithoutIngredient` — solo número con signo.
- `testVirtualSignedDeltaAccessible` — `$adj->signed_delta` accesible.
- `testVirtualFormattedQuantityAccessible`.

---

### Paso 15 — `InventoryAdjustmentsTableTest`

**Archivo:** `tests/TestCase/Model/Table/InventoryAdjustmentsTableTest.php`

`use Cake\TestSuite\TestCase`. Fixtures: `app.Ingredients`, `app.Users`,
`app.Roles`, `app.InventoryAdjustments`.

Casos:
- `testValidationRejectsTypeOutOfList`.
- `testValidationRejectsZeroQuantity` — `greaterThan(0)`.
- `testValidationRejectsNegativeQuantity`.
- `testValidationRejectsEmptyReason`.
- `testValidationRejectsTooLongReason` — `>120` chars.
- `testRulesRejectsMissingIngredient` — `ingredient_id=999`.
- `testRulesAllowsNullUserId`.
- `testFindChronologicalOrdersByCreatedDescIdDesc`.
- `testFindByIngredientFiltersCorrectly` —
  `find('byIngredient', ingredient_id: 1)` solo fila ingredient 1.
- `testFindByTypeFiltersEntrada`.
- `testFindByTypeReturnsAllWhenTypeAll`.
- `testFindInDateRangeAppliesInclusiveBounds` — `to` incluye `23:59:59`.
- `testTimestampBehaviorSetsCreatedOnNew` — save → `$adj->created` no null.
- `testNoModifiedColumnWriteOnSecondSave` — guardar dos veces NO falla por
  columna `modified` inexistente (valida que el behavior está configurado
  solo con `created`).

---

### Paso 16 — `InventoryAdjustmentServiceTest`

**Archivo:** `tests/TestCase/Service/InventoryAdjustmentServiceTest.php`

`use Cake\TestSuite\TestCase`. Fixtures: `app.Ingredients`, `app.Users`,
`app.Roles`, `app.InventoryAdjustments`.

`setUp()`: `$this->service = new InventoryAdjustmentService();` (usa
`IngredientService` real para validar integración end-to-end con `adjustStock`).

> Tests adicionales con mock de `IngredientService` (vía
> `$this->getMockBuilder(IngredientService::class)`) son **opcionales** —
> el service real es barato y ya probado en su propio test. Mockear solo
> el caso "adjustStock devuelve `success=false`" para verificar rollback
> sin necesitar setear stock real.

Casos:
- `testCreateSuccessPersistsRowAndMovesStock` — fixture stock=1500, crear
  entrada qty=500 → row persiste + stock pasa a 2000.000.
- `testCreateWithBajaMovesStockDown` — baja qty=200 → stock 1500 → 1300.
- `testCreateRejectsMissingIngredient` — ingredient_id=999.
- `testCreateRejectsInvalidType` — type='inventado'.
- `testCreateRejectsZeroQuantity`.
- `testCreateRejectsNegativeQuantity`.
- `testCreateRejectsEmptyReason`.
- `testCreateRejectsWhitespaceOnlyReason` — `'   '`.
- `testCreateBajaInsufficientStockRollsBack` — stock=100, baja qty=500 →
  `success=false`, row NO persiste (count tabla no cambió), stock NO cambió.
- `testCreateWithMockedAdjustStockFailureRollsBack` — mockear
  `IngredientService::adjustStock` para devolver `success=false`, verificar
  que la fila no queda en DB.
- `testDeleteRevertsStockAndDeletesRow` — fixture: 1500 + entrada 500 ya
  aplicada (stock = 2000). Borrar el adjustment → stock vuelve a 1500 y row
  desaparece.
- `testDeleteWithReverseGoingNegativeFails` — fixture: entrada de 1000 que
  ya se consumió (stock actual = 200). Borrar la entrada → revertir bajaría
  stock a -800 → service rechaza con mensaje específico ("revertir bajaría
  el stock de X a -Y"), row NO se borra.
- `testDeleteIngredientMissingReturnsError` — adjustment con
  `ingredient_id=999` (simulado manualmente, normalmente CASCADE lo borraría).
- `testNoUpdateMethodExists` — `assertFalse(method_exists($this->service, 'update'))`.

---

### Paso 17 — `AdjustmentsControllerTest`

**Archivo:** `tests/TestCase/Controller/AdjustmentsControllerTest.php`

`use IntegrationTestTrait`. Fixtures: `Users`, `Roles`, `Permissions`,
`Ingredients`, `InventoryAdjustments`.

`setUp()`: `$this->enableCsrfToken(); $this->enableSecurityToken();`.

Casos:
- `testIndexRedirectsAnonymous` — sin login → 302 a `/login`.
- `testIndexForbiddenWithoutPermission` — rol sin `adjustments.view` → 403.
- `testIndexOkWithPermission` — rol con permiso → 200 + entidades visibles.
- `testIndexAsAdministratorBypass`.
- `testIndexFilterByIngredient` — `?ingredient_id=1`.
- `testIndexFilterByType` — `?type=entrada`.
- `testIndexFilterByDateRange` — `?from=2026-05-20&to=2026-05-20`.
- `testIndexNormalizesInvertedDateRange` — `?from=2026-05-21&to=2026-05-20`
  → flash warning + intercambio.
- `testAddGetShowsForm`.
- `testAddGetWithPreselectIngredient` — `?ingredient_id=1` →
  `$adjustment->ingredient_id === 1`.
- `testAddPostForbiddenWithoutCreate`.
- `testAddPostSuccessRedirectsAndFlashes` — POST válido → 302 + flash
  success + row + stock movido.
- `testAddPostBajaInsufficientStockFlashesError` — flash error específico,
  no redirect a index sino re-render.
- `testDeleteForbiddenWithoutDelete` — rol sin `adjustments.delete` → 403.
- `testDeleteRequiresPostOrDelete` — GET → 405.
- `testDeleteSuccess` — POST → 302 + flash + row borrada + stock revertido.
- `testDeleteReverseFailsOnNegativeStock` — POST → 302 + flash error
  específico ("revertir bajaría stock..."), row NO borrada.
- `testDeleteMissingIdFlashesError` — `id=999` →
  flash + redirect (no 500).
- `testEditAction404` — GET `/adjustments/edit/1` → 404 (acción no existe).
- `testViewAction404` — GET `/adjustments/view/1` → 404.

---

### Paso 18 — Aplicar y volcar schema

```bash
php bin/cake.php migrations migrate
php bin/cake.php migrations dump
```

**Acceptance:** ambos comandos exitosos; `schema-dump-default.lock`
actualizado para incluir `inventory_adjustments`.

---

## 3. Test plan

### Archivos a crear

| Tipo | Path |
|---|---|
| Fixture | `tests/Fixture/InventoryAdjustmentsFixture.php` (1 entrada + 1 baja) |
| Entity test | `tests/TestCase/Model/Entity/InventoryAdjustmentTest.php` |
| Table test | `tests/TestCase/Model/Table/InventoryAdjustmentsTableTest.php` |
| Service test | `tests/TestCase/Service/InventoryAdjustmentServiceTest.php` |
| Controller test | `tests/TestCase/Controller/AdjustmentsControllerTest.php` |

### Cobertura por archivo (resumen)

- **Entity:** `isEntry/isBaja`, `getSignedDelta` con/sin signo, `getReverseDelta`,
  `getFormattedQuantity` con/sin ingrediente hidratado, virtuales accesibles.
- **Table:** validación (`inList type`, `greaterThan quantity`,
  `notEmptyString reason`, `maxLength reason 120`), rules (`existsIn ingredient`,
  `existsIn user allowNullableNulls`), finders (`chronological`, `byIngredient`,
  `byType`, `inDateRange`), Timestamp behavior solo escribe `created`.
- **Service:**
  - `create` happy path → fila + stock movido + log.
  - `create` falla por baja > stock → rollback (row count no cambió + stock no cambió).
  - `create` con mock `IngredientService::adjustStock` que falla → rollback.
  - `create` rechaza datos inválidos antes de tocar DB.
  - `delete` happy path → row borrada + stock revertido.
  - `delete` que dejaría stock negativo → rechazo con mensaje específico, row queda.
  - `delete` con ingrediente faltante → error claro.
  - `update` NO existe.
- **Controller:** auth (anónimo → /login), RBAC (sin permiso → 403,
  Admin bypass), CRUD (index, add GET/POST, delete POST/DELETE), filtros
  (ingredient_id, type, date range, inverted range), preselección
  `?ingredient_id=N`, `/edit` y `/view` retornan 404, casos de error
  (baja > stock al crear, reverse → stock negativo al borrar).

### Mock vs real IngredientService

- **Por default usar el real:** la integración entre service del módulo y
  `adjustStock` es el camino principal y debe probarse end-to-end.
- **Mock puntual** solo en `testCreateWithMockedAdjustStockFailureRollsBack`
  para forzar `success=false` sin tener que setear estados raros en DB.
  Patrón: `$mock = $this->createMock(IngredientService::class);
  $mock->method('adjustStock')->willReturn(['success' => false, 'errors' => ['boom']]);
  $service = new InventoryAdjustmentService($mock);`.

### Comandos de verificación

```bash
composer cs-check && composer cs-fix
vendor/bin/phpstan analyse
vendor/bin/psalm
php vendor/bin/phpunit tests/TestCase/Model/Entity/InventoryAdjustmentTest.php
php vendor/bin/phpunit tests/TestCase/Model/Table/InventoryAdjustmentsTableTest.php
php vendor/bin/phpunit tests/TestCase/Service/InventoryAdjustmentServiceTest.php
php vendor/bin/phpunit tests/TestCase/Controller/AdjustmentsControllerTest.php
php vendor/bin/phpunit
```

---

## 4. Verification checklist

Ejecutar antes de marcar el módulo como hecho:

- [ ] `php bin/cake.php migrations migrate` corre sin errores. Tabla
      `inventory_adjustments` creada con FK CASCADE (ingredient) y SET NULL
      (user); índices `idx_ia_*` presentes; sin columna `modified`.
- [ ] `php bin/cake.php migrations dump` actualiza el lock sin warnings.
- [ ] `composer cs-check` limpio sobre todos los archivos nuevos/modificados.
- [ ] `composer cs-fix` no produce cambios adicionales (idempotente).
- [ ] `php -l` (lint sintaxis) limpio sobre cada archivo nuevo:
  ```bash
  for f in src/Constants/InventoryAdjustmentConstants.php \
           src/Model/Entity/InventoryAdjustment.php \
           src/Model/Table/InventoryAdjustmentsTable.php \
           src/Service/InventoryAdjustmentService.php \
           src/Controller/AdjustmentsController.php \
           config/Migrations/20260524140000_CreateInventoryAdjustments.php \
           config/Migrations/20260524140100_SeedAdjustmentsPermissions.php; do
    php -l "$f" || exit 1
  done
  ```
- [ ] `vendor/bin/phpstan analyse` nivel 8 limpio sobre los nuevos archivos.
- [ ] `vendor/bin/psalm` sin nuevos errores.
- [ ] `php bin/cake.php routes | grep -i adjust` lista al menos:
  - `/adjustments` → `Adjustments::index`.
  - `/adjustments/add` → `Adjustments::add`.
  - `/adjustments/delete/*` → `Adjustments::delete`.
  (Resueltas por `$builder->fallbacks()` en `config/routes.php`; **no** se
  agregan rutas custom para este módulo.)
- [ ] Servidor dev levanta: `php bin/cake.php server -p 8765`.
- [ ] **Smoke manual** logueado como Administrador:
  1. `/adjustments` → empty state con CTA "Registrar el primero" (si DB limpia).
  2. `/adjustments/add` → formulario; seleccionar ingrediente, type entrada,
     qty 100, motivo "Compra a proveedor" → submit → flash success.
  3. Listado lo muestra con fecha, badge "Entrada" verde, cantidad `+100.000 gr`.
  4. Stock del ingrediente en `/ingredients` aumentó en 100.
  5. Crear baja qty 50 motivo "Merma" → flash success; stock disminuyó.
  6. Filtrar `?type=baja` → solo la baja.
  7. Filtrar `?ingredient_id={id}&from=2026-05-24` → ambas filas.
  8. Eliminar la baja → flash "Ajuste revertido"; stock regresa al valor previo.
  9. Crear baja qty 99999 → flash error "Stock insuficiente para X".
  10. GET `/adjustments/edit/1` → 404. GET `/adjustments/view/1` → 404.
- [ ] **Smoke RBAC:** rol no-admin con `adjustments.view=1` pero
      `adjustments.delete=0` → no aparece botón eliminar; POST directo a
      `/adjustments/delete/N` → 403.
- [ ] **Smoke RBAC create:** rol con `view=1, create=0` → no aparece botón
      "Nuevo ajuste"; POST a `/adjustments/add` → 403.
- [ ] **Smoke FK cascade:** crear ajuste sobre ingrediente X; borrar
      ingrediente X desde `/ingredients/delete/{id}` → fila del ajuste
      desaparece automáticamente.
- [ ] **Smoke FK SET NULL:** crear ajuste con userId=N; borrar user N (no
      Administrador) → en `/adjustments` el autor aparece como "Usuario
      eliminado" (la fila del ajuste sobrevive).
- [ ] **Smoke navegación:** item "Ajustes" aparece en sidebar después de
      "Recetas" para usuarios con `adjustments.view`.

---

## 5. Risks / gotchas

1. **FK signed/unsigned matching.** `ingredients.id` es `int unsigned`
   (`'signed' => false` verificado en `CreateIngredients` línea 13) y
   `users.id` también es `int unsigned` (verificado en `CreateUsers` línea 16).
   Por lo tanto:
   - `ingredient_id` columna: `['signed' => false, 'null' => false]`.
   - `user_id` columna: `['signed' => false, 'null' => true]`.
   - PK `id` de la tabla nueva: `'signed' => false` en `$this->table(...,
     ['signed' => false])`.
   Mismatch hace que MySQL rechace la FK con error críptico tipo
   `Cannot add foreign key constraint`. Verificar antes de migrar.

2. **`modified` NO existe en la tabla.** Append-only por diseño. El
   `Timestamp` behavior **debe** configurarse explícitamente para escribir
   **solo** `created`:
   ```php
   $this->addBehavior('Timestamp', [
       'events' => [
           'Model.beforeSave' => ['created' => 'new'],
       ],
   ]);
   ```
   Si se omite la config (`addBehavior('Timestamp')` plano), el behavior
   intentará escribir `modified` en cada save y fallará con error
   `Unknown column 'modified'`. Tampoco agregar `modified` a `$_accessible`.

3. **Controller `AdjustmentsController` no inflexiona a tabla
   `inventory_adjustments`.** CakePHP inflexionaría `Adjustments → adjustments`,
   que NO existe. Solución dentro del controller:
   ```php
   $this->InventoryAdjustments = $this->fetchTable('InventoryAdjustments');
   ```
   (alternativa: declarar `public ?string $defaultTable = 'InventoryAdjustments';`
   en CakePHP 5, pero `fetchTable` es más explícito). **No** usar
   `loadModel` — deprecado en CakePHP 5. La carpeta de templates va a
   `templates/Adjustments/` (matchea el nombre del controller).

4. **`bcmath` prohibido (memoria de usuario).** Toda matemática debe usar
   `(float)` cast + `round()` + `number_format()` con escala explícita.
   `IngredientService::adjustStock` ya migró a `round` —
   `InventoryAdjustmentService` no debe introducir regresiones. Si en algún
   helper aparece `bcadd`/`bccomp`/`bcsub`, reemplazar por la combinación
   `round(...)` + `number_format(...)`.

5. **`currentUser->id` desde Authentication.** El service recibe `int $userId`,
   y el controller lo obtiene con:
   ```php
   $userId = (int)$this->Authentication->getIdentity()?->get('id');
   ```
   El `?->get('id')` es seguro porque `beforeFilter` ya garantiza identidad
   no-null. El cast a `(int)` convierte null en 0 (defensa extra). El service
   acepta 0 y lo traduce a `null` en la columna `user_id` antes de persistir
   (`$userId > 0 ? $userId : null`).

6. **`adjustStock` ya abre su propia transacción con `FOR UPDATE`.** Cuando
   se llama desde dentro de la transacción del `InventoryAdjustmentService`,
   CakePHP usa savepoints anidados. Verificar que el rollback externo
   propaga el deshacer del `adjustStock`. Test
   `testCreateBajaInsufficientStockRollsBack` valida este invariante.

7. **`type` validation `inList` vive en tabla, no en service.** La validación
   `inList('type', InventoryAdjustmentConstants::TYPES, ...)` es chequeo de
   **formato**, no de **negocio** (regla §4.11 ARQUITECTURE). No mover al
   service. El service hace su propia validación rápida ANTES de tocar DB
   por defensa, pero la fuente de verdad de formato sigue siendo la tabla.

8. **Datalist en `add.php` no es validador.** `<datalist>` es solo sugerencia;
   el usuario puede escribir cualquier texto. La validación de longitud
   (`maxLength 120`) y presencia (`notEmptyString`) vive en la tabla. NO
   convertir el datalist en select (rompería el spec §12 que dice "texto libre").

9. **Cascade `ON DELETE CASCADE` para ingrediente, `ON DELETE SET NULL`
   para user.** Es intencional: la fila del ajuste vive mientras exista el
   ingrediente (su contexto principal), pero sobrevive a la baja del usuario
   que la creó (auditoría histórica preservada). NO invertir, NO uniformar
   ambos a CASCADE.

10. **`view`/`edit` 404 es comportamiento esperado.** No definir las acciones
    en el controller. CakePHP responde 404 automáticamente. NO agregar
    `throw new MethodNotAllowedException` manual — son acciones inexistentes,
    no métodos no permitidos.

11. **`schema-dump-default.lock` viene modificado en working tree.** `git
    status` muestra `M config/Migrations/schema-dump-default.lock` por
    trabajo previo. Hacer `migrations dump` UNA SOLA VEZ al final del módulo
    y commitear el lock con los cambios de este plan + los pre-existentes.

12. **`Authentication->getIdentity()?->get('id')` requiere PHP 8+.** El
    proyecto está en PHP ≥ 8.2, así que el null-safe operator y `get(string)`
    de `IdentityInterface` están disponibles. Si CS-check se queja del estilo
    del null-safe (improbable), envolver en `if ($identity !== null)`.

13. **`get($id, contain: ['Ingredients'])` usa named arguments (PHP 8+).**
    Soportado por CakePHP 5 `Table::get()`. Si phpstan se queja, alternativa
    array: `get($id, ['contain' => ['Ingredients']])`. Ambas equivalentes.

14. **Tests con fixture `Ingredients` y `Users` requieren coordinación.**
    Si `tests/Fixture/IngredientsFixture.php` o `UsersFixture.php` aún no
    existen, crearlos como parte de este módulo (seeds mínimos: 1 ingrediente
    `Carne molida`, 1 usuario admin con role `is_admin=1`). Si existen, NO
    modificarlos — alinear los records de este módulo a las IDs ya
    presentes (típicamente `id=1`).

15. **Tests pueden bloquearse por env (`pdo_sqlite`, etc.).** Memoria del
    usuario dice opt-out pero la instrucción actual REQUIERE tests. Si el
    env no tiene `pdo_sqlite`, los tests de Table/Service/Controller fallan
    al inicializar conexión. Los tests de Entity son puros (sin DB) y
    deberían correr siempre. Solución: arreglar env (`pecl install pdo_sqlite`),
    no el código.
