# Diseño — Módulo Ajustes de Inventario

> Documento de diseño técnico para el módulo de Inventario → Ajustes. Es un
> **registro append-only** (sin edit) que mueve el stock de los ingredientes
> vía `IngredientService::adjustStock()`. Cada fila es un evento auditable.
>
> Referencias: `davirapid.md` §12 (Ajustes de Inventario), §10 (Ingredientes),
> §21 (Reglas de inventario); `.claude/rules/ARQUITECTURE.md` (capas, patrones,
> familia de servicios, validación tabla vs servicio); `.claude/rules/DESIGN.md`
> (sistema visual); `.claude/designs/01-ingredientes.md` (módulo predecesor —
> expone `IngredientService::adjustStock` que este módulo **debe** consumir);
> `.claude/designs/02-recetas.md` (mismo grupo Inventario en sidebar).

---

## 1. Data model

### 1.1 Tabla `inventory_adjustments`

| Columna          | Tipo                 | Null | Default | Notas                                                                                  |
|------------------|----------------------|------|---------|----------------------------------------------------------------------------------------|
| `id`             | int unsigned, PK, AI | no   | —       | `signed=false`, consistente con `ingredients.id`, `products.id`.                       |
| `ingredient_id`  | int unsigned         | no   | —       | FK → `ingredients.id` **ON DELETE CASCADE**. Spec §10: al borrar un ingrediente se borra su historial. |
| `type`           | varchar(10)          | no   | —       | `'entrada'` o `'baja'`. Validado con `inList` contra `InventoryAdjustmentConstants::TYPES`. |
| `quantity`       | decimal(12,3)        | no   | —       | Magnitud **positiva** (sin signo). El signo lo aporta `type`.                          |
| `reason`         | varchar(120)         | no   | —       | Texto libre obligatorio (con datalist de sugerencias en UI).                           |
| `notes`          | text                 | sí   | null    | Observaciones opcionales.                                                              |
| `user_id`        | int unsigned         | sí   | null    | FK → `users.id` **ON DELETE SET NULL**. Mantener el evento aun si el usuario es eliminado. |
| `created`        | datetime             | no   | —       | Behavior `Timestamp` (set on create). Doubles as "fecha del ajuste".                   |

**Sin `modified`.** Append-only: una vez creada la fila no se edita. Cualquier
"corrección" se hace registrando un nuevo ajuste de signo inverso. Esto se
documenta como decisión explícita en §11 y se enforcea en el controller
(sin acción `edit`) y en el service (sin método `update`).

**Índices:**

- `idx_ia_ingredient_created` (`ingredient_id`, `created`) — soporta
  "todos los ajustes de este ingrediente, más recientes primero" (vista de
  detalle futura del ingrediente).
- `idx_ia_created_desc` (`created`) — listado global por fecha desc (la vista
  principal de Ajustes). MySQL ignora la dirección en index, pero declarar el
  índice acelera el `ORDER BY created DESC` del index page.
- `idx_ia_type` (`type`) — selectividad media (solo dos valores), pero el
  filtro por tipo + rango de fechas se beneficia del compuesto. Alternativa:
  no crearlo si el optimizador lo descarta. **Decisión:** crearlo —
  filtros del listado lo usan habitualmente.
- `idx_ia_user_id` (`user_id`) — para "ajustes hechos por X" en auditoría
  futura. No es bloqueante hoy pero el costo del índice es marginal.
- FK `ingredient_id` → `ingredients(id)` `ON DELETE CASCADE ON UPDATE RESTRICT`.
- FK `user_id` → `users(id)` `ON DELETE SET NULL ON UPDATE RESTRICT`.

**Justificación de columnas:**

- **`type` como `varchar(10)` (no enum DB)** — consistente con `unit` en
  `ingredients`. Validado vía `inList()` contra constantes PHP. Agregar un
  nuevo tipo (improbable, pero p.ej. `'transferencia'` entre sucursales)
  no requeriría migración.
- **`quantity` siempre positiva** — el signo se deriva de `type` en runtime
  (entity helper `getSignedDelta()`). Razón: simplifica formularios
  (`min="0.001"` en el input) y evita estados ilegales como
  `type=entrada, quantity=-2`. La representación en DB es lo que el usuario
  ingresó; el signo se aplica al consumir.
- **`reason` `varchar(120)` obligatorio** — coincide con `name` de ingredientes,
  margen suficiente para frases ("compra a proveedor mayorista X"). Texto libre
  (no enum) porque el spec lo describe como ejemplos, no como lista cerrada:
  "ej. 'compra a proveedor', 'merma', 'daño', 'conteo físico'". La UI muestra
  estas sugerencias vía `<datalist>` para guiar sin restringir.
- **`notes` `TEXT NULL`** — observaciones largas (descripción detallada de
  una merma). Sin límite arbitrario.
- **`user_id` opcional + `ON DELETE SET NULL`** — el evento histórico
  sobrevive a la baja del usuario. La auditoría no debe perderse porque alguien
  dejó la empresa. En UI se muestra `"Usuario eliminado"` cuando es null.
- **`created` `NOT NULL`** (a diferencia del resto del proyecto que usa
  `null=true`). Razón: `created` aquí **es el dato** ("fecha del ajuste"),
  no metadata. Sin `Timestamp` behavior fallaría todo el dominio. Setteado
  por el behavior y nunca queda null en práctica; el `NOT NULL` lo enforcea
  a nivel DB.
- **Sin `modified`** — append-only por diseño (ver §11).

### 1.2 Entity `InventoryAdjustment`

```php
class InventoryAdjustment extends Entity
{
    protected array $_accessible = [
        'ingredient_id' => true,
        'type'          => true,
        'quantity'      => true,
        'reason'        => true,
        'notes'         => true,
        'user_id'       => true,
        // Para hidratar asociaciones desde patchEntity si fuera necesario.
        'ingredient'    => true,
        'user'          => true,
    ];

    protected array $_virtual = ['signed_delta', 'formatted_quantity'];

    public function isEntry(): bool
    {
        return $this->type === InventoryAdjustmentConstants::TYPE_ENTRY;
    }

    public function isBaja(): bool
    {
        return $this->type === InventoryAdjustmentConstants::TYPE_BAJA;
    }

    /** Devuelve la cantidad con signo: '+2.500' para entrada, '-0.250' para baja. */
    public function getSignedDelta(): string
    {
        $sign = $this->isEntry() ? '+' : '-';
        return $sign . number_format(
            (float)$this->quantity,
            IngredientConstants::STOCK_DECIMALS,
            '.',
            '',
        );
    }

    /** Devuelve la cantidad con signo invertido — usado al borrar el ajuste. */
    public function getReverseDelta(): string
    {
        $sign = $this->isEntry() ? '-' : '+';
        return $sign . number_format(
            (float)$this->quantity,
            IngredientConstants::STOCK_DECIMALS,
            '.',
            '',
        );
    }

    /** Cantidad formateada con unidad del ingrediente (ej. "+1.250 gr"). */
    public function getFormattedQuantity(): string
    {
        $unit = $this->ingredient?->unit ?? '';
        return trim($this->getSignedDelta() . ' ' . $unit);
    }

    protected function _getSignedDelta(): string { return $this->getSignedDelta(); }
    protected function _getFormattedQuantity(): string { return $this->getFormattedQuantity(); }
}
```

### 1.3 Tabla `InventoryAdjustmentsTable`

**`initialize()`:**

- `setTable('inventory_adjustments')`, `setPrimaryKey('id')`,
  `setDisplayField('reason')`.
- `addBehavior('Timestamp', ['events' => ['Model.beforeSave' => ['created' => 'new']]])`
  — solo `created`, no `modified` (no existe columna).
- Asociaciones:
  - `belongsTo('Ingredients', ['foreignKey' => 'ingredient_id', 'joinType' => 'INNER'])`.
  - `belongsTo('Users', ['foreignKey' => 'user_id', 'joinType' => 'LEFT'])` —
    LEFT join porque `user_id` puede ser null tras `ON DELETE SET NULL`.

**`validationDefault()`** (validación de **formato**):

```text
- notEmptyString('type', 'El tipo es requerido')
- inList('type', InventoryAdjustmentConstants::TYPES, 'Tipo inválido')
- notEmptyString('reason', 'El motivo es requerido')
- maxLength('reason', 120, 'El motivo no puede exceder 120 caracteres')
- numeric('quantity', 'La cantidad debe ser numérica')
- greaterThan('quantity', 0, 'La cantidad debe ser mayor a 0')
- requirePresence('ingredient_id', 'create')
- integer('ingredient_id')
- allowEmptyString('notes')
```

**`buildRules()`:**

- `existsIn(['ingredient_id'], 'Ingredients', 'El ingrediente no existe')`.
- `existsIn(['user_id'], 'Users', ['allowNullableNulls' => true])` — permite
  null pero valida si está presente.

**Custom finders:**

- `findChronological(SelectQuery $query): SelectQuery` — `orderBy(['created' => 'DESC', 'id' => 'DESC'])`
  (id desempata cuando dos filas comparten `created` al segundo).
- `findByIngredient(SelectQuery $q, array $opts): SelectQuery` — recibe `['ingredient_id' => int]`.
- `findByType(SelectQuery $q, array $opts): SelectQuery` — recibe `['type' => string]`.
- `findInDateRange(SelectQuery $q, array $opts): SelectQuery` — recibe
  `['from' => string|null, 'to' => string|null]`, aplica `created >= from`
  y `created <= to 23:59:59`.
- **No** sobrescribir `findList()` (regla del proyecto, CakePHP 5).

---

## 2. Constants — `InventoryAdjustmentConstants`

```php
final class InventoryAdjustmentConstants
{
    public const TYPE_ENTRY = 'entrada';
    public const TYPE_BAJA  = 'baja';

    /** @var list<string> */
    public const TYPES = [self::TYPE_ENTRY, self::TYPE_BAJA];

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_ENTRY => 'Entrada',
        self::TYPE_BAJA  => 'Baja',
    ];

    /**
     * Sugerencias presentadas en el datalist del formulario.
     * El campo es texto libre (spec §12) — esto es solo guía, no enum.
     * @var list<string>
     */
    public const REASON_SUGGESTIONS = [
        'Compra a proveedor',
        'Merma',
        'Daño',
        'Conteo físico',
        'Devolución',
        'Robo',
    ];

    public const REASON_MAX_LENGTH = 120;
}
```

**Decisiones:**

- Valores literales en español (`'entrada'`, `'baja'`) — terminología del
  negocio visible en la UI, mismo criterio que `OrderConstants::STATUS_CANCELLED`.
- Sugerencias en `PascalCase` para mostrarse tal cual en el datalist. El
  operador puede escribir lo que quiera; las opciones aceleran el caso
  frecuente.
- Sin `BAJA_REASONS` ni `ENTRY_REASONS` por separado — la lista única simplifica
  y no restringe (el operador podría tener un caso legítimo donde "Devolución"
  sea entrada).

---

## 3. Service layer — `InventoryAdjustmentService`

Patrón calcado de `IngredientService` / `RecipeService`: métodos retornan
`array{success: bool, adjustment?: ..., errors?: string[]}`; toda operación
que mueve stock se envuelve en `Connection::transactional()`; **toda mutación
de stock pasa por `IngredientService::adjustStock`**, nunca tocando la columna
`stock_quantity` directamente.

### 3.1 Constructor

```php
public function __construct(?IngredientService $ingredients = null)
{
    $this->ingredients = $ingredients ?? new IngredientService();
}
```

DI opcional — production usa la instancia por defecto, tests pueden mockear.

### 3.2 Métodos públicos

| Método                                                | Propósito                                                                                                  |
|-------------------------------------------------------|------------------------------------------------------------------------------------------------------------|
| `create(array $data, int $userId): array`             | Crea el ajuste y mueve el stock atómicamente. Falla si `adjustStock` rechaza (stock insuficiente en baja). |
| `delete(InventoryAdjustment $adj): array`             | Revierte el ajuste y borra la fila atómicamente. Falla si la reversión dejaría stock negativo.             |
| **(no `update`)**                                     | Append-only por diseño. Una corrección se hace creando un ajuste de signo opuesto.                         |

#### `create(array $data, int $userId): array`

```text
1. Validar input mínimo:
   - 'ingredient_id' presente y entero.
   - 'type' ∈ TYPES.
   - 'quantity' > 0 numérico.
   - 'reason' no vacío.
   Si falla, retornar ['success' => false, 'errors' => [...]] SIN tocar DB.

2. Fetch del ingrediente fresco vía $this->fetchTable('Ingredients')->get($id).
   Si no existe → error 'Ingrediente no encontrado'.

3. Abrir transacción ($conn->transactional(function() use(...) { ... })):
   a. Construir entity con $data + ['user_id' => $userId].
   b. $adjustmentsTable->save($adjustment).
      - Si falla → return false (rollback).
   c. Llamar $this->ingredients->adjustStock(
          $ingredient,
          $adjustment->getSignedDelta(),    // '+2.500' o '-0.250'
          "Ajuste #{$adjustment->id}: {$adjustment->reason}"
      ).
      - Si retorna success=false → return false (rollback, propagar el error).
   d. Log::info('Inventory adjustment created: id={id} ingredient={ing} type={t} qty={q}', ...).
   e. return true (commit).

4. Retornar resultado estructurado.
```

**Crítico:** `adjustStock` ya abre su propia transacción con `SELECT ... FOR UPDATE`.
Cuando se llama desde dentro de una transacción del caller, CakePHP usa
savepoints anidados — el rollback externo deshace el adjustStock. Validado
contra el código actual de `IngredientService::adjustStock` (líneas 113-179).

#### `delete(InventoryAdjustment $adj): array`

```text
1. Cargar el ingrediente fresco (contain o get).
   Si no existe (improbable: cascade ya habría borrado el ajuste) → error.

2. Abrir transacción:
   a. Llamar $this->ingredients->adjustStock(
          $ingredient,
          $adj->getReverseDelta(),   // signo invertido
          "Reversión del ajuste #{$adj->id}"
      ).
      - Si retorna success=false (típico: revertir una entrada de 10
        unidades cuando solo quedan 3 en stock → bajaría a -7) →
        return false (rollback, propagar 'Stock insuficiente...').
   b. $adjustmentsTable->delete($adj).
      - Si falla → return false (rollback).
   c. Log::warning('Inventory adjustment reversed: id={id} ingredient={ing} reverse_delta={d}', ...).
   d. return true (commit).

3. Retornar resultado.
```

**El error de stock insuficiente al revertir** se vuelve un mensaje claro
para el usuario: *"No se puede eliminar el ajuste: dejaría el stock de
{ingrediente} en negativo (actual: X, requiere revertir: Y)."* Esta lógica
de mensaje vive en el service, no en el controller (regla §4.8 de la arquitectura).

### 3.3 Validación: tabla vs servicio

| Capa     | Reglas                                                                                                              |
|----------|---------------------------------------------------------------------------------------------------------------------|
| Tabla    | Presencia/tipo/longitud/`inList`/`> 0` (ver §1.3). `existsIn` de `ingredient_id`.                                   |
| Servicio | Validación de input ANTES de tocar DB (defensa rápida), orquestación con `adjustStock`, manejo de stock-negativo en `delete`. |

No mover `inList` ni `gt 0` al service — son chequeos de forma. Ver §4.11 de
la arquitectura.

### 3.4 ¿Por qué no hay `update`?

Tres razones, en orden de importancia:

1. **Auditoría.** El spec §12 lista "registrar nuevo ajuste" y "eliminar un
   ajuste" — nunca "editar". Cada ajuste es un evento; editarlo borraría
   evidencia (¿qué motivo había antes?). Append-only es la semántica natural
   de un libro de movimientos.
2. **Reglas de inventario §21.5.** "Los ajustes manuales registran el motivo
   siempre" — un edit que cambie el motivo invalida esta garantía.
3. **Simplicidad de stock.** Un edit que cambie cantidad o tipo debería:
   revertir el delta viejo, validar que no caiga negativo, aplicar el delta
   nuevo, validar que no caiga negativo. Es ejecutable, pero el flujo
   "borrar + recrear" lo logra con dos operaciones ya probadas y mantiene
   trazabilidad: aparecen dos filas (el original y el corregido) en lugar de
   una mutada.

Decisión documentada explícitamente porque el formulario `bake` por defecto
genera `edit`. Si el usuario pide editar, se redirige a `delete + add`.

---

## 4. Controller — `AdjustmentsController`

**URL pública:** `/adjustments`. Más corta que `/inventory-adjustments`
(que también funcionaría con un controller `InventoryAdjustmentsController`)
y consistente con el dominio operativo — "Ajustes" es como se conoce en la
UI (sidebar, breadcrumbs). El controller convencional `Adjustments` ⇒ tabla
`adjustments` rompería la convención CakePHP, **por lo que se usa
`setTable('inventory_adjustments')`** explícitamente en la tabla y se mantiene
el controller como `AdjustmentsController` con `$this->fetchTable('InventoryAdjustments')`
cuando sea necesario. Alternativa rechazada: `InventoryAdjustmentsController`
+ URL `/inventory-adjustments` — más verboso sin beneficio.

> **Importante con CakePHP 5:** porque el controller `Adjustments` no
> matchea por inflexión la tabla `inventory_adjustments`, debemos:
> 1. En `AdjustmentsController::initialize()` cargar explícitamente:
>    `$this->fetchTable('InventoryAdjustments')` y exponerlo como
>    `$this->InventoryAdjustments` (loadModel deprecado en CakePHP 5; usar
>    propiedad asignada manualmente).
> 2. La carpeta de templates va a `templates/Adjustments/` (matchea controller).

### 4.1 Acciones y mapeo de permisos

| Acción     | HTTP        | Permiso (`_actionToPermission` base) | Notas                                                                  |
|------------|-------------|--------------------------------------|------------------------------------------------------------------------|
| `index`    | GET         | `view`                               | Listado cronológico con filtros (ingrediente, tipo, rango fechas).     |
| `add`      | GET/POST    | `create`                             | Form de alta. Acepta `?ingredient_id=N` para pre-seleccionar.          |
| `delete`   | POST/DELETE | `delete`                             | Reversión + borrado vía service.                                       |
| **(sin `view`, sin `edit`)**                  | append-only.                                                           |

Todas las acciones calzan en el mapeo base; **no** se necesita override de
`_actionToPermission`.

### 4.2 Paginación y ordenamiento

```php
public array $paginate = [
    'limit' => 15,
    'maxLimit' => 15,
    'order' => ['InventoryAdjustments.created' => 'DESC', 'InventoryAdjustments.id' => 'DESC'],
    'sortableFields' => ['created', 'type'],
];
```

Sort fijo a `created DESC` por default; el usuario puede cambiar a `type`
para agrupar visualmente, pero la vista de auditoría tiene sentido casi
exclusivamente en orden cronológico inverso.

### 4.3 Filtros (`_currentFilters`)

```text
{
  ingredient_id: int|''     // '' = todos
  type:          'all'|'entrada'|'baja'
  from:          'YYYY-MM-DD'|''
  to:            'YYYY-MM-DD'|''
  sort:          'created'|'type'
  direction:     'asc'|'desc'
}
```

`_buildIndexQuery($filters)`:

- `contain(['Ingredients', 'Users'])` siempre (necesario para mostrar nombre
  + autor sin N+1).
- `WHERE ingredient_id = :id` si presente.
- `WHERE type = :type` si `!== 'all'`.
- Rango fechas: `created >= from 00:00:00` y `created <= to 23:59:59`.
- `ORDER BY` whitelisteado.

### 4.4 Estructura interna

```php
class AdjustmentsController extends AppController
{
    private InventoryAdjustmentService $adjustmentService;
    private \App\Model\Table\InventoryAdjustmentsTable $InventoryAdjustments;
    private \App\Model\Table\IngredientsTable $Ingredients;

    public function initialize(): void
    {
        parent::initialize();
        $this->adjustmentService = new InventoryAdjustmentService();
        $this->InventoryAdjustments = $this->fetchTable('InventoryAdjustments');
        $this->Ingredients = $this->fetchTable('Ingredients');
    }

    public function index(): void { /* paginate + filters + set */ }
    public function add() { /* form + service->create + flash + redirect */ }
    public function delete(int $id) { /* service->delete + flash + redirect */ }
}
```

**`add`:**

- GET: instanciar entity vacía, leer `?ingredient_id=N` del query string para
  pre-seleccionar el ingrediente (caso de uso: click en "Registrar ajuste"
  desde la vista del ingrediente).
- POST: `$result = $service->create($data, $currentUser['id'])`. Flash + redirect.
- Lista de ingredientes para el select: `$this->Ingredients->find('nameList')`.

**`delete`:**

- `allowMethod(['post', 'delete'])`.
- `try { $adj = $this->InventoryAdjustments->get($id, contain: ['Ingredients']); } catch (RecordNotFoundException) { flash + redirect; }`.
- `$result = $this->adjustmentService->delete($adj)`. Si `success=false`,
  el flash muestra `$result['errors'][0]` (ej. el mensaje de stock insuficiente).

**No `view`, no `edit`** — los métodos no existen en el controller. Cualquier
request a `/adjustments/view/N` o `/adjustments/edit/N` cae al fallback de
CakePHP y devuelve 404, que es el comportamiento correcto. (Como capa
adicional, se podría agregar un `disabledActions = ['view', 'edit']` con
chequeo en `beforeFilter` para devolver 405 con mensaje claro; opcional.)

---

## 5. RBAC integration

### 5.1 `AppController::$controllerModuleMap`

Agregar entrada:

```php
'Adjustments' => 'adjustments',
```

### 5.2 `AuthorizationService::MODULES`

Agregar entrada:

```php
'adjustments' => 'Ajustes de Inventario',
```

### 5.3 Seed migration de permisos

Archivo: `config/Migrations/YYYYMMDDHHMMSS_SeedAdjustmentsPermissions.php`
(timestamp posterior a `CreateInventoryAdjustments`). Plantilla calcada de
`SeedIngredientsPermissions` / `SeedRecipesPermissions`:

```php
class SeedAdjustmentsPermissions extends BaseMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // No-admin: view + create + delete. SIN edit (módulo append-only).
        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'adjustments', 1, 1, 0, 0, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 0
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'adjustments'
               )"
        );

        // Administrador: matriz completa por consistencia. can_edit queda en 1
        // aunque el módulo no exponga edit — el bypass aplica igual y la
        // columna existe en el esquema.
        $this->execute(
            "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
             SELECT r.id, 'adjustments', 1, 1, 1, 1, '{$now}', '{$now}'
             FROM roles r
             WHERE r.is_admin = 1
               AND NOT EXISTS (
                 SELECT 1 FROM permissions p
                 WHERE p.role_id = r.id AND p.module = 'adjustments'
               )"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'adjustments'");
    }
}
```

**Decisión sobre `can_delete` default = 0.** En módulos anteriores
(Ingredientes, Recetas) `can_delete` default es 0 también (criterio
conservador). Acá es **más importante** porque borrar un ajuste mueve stock
— se reserva para roles explícitamente autorizados desde la UI de Roles.

### 5.4 Migración `CreateInventoryAdjustments` (esqueleto)

```php
class CreateInventoryAdjustments extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('inventory_adjustments')) { return; }

        $this->table('inventory_adjustments', [
                'collation' => 'utf8mb4_unicode_ci',
                'signed' => false,
            ])
            ->addColumn('ingredient_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('type', 'string', ['limit' => 10, 'null' => false])
            ->addColumn('quantity', 'decimal', ['precision' => 12, 'scale' => 3, 'null' => false])
            ->addColumn('reason', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('notes', 'text', ['null' => true])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['ingredient_id', 'created'], ['name' => 'idx_ia_ingredient_created'])
            ->addIndex(['created'], ['name' => 'idx_ia_created_desc'])
            ->addIndex(['type'], ['name' => 'idx_ia_type'])
            ->addIndex(['user_id'], ['name' => 'idx_ia_user_id'])
            ->addForeignKey('ingredient_id', 'ingredients', 'id', [
                'delete' => 'CASCADE',
                'update' => 'RESTRICT',
                'constraint' => 'fk_ia_ingredient',
            ])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'RESTRICT',
                'constraint' => 'fk_ia_user',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('inventory_adjustments')->drop()->save();
    }
}
```

**Crítico (regla del proyecto, §3.1 ARQUITECTURE.md):** los tipos de las
columnas FK deben coincidir con sus referencias.
- `ingredients.id` es `int unsigned` ⇒ `ingredient_id` `signed=false`.
- `users.id` debe verificarse en `CreateUsers` antes de ejecutar la
  migración. Si la columna `users.id` está `unsigned`, entonces
  `user_id` va con `signed=false`. Si está `signed`, ajustar acá.
  (Asunción razonable: dado que es el mismo proyecto greenfield, todas
  las PKs siguen `signed=false`.)

---

## 6. Screens & UX

Todas las vistas usan `default.php` + componentes de `DESIGN.md`. Patrón
visual heredado de Ingredientes (card de filtros + tabla + paginación).

### 6.1 `index.php` — Listado cronológico

**Encabezado de página (`dr-page-header`):**
- `h1.dr-page-title`: "Ajustes de Inventario".
- `button-primary` único: "Nuevo ajuste" (acción principal de la pantalla,
  destino `/adjustments/add`).

**Card de filtros (sobre la tabla):**
Layout horizontal, todos los controles a 40px (regla DESIGN):
1. Select `ingredient_id` con opciones `Todos los ingredientes` + lista
   (`findNameList`). max-width 240px. Idealmente con búsqueda local
   (`<select>` simple si no hay JS budget, `<datalist>`-input combo si lo hay).
2. Select `type` con `Todos`, `Entradas`, `Bajas`. max-width 140px.
3. Input `from` (date), label "Desde". 160px.
4. Input `to` (date), label "Hasta". 160px.
5. Botón "Filtrar" (`btn-secondary`).
6. Link "Limpiar" (`btn-light`) si hay algún filtro activo.

**Tabla (`card` + `table`):**

| Columna       | Width  | Alineación | Contenido                                                                                |
|---------------|--------|------------|------------------------------------------------------------------------------------------|
| Fecha         | 160px  | left       | `$adj->created->i18nFormat('dd/MM/yyyy HH:mm')`. Sortable.                                |
| Ingrediente   | auto   | left       | Link a `/ingredients/view/{id}` con `h($adj->ingredient->name)`.                         |
| Tipo          | 110px  | center     | Badge: `badge-soft-success` ("Entrada") o `badge-soft-warning` ("Baja").                 |
| Cantidad      | 140px  | right      | `$adj->getFormattedQuantity()` (ej. `+1.250 gr`, `-0.500 kg`). Color verde/naranja.       |
| Motivo        | 220px  | left       | `h($adj->reason)`. Si hay notas, mostrar ícono `bi-chat-square-text` con tooltip.        |
| Autor         | 160px  | left       | `$adj->user?->name ?? '—'`. Si null, mostrar literal `Usuario eliminado` en `text-muted`. |
| Acciones      | 80px   | right      | `btn-icon text-danger` eliminar (`bi-trash`) con `Form->postLink` y `confirm`.            |

**Decisión sobre badges.** Uso la familia genérica `badge-soft-*`, no la
`status-*` — esta última está reservada por DESIGN.md para el ciclo de vida
del pedido. Mapeo:
- Entrada → `badge-soft-success` (verde, "agrega").
- Baja → `badge-soft-warning` (naranja, "resta sin ser destructivo").

**No** usar `badge-soft-danger` para "Baja" — sería confundir una operación
normal con un error. Reservar el rojo para operaciones destructivas reales
(el botón delete y errores).

**Empty state:**
- Sin filtros: "Aún no hay ajustes registrados. [Registrar el primero]".
- Con filtros: "Sin ajustes para los filtros aplicados".

**Pie:** `<?= $this->element('pagination') ?>`.

### 6.2 `add.php` — Registrar nuevo ajuste

Layout en card único, dos columnas en `md+`.

**Columna izquierda — Datos del ajuste:**

- `ingredient_id`: select grande (40px). Pre-seleccionado vía
  `?ingredient_id=N` si viene del detalle del ingrediente. Opciones de
  `findNameList` (formato `"Carne (gr)"`).
  - Stat side-card debajo: muestra **"Stock actual: 1.250 gr"** del
    ingrediente seleccionado. Si JS disponible, actualizado al cambiar el
    select (fetch a un endpoint `/ingredients/stock/{id}.json` o data-attr
    embebido en cada `<option>`). Sin JS: se ve después de submit.
- `type`: dos radio buttons grandes (chips) "Entrada" / "Baja". Iconos
  `bi-arrow-down-circle` (entrada) y `bi-arrow-up-circle` (baja). Selección
  visible con `primary-soft` de fondo. Default sin seleccionar (forzar
  decisión consciente).
- `quantity`: input numérico, `step="0.001"`, `min="0.001"`. Sufijo visual
  con la unidad del ingrediente seleccionado (actualizado por JS al cambiar
  select, o renderizado server-side tras submit fallido).

**Columna derecha — Contexto:**

- `reason`: input texto con `list="reason-suggestions"`. El `<datalist
  id="reason-suggestions">` se llena con `InventoryAdjustmentConstants::REASON_SUGGESTIONS`.
  Helper text: "Texto libre — las sugerencias son guía".
- `notes`: textarea, 4 rows, opcional. Helper: "Detalle opcional para
  contexto futuro".

**Stat-card de previsualización (sobre el footer):**

Solo si JS disponible:
```text
Stock actual:           1.250 gr
Cantidad del ajuste:    0.500 gr (entrada)
Stock luego del ajuste: 1.750 gr  ← color verde si sube, naranja si baja
```

Si no hay JS: omitir la card. Tras submit, el flash + el listado muestran
el resultado.

**Pie del form:**
- `button-primary` "Registrar ajuste" (una sola acción primaria).
- `btn-light` "Cancelar" → redirect a `/adjustments`.

**Sin botón `Eliminar`** porque no es edit — es alta.

### 6.3 Confirmación de delete

El botón `postLink` de la fila usa el `confirm` nativo de CakePHP:

```text
"¿Eliminar este ajuste? Se revertirá el movimiento y el stock de
{ingrediente} pasará de {actual} a {nuevo}."
```

El cálculo del "nuevo" stock se hace server-side en el template del listado
(barato, ya está hidratado por el `contain`).

**Si el delete falla por stock insuficiente** (revertir una entrada pero ya
se consumió), el service devuelve el mensaje específico y aparece como flash
en `index`:

> *"No se puede eliminar el ajuste: revertir bajaría el stock de Carne a
> -3.500 gr. Registrá un ajuste de entrada antes de eliminar éste."*

### 6.4 Sidebar / navegación

Item "Ajustes" en el grupo **Inventario** del sidebar (icono `bi-arrow-left-right`
o `bi-clipboard-data`). Visible solo si `userPermissions['adjustments']['view']`.

Counter opcional (fuera de alcance hoy): cantidad de ajustes del día —
podría ser ruido. Mejor sin counter inicial.

### 6.5 Vista del ingrediente — sección "Últimos ajustes"

Cuando el módulo esté implementado, actualizar `templates/Ingredients/view.php`
(hoy tiene un placeholder según §6.3 del diseño 01) para mostrar:

- Tabla compacta de los últimos 5 ajustes del ingrediente.
- Botón "Registrar ajuste" → `/adjustments/add?ingredient_id={id}`.
- Link "Ver historial completo" → `/adjustments?ingredient_id={id}`.

Esto cierra el loop de UX: el operador ve un ingrediente con bajo stock,
revisa qué pasó (compras, mermas), y registra el siguiente movimiento sin
salir del flujo.

---

## 7. Edge cases & business rules

| Caso                                                            | Decisión                                                                                                                                       |
|-----------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------|
| `quantity = 0`                                                   | Bloquear en `validationDefault::greaterThan('quantity', 0)`. No tiene sentido un ajuste de cero.                                              |
| `quantity` negativa                                              | Bloquear en `greaterThan`. El signo lo dicta `type`, nunca el valor.                                                                          |
| `reason` vacío                                                   | Bloquear en `notEmptyString`. Spec §12 lo marca obligatorio. Reglas globales §21.5: "los ajustes manuales registran el motivo siempre".       |
| `type` inválido (manipulación del form)                          | Bloquear en `inList`. Mensaje en español.                                                                                                      |
| `ingredient_id` inválido                                         | Bloquear en `existsIn` (`buildRules`).                                                                                                         |
| Baja que dejaría stock < 0 al crear                              | `adjustStock` retorna `success=false` con mensaje "Stock insuficiente para {ing} (actual {x}, requerido {y})". El service propaga, no persiste el ajuste. Flash error. |
| Delete que dejaría stock < 0 al revertir                         | Service rechaza con mensaje claro (ver §6.3). No borra la fila. Usuario debe registrar un ajuste compensatorio primero.                       |
| Ingrediente eliminado durante el flujo (race)                    | Cascade ya borró la fila del ajuste si se borró el ingrediente. Si el usuario tenía la página abierta y dispara delete → `RecordNotFoundException` → flash "El ajuste ya no existe". |
| Usuario eliminado tras crear el ajuste                           | `user_id` → null (ON DELETE SET NULL). UI muestra "Usuario eliminado". Histórico preservado.                                                  |
| Concurrencia en `adjustStock`                                    | `IngredientService::adjustStock` ya usa `SELECT ... FOR UPDATE` dentro de su transacción (verified en líneas 122-125 del service). Los ajustes concurrentes se serializan por ingrediente. |
| Precisión decimal en `quantity` (`3.999999...`)                  | Input usa `step="0.001"`; service redondea a 3 decimales antes de persistir (`number_format` en `getSignedDelta`).                            |
| Display de ceros sobrantes (`+1.500 gr` vs `+1.5 gr`)            | Mantener 3 decimales constantes en backend (consistencia con `stock_quantity`). En UI, una utility opcional `formatTrimZeros($n)` puede recortar `+1.500` → `+1.5`. Decisión: NO recortar; consistencia visual prima sobre brevedad. |
| Notas con HTML/scripts (XSS)                                      | Todos los renders en template usan `h()` (escape de CakePHP). Sin HTML permitido en notas.                                                    |
| Fechas timezone                                                  | `created` se almacena UTC (default Cake). Display con `i18nFormat` que respeta `App.defaultTimezone` (configurado en `config/app.php`).      |
| Filtro de fechas con `to < from`                                  | El controller normaliza: si `to < from`, intercambia y muestra warning Flash una vez. Alternativa: validar y devolver 422. Elegir lo primero por mejor UX. |
| Ajuste creado fuera de horario operativo                         | Sin restricción horaria — operativo 24/7 (ajustes nocturnos por inventario físico son comunes).                                               |
| Filtrado por usuario-repartidor (regla §21 acceso 4)              | **No aplica** a este módulo. Repartidores no registran ajustes; el filtro automático opera sobre Pedidos, no sobre Ajustes.                   |

---

## 8. Tests to write later

> El proyecto opta-out de tests automatizados (memoria del usuario). Esta
> sección queda como **referencia** para cuando esa decisión se revierta.
> **No** escribir ningún archivo de test en la implementación actual.

**Ubicaciones esperadas (convención CakePHP 5):**

- `tests/TestCase/Model/Table/InventoryAdjustmentsTableTest.php`
- `tests/TestCase/Model/Entity/InventoryAdjustmentTest.php`
- `tests/TestCase/Service/InventoryAdjustmentServiceTest.php`
- `tests/TestCase/Controller/AdjustmentsControllerTest.php`
- `tests/Fixture/InventoryAdjustmentsFixture.php`

**Casos a cubrir:**

**Table:**
- Validación rechaza `type` fuera de `TYPES`, `quantity <= 0`, `reason` vacío.
- `existsIn` rechaza `ingredient_id` inexistente.
- `findChronological` ordena `created DESC, id DESC`.
- `findByIngredient` filtra correctamente.
- `findInDateRange` aplica límites inclusivos (`to` con `23:59:59`).

**Entity:**
- `isEntry` / `isBaja` retornan bool correcto por `type`.
- `getSignedDelta` formatea `+2.500` (entrada) y `-0.250` (baja).
- `getReverseDelta` invierte el signo.
- `getFormattedQuantity` concatena con unit del ingrediente.

**Service:**
- `create` exitoso: persiste fila + stock cambia + log emitido.
- `create` con baja > stock: retorna `success=false`, fila NO persiste, stock NO cambia (rollback).
- `create` con `quantity=0`: error de validación, no toca DB.
- `create` con `reason` vacío: error.
- `delete` exitoso: stock revertido + fila borrada.
- `delete` que dejaría stock negativo: rechaza, fila NO se borra, stock NO cambia.
- `delete` con `IngredientService` mock que falla: rollback completo.
- Concurrencia: dos creates concurrentes sobre el mismo ingrediente se serializan
  vía `FOR UPDATE` (test de integración, costoso, diferido).

**Controller:**
- GET `/adjustments` sin login → redirect a `/login`.
- GET `/adjustments` sin permiso `view` → 403.
- GET `/adjustments/add?ingredient_id=5` pre-selecciona el ingrediente 5.
- POST `/adjustments/add` exitoso → 302 + flash success + fila + stock movido.
- POST `/adjustments/add` sin permiso `create` → 403.
- POST `/adjustments/add` con baja > stock → 302 a `add` con flash error, fila NO persiste.
- POST `/adjustments/delete/N` con permiso → 302 + flash + fila borrada + stock revertido.
- POST `/adjustments/delete/N` sin permiso → 403.
- POST `/adjustments/delete/N` que dejaría stock negativo → 302 + flash error específico, fila NO borrada.
- GET `/adjustments/edit/N` → 404 (acción no existe).
- GET `/adjustments/view/N` → 404 (acción no existe).
- Administrador bypassea matriz.
- Filtros del index: `?ingredient_id=N&type=entrada&from=2026-01-01&to=2026-01-31`.

---

## 9. Open questions / risks

1. **¿`AdjustmentsController` vs `InventoryAdjustmentsController`?**
   Recomiendo el corto (`AdjustmentsController` + URL `/adjustments`),
   asumiendo que en el dominio "ajustes" siempre se refiere a inventario.
   Si en una fase futura aparece otro tipo de "ajuste" (ajustes de pago,
   ajustes de stock vs ajustes de precio), habrá que renombrar a
   `InventoryAdjustmentsController`. **Mitigación:** el módulo RBAC se
   llama `adjustments` (singular concepto), fácil de cambiar a
   `inventory_adjustments` con una migración + un edit en `AppController`
   y `AuthorizationService`. Riesgo bajo.

2. **¿Permitir o no `view` (detalle de un ajuste)?** El spec §12 no lo
   menciona; el listado ya muestra todo (fecha, ingrediente, tipo, cantidad,
   motivo, autor). Las `notes` largas se ven con tooltip. **Decisión:** sin
   `view`. Si se vuelve necesario, sumar `view.php` con info completa es
   trivial (1 acción + 1 template + permiso ya existe). Por ahora,
   simplificar.

3. **Reversión vs evento de reversión.** Cuando hago `delete`, modifico
   stock pero la única evidencia es el log + la ausencia de la fila. Una
   alternativa más estricta sería **soft-delete + crear un ajuste de signo
   opuesto automáticamente** (en lugar de borrar). Pros: trazabilidad
   perfecta. Contras: cambia la semántica del spec ("eliminar un ajuste
   también revierte su impacto"), agrega columna `deleted_at` y filtrado,
   duplica filas. **Decisión:** implementar lo literal del spec (delete
   directo + log) en Fase 1. Si auditoría operativa lo pide, evolucionar
   en Fase 2 a la versión con audit trail completo.

4. **Idempotencia del POST `add`.** Doble-submit del form crea dos ajustes
   con el mismo motivo y mueve stock dos veces. **Mitigación leve:** disable
   del botón submit vía JS al primer click. **Mitigación fuerte (futura):**
   token de idempotencia con TTL, similar a lo que se haría en Pedidos.
   No es bloqueante para Fase 1 — los ajustes son raros y reversibles.

5. **Bulk operations.** Spec no las pide. ¿Importación masiva de un conteo
   físico (50 ingredientes ajustados a la vez)? Sería un caso real ("hicimos
   inventario completo del depósito"). **Out of scope** para Fase 1.
   Cuando se pida, se puede agregar `/adjustments/bulk` con CSV upload +
   preview + confirm. Cada línea sería una fila de la tabla, mismo flujo
   `create` por cada una en una transacción única.

6. **Permiso `edit` para Administrador.** El seed marca `can_edit = 1` para
   admin por consistencia con otros módulos, aunque el controller no exponga
   `edit`. Riesgo: cero (la acción no existe ⇒ 404). Pero confunde al leer
   la matriz desde la UI de Roles. **Alternativa:** poner `can_edit = 0`
   para todos (incluido admin) — la columna seguiría existiendo pero el
   significado sería claro. **Decisión:** mantener `can_edit = 1` para
   admin para alinear con la convención existente; documentar acá el motivo.
   Si la UI de Roles confunde, se puede deshabilitar el toggle del módulo
   `adjustments` en la columna "Editar" con un disclaimer.

7. **Unidad del ingrediente al renderizar.** `getFormattedQuantity` concatena
   `quantity` con `ingredient->unit`. Si en el futuro Ingredientes permite
   **convertir unidad** y arrastra los ajustes (hoy NO lo hace según diseño
   01 §7), los ajustes históricos quedarían con la unidad "actual" del
   ingrediente, no con la que tenían al momento del registro. **Mitigación
   futura:** snapshot de `unit` en la fila del ajuste (`unit_snapshot
   VARCHAR(16)`). No agregar hoy — diseño 01 ya decidió no convertir.

8. **Mensaje al revertir y stock < 0.** El error actual ("revertir bajaría
   stock a -X") es informativo pero no resuelve. ¿Ofrecer un wizard "crear
   ajuste compensatorio y luego borrar éste"? **Decisión:** no por ahora.
   El mensaje guía al usuario hacia la acción manual ("Registrá un ajuste
   de entrada antes de eliminar éste"); mantener el flujo simple y predecible.
