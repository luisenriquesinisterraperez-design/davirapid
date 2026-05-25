# Diseño — Módulo Ingredientes (Insumos)

> Documento de diseño técnico para el módulo de Inventario → Ingredientes. Sirve como
> insumo único para el planner y el implementador. No se escribe código aquí — solo
> esquemas, contratos y decisiones.
>
> Referencias: `davirapid.md` §10 (Ingredientes), §11 (Recetas), §12 (Ajustes), §19
> (Dashboard alertas); `.claude/rules/ARQUITECTURE.md` (capas, convenciones);
> `.claude/rules/DESIGN.md` (sistema visual).

---

## 1. Data model

### 1.1 Tabla `ingredients`

| Columna           | Tipo                   | Null | Default | Notas                                                              |
|-------------------|------------------------|------|---------|--------------------------------------------------------------------|
| `id`              | int unsigned, PK, AI   | no   | —       | Convención del proyecto (`signed=false` como en `products`).       |
| `name`            | varchar(120)           | no   | —       | Único (case-insensitive vía collation utf8mb4_unicode_ci).         |
| `unit`            | varchar(16)            | no   | —       | Restringido por `inList(IngredientConstants::UNITS)` en validación.|
| `stock_quantity`  | decimal(12,3)          | no   | 0       | Stock actual. Tres decimales para gr/ml granulares.                |
| `unit_cost`       | decimal(12,2)          | no   | 0       | Costo por unidad. Dos decimales (moneda local, sin centavos finos).|
| `created`         | datetime               | sí   | null    | Behavior Timestamp.                                                |
| `modified`        | datetime               | sí   | null    | Behavior Timestamp.                                                |

**Índices:**
- `uniq_ingredients_name` UNIQUE (`name`) — el spec exige unicidad de nombre.
- `idx_ingredients_low_stock` (`stock_quantity`) — soporta el filtro "solo bajo stock" y la query del Dashboard de alertas.

**Constraints/decisiones de columna y justificación:**

- **`stock_quantity` = `decimal(12,3)`** (no `integer`). El spec usa "unidades" como una de las medidas, pero las otras (`gr`, `ml`) requieren fracciones realistas (200gr de carne, 15ml de salsa — del ejemplo de §11). Con 3 decimales cubrimos gramos/mililitros sin perder precisión; con `12,3` el rango útil es ~999.999.999,999 — más que suficiente para inventario de un local de comida rápida. Se almacena como string-decimal y se trabaja con `BigDecimal`/`bcmath` solo si en el futuro Pedidos/Ajustes lo requieren; para el CRUD del módulo basta con casts de CakePHP.
- **`unit_cost` = `decimal(12,2)`**. Moneda local (COP) — sin centavos en la práctica, pero `decimal(12,2)` es el estándar seguro para evitar floats. Permite costos unitarios pequeños (ej. costo por gramo = 0.05) sin que se redondee a cero.
- **`unit` = `varchar(16)`** (no enum DB). Se valida en aplicación con `inList()` contra `IngredientConstants::UNITS`. Mantener la lista en PHP permite agregar unidades sin migración. El límite 16 cubre cualquier abreviatura razonable (`unidad`, `kg`, `ml`, etc.).
- **No `is_low_stock` persistido**. Es **virtual** en la entity (ver §1.3). Persistirlo crearía un dato derivable desincronizado tras cada `update` de stock — el riesgo no justifica la "optimización".
- **No soft-delete**. El spec §10 dice explícitamente que al eliminar se **cascadean** referencias en recetas y ajustes. Hard delete + cascade en FKs (definidas en migraciones futuras de Recetas/Ajustes).
- **Sin FKs salientes** desde `ingredients`. Las FKs entrantes (`product_ingredients.ingredient_id`, `inventory_adjustments.ingredient_id`) se definirán con `ON DELETE CASCADE` en las migraciones de los módulos que las introducen — Ingredientes no las anticipa hoy.

### 1.2 Entity `Ingredient`

**`$_accessible`:**
```
name           => true
unit           => true
stock_quantity => true
unit_cost      => true
```

`id`, `created`, `modified` quedan fuera (whitelist estricta, consistente con `Product`).

**Helpers de dominio:**

- `isLowStock(): bool` — devuelve `$this->stock_quantity <= IngredientConstants::LOW_STOCK_THRESHOLD`.
- `getFormattedStock(): string` — formatea `stock_quantity + ' ' + unit` (ej. `"1.250 gr"`). Elimina decimales innecesarios cuando el valor es entero exacto.
- `getFormattedUnitCost(): string` — usa la misma estrategia que `Product::getFormattedPrice()` (`'$' . number_format(...)`).
- `_getIsLowStock(): bool` — virtual property accesible como `$ingredient->is_low_stock` en plantillas (delega a `isLowStock()`). Va en `$_virtual` para serializarla si se necesita en JSON.

### 1.3 `IngredientsTable`

**`initialize()`:**
- `setTable('ingredients')`, `setPrimaryKey('id')`, `setDisplayField('name')`.
- `addBehavior('Timestamp')`.
- Asociaciones futuras (comentadas por ahora, igual que `ProductsTable`):
  - `hasMany('ProductIngredients', ['foreignKey' => 'ingredient_id', 'dependent' => true, 'cascadeCallbacks' => true])`
  - `hasMany('InventoryAdjustments', ['foreignKey' => 'ingredient_id', 'dependent' => true, 'cascadeCallbacks' => true])`
  > `dependent => true` da seguridad adicional aunque la FK ya tenga `ON DELETE CASCADE` — si se llama `delete()` desde CakePHP se ejecutan callbacks (útil para auditoría futura).

**`validationDefault()`** (validación de **formato**, no de negocio):
```
- notEmptyString('name', 'El nombre es requerido')
- maxLength('name', 120, ...)
- notEmptyString('unit', 'La unidad es requerida')
- inList('unit', IngredientConstants::UNITS, 'Unidad no válida')
- numeric('stock_quantity', 'El stock debe ser numérico')
- greaterThanOrEqual('stock_quantity', 0, 'El stock no puede ser negativo')
- numeric('unit_cost', 'El costo debe ser numérico')
- greaterThanOrEqual('unit_cost', 0, 'El costo no puede ser negativo')
```

**`buildRules()`:**
- `isUnique(['name'], ['message' => 'Ya existe un ingrediente con ese nombre'])` con comparación case-insensitive vía collation.

**Custom finders:**
- `findLowStock(SelectQuery $query): SelectQuery` — `where(['stock_quantity <=' => IngredientConstants::LOW_STOCK_THRESHOLD])`.
- `findSearch(SelectQuery $query, array $options): SelectQuery` — recibe `['q' => string]`; aplica `name LIKE %q%`.
- `findNameList(SelectQuery $query): SelectQuery` — para selectores futuros en Recetas/Ajustes. Devuelve `id => "name (unit)"`. **No** sobrescribe `findList()` (regla del proyecto: la firma en CakePHP 5 es incompatible).

---

## 2. Constants — `IngredientConstants`

```
final class IngredientConstants
{
    public const UNIT_GRAM        = 'gr';
    public const UNIT_KILOGRAM    = 'kg';
    public const UNIT_MILLILITER  = 'ml';
    public const UNIT_LITER       = 'l';
    public const UNIT_UNIT        = 'unidad';

    public const UNITS = [
        self::UNIT_GRAM,
        self::UNIT_KILOGRAM,
        self::UNIT_MILLILITER,
        self::UNIT_LITER,
        self::UNIT_UNIT,
    ];

    public const UNIT_LABELS = [
        self::UNIT_GRAM       => 'Gramos (gr)',
        self::UNIT_KILOGRAM   => 'Kilogramos (kg)',
        self::UNIT_MILLILITER => 'Mililitros (ml)',
        self::UNIT_LITER      => 'Litros (l)',
        self::UNIT_UNIT       => 'Unidad',
    ];

    public const LOW_STOCK_THRESHOLD = 5; // §10, §19 del spec
    public const STOCK_DECIMALS = 3;
    public const COST_DECIMALS  = 2;
    public const NAME_MAX_LENGTH = 120;
}
```

**Decisiones:**
- Valores de unidad en español (`'gr'`, `'unidad'`) — son terminología visible en la UI y consistentes con la regla del proyecto sobre constantes de negocio.
- `LOW_STOCK_THRESHOLD = 5` — literal del spec §10 y §19.5; centralizado para que Dashboard y filtro `index` usen la misma fuente.
- Incluyo `kg` y `l` desde el inicio aunque el spec mencione "gr, ml, unidad, etc." — son las extensiones obvias y evitan migraciones de datos posteriores.

---

## 3. Service layer — `IngredientService`

Patrón calcado de `ProductService`: métodos retornan `array{success: bool, ingredient?: Ingredient, errors?: array<string>}`; operaciones que tocan más de una tabla se envuelven en `Connection::transactional()`.

### 3.1 Métodos públicos

| Método                                            | Propósito                                                                                       |
|---------------------------------------------------|-------------------------------------------------------------------------------------------------|
| `create(array $data): array`                      | Crea ingrediente. Logs `Log::info` al éxito.                                                    |
| `update(Ingredient $i, array $data): array`       | Actualiza. Si cambia `unit` ver §7 (decisión: permitido pero con warning).                      |
| `delete(Ingredient $i): array`                    | Hard delete + cascade (vía FKs `ON DELETE CASCADE` o `dependent => true` cuando existan).       |
| `adjustStock(Ingredient $i, string $deltaSigned, string $reason): array` | Helper interno para Pedidos/Ajustes/Recetas. Suma/resta `stock_quantity` atómicamente, valida no-negativo, loguea. **No** crea registro de auditoría — eso lo hace el caller (`InventoryAdjustmentService` cuando exista). |

**Firma de `adjustStock`** (detallada porque la consumirán futuros módulos):
- `$deltaSigned`: string decimal con signo (`'+2.500'`, `'-0.250'`). Se usa string para evitar pérdida por float; CakePHP convierte en `save()`.
- Devuelve `['success' => bool, 'ingredient' => Ingredient, 'new_stock' => string, 'errors' => string[]]`.
- Si `new_stock < 0`, devuelve error: `'Stock insuficiente para {name} (actual {current}, requerido {delta})'`. **No** clampea a 0 — la decisión de overdraft pertenece al caller.
- Usa `SELECT ... FOR UPDATE` (vía `$query->epilog('FOR UPDATE')`) dentro de una transacción del caller para evitar carreras cuando varios pedidos descuenten el mismo insumo. Si no hay transacción activa, abre una.

### 3.2 Validación: tabla vs servicio

| Capa     | Reglas                                                                                              |
|----------|------------------------------------------------------------------------------------------------------|
| Tabla    | Presencia de campos, tipo numérico, longitudes, `inList` de `unit`, `>= 0` para stock/cost, unicidad de `name`. |
| Servicio | Negocio: validar que `delete` no se llama sobre `null`, normalizar `name` (trim + colapsar espacios), redondear `stock_quantity` y `unit_cost` a la precisión correcta antes de persistir. |

> No mover la unicidad ni el `>= 0` al servicio — son chequeos de **forma**. La separación se respeta para que `bake` y los tests de tabla puedan ejercer todo el contrato sin mocks.

### 3.3 Cascade en `delete`

El spec §10 es explícito: "el sistema también elimina sus referencias en recetas y su historial de ajustes". Estrategia:

1. **Hoy** (Fase actual, sin Recetas ni Ajustes en DB): `delete()` simplemente borra la fila.
2. **Cuando existan `product_ingredients` e `inventory_adjustments`**: FKs con `ON DELETE CASCADE` apuntando a `ingredients.id` desde sus migraciones (no desde la migración de Ingredientes — la responsabilidad es del módulo que introduce la dependencia).
3. **Sin bloqueo blando**: NO se rechaza el delete por tener recetas o ajustes (a diferencia de `Product::delete` que bloquea si hay ventas). El spec lo permite explícitamente.
4. **Log obligatorio**: `Log::warning('Ingredient deleted: id={id} name={name} cascade=...')` con conteo de filas cascadeadas (cuando esos módulos existan), para que la operación deje rastro fuera de la auditoría de pedidos.

### 3.4 Dependencias del constructor

```
public function __construct() { /* sin dependencias hoy */ }
```

Cuando Ajustes/Pedidos consuman `adjustStock`, sus respectivos servicios lo inyectarán via:
```
public function __construct(?IngredientService $ingredients = null) { ... }
```

---

## 4. Controller — `IngredientsController`

### 4.1 Acciones y mapeo de permisos

| Acción         | HTTP        | Permiso (vía `_actionToPermission`) | Notas                                        |
|----------------|-------------|--------------------------------------|----------------------------------------------|
| `index`        | GET         | `view`                               | Listado con filtros (q, low-stock).          |
| `view`         | GET         | `view`                               | Detalle.                                     |
| `add`          | GET/POST    | `create`                             | Form de alta.                                |
| `edit`         | GET/POST    | `edit`                               | Form de edición.                             |
| `delete`       | POST/DELETE | `delete`                             | Cascade implícito (ver §3.3).                |

No hay acciones custom — todas calzan en el mapeo base de `AppController::_actionToPermission`, así que **no es necesario** sobrescribirlo.

### 4.2 Paginación y ordenamiento

```
public array $paginate = [
    'limit' => 15,
    'maxLimit' => 15,
    'order' => ['Ingredients.name' => 'ASC'],
    'sortableFields' => ['name', 'stock_quantity', 'unit_cost', 'created'],
];
```

### 4.3 Filtros (`_currentFilters`)

```
{
  q:          string  // búsqueda por nombre
  unit:       string  // 'all' | uno de IngredientConstants::UNITS
  low_stock:  '0'|'1' // checkbox "solo bajo stock"
  sort:       'name' | 'stock_quantity' | 'unit_cost' | 'created'
  direction:  'asc' | 'desc'
}
```

`_buildIndexQuery($filters)` construye:
- `WHERE name LIKE %q%` si `q` no vacío.
- `WHERE unit = :unit` si `unit !== 'all'`.
- `WHERE stock_quantity <= LOW_STOCK_THRESHOLD` si `low_stock === '1'` (usa `findLowStock`).
- `ORDER BY` según `sort`/`direction` con whitelist.

### 4.4 Estructura interna

Calcada de `ProductsController`:
- `initialize()` instancia `IngredientService`.
- Acciones delegan a service; controller solo arma flash + redirect.
- `view($id)` usa `$this->Ingredients->get($id)` (lanza `NotFoundException` automáticamente — convención del proyecto).
- `delete($id)` envuelve `get` en `try/catch RecordNotFoundException` con flash en español.

---

## 5. RBAC integration

### 5.1 `AppController::$controllerModuleMap`

Agregar entrada:
```
'Ingredients' => 'ingredients',
```

### 5.2 `AuthorizationService::MODULES`

Agregar entrada:
```
'ingredients' => 'Ingredientes',
```

### 5.3 Seed migration de permisos

Archivo: `config/Migrations/YYYYMMDDHHMMSS_SeedIngredientsPermissions.php` (timestamp posterior a `CreateIngredients`).

Plantilla calcada de `SeedProductsPermissions`:

```php
class SeedIngredientsPermissions extends BaseMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // Roles no-admin: por defecto view+create+edit, sin delete
        // (el spec §10 permite delete con cascade, pero defaulteamos a no destructivo).
        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'ingredients', 1, 1, 1, 0, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 0
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'ingredients'
               )"
        );

        // Administrador: matriz completa (bypass igual aplica, pero
        // consistencia con SeedProductsPermissions).
        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'ingredients', 1, 1, 1, 1, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 1
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'ingredients'
               )"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'ingredients'");
    }
}
```

### 5.4 Migración `CreateIngredients` (esqueleto)

Archivo: `config/Migrations/YYYYMMDDHHMMSS_CreateIngredients.php`.

```php
class CreateIngredients extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('ingredients')) { return; }

        $this->table('ingredients', [
                'collation' => 'utf8mb4_unicode_ci',
                'signed' => false,
            ])
            ->addColumn('name', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('unit', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('stock_quantity', 'decimal', ['precision' => 12, 'scale' => 3, 'null' => false, 'default' => '0.000'])
            ->addColumn('unit_cost', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => false, 'default' => '0.00'])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['name'], ['unique' => true, 'name' => 'uniq_ingredients_name'])
            ->addIndex(['stock_quantity'], ['name' => 'idx_ingredients_low_stock'])
            ->create();
    }

    public function down(): void
    {
        $this->table('ingredients')->drop()->save();
    }
}
```

---

## 6. Screens & UX

Todas las vistas usan el layout `default.php` y los componentes de `DESIGN.md`. Mantener el patrón del módulo Products (tarjeta de filtros sobre tabla, paginación abajo, breadcrumbs en topbar).

### 6.1 `index.php` — Listado

**Encabezado de página (`dr-page-header`):**
- `h1.dr-page-title`: "Ingredientes".
- `button-primary` único: "Nuevo ingrediente" (acción más importante de la pantalla).

**Card de filtros (sobre la tabla):**
Layout horizontal, todos los controles a 40px de altura (regla DESIGN):
1. Input texto `q` con icono `bi-search`, placeholder "Buscar por nombre" (max-width 320px).
2. Select `unit` con opciones `Todas las unidades` + `UNIT_LABELS` (max-width 200px).
3. Checkbox switch `low_stock` con label "Solo bajo stock" (≤ 5).
4. Botón "Filtrar" (`btn-secondary`).
5. Link "Limpiar" (`btn-light`) si hay algún filtro activo.

**Tabla (`card` + `table`):**
| Columna           | Width  | Alineación | Contenido                                                              |
|-------------------|--------|------------|------------------------------------------------------------------------|
| Nombre            | auto   | left       | Link a `edit` con `h($ingredient->name)`. Si bajo stock, badge a la derecha. |
| Unidad            | 110px  | left       | Label legible (`UNIT_LABELS[$unit]`).                                  |
| Stock actual      | 140px  | right      | `getFormattedStock()`. Si bajo stock, color `text-danger`.             |
| Costo unitario    | 140px  | right      | `getFormattedUnitCost()`.                                              |
| Estado            | 140px  | center     | Badge stock-bajo / OK (ver §6.5 sobre decisión badge vs status).       |
| Acciones          | 140px  | right      | `btn-icon` editar (`bi-pencil`) + `btn-icon text-danger` eliminar (`bi-trash`) con `confirm`. |

Filas con `low_stock` reciben **solo el badge** + texto rojo en columna stock — sin fondo rojo en toda la fila (regla DESIGN §Don'ts: no rojo saturado en regiones grandes).

**Empty state:**
- Sin filtros: "Aún no hay ingredientes. [Crear el primero]".
- Con filtros: "Sin resultados para los filtros aplicados".

**Pie:** `<?= $this->element('pagination') ?>`.

### 6.2 `add.php` y `edit.php` — Formularios

Comparten un partial `templates/element/Ingredients/_form.php` (mismo patrón que `templates/element/Products/_form.php`).

**Estructura del form (card único, layout en 2 columnas en `md+`):**

Columna izquierda — Identidad:
- `name`: input texto, requerido, autofocus en `add`. Helper text: "Debe ser único".
- `unit`: select con `UNIT_LABELS`. En `edit` mostrar warning helper si hay recetas/ajustes asociados (ver §7).

Columna derecha — Inventario y costo:
- `stock_quantity`: input numérico con `step="0.001"`, `min="0"`. Sufijo visual con la unidad seleccionada (actualización JS al cambiar el select).
- `unit_cost`: input numérico con `step="0.01"`, `min="0"`. Prefijo visual `$`.

**Pie del form:**
- `button-primary` "Crear ingrediente" / "Guardar cambios" (uno por pantalla).
- `btn-light` "Cancelar" que redirige a `index` (en `add`) o `view` (en `edit`).

**`edit.php` adicional:** botón secundario `button-danger` "Eliminar" arriba a la derecha (con `confirm` de JS) — usa `Form->postLink` a la acción `delete`. Aplica solo si `userPermissions['ingredients']['delete']` es true.

### 6.3 `view.php` — Detalle

Estructura similar a `Products/view.php`:
- Header con nombre + botones "Editar" (`btn-secondary`) y "Volver" (`btn-light`).
- Card principal con `<dl>` de dos columnas:
  - Nombre
  - Unidad (label legible)
  - Stock actual (formateado, con badge bajo stock si aplica)
  - Costo unitario (formateado)
  - Creado / Modificado
- Sección "Usos en recetas" — placeholder hoy (`<div class="text-muted">Las recetas se mostrarán cuando el módulo esté disponible.</div>`), wireada para cuando exista `ProductIngredients`.
- Sección "Últimos ajustes" — mismo placeholder, wireada para `InventoryAdjustments`.

### 6.4 Sidebar / navegación

Agregar item "Ingredientes" en el grupo **Inventario** del sidebar (icono `bi-box-seam` o `bi-egg-fried`). Visible solo si `userPermissions['ingredients']['view']`. Counter opcional (no en esta fase) que muestre cantidad de insumos en bajo stock.

### 6.5 Decisión badge vs status

Usar **`badge-soft-danger`** (genérico, no `status-*`) para "Bajo stock". Razón: la familia `status-*` está reservada por DESIGN.md para el ciclo de vida del **pedido** (pending/preparing/on-route/delivered/cancelled). Un ingrediente no tiene estados de pipeline — solo una alerta de stock. Usar `status-cancelled` o similar confundiría el lenguaje visual. Variantes:

- Stock OK (> 5): sin badge (limpieza visual).
- Bajo stock (≤ 5 y > 0): `<span class="badge badge-soft-danger">Bajo stock</span>` + texto rojo en columna stock.
- Sin stock (= 0): `<span class="badge badge-soft-danger">Sin stock</span>` (mismo color, copy distinto). Helper en entity: `isOutOfStock()`.

---

## 7. Edge cases & business rules

| Caso                                        | Decisión                                                                                                                                                          |
|---------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Nombre duplicado                            | Bloquear en `buildRules::isUnique`. Mensaje en español: "Ya existe un ingrediente con ese nombre". Case-insensitive vía collation.                                |
| Stock negativo al crear/editar              | Bloquear en `validationDefault::greaterThanOrEqual('stock_quantity', 0)`. No "clampear" a 0 — el usuario debe verlo como error.                                   |
| `adjustStock` que dejaría stock negativo    | Service devuelve `['success' => false, 'errors' => ['Stock insuficiente...']]`. NO persiste. Caller (futuro Pedidos) decide si abortar la venta o forzar.         |
| Costo unitario negativo                     | Bloquear igual que stock. `0` es válido (ingredientes regalados o aún sin costear).                                                                               |
| Eliminar ingrediente con recetas/ajustes    | **Permitido** (spec §10). Cascade vía FKs `ON DELETE CASCADE` en las tablas dependientes. Loguear como `Log::warning` con conteo de filas cascadeadas. **No** mostrar confirmación extra "tiene N recetas asociadas" — el flujo del spec es directo. (Riesgo: ver §9.) |
| Cambiar `unit` después de tener stock       | **Permitido**, pero **no convierte** el valor de stock (no es responsabilidad del módulo decidir que 200gr → 0.2kg). Mostrar warning en `edit`: "Cambiar la unidad no convierte el stock actual". Mantiene la operación simple y predecible. |
| Cambiar `unit` con recetas asociadas        | Cuando exista Recetas, agregar warning adicional: "N recetas usan esta unidad, podrían quedar inconsistentes". No bloquear — el sistema confía en el operador.    |
| Precisión decimal en `stock_quantity`       | Tres decimales — cubre `gr`/`ml`. Service redondea a 3 decimales antes de persistir para evitar errores de presentación.                                          |
| Importación masiva                          | Fuera de alcance. CRUD manual únicamente.                                                                                                                         |
| Carrera al descontar stock (Pedidos)        | `adjustStock` usa `SELECT ... FOR UPDATE` dentro de transacción. Documentado en §3.1.                                                                             |
| Eliminar mientras hay pedidos en curso      | Hard delete cascadea el historial pero no afecta pedidos ya entregados (los `order_items` no referencian ingredientes directamente, sino productos). Sin riesgo de FK rota. |
| `is_low_stock` virtual cambiante            | Recalculado en cada read. Sin caché. No persistir.                                                                                                                |

---

## 8. Tests to write later

> El proyecto opta-out de tests automatizados (memoria del usuario). Esta sección queda
> como **referencia** para cuando esa decisión se revierta. **No** escribir ningún archivo
> de test en la implementación actual.

**Ubicaciones esperadas (convención CakePHP 5):**

- `tests/TestCase/Model/Table/IngredientsTableTest.php`
- `tests/TestCase/Model/Entity/IngredientTest.php`
- `tests/TestCase/Service/IngredientServiceTest.php`
- `tests/TestCase/Controller/IngredientsControllerTest.php`
- `tests/Fixture/IngredientsFixture.php`

**Casos a cubrir (cuando aplique):**

**Table (`IngredientsTableTest`):**
- Validación rechaza name vacío, unit fuera de `UNITS`, stock negativo, cost negativo.
- `isUnique` rechaza nombre duplicado (case-insensitive).
- `findLowStock` filtra correctamente `<= 5`.
- `findSearch` aplica LIKE por nombre.
- `findNameList` formatea `"name (unit)"`.

**Entity (`IngredientTest`):**
- `isLowStock` true cuando stock ≤ 5, false en otro caso.
- `isOutOfStock` true sólo cuando stock = 0.
- `getFormattedStock` con valores enteros y decimales.
- `getFormattedUnitCost` con miles.

**Service (`IngredientServiceTest`):**
- `create` retorna `success=true` con datos válidos.
- `create` retorna `success=false` con nombre duplicado y errors en español.
- `update` permite cambiar unit (con warning, no bloqueo).
- `delete` borra fila simple (sin dependencias).
- `delete` cascade cuando existan `product_ingredients` (test de integración con fixtures).
- `adjustStock` suma/resta correctamente.
- `adjustStock` rechaza negativo: stock 1.000, delta -2.000 → error.
- `adjustStock` concurrente (test de integración con dos transacciones) — diferido.

**Controller (`IngredientsControllerTest`):**
- GET `/ingredients` requiere login → redirect a `/login`.
- GET `/ingredients` sin permiso `view` → 403.
- GET `/ingredients?low_stock=1` filtra correctamente.
- POST `/ingredients/add` con permiso `create` → 302 + flash success.
- POST `/ingredients/add` sin permiso → 403.
- POST `/ingredients/delete/{id}` sin permiso → 403.
- POST `/ingredients/delete/{id}` con permiso → 302 + flash success.
- Administrador bypassea matriz.

---

## 9. Open questions / risks

1. **Cascade en `delete` vs auditoría de pedidos.** El spec §10 ordena eliminar referencias en recetas y ajustes al borrar un ingrediente. Pero §9 (Auditoría de Pedidos) dice que toda eliminación que aplica a pedidos queda auditada — y borrar un ingrediente borra ajustes históricos. **Juicio:** Ingredientes no toca pedidos directamente, así que no hay log de auditoría que perder; pero sí se pierde el historial de ajustes (compras, mermas) que podría ser información financiera relevante. **Recomendación a confirmar con el usuario:** considerar agregar una columna `deleted_at` (soft delete) en una fase posterior, manteniendo cascade duro para Fase 1 tal como dicta el spec. Por ahora se implementa lo literal.

2. **Conversión de unidades.** Decidí NO convertir stock al cambiar `unit` (200gr → 0.2kg). Es predecible pero puede confundir al usuario que cambia accidentalmente la unidad y queda con un valor sin sentido (200kg). **Mitigación:** warning UI en `edit`. **Alternativa futura:** modal de confirmación con opción "convertir automáticamente".

3. **`stock_quantity` como string-decimal vs int.** Optamos por `decimal(12,3)` para soportar `gr`/`ml` realistas. Riesgo: PHP cast a float si no se cuida → errores de redondeo al sumar/restar muchas veces. **Mitigación:** el service redondea explícitamente; `adjustStock` recibe strings y usa la columna sin operaciones intermedias en PHP cuando sea posible (`UPDATE ingredients SET stock_quantity = stock_quantity + :delta` en lugar de leer-modificar-escribir, opcional).

4. **Umbral de bajo stock global vs por ingrediente.** El spec fija 5 unidades como umbral universal. Pero "5 unidades" significa cosas distintas para `gr` (irrisorio) vs `unidad` (razonable). **Juicio:** respetar el spec literalmente y abrir la puerta a un campo `low_stock_threshold` por-ingrediente en una iteración futura — no incluirlo hoy para no inflar el alcance.

5. **Filtro `low_stock` y orden.** Si el usuario filtra "solo bajo stock" y ordena por `stock_quantity ASC`, ve los más críticos primero. **No** forzamos ese orden cuando el filtro está activo (mantiene el orden del usuario), pero es algo a revisar tras feedback de operadores.

6. **Soporte multi-tenant / multi-sede.** Fuera del alcance del spec actual. Si en el futuro Davi Rapid tiene varias sucursales con stocks independientes, esta tabla necesita un `branch_id`. Mencionado por completitud — no implementar hoy.
