# Plan de implementación — Módulo Ingredientes

> Plan ordenado, paso a paso, para implementar el módulo **Ingredientes (Insumos)**
> según el diseño aprobado en `.claude/designs/01-ingredientes.md`.
>
> Referencias obligatorias antes de implementar:
> - `.claude/designs/01-ingredientes.md` (spec definitivo)
> - `.claude/rules/ARQUITECTURE.md` §3 (receta de módulo) y §4 (patrones)
> - `.claude/rules/DESIGN.md` (sistema visual)
> - Módulo de referencia para clonar la estructura: **Products**
>   (`ProductsController`, `ProductService`, `ProductsTable`, `Product`,
>   `ProductConstants`, `templates/Products/*`, `templates/element/Products/_form.php`,
>   migraciones `CreateProducts` + `SeedProductsPermissions`).

---

## 1. File manifest

Orden en el que tocar los archivos. Cada línea = archivo + cambio puntual.

### Migraciones (timestamp posterior a `20260503130200_SeedDeliveriesPermissions`)

1. `[CREATE] config/Migrations/20260524120000_CreateIngredients.php`
   — schema `ingredients` (design §1.1 + §5.4).
2. `[CREATE] config/Migrations/20260524120100_SeedIngredientsPermissions.php`
   — fila `permissions` por rol para módulo `ingredients` (design §5.3).

### Constantes y modelo

3. `[CREATE] src/Constants/IngredientConstants.php` — unidades, threshold,
   precisiones, longitudes (design §2).
4. `[CREATE] src/Model/Entity/Ingredient.php` — `$_accessible`, `isLowStock()`,
   `isOutOfStock()`, `getFormattedStock()`, `getFormattedUnitCost()`,
   `_getIsLowStock()` virtual (design §1.2).
5. `[CREATE] src/Model/Table/IngredientsTable.php` — `initialize()`,
   `validationDefault()`, `buildRules()` con `isUnique('name')`, finders
   `findLowStock`, `findSearch`, `findNameList` (design §1.3).

### Servicio

6. `[CREATE] src/Service/IngredientService.php` — `create`, `update`, `delete`,
   `adjustStock`, helpers privados `flattenErrors`, `normalizeName`
   (design §3).

### Controller

7. `[CREATE] src/Controller/IngredientsController.php` — CRUD + filtros + paginate
   (design §4).

### Templates

8. `[CREATE] templates/element/Ingredients/_form.php` — partial compartido
   add/edit (design §6.2).
9. `[CREATE] templates/Ingredients/index.php` — listado con filtros y badge bajo
   stock (design §6.1).
10. `[CREATE] templates/Ingredients/add.php` — render del partial `_form.php`
    con `submitLabel = 'Crear ingrediente'`.
11. `[CREATE] templates/Ingredients/edit.php` — render del partial `_form.php`
    con `submitLabel = 'Guardar cambios'` + botón `button-danger` "Eliminar"
    arriba a la derecha (visible solo si `userPermissions['ingredients']['delete']`).
12. `[CREATE] templates/Ingredients/view.php` — detalle (design §6.3) con
    placeholders para "Usos en recetas" y "Últimos ajustes".

### Cableado RBAC + Navegación

13. `[MODIFY] src/Controller/AppController.php` — agregar `'Ingredients' => 'ingredients'`
    al array `$controllerModuleMap` (línea ~23, después de `'Deliveries'`).
14. `[MODIFY] src/Service/AuthorizationService.php` — agregar
    `'ingredients' => 'Ingredientes'` al array `MODULES` (línea ~22, después de
    `'deliveries'`).
15. `[MODIFY] src/View/Helper/SidebarHelper.php` — insertar item Ingredientes en
    `$items` (después de `products`, agrupado mentalmente como "Inventario";
    icono `bi-egg-fried`).

### Cierre

16. `[RUN] php bin/cake.php migrations migrate` — aplicar migraciones.
17. `[RUN] php bin/cake.php migrations dump` — refrescar
    `config/Migrations/schema-dump-default.lock` (ya está en `git status` como
    modificado por trabajo previo; este paso lo deja consistente con el nuevo
    schema).

---

## 2. Step-by-step execution

### Paso 1 — Migración `CreateIngredients`

**Archivo:** `config/Migrations/20260524120000_CreateIngredients.php`

Crear migración con `Migrations\BaseMigration` (no `AbstractMigration`).
Proteger con `if ($this->hasTable('ingredients')) { return; }`. Tabla con
`signed => false` (PK unsigned) y collation `utf8mb4_unicode_ci` (case-insensitive
para `isUnique` por nombre).

Columnas:
```
name           varchar(120)   not null
unit           varchar(16)    not null
stock_quantity decimal(12,3)  not null default '0.000'
unit_cost      decimal(12,2)  not null default '0.00'
created        datetime       nullable
modified       datetime       nullable
```

Índices:
- `uniq_ingredients_name` UNIQUE sobre `name`.
- `idx_ingredients_low_stock` sobre `stock_quantity` (soporta el filtro low-stock).

`down()`: `drop` la tabla.

**Acceptance:** `php bin/cake.php migrations migrate` corre sin errores y
`SHOW CREATE TABLE ingredients` muestra los dos índices.

---

### Paso 2 — Constantes `IngredientConstants`

**Archivo:** `src/Constants/IngredientConstants.php`

Clase `final` en namespace `App\Constants`. Definir exactamente:

```php
public const UNIT_GRAM        = 'gr';
public const UNIT_KILOGRAM    = 'kg';
public const UNIT_MILLILITER  = 'ml';
public const UNIT_LITER       = 'l';
public const UNIT_UNIT        = 'unidad';

public const UNITS = [self::UNIT_GRAM, self::UNIT_KILOGRAM,
                     self::UNIT_MILLILITER, self::UNIT_LITER, self::UNIT_UNIT];

public const UNIT_LABELS = [
    self::UNIT_GRAM       => 'Gramos (gr)',
    self::UNIT_KILOGRAM   => 'Kilogramos (kg)',
    self::UNIT_MILLILITER => 'Mililitros (ml)',
    self::UNIT_LITER      => 'Litros (l)',
    self::UNIT_UNIT       => 'Unidad',
];

public const LOW_STOCK_THRESHOLD = 5;
public const STOCK_DECIMALS = 3;
public const COST_DECIMALS  = 2;
public const NAME_MAX_LENGTH = 120;
```

**Acceptance:** `composer cs-check` no se queja del archivo nuevo.

---

### Paso 3 — Entity `Ingredient`

**Archivo:** `src/Model/Entity/Ingredient.php`

Extiende `Cake\ORM\Entity`. `$_accessible` con whitelist exclusivamente para:
`name`, `unit`, `stock_quantity`, `unit_cost`. Dejar `id`, `created`, `modified`
fuera (consistente con `Product`).

Definir `$_virtual = ['is_low_stock']` para que `_getIsLowStock()` aparezca al
serializar.

Métodos:
- `isLowStock(): bool` — compara `(float)$this->stock_quantity <= IngredientConstants::LOW_STOCK_THRESHOLD`.
- `isOutOfStock(): bool` — `(float)$this->stock_quantity <= 0.0`.
- `_getIsLowStock(): bool` — delega a `isLowStock()`.
- `getFormattedStock(): string` — formato con `number_format` a 3 decimales,
  separador miles `'.'`, decimal `','`. Si la parte fraccional es 0, recortar
  los `,000` para limpieza visual. Concatenar con `' ' . $this->unit`.
- `getFormattedUnitCost(): string` — mismo patrón que `Product::getFormattedPrice()`:
  `'$' . number_format((float)$this->unit_cost, 0, ',', '.')`. (Dos decimales
  reales se reservan para reportes financieros; en UI se redondea a entero como
  precios de Productos para consistencia visual.)

**Acceptance:** instanciar manualmente con `new Ingredient(['stock_quantity' => '3.500', 'unit' => 'kg'])`
y verificar que `isLowStock()` devuelve `true` y `getFormattedStock()` devuelve
`'3,500 kg'`.

---

### Paso 4 — Table `IngredientsTable`

**Archivo:** `src/Model/Table/IngredientsTable.php`

Extiende `Cake\ORM\Table`. `initialize()`:
- `setTable('ingredients')`, `setPrimaryKey('id')`, `setDisplayField('name')`.
- `addBehavior('Timestamp')`.
- Comentar (igual que `ProductsTable`) las asociaciones futuras:
  `// $this->hasMany('ProductIngredients', ['dependent' => true, ...]);`
  `// $this->hasMany('InventoryAdjustments', ['dependent' => true, ...]);`

`validationDefault(Validator $v)`:
```
$v->notEmptyString('name', 'El nombre es requerido')
  ->maxLength('name', IngredientConstants::NAME_MAX_LENGTH,
              'El nombre puede tener hasta 120 caracteres')
  ->notEmptyString('unit', 'La unidad es requerida')
  ->inList('unit', IngredientConstants::UNITS, 'Unidad no válida')
  ->notEmptyString('stock_quantity', 'El stock es requerido')
  ->numeric('stock_quantity', 'El stock debe ser numérico')
  ->greaterThanOrEqual('stock_quantity', 0, 'El stock no puede ser negativo')
  ->notEmptyString('unit_cost', 'El costo es requerido')
  ->numeric('unit_cost', 'El costo debe ser numérico')
  ->greaterThanOrEqual('unit_cost', 0, 'El costo no puede ser negativo');
return $v;
```

`buildRules(RulesChecker $rules)`:
```
$rules->add($rules->isUnique(['name'],
    ['message' => 'Ya existe un ingrediente con ese nombre']),
    'uniqueName');
return $rules;
```

Custom finders (nombres NUEVOS, NO sobrescribir `findList()` — ARQUITECTURE §4.4):

- `findLowStock(SelectQuery $query): SelectQuery`
  → `$query->where(['Ingredients.stock_quantity <=' => IngredientConstants::LOW_STOCK_THRESHOLD])`.

- `findSearch(SelectQuery $query, array $options): SelectQuery`
  → si `!empty($options['q'])`: `$query->where(['Ingredients.name LIKE' => '%' . $options['q'] . '%'])`.

- `findNameList(SelectQuery $query): SelectQuery`
  → `formatResults` que combina `id => "name (unit)"` (para futuros selectores
  en Recetas/Ajustes; CakePHP 5 acepta `formatResults(fn ($r) => $r->combine(...))`).

**Acceptance:** `php bin/cake.php bake fixture --count=1 Ingredients` (sólo
verificación que la table es resoluble; **no commitear el fixture** dado el
opt-out de tests).

---

### Paso 5 — Service `IngredientService`

**Archivo:** `src/Service/IngredientService.php`

Clase `final` en `App\Service`. `use LocatorAwareTrait`. Sin dependencias
inyectables hoy; constructor vacío (design §3.4).

Forma de retorno estándar: `array{success: bool, ingredient?: Ingredient, errors?: string[]}`.

**Métodos públicos:**

1. `create(array $data): array`
   - Obtener tabla vía `$this->fetchTable('Ingredients')`.
   - `normalizeName($data)` — `trim` y colapso de espacios internos a uno
     (`preg_replace('/\s+/', ' ', ...)`).
   - `newEmptyEntity()` + `patchEntity($entity, $data)`.
   - `save()`. Si falla, `return ['success' => false, 'errors' => $this->flattenErrors($entity->getErrors()), 'ingredient' => $entity]`.
   - Log `Log::info('Ingredient created: id={id} name={name}', ['scope' => ['ingredients']])`.
   - `return ['success' => true, 'ingredient' => $entity]`.

2. `update(Ingredient $ingredient, array $data): array`
   - `normalizeName($data)`.
   - `patchEntity($ingredient, $data)`.
   - `save()`. Mismo manejo de errores que `create`.
   - Log `Log::info('Ingredient updated: id={id}', [...])`.
   - `return ['success' => true, 'ingredient' => $ingredient]`.

3. `delete(Ingredient $ingredient): array`
   - `$id = $ingredient->id`, `$name = $ingredient->name` (cachear antes).
   - `$table->delete($ingredient)`.
   - Si falla: `return ['success' => false, 'errors' => ['No se pudo eliminar el ingrediente.']]`.
   - Log `Log::warning('Ingredient deleted: id={id} name={name}', [...])`
     (warning, no info — el spec deja huella explícita).
   - `return ['success' => true]`.
   - Cascade real (recetas/ajustes) llegará con sus migraciones; hoy la fila
     simplemente desaparece.

4. `adjustStock(Ingredient $ingredient, string $deltaSigned, string $reason): array`
   - **Importante:** este método existe en la clase pero **no se invoca desde
     ningún controller en esta fase**. Lo consumirán los futuros
     `InventoryAdjustmentService` y `OrderService`.
   - Wrap en `Connection::transactional()` (default connection).
   - Re-leer ingrediente con `$query = $table->find()->where(['id' => $ingredient->id])->epilog('FOR UPDATE')->first()`.
   - `$current = (string)$fresh->stock_quantity; $delta = (string)$deltaSigned;`
   - `$new = bcadd($current, $delta, IngredientConstants::STOCK_DECIMALS);`
   - Si `bccomp($new, '0', STOCK_DECIMALS) < 0`:
     `return ['success' => false, 'errors' => ["Stock insuficiente para {$fresh->name} (actual {$current}, requerido {$delta})"], 'ingredient' => $fresh, 'new_stock' => $current]`.
   - `$fresh->stock_quantity = $new; $table->save($fresh);`
   - Log `Log::info('Ingredient stock adjusted: id={id} delta={delta} reason={reason} new={new}', [...])`.
   - `return ['success' => true, 'ingredient' => $fresh, 'new_stock' => $new]`.
   - Forma de retorno: `array{success: bool, ingredient?: Ingredient, new_stock?: string, errors?: string[]}`.

**Helpers privados:**
- `normalizeName(array &$data): void` — modifica `$data['name']` si está presente.
- `flattenErrors(array $errors): array` — copiar el helper de `ProductService::flattenErrors()` (mismo comportamiento).

**Acceptance:** `vendor/bin/phpstan analyse src/Service/IngredientService.php`
limpio nivel 8.

---

### Paso 6 — Controller `IngredientsController`

**Archivo:** `src/Controller/IngredientsController.php`

Extiende `AppController`. Calcado de `ProductsController` con adaptaciones:

```php
public array $paginate = [
    'limit' => 15,
    'maxLimit' => 15,
    'order' => ['Ingredients.name' => 'ASC'],
    'sortableFields' => ['name', 'stock_quantity', 'unit_cost', 'created'],
];

private IngredientService $ingredientService;

public function initialize(): void {
    parent::initialize();
    $this->ingredientService = new IngredientService();
}
```

Acciones:

- `index()`: `_currentFilters()` + `_buildIndexQuery($filters)` + `$this->paginate(...)`.
  Setear `compact('ingredients', 'filters')` y breadcrumbs `[['label' => 'Ingredientes']]`.

- `view(int $id)`: `$this->Ingredients->get($id)` (lanza `NotFoundException`
  automáticamente). Set entity + breadcrumbs.

- `add()`: `newEmptyEntity()`. Si POST: delegar a `$this->ingredientService->create($this->request->getData())`.
  Flash success + redirect a `index` si OK; flash errors y re-render si no.
  Si `$result['ingredient']` viene en errores, usarla para repopular el form.

- `edit(int $id)`: `$this->Ingredients->get($id)`. Si PUT/POST/PATCH:
  `$this->ingredientService->update($ingredient, $this->request->getData())`.
  Misma estrategia de flash/redirect que `add`.

- `delete(int $id)`: `$this->request->allowMethod(['post', 'delete'])`. `try/catch
  RecordNotFoundException` con flash en español. Si OK: flash success y redirect
  a `index`. Si fallo: flash con `$result['errors'][0]`.

**No** sobrescribir `_actionToPermission` — todas las acciones calzan en el mapeo
base (`index/view → view`, `add → create`, `edit → edit`, `delete → delete`).

`_currentFilters()`:
```
allowedSort = ['name', 'stock_quantity', 'unit_cost', 'created']
allowedUnits = ['all', ...IngredientConstants::UNITS]
allowedDir = ['asc', 'desc']

return [
  'q'         => trim((string)$this->request->getQuery('q', '')),
  'unit'      => in_array($unit, $allowedUnits, true) ? $unit : 'all',
  'low_stock' => $this->request->getQuery('low_stock') === '1' ? '1' : '0',
  'sort'      => in_array($sort, $allowedSort, true) ? $sort : 'name',
  'direction' => in_array($direction, $allowedDir, true) ? $direction : 'asc',
];
```

`_buildIndexQuery(array $filters): SelectQuery`:
- `$query = $this->Ingredients->find();`
- Si `$filters['q'] !== ''`: `$query = $this->Ingredients->find('search', ['q' => $filters['q']])` (o aplicar el `where` directo si querés mantener el chain).
- Si `$filters['unit'] !== 'all'`: `$query->where(['Ingredients.unit' => $filters['unit']])`.
- Si `$filters['low_stock'] === '1'`: `$query->where(['Ingredients.stock_quantity <=' => IngredientConstants::LOW_STOCK_THRESHOLD])`.
- `$query->orderBy(['Ingredients.' . $filters['sort'] => strtoupper($filters['direction'])])`.
- `return $query`.

**Acceptance:** `composer cs-check` y `vendor/bin/phpstan analyse` limpios sobre
el controller. Hit manual `GET /ingredients` (anónimo) redirige a `/login`.

---

### Paso 7 — Templates

#### 7.1 `templates/element/Ingredients/_form.php`

Partial compartido entre `add` y `edit`. Layout en dos columnas (Bootstrap
grid). `Form->create($ingredient)` sin `type => 'file'` (no hay upload).

Columna izquierda — Identidad:
- `Form->control('name', [...])` con `autofocus => $ingredient->isNew()`,
  `maxlength => 120`, `help => 'Debe ser único'`.
- `Form->select('unit', IngredientConstants::UNIT_LABELS, ['empty' => 'Seleccionar...', 'class' => 'form-select'])` con label manual.
  Si `!$ingredient->isNew()`: mostrar `<div class="form-text text-warning">
  Cambiar la unidad no convierte el stock actual.</div>`.

Columna derecha — Inventario y costo:
- `stock_quantity`: input `number` con `step="0.001"`, `min="0"`, `class="form-control"`. Sufijo visual con la unidad actual (texto, no se actualiza vía JS en
  esta fase — keep it simple; el sufijo refleja el valor guardado).
- `unit_cost`: input `number` con `step="0.01"`, `min="0"`. Prefijo `$` vía
  `input-group`.

Pie:
- `Form->button('<i class="bi bi-check-lg"></i> ' . h($submitLabel), [...])`
  con `class => 'btn btn-primary'`.
- `Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-light'])`
  (o `['action' => 'view', $ingredient->id]` desde `edit`).

#### 7.2 `templates/Ingredients/index.php`

Clonar la estructura de `templates/Products/index.php`:

Header:
- `h1.dr-page-title`: "Ingredientes".
- `button-primary` único: "Nuevo ingrediente".

Filtros (form GET, card):
- Input `q` con icono `bi-search`, placeholder "Buscar por nombre".
- Select `unit` con `<option value="all">Todas las unidades</option>` + loop
  sobre `IngredientConstants::UNIT_LABELS`.
- Checkbox `low_stock` con value `1`, hidden `0` previo, label "Solo bajo stock".
- Botón "Filtrar".
- Link "Limpiar" si hay algún filtro activo (`q !== '' || unit !== 'all' || low_stock === '1'`).

Tabla:
| Columna | Width | Align | Contenido |
|---|---|---|---|
| Nombre | auto | left | `Html->link(h($i->name), ['action' => 'edit', $i->id])` + badge si `isLowStock()` |
| Unidad | 110px | left | `IngredientConstants::UNIT_LABELS[$i->unit] ?? $i->unit` |
| Stock actual | 140px | right | `h($i->getFormattedStock())` envuelto en `<span class="text-danger">` si bajo stock |
| Costo unitario | 140px | right | `h($i->getFormattedUnitCost())` |
| Estado | 140px | center | Sin badge si OK; `badge badge-soft-danger` "Bajo stock" / "Sin stock" |
| Acciones | 140px | right | `btn-icon` editar + `postLink` eliminar con `confirm` |

Empty state idéntico a Products (con/sin filtros).

Pie: `<?= $this->element('pagination') ?>`.

#### 7.3 `templates/Ingredients/add.php`

```php
<?php
/** @var \App\View\AppView $this */
/** @var \App\Model\Entity\Ingredient $ingredient */
$this->assign('title', 'Nuevo ingrediente');
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Nuevo ingrediente</h1>
</div>
<?= $this->element('Ingredients/_form', ['submitLabel' => 'Crear ingrediente']) ?>
```

#### 7.4 `templates/Ingredients/edit.php`

Similar a `add.php` pero:
- Título "Editar ingrediente".
- `dr-page-header` con título a la izquierda y, si `!empty($userPermissions['ingredients']['delete'])`,
  `Form->postLink('<i class="bi bi-trash"></i> Eliminar', ['action' => 'delete', $ingredient->id],
   ['escape' => false, 'class' => 'btn btn-danger', 'confirm' => '¿Eliminar...?'])` a la derecha.
- `submitLabel => 'Guardar cambios'`.

#### 7.5 `templates/Ingredients/view.php`

Header con nombre + botones "Editar" (`btn-secondary`) y "Volver" (`btn-light`).
Card principal con `<dl class="row">` mostrando: Nombre, Unidad (label legible),
Stock actual (con badge bajo stock), Costo unitario, Creado, Modificado.

Sección "Usos en recetas" — placeholder:
`<div class="text-muted">Las recetas se mostrarán cuando el módulo esté disponible.</div>`

Sección "Últimos ajustes" — mismo placeholder con copy adaptado.

**Acceptance del bloque templates:** las 5 vistas renderizan sin warnings en
`debug = true` y `composer cs-check` queda limpio.

---

### Paso 8 — RBAC (3 puntos de cableado, exactos)

#### 8.1 `src/Controller/AppController.php`

En el array `$controllerModuleMap` (línea ~18-24) agregar **una línea** tras
`'Deliveries' => 'deliveries',`:

```php
'Ingredients' => 'ingredients',
```

#### 8.2 `src/Service/AuthorizationService.php`

En el array `MODULES` (línea ~17-23) agregar **una línea** tras
`'deliveries' => 'Repartidores',`:

```php
'ingredients' => 'Ingredientes',
```

#### 8.3 Migración `SeedIngredientsPermissions`

**Archivo:** `config/Migrations/20260524120100_SeedIngredientsPermissions.php`

Calcado de `SeedProductsPermissions`. Dos `INSERT ... SELECT` con
`NOT EXISTS` guard:

```sql
-- Roles no-admin: view + create + edit, sin delete por defecto.
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
SELECT r.id, 'ingredients', 1, 1, 1, 0, '{$now}', '{$now}'
FROM roles r
WHERE r.is_admin = 0
  AND NOT EXISTS (SELECT 1 FROM permissions p WHERE p.role_id = r.id AND p.module = 'ingredients');

-- Administrador: matriz completa (bypass cubre igual, pero se siembra por consistencia).
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
SELECT r.id, 'ingredients', 1, 1, 1, 1, '{$now}', '{$now}'
FROM roles r
WHERE r.is_admin = 1
  AND NOT EXISTS (SELECT 1 FROM permissions p WHERE p.role_id = r.id AND p.module = 'ingredients');
```

`down()`: `$this->execute("DELETE FROM permissions WHERE module = 'ingredients'");`

**Acceptance:** tras `migrations migrate`, `SELECT module FROM permissions
GROUP BY module` debe incluir `ingredients`.

---

### Paso 9 — Sidebar

**Archivo:** `src/View/Helper/SidebarHelper.php`

En el array `$items` (línea ~21-52), insertar **después del bloque `products`**
(porque la idea futura es agruparlo bajo "Inventario", y Productos vive
visualmente cerca):

```php
[
    'module' => 'ingredients',
    'label' => 'Ingredientes',
    'icon' => 'bi-egg-fried',
    'url' => ['controller' => 'Ingredients', 'action' => 'index'],
],
```

El helper ya filtra por permiso (`empty($permissions[$item['module']]['view'])`),
así que el item aparece automáticamente para Administrador y para cualquier rol
con `can_view = 1` en `ingredients`.

**Acceptance:** loguearse como Administrador → ver "Ingredientes" en el sidebar
entre "Productos" y "Clientes".

---

### Paso 10 — Aplicar y volcar schema

**Comando 1:** `php bin/cake.php migrations migrate`

**Comando 2:** `php bin/cake.php migrations dump`

Esto actualiza `config/Migrations/schema-dump-default.lock` para incluir la
tabla `ingredients` y queda en el commit.

**Acceptance:** ambos comandos exitosos, el lock actualizado entra al diff.

---

## 3. Test plan

> **Nota importante:** el proyecto tiene **opt-out de tests automatizados**
> (memoria de usuario: `feedback_no_tests.md`). El design §8 también lo aclara.
> **NO crear ningún archivo de test en esta implementación.** Esta sección queda
> como referencia para cuando esa decisión se revierta.

### Archivos esperados (cuando se reactiven tests)

- `tests/Fixture/IngredientsFixture.php` — seed mínimo: 3 filas (stock alto,
  bajo, cero).
- `tests/TestCase/Model/Entity/IngredientTest.php` — helpers de dominio.
- `tests/TestCase/Model/Table/IngredientsTableTest.php` — validación, rules,
  finders.
- `tests/TestCase/Service/IngredientServiceTest.php` — create, update, delete,
  adjustStock con guard no-negativo.
- `tests/TestCase/Controller/IngredientsControllerTest.php` — auth, RBAC, CRUD,
  filtros.

### Casos por archivo

**`IngredientTest`:**
- `isLowStock` true cuando stock ≤ 5; false cuando 5.001.
- `isOutOfStock` true sólo cuando stock = 0.
- `getFormattedStock`: `'3.000'` con unidad `'kg'` → `'3 kg'`; `'1.250'` → `'1,250 kg'`.
- `getFormattedUnitCost`: `'1500'` → `'$1.500'`.
- `_getIsLowStock` accesible via `$entity->is_low_stock`.

**`IngredientsTableTest`:**
- Rechaza name vacío, unit fuera de `UNITS`, stock negativo, cost negativo.
- `isUnique` rechaza duplicado (incluyendo case distinto vía collation).
- `findLowStock` filtra `<= 5` correctamente.
- `findSearch` aplica LIKE por nombre.
- `findNameList` devuelve `id => "name (unit)"`.

**`IngredientServiceTest`:**
- `create` con datos válidos → `success=true`.
- `create` con nombre duplicado → `success=false`, error en español.
- `update` permite cambiar unit y stock.
- `delete` borra fila simple.
- `adjustStock` con delta positivo suma; con delta negativo resta.
- `adjustStock` que dejaría stock negativo → `success=false`, no persiste.

**`IngredientsControllerTest`:**
- GET `/ingredients` anónimo → 302 `/login`.
- GET `/ingredients` con rol sin `view` → 403.
- GET `/ingredients?low_stock=1&unit=gr` filtra y pagina.
- POST `/ingredients/add` con permiso `create` → 302 + flash success.
- POST `/ingredients/add` sin permiso → 403.
- POST `/ingredients/delete/{id}` requiere POST/DELETE; sin permiso → 403.
- Administrador bypassea matriz (asserts con role `is_admin=1`).

### Comandos de verificación (cuando se vuelvan a habilitar tests)

```bash
composer cs-check && composer cs-fix
vendor/bin/phpstan analyse
vendor/bin/psalm
php vendor/bin/phpunit tests/TestCase/Model/Table/IngredientsTableTest.php
php vendor/bin/phpunit tests/TestCase/Service/IngredientServiceTest.php
php vendor/bin/phpunit tests/TestCase/Controller/IngredientsControllerTest.php
php vendor/bin/phpunit  # suite completa
```

---

## 4. Verification checklist

Ejecutar todo lo siguiente antes de marcar el módulo como hecho:

- [ ] `php bin/cake.php migrations migrate` corre sin errores. Tabla
      `ingredients` y filas en `permissions` para módulo `ingredients` quedan
      creadas.
- [ ] `php bin/cake.php migrations dump` actualiza el lock sin warnings.
- [ ] `composer cs-check` limpio (sin nuevos warnings).
- [ ] `composer cs-fix` no produce cambios adicionales (idempotente).
- [ ] `vendor/bin/phpstan analyse` nivel 8 limpio sobre los nuevos archivos.
- [ ] `vendor/bin/psalm` sin nuevos errores.
- [ ] Servidor dev levanta: `php bin/cake.php server -p 8765`.
- [ ] **Smoke manual** logueado como Administrador:
  1. `/ingredients` → empty state con CTA "Crear el primero".
  2. `/ingredients/add` → crear "Carne molida" (unit `gr`, stock `1500`, costo `25`).
  3. Listado lo muestra; columna stock formateada `1.500 gr` (o equivalente),
     sin badge porque stock > 5.
  4. `/ingredients/edit/{id}` → cambiar stock a `3`.
  5. Listado muestra badge "Bajo stock" + texto rojo en columna stock.
  6. Filtro `?low_stock=1` lo muestra; `?low_stock=0&unit=ml` no.
  7. `/ingredients/view/{id}` → detalle con placeholders para Recetas/Ajustes.
  8. Eliminar desde el listado → flash success, fila desaparece.
- [ ] **Smoke RBAC**: loguearse como rol no-admin con permiso `view` pero sin
      `delete` → no aparece el botón eliminar y POST directo a `/ingredients/delete/{id}`
      devuelve 403.
- [ ] **Smoke navegación**: item "Ingredientes" aparece en sidebar entre
      "Productos" y "Clientes" cuando el usuario tiene `view`.

---

## 5. Risks / gotchas

1. **`findList()` no se sobrescribe.** ARQUITECTURE §3.4 lo dice explícito: la
   firma en CakePHP 5 es incompatible. El módulo expone `findNameList`. Si
   alguien futuro hace `find('list')` esperando "name + unit", debe usar
   `find('nameList')` o pedirlo a este finder. Documentarlo en el PR.

2. **`stock_quantity` como decimal → CakePHP lo devuelve como `string`,
   no `float`.** Casts intermedios (`(float)`, `(int)`) introducen errores de
   redondeo. Los helpers de la entity casteán explícitamente solo para mostrar.
   `adjustStock` usa `bcadd`/`bccomp` con escala 3 — **requiere extensión
   `bcmath` instalada** (estándar en PHP, pero verificarlo en el entorno de
   prod). En su ausencia, el método explotará.

3. **Validation vs business rules — `isUnique` debe vivir en `buildRules`,
   NO en `validationDefault`.** Si se mueve, no chequea contra la DB. Es un
   error común al hacer copy-paste.

4. **Collation case-insensitive para unicidad.** La tabla se crea con
   `utf8mb4_unicode_ci`, lo que hace que `'Carne'` y `'carne'` se consideren
   duplicados al evaluar `isUnique`. Si en algún momento se cambia la collation
   a `_bin`, la unicidad se vuelve case-sensitive y el test correspondiente
   fallará.

5. **`low_stock` checkbox + hidden input.** CakePHP/HTML no envía checkboxes
   desmarcados. El template debe poner un `<input type="hidden" name="low_stock" value="0">`
   antes del checkbox (mismo patrón que `is_active` en Products `_form.php`).
   Olvidarlo hace que el filtro nunca se "apague" tras activarse.

6. **CSRF en CRUD estándar.** No hay endpoints "toggle" en este módulo (a
   diferencia de Products). `Form->create` y `Form->postLink` ya inyectan el
   token CSRF automáticamente; no requiere configuración extra.

7. **Cascade de delete futuro.** Hoy `delete` borra una fila simple. Cuando se
   creen `product_ingredients` e `inventory_adjustments`, esas migraciones
   deben declarar `'delete' => 'CASCADE'` en sus FKs apuntando a
   `ingredients.id`. Si se olvidan, MySQL bloqueará el delete del ingrediente.
   El servicio actualmente no anticipa esa lógica.

8. **`adjustStock` sin caller en Fase 1.** Lo definimos para que cuando llegue
   Recetas/Ajustes/Pedidos, el contrato ya esté. PHPStan podría quejarse de
   "método unused" si el proyecto activa esa regla — está OK ignorarlo (es
   API pública del servicio).

9. **FK type mismatches futuros.** Cuando se cree `product_ingredients`, la
   columna `ingredient_id` debe declararse con `'signed' => false` (igual que
   `ingredients.id`). Mismatch entre signed/unsigned hace fallar la creación
   de la FK en MySQL.

10. **Sidebar: agrupación visual "Inventario".** Hoy el sidebar es plano. Cuando
    crezcan los módulos del grupo Inventario (Recetas, Ajustes), considerar
    introducir secciones agrupadas en el `SidebarHelper`. Para Fase 1
    Ingredientes vive plano entre Productos y Clientes.

11. **`schema-dump-default.lock` ya viene modificado en el working tree.**
    `git status` al inicio muestra `M config/Migrations/schema-dump-default.lock`.
    Confirmar con el usuario si esos cambios pre-existentes deben commitearse
    junto con el dump del nuevo schema o separados. Por simplicidad, hacer
    `migrations dump` al final y commitear el lock una sola vez.

12. **PHP 8.2+ feature `final class`.** El proyecto usa `final` en servicios
    (ver `ProductService`). Mantenerlo en `IngredientService` por consistencia.

13. **Vista `view.php` y `view` action devuelven `void`/`null`** — no confundir
    con el módulo de "vista" de CakePHP. El método `view(int $id)` en el
    controller renderiza `templates/Ingredients/view.php`.
