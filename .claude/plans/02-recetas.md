# Plan de implementación — Módulo Recetas (ProductIngredients)

> Plan ordenado, paso a paso, para implementar el módulo **Recetas** según el
> diseño aprobado en `.claude/designs/02-recetas.md`.
>
> Referencias obligatorias antes de implementar:
> - `.claude/designs/02-recetas.md` (spec definitivo)
> - `.claude/plans/01-ingredientes.md` (módulo predecesor, formato/estilo)
> - `.claude/rules/ARQUITECTURE.md` §3 (receta de módulo) y §4 (patrones)
> - `.claude/rules/DESIGN.md` (sistema visual)
> - Módulo de referencia para clonar la estructura: **Ingredients**
>   (`IngredientsController`, `IngredientService`, `IngredientsTable`,
>   `Ingredient`, `IngredientConstants`, `templates/Ingredients/*`,
>   `templates/element/Ingredients/_form.php`, migraciones
>   `CreateIngredients` + `SeedIngredientsPermissions`).
> - `src/Controller/AppController.php` y `src/Service/AuthorizationService.php`
>   (puntos de cableado RBAC).
> - `src/View/Helper/SidebarHelper.php` (navegación).
> - `config/routes.php` (rutas custom).

---

## 1. File manifest

Orden en el que tocar los archivos. Cada línea = archivo + cambio puntual.
Timestamps de migración: **2026-05-24** (mismo día que Ingredientes, posteriores
al seed de permisos `20260524120100`).

### Migraciones

1. `[CREATE] config/Migrations/20260524130000_CreateProductIngredients.php`
   — schema `product_ingredients` (design §1.1).
2. `[CREATE] config/Migrations/20260524130100_SeedRecipesPermissions.php`
   — fila `permissions` por rol para módulo `recipes` (design §6.2 punto 4).

### Constantes y modelo

3. `[CREATE] src/Constants/RecipeConstants.php` — `QUANTITY_MIN`,
   `QUANTITY_MAX`, `QUANTITY_DECIMALS` (design §2).
4. `[CREATE] src/Model/Entity/ProductIngredient.php` — `$_accessible`,
   `getLineCost()`, `_getLineCost()` virtual, `getFormattedQuantity()`,
   `getFormattedLineCost()` (design §1.2).
5. `[CREATE] src/Model/Table/ProductIngredientsTable.php` — `initialize()`,
   `validationDefault()`, `buildRules()` (`existsIn` + `isUnique`),
   `findForProduct`, `findForIngredient` (design §1.3).

### Asociaciones cruzadas

6. `[MODIFY] src/Model/Table/ProductsTable.php` — reemplazar comentario
   placeholder de `// $this->hasMany('ProductIngredients');` por la declaración
   real + `belongsToMany Ingredients through ProductIngredients` (design §3.2).
7. `[MODIFY] src/Model/Table/IngredientsTable.php` — reemplazar comentario
   placeholder existente por `hasMany ProductIngredients` real +
   `belongsToMany Products through ProductIngredients` (design §3.3).

### Servicio

8. `[CREATE] src/Service/RecipeService.php` — `addLine`, `updateLine`,
   `removeLine`, `getRecipeFor`, `calculateRecipeCost`, `hasRecipe`,
   `buildDecrementPlan`. Constructor opcional con `IngredientService`
   inyectable (design §4).

### Controllers

9. `[MODIFY] src/Controller/AppController.php`:
   - Agregar `'Recipes' => 'recipes'` al array `$controllerModuleMap`.
   - Introducir nuevo array protegido `$actionModuleMap = []` con doc-comment
     que explica que las subclases pueden mapear acciones puntuales a otro
     módulo (override del mapeo per-controller). Default: `[]`.
   - En `beforeFilter()`, ANTES de resolver `$module` desde
     `$controllerModuleMap`, consultar `$this->actionModuleMap[$action] ?? null`
     y usarlo como ganador. Si está vacío, caer al mapeo por controller (lógica
     actual intacta). **Backward-compatible** porque ningún controller existente
     define `actionModuleMap`.
10. `[CREATE] src/Controller/RecipesController.php` — solo `index()` (listado
    global de productos con estado de receta, design §5.5). Extiende
    `AppController`. Sin `actionModuleMap` (el mapeo controller→`recipes` lo
    cubre `$controllerModuleMap`).
11. `[MODIFY] src/Controller/ProductsController.php`:
    - Sumar dependencia `private RecipeService $recipeService;` en
      `initialize()`.
    - Agregar acciones `recipe(int $id)`, `addRecipeLine(int $id)`,
      `updateRecipeLine(int $id, int $lineId)`,
      `removeRecipeLine(int $id, int $lineId)` (design §5.4).
    - Sumar `$actionModuleMap` apuntando esas 4 acciones a `'recipes'`
      (design §6.2 punto 3 opción A).
    - Extender `_actionToPermission()` con los mapeos `recipe → view`,
      `addRecipeLine → create`, `updateRecipeLine → edit`,
      `removeRecipeLine → delete`, manteniendo el `toggleActive → edit`
      existente y delegando a `parent::_actionToPermission` en `default`.

### RBAC y navegación

12. `[MODIFY] src/Service/AuthorizationService.php` — agregar
    `'recipes' => 'Recetas'` al array `MODULES` tras `'ingredients'`.
13. `[MODIFY] src/View/Helper/SidebarHelper.php` — insertar item `Recetas`
    después del item `ingredients` (icono `bi-journal-text`).

### Rutas

14. `[MODIFY] config/routes.php` — registrar 4 rutas custom **antes** de
    `$builder->fallbacks()` (design §5.3). El listado global `/recipes` lo
    cubre el fallback estándar.

### Templates

15. `[CREATE] templates/Recipes/index.php` — listado global de productos con
    conteo de líneas, costo de receta y margen estimado (design §7.2).
16. `[CREATE] templates/Products/recipe.php` — editor de receta del producto
    (5 capas, design §7.1).
17. `[CREATE] templates/element/Recipes/_add_line_form.php` — sub-form
    "Agregar ingrediente" extraído del template `recipe.php` para mantenerlo
    legible (design §7.1 capa 4).

### Tests (REQUERIDOS — ver §3)

18. `[CREATE] tests/Fixture/ProductIngredientsFixture.php` — seed mínimo de
    líneas para 2 productos.
19. `[CREATE] tests/Fixture/ProductsFixture.php` (si no existe) — seed reusable.
    Verificar antes de crearlo; si existe, no tocarlo.
20. `[CREATE] tests/TestCase/Model/Entity/ProductIngredientTest.php`.
21. `[CREATE] tests/TestCase/Model/Table/ProductIngredientsTableTest.php`.
22. `[CREATE] tests/TestCase/Service/RecipeServiceTest.php`.
23. `[CREATE] tests/TestCase/Controller/RecipesControllerTest.php`.
24. `[CREATE] tests/TestCase/Controller/ProductsControllerRecipeTest.php` —
    cubre solo las 4 acciones nested (`recipe`, `addRecipeLine`,
    `updateRecipeLine`, `removeRecipeLine`).

### Cierre

25. `[RUN] php bin/cake.php migrations migrate`.
26. `[RUN] php bin/cake.php migrations dump`.
27. `[RUN] composer cs-check` (limpio sobre los nuevos archivos).

---

## 2. Step-by-step execution

### Paso 1 — Migración `CreateProductIngredients`

**Archivo:** `config/Migrations/20260524130000_CreateProductIngredients.php`

Migración con `Migrations\BaseMigration` (no `AbstractMigration`). Proteger
con `if ($this->hasTable('product_ingredients')) { return; }`. Tabla con
`'signed' => false` para que la PK `id` sea `unsigned` (match con
`products.id` e `ingredients.id`, ambas unsigned).

Columnas:
```
product_id     integer        signed=false, null=false
ingredient_id  integer        signed=false, null=false
quantity       decimal(12,3)  null=false
created        datetime       nullable
modified       datetime       nullable
```

Índices y constraints:
- `addIndex(['product_id', 'ingredient_id'], ['unique' => true, 'name' => 'uniq_pi_product_ingredient'])`.
- `addIndex(['ingredient_id'], ['name' => 'idx_pi_ingredient_id'])`.
- (No agregar `idx_pi_product_id` separado — el prefijo del UNIQUE ya lo
  cubre y MySQL lo usa; la fila simplifica el schema.)
- `addForeignKey('product_id', 'products', 'id', ['delete' => 'CASCADE', 'update' => 'RESTRICT'])`.
- `addForeignKey('ingredient_id', 'ingredients', 'id', ['delete' => 'CASCADE', 'update' => 'RESTRICT'])`.

`down()`: `if ($this->hasTable('product_ingredients')) { $this->table('product_ingredients')->drop()->update(); }`.

**Acceptance:** `php bin/cake.php migrations migrate` corre sin errores y
`SHOW CREATE TABLE product_ingredients` muestra:
- PK `id` `int(10) unsigned`.
- UNIQUE `uniq_pi_product_ingredient (product_id, ingredient_id)`.
- FK `product_id` → `products(id)` ON DELETE CASCADE.
- FK `ingredient_id` → `ingredients(id)` ON DELETE CASCADE.

---

### Paso 2 — Migración `SeedRecipesPermissions`

**Archivo:** `config/Migrations/20260524130100_SeedRecipesPermissions.php`

Calcado de `SeedIngredientsPermissions`. Dos `INSERT ... SELECT ... WHERE NOT
EXISTS` con `module = 'recipes'`:

```sql
-- Roles no-admin: view + create + edit, sin delete por defecto.
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
SELECT r.id, 'recipes', 1, 1, 1, 0, '{$now}', '{$now}'
FROM roles r
WHERE r.is_admin = 0
  AND NOT EXISTS (SELECT 1 FROM permissions p WHERE p.role_id = r.id AND p.module = 'recipes');

-- Administrador: matriz completa (bypass cubre, pero por consistencia).
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
SELECT r.id, 'recipes', 1, 1, 1, 1, '{$now}', '{$now}'
FROM roles r
WHERE r.is_admin = 1
  AND NOT EXISTS (SELECT 1 FROM permissions p WHERE p.role_id = r.id AND p.module = 'recipes');
```

`down()`: `$this->execute("DELETE FROM permissions WHERE module = 'recipes'");`

**Acceptance:** tras `migrations migrate`, `SELECT DISTINCT module FROM
permissions` incluye `recipes`.

---

### Paso 3 — Constantes `RecipeConstants`

**Archivo:** `src/Constants/RecipeConstants.php`

Clase `final` en `App\Constants`:

```php
namespace App\Constants;

final class RecipeConstants
{
    /** Mínimo exclusivo: la cantidad debe ser > 0. */
    public const QUANTITY_MIN = 0;

    /** Tope alto pero no infinito; red de seguridad contra typos. */
    public const QUANTITY_MAX = 999999.999;

    /** Reusa la precisión de Ingredients (3 decimales). */
    public const QUANTITY_DECIMALS = IngredientConstants::STOCK_DECIMALS;
}
```

`use App\Constants\IngredientConstants;` arriba.

**Acceptance:** `composer cs-check` limpio sobre el archivo.

---

### Paso 4 — Entity `ProductIngredient`

**Archivo:** `src/Model/Entity/ProductIngredient.php`

Extiende `Cake\ORM\Entity`. `$_accessible` con whitelist (design §1.2):
`product_id`, `ingredient_id`, `quantity`, `product`, `ingredient`.
`$_virtual = ['line_cost']`.

Métodos:

- `getLineCost(): float`
  - Si `empty($this->ingredient)` → `return 0.0`.
  - `return round((float)$this->quantity * (float)$this->ingredient->unit_cost,
    IngredientConstants::COST_DECIMALS);`

- `_getLineCost(): float` → delega a `getLineCost()`.

- `getFormattedQuantity(): string` — formato como en
  `Ingredient::getFormattedStock`: `number_format` a `STOCK_DECIMALS`
  decimales con `','` decimal y `'.'` miles, recortar `,000` si exacto.
  Apenndear `' ' . $this->ingredient->unit` si está hidratado, o solo el
  número si no.

- `getFormattedLineCost(): string` →
  `'$' . number_format($this->getLineCost(), 0, ',', '.')` (consistente con
  `Ingredient::getFormattedUnitCost`).

**Acceptance:** instancia manual `new ProductIngredient(['quantity' => '200.000', 'ingredient' => new Ingredient(['unit' => 'gr', 'unit_cost' => '0.50'])])`
y verificar que `getLineCost()` → `100.0` y `getFormattedLineCost()` →
`'$100'`. `getFormattedQuantity()` → `'200 gr'`.

---

### Paso 5 — Table `ProductIngredientsTable`

**Archivo:** `src/Model/Table/ProductIngredientsTable.php`

Extiende `Cake\ORM\Table`. `initialize()`:
- `setTable('product_ingredients')`, `setPrimaryKey('id')`.
- `addBehavior('Timestamp')`.
- `belongsTo('Products', ['foreignKey' => 'product_id', 'joinType' => 'INNER'])`.
- `belongsTo('Ingredients', ['foreignKey' => 'ingredient_id', 'joinType' => 'INNER'])`.

`validationDefault(Validator $v)`:

```php
return $v
    ->notEmptyString('product_id', 'El producto es requerido')
    ->integer('product_id')
    ->notEmptyString('ingredient_id', 'El ingrediente es requerido')
    ->integer('ingredient_id')
    ->notEmptyString('quantity', 'La cantidad es requerida')
    ->numeric('quantity', 'La cantidad debe ser numérica')
    ->greaterThan('quantity', RecipeConstants::QUANTITY_MIN,
        'La cantidad debe ser mayor a cero')
    ->lessThanOrEqual('quantity', RecipeConstants::QUANTITY_MAX,
        'La cantidad excede el máximo permitido');
```

`buildRules(RulesChecker $rules)`:

```php
$rules->add($rules->existsIn(['product_id'], 'Products'), 'productExists');
$rules->add($rules->existsIn(['ingredient_id'], 'Ingredients'), 'ingredientExists');
$rules->add(
    $rules->isUnique(
        ['product_id', 'ingredient_id'],
        ['message' => 'Ese ingrediente ya está en la receta de este producto'],
    ),
    'uniqueProductIngredient',
);
return $rules;
```

Custom finders:

- `findForProduct(SelectQuery $query, array $options): SelectQuery`
  - Cast `$options['product_id']` a `int`.
  - `contain(['Ingredients'])`.
  - `where(['ProductIngredients.product_id' => $productId])`.
  - `orderBy(['Ingredients.name' => 'ASC'])`.

- `findForIngredient(SelectQuery $query, array $options): SelectQuery`
  - Cast `$options['ingredient_id']` a `int`.
  - `contain(['Products'])`.
  - `where(['ProductIngredients.ingredient_id' => $ingredientId])`.
  - `orderBy(['Products.name' => 'ASC'])`.

**Acceptance:** `vendor/bin/phpstan analyse src/Model/Table/ProductIngredientsTable.php`
limpio nivel 8.

---

### Paso 6 — Asociación en `ProductsTable`

**Archivo:** `src/Model/Table/ProductsTable.php`

Reemplazar las dos líneas de comentario (`// $this->hasMany('ProductIngredients');`
y `// $this->hasMany('OrderItems');`) por:

```php
$this->hasMany('ProductIngredients', [
    'foreignKey' => 'product_id',
    'dependent' => true,
    'cascadeCallbacks' => true,
]);

$this->belongsToMany('Ingredients', [
    'through' => 'ProductIngredients',
    'foreignKey' => 'product_id',
    'targetForeignKey' => 'ingredient_id',
    'joinTable' => 'product_ingredients',
]);

// Future associations declared when their tables exist:
// $this->hasMany('OrderItems');
```

> **Crítico:** `joinTable` debe ser exactamente `'product_ingredients'`
> (snake_case, plural) para matchear el nombre real de la tabla — CakePHP no
> inflexiona el orden alfabético "ingredients_products" como haría por
> default; lo evitamos pasándoselo explícito.

**Acceptance:** `php bin/cake.php` arranca sin errores (la app puede resolver
la tabla `ProductIngredients` que aún no existe en runtime hasta este punto;
las asociaciones son lazy).

---

### Paso 7 — Asociación en `IngredientsTable`

**Archivo:** `src/Model/Table/IngredientsTable.php`

Reemplazar el bloque de comentario que declara las asociaciones futuras
(`// $this->hasMany('ProductIngredients', [...]);` y
`// $this->hasMany('InventoryAdjustments', [...]);`) por:

```php
$this->hasMany('ProductIngredients', [
    'foreignKey' => 'ingredient_id',
    'dependent' => true,
    'cascadeCallbacks' => true,
]);

$this->belongsToMany('Products', [
    'through' => 'ProductIngredients',
    'foreignKey' => 'ingredient_id',
    'targetForeignKey' => 'product_id',
    'joinTable' => 'product_ingredients',
]);

// Future associations declared when their tables exist:
// $this->hasMany('InventoryAdjustments', [
//     'foreignKey' => 'ingredient_id',
//     'dependent' => true,
//     'cascadeCallbacks' => true,
// ]);
```

**Acceptance:** desde un script ad-hoc `bin/cake.php console`, ejecutar
`Cake\ORM\TableRegistry::getTableLocator()->get('Ingredients')->associations()->keys()`
debe incluir `'productingredients'` y `'products'`.

---

### Paso 8 — Service `RecipeService`

**Archivo:** `src/Service/RecipeService.php`

Clase `final` en `App\Service`. `use LocatorAwareTrait`. Constructor con
parámetro opcional `IngredientService` (design §4.6).

Forma de retorno estándar (similar a `IngredientService`):
`array{success: bool, line?: ProductIngredient, errors?: string[]}`.

**Imports clave:**
- `App\Constants\IngredientConstants`
- `App\Constants\RecipeConstants`
- `App\Model\Entity\ProductIngredient`
- `App\Service\IngredientService`
- `Cake\Datasource\ConnectionManager`
- `Cake\Log\Log`
- `Cake\ORM\Locator\LocatorAwareTrait`

**Métodos públicos:**

1. **`addLine(array $data): array`** — design §4.3.
   - Validar entrada:
     - `$productId = (int)($data['product_id'] ?? 0)`.
     - `$ingredientId = (int)($data['ingredient_id'] ?? 0)`.
     - `$quantity = (string)($data['quantity'] ?? '')`.
     - `$updateCost = !empty($data['update_ingredient_cost'])`.
     - `$newCost = $data['new_unit_cost'] ?? null`.
   - **Pre-validación de `new_unit_cost` cuando `updateCost === true`:**
     - Si `$newCost === null || $newCost === ''`:
       `return ['success' => false, 'errors' => ['Si vas a actualizar el costo, ingresá el nuevo valor.']];`
     - Si `!is_numeric($newCost)`:
       `return ['success' => false, 'errors' => ['El nuevo costo debe ser numérico.']];`
     - Si `(float)$newCost < 0`:
       `return ['success' => false, 'errors' => ['El nuevo costo no puede ser negativo.']];`
   - Tablas: `$piTable = $this->fetchTable('ProductIngredients');`
     `$ingredientsTable = $this->fetchTable('Ingredients');`
   - **Detectar línea existente** (regla "sobreescribir, no acumular"):
     ```php
     $existing = $piTable->find()
         ->where([
             'ProductIngredients.product_id' => $productId,
             'ProductIngredients.ingredient_id' => $ingredientId,
         ])
         ->first();
     ```
   - Construir entidad:
     ```php
     if ($existing !== null) {
         $line = $piTable->patchEntity($existing, ['quantity' => $quantity]);
     } else {
         $line = $piTable->newEntity([
             'product_id' => $productId,
             'ingredient_id' => $ingredientId,
             'quantity' => $quantity,
         ]);
     }
     ```
   - **Transacción** (envuelve cost update + line save):
     ```php
     $resultBox = ['success' => false, 'errors' => ['Error desconocido.']];
     $conn = ConnectionManager::get('default');
     $conn->transactional(function () use (..., &$resultBox): bool {
         if ($updateCost) {
             $ingredient = $ingredientsTable->find()
                 ->where(['Ingredients.id' => $ingredientId])
                 ->first();
             if ($ingredient === null) {
                 $resultBox = ['success' => false, 'errors' => ['Ingrediente no encontrado.']];
                 return false;
             }
             $upd = $this->ingredients->update($ingredient, ['unit_cost' => (string)$newCost]);
             if (!$upd['success']) {
                 $resultBox = ['success' => false, 'errors' => $upd['errors'] ?? ['No se pudo actualizar el costo.']];
                 return false;
             }
         }
         if (!$piTable->save($line)) {
             $resultBox = [
                 'success' => false,
                 'errors' => $this->flattenErrors($line->getErrors()),
                 'line' => $line,
             ];
             return false;
         }
         $resultBox = ['success' => true];
         return true;
     });
     ```
   - Si `$resultBox['success']`:
     - Re-leer línea con `contain(['Ingredients'])` para devolverla hidratada:
       `$hydrated = $piTable->get($line->id, contain: ['Ingredients']);`
     - Log:
       ```php
       Log::info('Recipe line saved: product={product_id} ingredient={ingredient_id} qty={qty} cost_updated={cu}', [
           'product_id' => $productId,
           'ingredient_id' => $ingredientId,
           'qty' => $hydrated->quantity,
           'cu' => $updateCost ? 'true' : 'false',
           'scope' => ['recipes'],
       ]);
       ```
     - `return ['success' => true, 'line' => $hydrated];`
   - Si no: devolver `$resultBox`.

2. **`updateLine(int $lineId, string|float $quantity): array`**
   - `$piTable = $this->fetchTable('ProductIngredients');`
   - `try { $line = $piTable->get($lineId); } catch (RecordNotFoundException) { return ['success' => false, 'errors' => ['La línea de receta no existe.']]; }`
   - `$line = $piTable->patchEntity($line, ['quantity' => (string)$quantity]);`
   - `if (!$piTable->save($line)) { return ['success' => false, 'errors' => $this->flattenErrors($line->getErrors()), 'line' => $line]; }`
   - Log info.
   - `return ['success' => true, 'line' => $piTable->get($line->id, contain: ['Ingredients'])];`

3. **`removeLine(int $lineId): array`**
   - `$piTable = $this->fetchTable('ProductIngredients');`
   - `try { $line = $piTable->get($lineId); } catch (RecordNotFoundException) { return ['success' => false, 'errors' => ['La línea de receta no existe.']]; }`
   - `$pid = $line->product_id; $iid = $line->ingredient_id;`
   - `if (!$piTable->delete($line)) { return ['success' => false, 'errors' => ['No se pudo eliminar la línea de receta.']]; }`
   - `Log::warning('Recipe line removed: product={pid} ingredient={iid}', [...]);`
   - `return ['success' => true];`

4. **`getRecipeFor(int $productId): array`**
   - `return $this->fetchTable('ProductIngredients')->find('forProduct', product_id: $productId)->toList();`

5. **`calculateRecipeCost(int $productId): float`**
   - Loopear líneas de `getRecipeFor`, acumular `$line->getLineCost()`.
   - `return round($total, IngredientConstants::COST_DECIMALS);`

6. **`hasRecipe(int $productId): bool`**
   - `return $this->fetchTable('ProductIngredients')->exists(['product_id' => $productId]);`

7. **`buildDecrementPlan(int $productId, int $unitsSold): array`** — design §4.5.
   - Si `$unitsSold <= 0`: `return [];`
   - `$lines = $this->getRecipeFor($productId);`
   - Si vacío: `return [];`
   - Mapear cada línea a:
     ```php
     [
         'ingredient_id' => (int)$line->ingredient_id,
         'quantity' => number_format(
             round((float)$line->quantity * $unitsSold, RecipeConstants::QUANTITY_DECIMALS),
             RecipeConstants::QUANTITY_DECIMALS, '.', ''
         ),
     ]
     ```
   - `return $plan;`

**Helper privado:** `flattenErrors(array $errors): array` — calcado del de
`IngredientService::flattenErrors()`.

**Acceptance:** `vendor/bin/phpstan analyse src/Service/RecipeService.php`
limpio nivel 8.

---

### Paso 9 — Extender `AppController` con `actionModuleMap`

**Archivo:** `src/Controller/AppController.php`

1. Agregar `'Recipes' => 'recipes'` al final del array `$controllerModuleMap`.

2. Introducir nuevo array protegido (después de `$publicActions`):

   ```php
   /**
    * Override per-acción del mapeo a módulo. Las subclases declaran acá las
    * acciones cuyo permiso debe chequearse contra un módulo distinto al que
    * corresponde al controller (ej: una acción de ProductsController que
    * realmente pertenece al módulo 'recipes' a efectos de RBAC).
    *
    * Vacío por default = sin override; cae al $controllerModuleMap.
    *
    * @var array<string, string> Mapa action => moduleKey.
    */
   protected array $actionModuleMap = [];
   ```

3. En `beforeFilter()`, sección "4. Enforce permission", reemplazar:

   ```php
   $module = $this->controllerModuleMap[$controller] ?? null;
   ```

   por:

   ```php
   $module = $this->actionModuleMap[$action]
       ?? $this->controllerModuleMap[$controller]
       ?? null;
   ```

**Backward compat:** ningún controller existente declara `actionModuleMap`,
así que `?? null` siempre cae al lookup actual.

**Acceptance:**
- Loguearse como usuario no-admin sin `recipes.view` y visitar
  `/products/recipe/1` → debería retornar 403 (no 200), demostrando que el
  override per-acción funciona.
- Loguearse como mismo usuario y visitar `/products` (acción `index`) → 200,
  demostrando que las acciones sin override siguen usando `products`.

---

### Paso 10 — `RecipesController::index`

**Archivo:** `src/Controller/RecipesController.php`

Extiende `AppController`. Solo `index()`.

```php
namespace App\Controller;

use Cake\ORM\Query\SelectQuery;

class RecipesController extends AppController
{
    public array $paginate = [
        'limit' => 15,
        'maxLimit' => 15,
        'order' => ['Products.name' => 'ASC'],
        'sortableFields' => ['name', 'price'],
    ];

    public function index(): void
    {
        $filters = $this->_currentFilters();
        $query = $this->_buildIndexQuery($filters);
        $products = $this->paginate($query);

        $this->set(compact('products', 'filters'));
        $this->set('breadcrumbs', [['label' => 'Recetas']]);
    }

    /**
     * @return array{q: string, has_recipe: string}
     */
    protected function _currentFilters(): array
    {
        $allowedHasRecipe = ['all', 'with', 'without'];
        $hr = (string)$this->request->getQuery('has_recipe', 'all');
        return [
            'q' => trim((string)$this->request->getQuery('q', '')),
            'has_recipe' => in_array($hr, $allowedHasRecipe, true) ? $hr : 'all',
        ];
    }

    protected function _buildIndexQuery(array $filters): SelectQuery
    {
        $query = $this->fetchTable('Products')
            ->find()
            ->contain(['ProductIngredients' => ['Ingredients']]);

        if ($filters['q'] !== '') {
            $like = '%' . $filters['q'] . '%';
            $query->where(['OR' => [
                'Products.name LIKE' => $like,
                'Products.code LIKE' => $like,
            ]]);
        }
        if ($filters['has_recipe'] === 'with') {
            $query->matching('ProductIngredients');
            $query->group(['Products.id']);
        } elseif ($filters['has_recipe'] === 'without') {
            $sub = $this->fetchTable('ProductIngredients')->find()
                ->select(['ProductIngredients.product_id'])
                ->distinct();
            $query->where(['Products.id NOT IN' => $sub]);
        }

        return $query;
    }
}
```

> **Nota performance** (design §10.5): `contain(['ProductIngredients' =>
> ['Ingredients']])` está bien para 15 productos por página. Si crece el
> catálogo se introduce columna derivada cacheada (fuera de alcance Fase 1).

**Acceptance:** GET `/recipes` con login Admin renderiza el listado paginado.

---

### Paso 11 — Acciones nested en `ProductsController`

**Archivo:** `src/Controller/ProductsController.php`

1. **Imports:** sumar `use App\Service\RecipeService;`.

2. **Dependencia + actionModuleMap.** En propiedades:

   ```php
   private ProductService $recipeService; // existe ya productService
   private RecipeService $recipeService;

   /**
    * @var array<string, string>
    */
   protected array $actionModuleMap = [
       'recipe' => 'recipes',
       'addRecipeLine' => 'recipes',
       'updateRecipeLine' => 'recipes',
       'removeRecipeLine' => 'recipes',
   ];
   ```

3. **`initialize()`:** sumar `$this->recipeService = new RecipeService();`.

4. **Acción `recipe(int $id): void`:**
   ```php
   $product = $this->Products->get($id);
   $lines = $this->recipeService->getRecipeFor($id);
   $cost = $this->recipeService->calculateRecipeCost($id);

   // Ingredientes disponibles = todos menos los ya presentes (design §10.4).
   $usedIds = array_map(fn($l) => (int)$l->ingredient_id, $lines);
   $ingredientsQuery = $this->fetchTable('Ingredients')->find('nameList');
   if (!empty($usedIds)) {
       $ingredientsQuery->where(['Ingredients.id NOT IN' => $usedIds]);
   }
   $availableIngredients = $ingredientsQuery->toArray();

   // Para sufijo dinámico del input y pre-poblado de costo (design §7.1 capa 4):
   $ingredientsMeta = $this->fetchTable('Ingredients')->find()
       ->select(['id', 'unit', 'unit_cost'])
       ->where(empty($usedIds) ? [] : ['Ingredients.id NOT IN' => $usedIds])
       ->all()
       ->indexBy('id')
       ->toArray();

   $this->set(compact('product', 'lines', 'cost', 'availableIngredients', 'ingredientsMeta'));
   $this->set('breadcrumbs', [
       ['label' => 'Productos', 'url' => ['action' => 'index']],
       ['label' => $product->name, 'url' => ['action' => 'view', $id]],
       ['label' => 'Receta'],
   ]);
   ```

5. **Acción `addRecipeLine(int $id)`:**
   ```php
   $this->request->allowMethod(['post']);
   $data = $this->request->getData();
   $data['product_id'] = $id;
   $data['update_ingredient_cost'] = !empty($data['update_ingredient_cost']);

   $result = $this->recipeService->addLine($data);
   if ($result['success']) {
       $this->Flash->success('Ingrediente agregado a la receta.');
   } else {
       foreach ($result['errors'] ?? ['No se pudo agregar el ingrediente.'] as $msg) {
           $this->Flash->error($msg);
       }
   }
   return $this->redirect(['action' => 'recipe', $id]);
   ```

6. **Acción `updateRecipeLine(int $id, int $lineId)`:**
   ```php
   $this->request->allowMethod(['post']);
   $quantity = (string)$this->request->getData('quantity', '');
   $result = $this->recipeService->updateLine($lineId, $quantity);
   if ($result['success']) {
       $this->Flash->success('Cantidad actualizada.');
   } else {
       $this->Flash->error($result['errors'][0] ?? 'No se pudo actualizar la cantidad.');
   }
   return $this->redirect(['action' => 'recipe', $id]);
   ```

7. **Acción `removeRecipeLine(int $id, int $lineId)`:**
   ```php
   $this->request->allowMethod(['post']);
   $result = $this->recipeService->removeLine($lineId);
   if ($result['success']) {
       $this->Flash->success('Ingrediente eliminado de la receta.');
   } else {
       $this->Flash->error($result['errors'][0] ?? 'No se pudo eliminar la línea.');
   }
   return $this->redirect(['action' => 'recipe', $id]);
   ```

8. **`_actionToPermission()`:** extender el `match`:
   ```php
   return match ($action) {
       'toggleActive' => 'edit',
       'recipe' => 'view',
       'addRecipeLine' => 'create',
       'updateRecipeLine' => 'edit',
       'removeRecipeLine' => 'delete',
       default => parent::_actionToPermission($action),
   };
   ```

**Acceptance:** `composer cs-check` y `vendor/bin/phpstan analyse` limpios
sobre el controller.

---

### Paso 12 — `AuthorizationService::MODULES`

**Archivo:** `src/Service/AuthorizationService.php`

Agregar `'recipes' => 'Recetas'` al final del array `MODULES` (después de
`'ingredients' => 'Ingredientes'`).

**Acceptance:** ningún otro cambio en el archivo. `composer cs-check` limpio.

---

### Paso 13 — Sidebar

**Archivo:** `src/View/Helper/SidebarHelper.php`

Insertar **después** del item `ingredients` en `$items`:

```php
[
    'module' => 'recipes',
    'label' => 'Recetas',
    'icon' => 'bi-journal-text',
    'url' => ['controller' => 'Recipes', 'action' => 'index'],
],
```

(Eleccion de icono `bi-journal-text` — sugiere "libro de recetas". Alternativa
documentada `bi-card-list` si quedara visualmente muy similar a Pedidos
cuando exista; revisable en review visual.)

**Acceptance:** loguearse como Administrador → ver "Recetas" en el sidebar
entre "Ingredientes" y "Clientes".

---

### Paso 14 — Rutas custom

**Archivo:** `config/routes.php`

Agregar **antes** de `$builder->fallbacks()` (después del bloque de
`deliveries/toggle-active`):

```php
// Edición de receta (lectura).
$builder->connect(
    '/products/recipe/{id}',
    ['controller' => 'Products', 'action' => 'recipe'],
    ['id' => '\d+', 'pass' => ['id']]
);

// Mutaciones sobre líneas (solo POST).
$builder->connect(
    '/products/add-recipe-line/{id}',
    ['controller' => 'Products', 'action' => 'addRecipeLine'],
    ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST']
);
$builder->connect(
    '/products/update-recipe-line/{id}/{lineId}',
    ['controller' => 'Products', 'action' => 'updateRecipeLine'],
    ['id' => '\d+', 'lineId' => '\d+', 'pass' => ['id', 'lineId'], '_method' => 'POST']
);
$builder->connect(
    '/products/remove-recipe-line/{id}/{lineId}',
    ['controller' => 'Products', 'action' => 'removeRecipeLine'],
    ['id' => '\d+', 'lineId' => '\d+', 'pass' => ['id', 'lineId'], '_method' => 'POST']
);
```

> Las rutas usan `kebab-case` (convención del proyecto §4.12) y dejan el
> parámetro `id` siempre primero por consistencia con las otras rutas custom.

**Acceptance:** `bin/cake.php routes` lista las cuatro rutas con sus métodos
HTTP correctos.

---

### Paso 15 — Template `templates/Recipes/index.php`

Design §7.2. Estructura (cabeza, filtros, tabla, paginación):

- **Header:** `dr-page-header` con `h1.dr-page-title` "Recetas". Sin botón
  primary (no se crea una receta global; se crea desde Productos).
- **Card de filtros** (form GET con altura 40px):
  - Input `q` con placeholder "Buscar por nombre o código".
  - Select `has_recipe` con opciones `all="Todas"`, `with="Con receta"`,
    `without="Sin receta"`.
  - Botón "Filtrar" `btn-secondary`. Link "Limpiar" cuando hay filtros activos.
- **Tabla** (15 ítems máx):

| Columna | Width | Contenido |
|---|---|---|
| Producto | auto | `Html->link(h($p->name), ['controller'=>'Products','action'=>'view',$p->id])` + badge `Inactivo` si `!is_active` |
| Precio | 120px | `h($p->getFormattedPrice())` |
| Ingredientes | 130px | `count($p->product_ingredients)` o badge `badge badge-soft-info` "Sin receta" |
| Costo de receta | 140px | Sumar `getLineCost()` en PHP o llamar `RecipeService` cacheado; mostrar formateado. Si vacío, "—" |
| Margen | 120px | `(price - cost)` con clase color según ratio (verde/amarillo/rojo, design §7.6) |
| Acciones | 140px | `btn btn-secondary btn-sm` "Editar receta" → `['controller'=>'Products','action'=>'recipe',$p->id]` |

- **Empty state** estándar (igual familia que Ingredientes).
- Pie: `<?= $this->element('pagination') ?>`.

**Helper local en el template** (no en helper compartido, evita over-engineering
Fase 1): `function _recipeCostSum($product): float` para sumar
`getLineCost()` sobre `$product->product_ingredients`. Documentar
explícitamente que esto evita una segunda query por fila.

**Acceptance:** render sin warnings; columnas alineadas; badge "Sin receta"
visible para productos sin líneas.

---

### Paso 16 — Template `templates/Products/recipe.php`

Design §7.1. **Pantalla primaria del módulo.** Cinco capas:

#### Capa 1 — Header
- Breadcrumbs vía `$breadcrumbs` (ya seteados en controller).
- `h1.dr-page-title` "Receta — `<?= h($product->name) ?>`".
- Subtítulo `<code>` con `$product->code` si existe.
- Badge `badge badge-soft-warning` "Inactivo" si `!$product->is_active`
  (recordatorio visible, design §8 caso "Receta de producto inactivo").
- Acción secundaria a la derecha: `btn-secondary` "Volver al producto"
  → `['action' => 'view', $product->id]`.

#### Capa 2 — Stat-cards (grid responsive)
Card 1: "Precio de venta" → `$product->getFormattedPrice()`.
Card 2: "Costo total de receta" → si `count($lines) === 0`, `$0` con badge
        `badge-soft-info` "Sin receta"; si no, `'$' . number_format($cost, 0, ',', '.')`.
Card 3: "Margen estimado" → si vacío `'—'`; si no, calcular `(float)$product->price - $cost`
        con badge color: verde si ratio ≥ 50%, amarillo si 20-50%, rojo si < 20%.

#### Capa 3 — Tabla de líneas existentes (si `count($lines) > 0`)
Card que contiene tabla con columnas:
| Ingrediente | Unidad | Cantidad por unidad | Costo unitario | Costo de línea | Acciones |
|---|---|---|---|---|---|

- **Cantidad** = mini-form inline `Form->create` apuntando a
  `['action' => 'updateRecipeLine', $product->id, $line->id]` con
  `input[type=number] step="0.001" min="0.001"` + botón "Actualizar"
  (`btn btn-light btn-sm`). Cada form es independiente, CSRF inyectado
  automáticamente.
- **Acciones** = `Form->postLink('<i class="bi bi-trash"></i>', ['action' =>
  'removeRecipeLine', $product->id, $line->id], ['escape' => false, 'class' =>
  'btn btn-icon text-danger', 'confirm' => '¿Eliminar este ingrediente de la receta?'])`.
- Fila final (footer): "Total" con la suma formateada (reusar `$cost` ya calculado).

#### Capa 4 — Form "Agregar ingrediente"
Render del sub-template:
`<?= $this->element('Recipes/_add_line_form', ['product' => $product, 'availableIngredients' => $availableIngredients, 'ingredientsMeta' => $ingredientsMeta]) ?>`

#### Capa 5 — Empty state
Si `count($lines) === 0`, reemplazar la capa 3 con un card con copy:
> **Este producto no tiene receta.**
> Agregá ingredientes para que el inventario se descuente automáticamente al
> vender este producto.

La capa 4 sigue visible debajo en ambos casos.

**Acceptance:** las 5 capas renderizan sin warnings; el empty state aparece
para producto sin líneas; la tabla de líneas aparece cuando hay líneas.

---

### Paso 17 — Template `templates/element/Recipes/_add_line_form.php`

Sub-form para "Agregar ingrediente":

- `Form->create(null, ['url' => ['controller' => 'Products', 'action' => 'addRecipeLine', $product->id]])`.
- Fila 1 (todos 40px):
  - `Form->select('ingredient_id', $availableIngredients, ['empty' => 'Seleccionar ingrediente...', 'class' => 'form-select', 'required' => true])`.
    Para soportar `data-unit`/`data-cost` por option, generar manualmente el
    `<select>` iterando `$availableIngredients` y leyendo
    `$ingredientsMeta[$id]` para los `data-*`.
  - `Form->control('quantity', ['type' => 'number', 'step' => '0.001', 'min' => '0.001', 'label' => 'Cantidad', 'required' => true])`.
  - `Form->button('Agregar', ['class' => 'btn btn-primary'])`.
- Fila 2 (sub-form colapsable, oculto por default vía `.dr-toggle`):
  - Checkbox `update_ingredient_cost` con label "Actualizar costo unitario
    del ingrediente al guardar".
  - Input `new_unit_cost` `type="number"` `step="0.01"` `min="0"` con `style="display:none"`
    inicialmente; JS lo muestra cuando el checkbox queda activo y pre-pobla con
    `data-cost` del option seleccionado.
  - Helper text "Este nuevo costo aplicará a todas las recetas que usan este
    ingrediente, no solo a esta línea."

JS mínimo inline (sin build step): `addEventListener('change')` en el select
para actualizar el sufijo de unidad y el placeholder del input de costo;
listener en el checkbox para mostrar/ocultar el input de costo.

**Acceptance:** sub-form renderiza; cuando se cambia el select, el placeholder
del input quantity refleja la unidad; al marcar el checkbox aparece el input
de costo pre-poblado con el costo actual.

---

### Paso 18 — Fixtures de test

**Archivos:**

1. `tests/Fixture/ProductIngredientsFixture.php` — 3 filas mínimas:
   - product_id 1 + ingredient_id 1, quantity 200.000.
   - product_id 1 + ingredient_id 2, quantity 1.000.
   - product_id 2 + ingredient_id 1, quantity 100.000.

2. `tests/Fixture/ProductsFixture.php` — verificar si existe en
   `tests/Fixture/`; si **no** existe, crear seed mínimo con 2 productos
   activos (`id=1 'Hamburguesa'`, `id=2 'Pizza'`). Si existe, NO modificarlo.

Reusar `IngredientsFixture.php` ya presente.

**Acceptance:** `vendor/bin/phpunit --list-tests tests/TestCase/Model/Table/ProductIngredientsTableTest.php`
no falla por fixture missing.

---

### Paso 19 — `ProductIngredientTest` (Entity)

**Archivo:** `tests/TestCase/Model/Entity/ProductIngredientTest.php`

`use Cake\TestSuite\TestCase`. Sin fixtures (entity puro).

Casos:
- `testGetLineCostReturnsZeroWhenIngredientNotHydrated`.
- `testGetLineCostReturnsQuantityTimesUnitCost` — instancia con
  `quantity='200.000'` e `ingredient` con `unit_cost='0.50'` → 100.0.
- `testGetLineCostRoundsToCostDecimals` — `quantity='0.333'`, `unit_cost='3'` → 1.00.
- `testGetFormattedQuantityWithExactInteger` — `quantity='200.000'`, unit `'gr'` → `'200 gr'`.
- `testGetFormattedQuantityWithDecimals` — `quantity='1.500'`, unit `'kg'` → `'1,5 kg'`.
- `testGetFormattedQuantityWithoutIngredient` — sin ingrediente → solo número.
- `testGetFormattedLineCostFormat` — `quantity='200.000'`, `unit_cost='0.50'` → `'$100'`.
- `testLineCostVirtualPropertyAccessible` — `$line->line_cost` accesible.

---

### Paso 20 — `ProductIngredientsTableTest`

**Archivo:** `tests/TestCase/Model/Table/ProductIngredientsTableTest.php`

`use Cake\TestSuite\TestCase`. Fixtures: `app.Products`, `app.Ingredients`,
`app.ProductIngredients`.

Casos:
- `testValidationRequiresProductId`.
- `testValidationRequiresIngredientId`.
- `testValidationRejectsNonNumericQuantity`.
- `testValidationRejectsZeroQuantity` — `greaterThan(0)`.
- `testValidationRejectsNegativeQuantity`.
- `testValidationRejectsQuantityAboveMax` — `> QUANTITY_MAX`.
- `testRulesRejectsMissingProduct` — `product_id=999` → `existsIn` falla.
- `testRulesRejectsMissingIngredient`.
- `testRulesRejectsDuplicatePair` — insertar dupe `(1,1)` → falla con mensaje
  en español.
- `testFindForProductFiltersAndOrders` — `find('forProduct', product_id: 1)`
  devuelve solo las líneas del product 1 ordenadas por nombre de ingrediente.
- `testFindForIngredientFiltersAndOrders`.

---

### Paso 21 — `RecipeServiceTest`

**Archivo:** `tests/TestCase/Service/RecipeServiceTest.php`

`use Cake\TestSuite\TestCase`. Fixtures: `app.Products`, `app.Ingredients`,
`app.ProductIngredients`. `setUp()`: `$this->service = new RecipeService();`

Casos (design §9 lista completa):
- `testAddLineSuccessReturnsHydratedLine`.
- `testAddLineRejectsMissingProduct`.
- `testAddLineRejectsMissingIngredient`.
- `testAddLineRejectsZeroQuantity`.
- `testAddLineOverwritesExistingPair` — agregar `(1,1)` con qty `999`,
  verificar que la fila existente queda con `quantity=999.000` y no se crea
  una nueva.
- `testAddLineWithCostUpdateSucceeds` — `update_ingredient_cost=true`,
  `new_unit_cost='1.25'` — verificar línea persiste y `unit_cost` del
  ingrediente queda en `1.25`.
- `testAddLineWithCostUpdateMissingNewCostReturnsError` — y verificar que la
  línea NO se inserta.
- `testAddLineWithCostUpdateNegativeNewCostReturnsError`.
- `testAddLineCostUpdateRollsBackOnLineFailure` — forzar fallo en línea
  (qty inválida) y verificar que `unit_cost` del ingrediente NO cambió.
- `testUpdateLineSuccess`.
- `testUpdateLineRejectsMissingId` — `lineId=999`.
- `testUpdateLineRejectsZeroQuantity`.
- `testRemoveLineSuccess`.
- `testRemoveLineRejectsMissingId`.
- `testGetRecipeForReturnsHydratedListOrderedByName`.
- `testGetRecipeForEmptyWhenNoLines`.
- `testCalculateRecipeCostSums` — fixture conocido → suma esperada.
- `testCalculateRecipeCostZeroWhenEmpty`.
- `testHasRecipeTrueFalse`.
- `testBuildDecrementPlanScalesByUnitsSold` — `unitsSold=2` → cantidades
  duplicadas como string decimal con 3 decimales.
- `testBuildDecrementPlanEmptyWhenNoRecipe`.
- `testBuildDecrementPlanEmptyWhenUnitsSoldZero`.

---

### Paso 22 — `RecipesControllerTest`

**Archivo:** `tests/TestCase/Controller/RecipesControllerTest.php`

`use IntegrationTestTrait`. Fixtures: `Users`, `Roles`, `Permissions`,
`Products`, `Ingredients`, `ProductIngredients`.

Casos:
- `testIndexRedirectsAnonymous` — sin login → 302 a `/login`.
- `testIndexForbiddenWithoutPermission` — login con rol no-admin sin
  `recipes.view` → 403.
- `testIndexOkWithPermission`.
- `testIndexAsAdministratorBypass`.
- `testIndexFilterHasRecipeWith` — `?has_recipe=with` solo muestra productos
  con líneas (verificar count).
- `testIndexFilterHasRecipeWithout`.
- `testIndexSearchFilter`.

---

### Paso 23 — `ProductsControllerRecipeTest`

**Archivo:** `tests/TestCase/Controller/ProductsControllerRecipeTest.php`

Solo las 4 acciones nested. `IntegrationTestTrait`. Mismos fixtures.

Casos:
- `testRecipeRedirectsAnonymous`.
- `testRecipeForbiddenWithProductsViewButWithoutRecipesView` — **clave para
  validar el override `actionModuleMap`.** Crear rol con `products.view=1,
  recipes.view=0`, login, GET `/products/recipe/1` → 403.
- `testRecipeOkWithRecipesView` — rol con `recipes.view=1` → 200,
  variables `product`, `lines`, `cost` presentes.
- `testAddRecipeLineForbiddenWithoutCreate`.
- `testAddRecipeLineRequiresPost` — GET → 405.
- `testAddRecipeLineSuccessFlashesAndRedirects` — POST válido → 302 +
  flash success + nueva fila visible al volver a la pantalla.
- `testAddRecipeLineWithCostUpdateAffectsIngredient` — verificar
  `ingredient.unit_cost` cambió en DB.
- `testUpdateRecipeLineForbiddenWithoutEdit`.
- `testUpdateRecipeLineSuccess`.
- `testRemoveRecipeLineForbiddenWithoutDelete`.
- `testRemoveRecipeLineSuccess` — POST → línea borrada.
- `testAllRecipeActionsBypassedByAdministrator` — administrador siempre 200/302.

> **CSRF en tests:** usar `$this->enableCsrfToken()` y
> `$this->enableSecurityToken()` en `setUp()` (patrón estándar CakePHP 5).

---

### Paso 24 — Aplicar y volcar schema

```bash
php bin/cake.php migrations migrate
php bin/cake.php migrations dump
```

**Acceptance:** ambos comandos exitosos; `schema-dump-default.lock`
actualizado para incluir `product_ingredients`.

---

## 3. Test plan

> **IMPORTANTE:** el design §9 dice "no tests" siguiendo la memoria del
> usuario, pero la **instrucción explícita del prompt actual SOBRESCRIBE
> esa preferencia**: tests REQUERIDOS. Los archivos se crean siempre. La
> ejecución puede estar bloqueada por falta de `pdo_sqlite`/`bcmath` en el
> host de desarrollo — en ese caso, los tests quedan listos para correr en
> CI o cuando el entorno se arregle.

### Archivos a crear

| Tipo | Path |
|---|---|
| Fixture | `tests/Fixture/ProductIngredientsFixture.php` |
| Fixture (cond.) | `tests/Fixture/ProductsFixture.php` (solo si no existe) |
| Entity test | `tests/TestCase/Model/Entity/ProductIngredientTest.php` |
| Table test | `tests/TestCase/Model/Table/ProductIngredientsTableTest.php` |
| Service test | `tests/TestCase/Service/RecipeServiceTest.php` |
| Controller test | `tests/TestCase/Controller/RecipesControllerTest.php` |
| Controller test | `tests/TestCase/Controller/ProductsControllerRecipeTest.php` |

### Comandos de verificación

```bash
composer cs-check && composer cs-fix
vendor/bin/phpstan analyse
vendor/bin/psalm
php vendor/bin/phpunit tests/TestCase/Model/Entity/ProductIngredientTest.php
php vendor/bin/phpunit tests/TestCase/Model/Table/ProductIngredientsTableTest.php
php vendor/bin/phpunit tests/TestCase/Service/RecipeServiceTest.php
php vendor/bin/phpunit tests/TestCase/Controller/RecipesControllerTest.php
php vendor/bin/phpunit tests/TestCase/Controller/ProductsControllerRecipeTest.php
php vendor/bin/phpunit
```

> Si `pdo_sqlite`/`bcmath` no están disponibles, los tests de Table/Service/
> Controller fallarán al inicializar la conexión. La solución es a nivel de
> entorno (`pecl install` o equivalente), **no** del código del módulo. Los
> tests de Entity (puros, sin DB) **deberían correr** aun en ese entorno
> limitado.

---

## 4. Verification checklist

Ejecutar todo lo siguiente antes de marcar el módulo como hecho:

- [ ] `php bin/cake.php migrations migrate` corre sin errores. Tabla
      `product_ingredients` creada con FK CASCADE y unique compuesto.
- [ ] `php bin/cake.php migrations dump` actualiza el lock sin warnings.
- [ ] `composer cs-check` limpio sobre todos los archivos nuevos/modificados.
- [ ] `composer cs-fix` no produce cambios adicionales (idempotente).
- [ ] `vendor/bin/phpstan analyse` nivel 8 limpio sobre los nuevos archivos.
- [ ] `vendor/bin/psalm` sin nuevos errores.
- [ ] Servidor dev levanta: `php bin/cake.php server -p 8765`.
- [ ] **Smoke manual** logueado como Administrador:
  1. Crear un ingrediente "Carne molida" (gr, stock 1500, costo $25) si no existe.
  2. Crear un ingrediente "Pan" (unidad, stock 50, costo $200) si no existe.
  3. `/products` → click "Receta" (link aún no existe en index productos;
     navegar directo a `/products/recipe/{id}` de un producto existente).
  4. Empty state visible: "Este producto no tiene receta."
  5. Seleccionar "Carne molida" en el select, ingresar quantity 200 → "Agregar".
  6. Línea aparece en la tabla con costo `$5.000` y total reflejado.
  7. Editar cantidad inline a 250 → flash "Cantidad actualizada" + costo
     se actualiza.
  8. Agregar "Pan" con quantity 1, marcar "Actualizar costo del ingrediente"
     con `new_unit_cost=180` → verificar que el ingrediente Pan ahora tiene
     `unit_cost=180`.
  9. Eliminar línea de Pan → flash "Ingrediente eliminado de la receta",
     desaparece, total recalculado.
  10. `/recipes` → producto aparece con conteo "1 ingrediente", costo y
      margen.
  11. Filtrar `?has_recipe=without` → muestra solo productos sin líneas.
- [ ] **Smoke RBAC (override `actionModuleMap`):** loguearse como rol no-admin
      con `products.view=1, recipes.view=0` → GET `/products/recipe/{id}`
      retorna 403 (mientras `/products` retorna 200).
- [ ] **Smoke RBAC delete:** rol con `recipes.delete=0` no ve botón eliminar
      y POST directo a `/products/remove-recipe-line/{id}/{lineId}` retorna 403.
- [ ] **Smoke navegación:** item "Recetas" aparece en sidebar entre
      "Ingredientes" y "Clientes" para usuarios con `recipes.view`.
- [ ] **Smoke FK cascade:** crear líneas para producto X, borrar producto X
      desde `/products/delete/{id}` → líneas en `product_ingredients`
      desaparecen automáticamente. Repetir borrando un ingrediente.

---

## 5. Risks / gotchas

1. **`actionModuleMap` es un patrón NUEVO en `AppController`.** Es la primera
   vez que un controller necesita chequear acciones puntuales contra un
   módulo distinto al suyo. El cambio es **backward-compatible** (default
   `[]`), pero introduce un punto de extensión que se va a reusar (Abonos
   bajo CxC, ítems bajo Pedidos). Documentar el patrón en el PR para que el
   próximo módulo lo siga sin reinventarlo. Si genera dudas, la alternativa
   más conservadora es crear `RecipesController` con `view/add/edit/delete`
   que reciba `product_id` por querystring — más URLs feas, pero cero
   cambios en `AppController`. **Esta es la opción adoptada (A) por las
   razones de §6.2 del design.**

2. **`belongsToMany.joinTable` debe ser literal `'product_ingredients'`.**
   CakePHP infiere por default un nombre alfabético `ingredients_products`,
   que NO coincide con la tabla real. Sin `'joinTable' => 'product_ingredients'`
   las queries fallan. Aplica en ambas tablas (Products e Ingredients).

3. **Composite unique index + `isUnique` rule duplicado por diseño.** El
   índice de DB previene la inserción a nivel de motor; el rule de CakePHP
   da un mensaje en español ANTES de tocar la DB y permite manejarlo en el
   service. **Ambos son necesarios**: el rule sin índice deja una ventana
   de race condition; el índice sin rule retorna un error genérico de DB.

4. **FK signed/unsigned mismatch.** `products.id` e `ingredients.id` son
   `unsigned` (creadas con `'signed' => false`). `product_ingredients` debe
   crearse igual (`'signed' => false`) y declarar las FK con tipos
   `integer` no firmados. Mismatch → MySQL rechaza la FK con error críptico.

5. **NO usar `bcmath`.** Memoria del usuario explícita: no bcmath. Toda
   matemática usa `(float)` cast + `round()` + `number_format()`. Aplica en
   `RecipeService::buildDecrementPlan` y `Entity::getLineCost`.
   `IngredientService::adjustStock` (preexistente) ya migró a `round` —
   no introducir regresiones.

6. **"Excluir ingredientes ya presentes" en `recipe.php`.** El controller
   calcula `$usedIds` y filtra con `notIn`. UX: el operador no puede
   "agregar" un ingrediente que ya está en la receta — debe usar el input
   inline de la tabla para actualizar. Documentado en design §10.4 con
   tradeoff. Si en review se prefiere unificar, sacar el `notIn` y dejar
   que el service sobreescriba (regla §4.3 ya lo soporta).

7. **Cascade delete vive en la FK de DB.** No emular cascade en PHP. La FK
   `ON DELETE CASCADE` se encarga; la asociación `hasMany ... dependent =>
   true, cascadeCallbacks => true` complementa para que los `afterDelete`
   callbacks de `ProductIngredient` (si se agregan en el futuro) corran.

8. **CSRF en mini-form inline de cantidad.** Cada `Form->create` en la tabla
   de líneas inyecta su propio token CSRF. NO compartir el token entre
   forms (CakePHP los rota). Si la tabla tiene 10 líneas, hay 10 forms
   independientes — comportamiento esperado, no es bug.

9. **Test `testRecipeForbiddenWithProductsViewButWithoutRecipesView` es el
   único que valida `actionModuleMap`.** Si este test falla, hay regresión
   en el override. Es el "test smoke" del patrón nuevo. Mantener obligatorio.

10. **Performance de `Recipes::index`.** `contain(['ProductIngredients' =>
    ['Ingredients']])` para 15 productos × ~10 ingredientes promedio = ~150
    filas adicionales. Aceptable hoy. Cuando el catálogo crezca >500
    productos, considerar columna `recipe_cost_cached` con invalidación
    en `afterSave`/`afterDelete` de `ProductIngredient`. Fuera de alcance
    Fase 1.

11. **`schema-dump-default.lock` ya viene modificado en el working tree.**
    Mismo riesgo que en plan 01: `git status` muestra `M` en el lock por
    trabajo previo. Hacer `migrations dump` UNA SOLA VEZ al final del módulo
    y commitear el lock con los cambios de este plan + los pre-existentes.

12. **Entity virtual `line_cost` y serialización JSON.** Como
    `_getLineCost()` depende de `$this->ingredient` hidratado, serializar
    una `ProductIngredient` sin hidratar (ej. en una respuesta JSON futura)
    devolverá `0.0`. Es comportamiento conservador (no rompe) pero
    confusivo si alguien lo consume sin saberlo. Documentar en doc-comment
    del método.

13. **`get(... contain: ...)` requiere PHP 8.1+ named arguments y CakePHP 5
    `get()` signature.** El proyecto está en PHP ≥ 8.2 y CakePHP 5.x, así
    que es soportado. Si CS-check se queja del estilo, usar la firma array
    `get($id, ['contain' => ['Ingredients']])` que es equivalente.

14. **Icono del sidebar `bi-journal-text`.** Si visualmente confunde con
    un futuro item de Pedidos, alternativas: `bi-card-list`, `bi-clipboard-data`,
    `bi-list-check`. Revisable en code review visual.

15. **`Products::view` y `Products::index` aún NO muestran datos de
    receta.** El design §7.3 y §7.4 los menciona como "integración futura".
    Este plan **NO los incluye** para mantener el scope acotado. Si en
    review se piden, son cambios incrementales sobre los templates
    existentes (no afectan controller ni servicio).

16. **Tests requieren `pdo_sqlite` y posiblemente `bcmath`.** El proyecto
    tiene tests opt-out por memoria del usuario, pero la instrucción
    actual los REQUIERE. Si la suite falla por env, los archivos quedan
    igual para correr en CI o cuando el dev arregle el host. Los tests de
    Entity son puros (sin DB) y deberían correr siempre.
