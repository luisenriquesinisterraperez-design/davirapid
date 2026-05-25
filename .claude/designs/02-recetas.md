# Diseño — Módulo Recetas (ProductIngredients)

> Documento de diseño técnico para el módulo de Inventario → Recetas. Conecta
> Productos (catálogo) con Ingredientes (stock) y prepara el contrato que el
> futuro módulo de Pedidos consumirá para descontar inventario al vender.
>
> Referencias: `davirapid.md` §11 (Recetas), §10 (Ingredientes), §5 (Productos),
> §13 (Flujo integrado), §21 (Reglas de inventario);
> `.claude/rules/ARQUITECTURE.md` (capas y patrones);
> `.claude/rules/DESIGN.md` (sistema visual);
> `.claude/designs/01-ingredientes.md` (módulo predecesor, ya implementado).

---

## 1. Data model

### 1.1 Tabla `product_ingredients`

Es una **tabla pivote enriquecida** (join table con `quantity` como atributo del
join). No se modela como `HABTM` puro porque el pivote tiene su propio dato de
dominio (`quantity`) y se va a manipular como entidad propia desde la pantalla
de edición de receta.

| Columna           | Tipo                   | Null | Default | Notas                                                                       |
|-------------------|------------------------|------|---------|-----------------------------------------------------------------------------|
| `id`              | int unsigned, PK, AI   | no   | —       | `signed=false`, igual que `products.id` e `ingredients.id`.                  |
| `product_id`      | int unsigned           | no   | —       | FK → `products.id` ON DELETE CASCADE. Mismo tipo (unsigned).                 |
| `ingredient_id`   | int unsigned           | no   | —       | FK → `ingredients.id` ON DELETE CASCADE. Mismo tipo (unsigned).              |
| `quantity`        | decimal(12,3)          | no   | —       | Cantidad por unidad de producto, en la unidad propia del ingrediente.        |
| `created`         | datetime               | sí   | null    | Behavior Timestamp.                                                          |
| `modified`        | datetime               | sí   | null    | Behavior Timestamp.                                                          |

**Índices y constraints:**

- `uniq_pi_product_ingredient` UNIQUE (`product_id`, `ingredient_id`) — un
  ingrediente aparece **una sola vez** por receta. Si el operador quiere
  "dos gramos más", actualiza la línea; no crea una segunda.
- `idx_pi_product_id` (`product_id`) — ya cubierto por el prefijo del UNIQUE,
  pero se agrega explícitamente para claridad y para escenarios donde el
  optimizador no use el compuesto.
- `idx_pi_ingredient_id` (`ingredient_id`) — necesario para las queries
  "¿qué productos usan este ingrediente?" (vista de detalle de ingrediente,
  reverse lookup desde `IngredientService::delete` log).
- FK `product_id` → `products(id)` ON DELETE CASCADE ON UPDATE RESTRICT.
- FK `ingredient_id` → `ingredients(id)` ON DELETE CASCADE ON UPDATE RESTRICT.

**Justificación de columnas:**

- **`quantity` = `decimal(12,3)`** — misma precisión que
  `ingredients.stock_quantity` (3 decimales). Razón: el descuento futuro será
  `stock -= recipe_qty * sold_qty`; mantener idéntica `scale` evita drift de
  precisión y matches falsos por redondeo. El rango (~999.999.999,999) cubre
  cualquier escenario realista (200gr de carne, 0.015l de salsa).
- **No `unit` propia en la línea** — la unidad la dicta el ingrediente
  (`ingredients.unit`). Almacenar la unidad acá sería redundante y abriría la
  puerta a desincronizaciones (línea dice `gr`, ingrediente dice `kg`). Si en
  el futuro se quiere mostrar "200 g" cuando el ingrediente está en `kg`,
  es presentación, no almacenamiento (ver §8).
- **No `unit_cost_snapshot`** — el costo siempre se lee fresh del ingrediente.
  Capturar snapshot en la línea generaría costos rancios en la vista de receta
  (el operador acaba de actualizar el costo y el snapshot sigue mostrando el
  viejo). El costo "histórico" del momento de venta es responsabilidad futura
  de `OrderItem` (snapshot a nivel de venta, no de receta).
- **No soft-delete** — eliminar una línea es operación cotidiana, sin valor
  para auditoría. La auditoría relevante (cuándo se vendió cuánto y cuánto
  costó) vive en Pedidos.
- **Sin columna `notes` o `is_optional`** — fuera del alcance del spec §11.
  Si se requiere, se suma en una iteración posterior.

### 1.2 Entity `ProductIngredient`

```php
class ProductIngredient extends Entity
{
    protected array $_accessible = [
        'product_id'    => true,
        'ingredient_id' => true,
        'quantity'      => true,
        // Para que patchEntity hidrate asociaciones si llegan por form.
        'product'       => true,
        'ingredient'    => true,
    ];

    protected array $_virtual = ['line_cost'];

    /** Costo aportado por esta línea = quantity × ingredient.unit_cost. */
    public function getLineCost(): float
    {
        if (empty($this->ingredient)) {
            return 0.0;
        }

        return round(
            (float)$this->quantity * (float)$this->ingredient->unit_cost,
            IngredientConstants::COST_DECIMALS,
        );
    }

    protected function _getLineCost(): float
    {
        return $this->getLineCost();
    }

    public function getFormattedQuantity(): string
    {
        // Reusa la lógica de Ingredient::getFormattedStock para consistencia
        // visual ("200 gr", "1,5 kg", "3 unidad").
        $value = (float)$this->quantity;
        $formatted = number_format($value, IngredientConstants::STOCK_DECIMALS, ',', '.');
        if (str_ends_with($formatted, ',' . str_repeat('0', IngredientConstants::STOCK_DECIMALS))) {
            $formatted = substr($formatted, 0, -(IngredientConstants::STOCK_DECIMALS + 1));
        }
        $unit = $this->ingredient->unit ?? '';

        return $unit === '' ? $formatted : $formatted . ' ' . $unit;
    }

    public function getFormattedLineCost(): string
    {
        return '$' . number_format($this->getLineCost(), 0, ',', '.');
    }
}
```

> Los helpers `getFormatted*()` requieren que `$this->ingredient` esté
> hidratado. La regla operativa es: cualquier query que termine renderizando
> líneas siempre `contain(['Ingredients'])`. El service lo garantiza.

### 1.3 `ProductIngredientsTable`

```php
class ProductIngredientsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('product_ingredients');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Products', [
            'foreignKey' => 'product_id',
            'joinType'   => 'INNER',
        ]);
        $this->belongsTo('Ingredients', [
            'foreignKey' => 'ingredient_id',
            'joinType'   => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->notEmptyString('product_id', 'El producto es requerido')
            ->integer('product_id')
            ->notEmptyString('ingredient_id', 'El ingrediente es requerido')
            ->integer('ingredient_id')
            ->notEmptyString('quantity', 'La cantidad es requerida')
            ->numeric('quantity', 'La cantidad debe ser numérica')
            ->greaterThan(
                'quantity',
                RecipeConstants::QUANTITY_MIN, // 0
                'La cantidad debe ser mayor a cero',
            )
            ->lessThanOrEqual(
                'quantity',
                RecipeConstants::QUANTITY_MAX, // 999999.999
                'La cantidad excede el máximo permitido',
            );
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        // FK existence: doble check además de la FK de DB para errores en español.
        $rules->add($rules->existsIn(['product_id'], 'Products'),    'productExists');
        $rules->add($rules->existsIn(['ingredient_id'], 'Ingredients'), 'ingredientExists');

        // Unicidad (product_id, ingredient_id): respaldado por el índice UNIQUE,
        // pero acá da un mensaje en español antes de tocar la DB.
        $rules->add(
            $rules->isUnique(
                ['product_id', 'ingredient_id'],
                ['message' => 'Ese ingrediente ya está en la receta de este producto'],
            ),
            'uniqueProductIngredient',
        );

        return $rules;
    }

    /**
     * Hidrata líneas con su ingrediente y las ordena por nombre del ingrediente.
     * Uso típico: pantalla de edición de receta.
     */
    public function findForProduct(SelectQuery $query, array $options): SelectQuery
    {
        return $query
            ->contain(['Ingredients'])
            ->where(['ProductIngredients.product_id' => (int)$options['product_id']])
            ->orderBy(['Ingredients.name' => 'ASC']);
    }

    /**
     * Reverse lookup: productos que usan un ingrediente dado.
     * Uso: vista de detalle de ingrediente, alertas de eliminación.
     */
    public function findForIngredient(SelectQuery $query, array $options): SelectQuery
    {
        return $query
            ->contain(['Products'])
            ->where(['ProductIngredients.ingredient_id' => (int)$options['ingredient_id']])
            ->orderBy(['Products.name' => 'ASC']);
    }
}
```

### 1.4 Asociaciones cruzadas (ver §3)

`ProductsTable` y `IngredientsTable` se actualizan para declarar el `hasMany`
correspondiente. Detalle en §3.

---

## 2. Constants — `RecipeConstants`

Archivo: `src/Constants/RecipeConstants.php`.

```php
final class RecipeConstants
{
    /** Mínimo exclusivo: la cantidad debe ser > 0. */
    public const QUANTITY_MIN = 0;

    /** Tope alto pero no infinito; evita typos absurdos (999.999,999). */
    public const QUANTITY_MAX = 999999.999;

    /** Reusa la precisión de Ingredients para que stock y receta hablen el mismo lenguaje. */
    public const QUANTITY_DECIMALS = IngredientConstants::STOCK_DECIMALS;
}
```

**Decisiones:**

- **No constante de "razón mínima por unidad de producto"** (ej. permitir
  recetas con `0.001`): el sistema confía en el operador. Validar
  `> 0` es suficiente.
- **`QUANTITY_MAX`** existe solo como red de seguridad contra errores de
  tecleo (`200000` en lugar de `200` y que el sistema acepte vaciar todo el
  stock con una venta). Si una receta legítima excede 999999, se ajusta el
  límite.
- **No exposición de constantes de label** (no hay enums de estado, tipo, etc.
  en este módulo — `RecipeConstants` queda compacto).

---

## 3. Asociaciones

### 3.1 Vista completa

| Origen                     | Tipo            | Destino             | Propiedad         | Notas                                                                |
|----------------------------|-----------------|---------------------|-------------------|----------------------------------------------------------------------|
| `ProductIngredients`       | `belongsTo`     | `Products`          | `product`         | `joinType => INNER`, foreignKey `product_id`.                        |
| `ProductIngredients`       | `belongsTo`     | `Ingredients`       | `ingredient`      | `joinType => INNER`, foreignKey `ingredient_id`.                     |
| `Products`                 | `hasMany`       | `ProductIngredients`| `product_ingredients` | `dependent => true`, `cascadeCallbacks => true`. La FK ya cascada en DB, pero CakePHP-side garantiza callbacks. |
| `Products`                 | `belongsToMany` | `Ingredients`       | `ingredients`     | `through => 'ProductIngredients'`, `joinTable => 'product_ingredients'`. Permite `contain(['Ingredients'])` desde Product para listados rápidos. |
| `Ingredients`              | `hasMany`       | `ProductIngredients`| `product_ingredients` | `dependent => true`, `cascadeCallbacks => true`. Coincide con lo que ya está comentado en `IngredientsTable::initialize()`. |
| `Ingredients`              | `belongsToMany` | `Products`          | `products`        | Reverse de la anterior. Útil para "¿en qué productos se usa este ingrediente?" |

### 3.2 Configuración en `ProductsTable::initialize()`

Reemplazar el comentario placeholder existente por:

```php
$this->hasMany('ProductIngredients', [
    'foreignKey'        => 'product_id',
    'dependent'         => true,
    'cascadeCallbacks'  => true,
]);

$this->belongsToMany('Ingredients', [
    'through'      => 'ProductIngredients',
    'foreignKey'   => 'product_id',
    'targetForeignKey' => 'ingredient_id',
    'joinTable'    => 'product_ingredients',
]);
```

### 3.3 Configuración en `IngredientsTable::initialize()`

Reemplazar el comentario placeholder existente por:

```php
$this->hasMany('ProductIngredients', [
    'foreignKey'        => 'ingredient_id',
    'dependent'         => true,
    'cascadeCallbacks'  => true,
]);

$this->belongsToMany('Products', [
    'through'      => 'ProductIngredients',
    'foreignKey'   => 'ingredient_id',
    'targetForeignKey' => 'product_id',
    'joinTable'    => 'product_ingredients',
]);
```

### 3.4 Por qué declarar el `belongsToMany` además del `hasMany`

Los dos coexisten porque resuelven dos casos distintos:

- **`hasMany ProductIngredients`** se usa cuando el código necesita manipular
  el pivote (agregar/quitar líneas con su `quantity`, calcular costos). Es la
  vista "rica" que respeta el atributo del join.
- **`belongsToMany Ingredients`** se usa para queries de lectura simple ("dame
  el producto con sus ingredientes" en un listado). CakePHP genera el JOIN
  automáticamente y el resultado es navegable como
  `$product->ingredients[*]->name`.

Si solo se declarara `belongsToMany`, manipular `quantity` se vuelve incómodo
(hay que ir por `_joinData`). Si solo se declarara `hasMany`, las queries de
lectura siempre requieren explicitar el contain del pivote. Tener ambos da el
mejor patrón por caso de uso.

---

## 4. Service layer — `RecipeService`

### 4.1 Decisión de nombre: `RecipeService` (no `ProductIngredientService`)

**Decisión: `RecipeService`.** Justificación:

- "Receta" es el término del dominio (§11). `ProductIngredient` es el detalle
  de implementación (nombre de la tabla pivote).
- Mantiene paridad con la nomenclatura del spec y del usuario final.
- Cuando exista `OrderService`, hablará de "leer la receta del producto X",
  no de "leer los product_ingredients del producto X".
- Coherente con la línea del proyecto: los servicios se nombran por dominio
  (`CustomerService`, `DeliveryService`), no por tabla.

Archivo: `src/Service/RecipeService.php`.

### 4.2 Contrato público

```php
final class RecipeService
{
    use LocatorAwareTrait;

    public function __construct(?IngredientService $ingredients = null)
    {
        $this->ingredients = $ingredients ?? new IngredientService();
    }

    /**
     * Agrega o actualiza una línea de receta. Opcionalmente actualiza el
     * costo unitario del ingrediente en la misma transacción.
     *
     * @param array{
     *   product_id: int,
     *   ingredient_id: int,
     *   quantity: string|float,
     *   update_ingredient_cost?: bool,
     *   new_unit_cost?: string|float|null,
     * } $data
     * @return array{success: bool, line?: ProductIngredient, errors?: array<int,string>}
     */
    public function addLine(array $data): array;

    /**
     * Actualiza solo la cantidad de una línea existente.
     */
    public function updateLine(int $lineId, string|float $quantity): array;

    /**
     * Borra una línea de receta. No toca el ingrediente, no devuelve stock
     * (la receta es declarativa; el stock se mueve por ventas/ajustes).
     */
    public function removeLine(int $lineId): array;

    /**
     * Devuelve la receta completa (líneas con ingrediente hidratado).
     *
     * @return list<ProductIngredient>
     */
    public function getRecipeFor(int $productId): array;

    /**
     * Costo total de la receta = Σ (quantity × ingredient.unit_cost).
     * Retorna 0.0 si el producto no tiene receta.
     */
    public function calculateRecipeCost(int $productId): float;

    /**
     * ¿El producto tiene al menos una línea de receta?
     * Usado por listado de productos y por OrderService (futuro) para
     * decidir si descontar stock o no (§21 inventario rule 4).
     */
    public function hasRecipe(int $productId): bool;

    /**
     * Contrato leído por OrderService (futuro): devuelve el plan de descuento
     * para vender N unidades de un producto. Cada entrada incluye ingredient_id
     * y la cantidad total a restar.
     *
     * @return list<array{ingredient_id: int, quantity: string}>
     */
    public function buildDecrementPlan(int $productId, int $unitsSold): array;
}
```

### 4.3 Lógica de `addLine`

```text
1. Validar product_id e ingredient_id (existsIn vía buildRules).
2. Si update_ingredient_cost = true:
     a. Validar new_unit_cost (numérico, >= 0).
     b. Abrir Connection::transactional.
     c. ingredientService->update($ingredient, ['unit_cost' => $newCost]).
     d. Si falla, rollback + retornar errores.
3. Si ya existe línea (product_id, ingredient_id):
     - Actualizar quantity (update path) en lugar de fallar por unique.
       Razón: UX — el operador agrega "200gr carne" dos veces queriendo
       decir "ahora son 200gr" (no acumular ni rechazar).
     - Decisión documentada explícita: NO acumular (no sumar 200 + 200);
       SOBREESCRIBIR (queda 200). Acumular es propenso a errores.
4. Insertar/actualizar la línea.
5. Commit (si había transacción).
6. Loguear Log::info con product_id, ingredient_id, quantity, cost_updated.
7. Retornar {success: true, line: $lineConHidratacion}.
```

**Atomicidad cost-update + add-line:** ambas operaciones quedan dentro de la
misma transacción. Si la actualización del costo falla, la línea no se
inserta. Si la inserción falla, el costo no se modifica. **Esto resuelve la
condición de carrera con `IngredientsController::edit()`:** si dos operadores
editan el costo al mismo tiempo desde lugares distintos, la transacción es
serial (con `SELECT ... FOR UPDATE` opcional sobre el ingrediente si la
contención es alta — diferido).

### 4.4 Validaciones de negocio

Las validaciones de **formato** quedan en `ProductIngredientsTable` (presencia,
numérico, `> 0`, `isUnique`, `existsIn`). El servicio aporta:

| Regla                                              | Capa     | Mensaje en español                                                  |
|----------------------------------------------------|----------|---------------------------------------------------------------------|
| Producto no existe                                 | Tabla    | "El producto seleccionado no existe."                                |
| Ingrediente no existe                              | Tabla    | "El ingrediente seleccionado no existe."                             |
| Cantidad ≤ 0                                       | Tabla    | "La cantidad debe ser mayor a cero."                                 |
| Cantidad > QUANTITY_MAX                            | Tabla    | "La cantidad excede el máximo permitido."                            |
| Duplicado (product_id, ingredient_id)              | Tabla    | "Ese ingrediente ya está en la receta de este producto."             |
| `update_ingredient_cost=true` sin `new_unit_cost`  | Servicio | "Si vas a actualizar el costo, ingresá el nuevo valor."              |
| `new_unit_cost` < 0                                | Servicio | "El nuevo costo no puede ser negativo."                              |
| Producto inactivo (warning, no bloqueo)            | Servicio | Solo log/flash de info — no rechaza (ver §8).                        |

### 4.5 Helper `buildDecrementPlan(productId, unitsSold)`

Esta es la **interfaz pública que el futuro `OrderService` consumirá** al
crear/editar/cancelar pedidos. Documentada acá porque define el contrato.

```text
INPUT:  productId = 17,  unitsSold = 2
RECETA: 200 gr carne, 1 unidad pan, 20 gr queso

OUTPUT: [
  {ingredient_id: 4, quantity: '400.000'},  // 200 * 2
  {ingredient_id: 7, quantity: '2.000'},
  {ingredient_id: 9, quantity: '40.000'},
]
```

Reglas:

- Si el producto **no tiene receta**, retorna `[]`. El caller interpreta esto
  como "no hay movimiento de inventario" (spec §21 inventario regla 4).
- Las cantidades se devuelven como **string decimal** con `QUANTITY_DECIMALS`
  decimales — match exacto con la signature de `IngredientService::adjustStock`.
- El método no toca stock — solo calcula el plan. Es responsabilidad de
  `OrderService` envolver la iteración en una transacción y llamar a
  `IngredientService::adjustStock($ingredient, '-' . $qty, 'Order #...')` para
  cada entrada.
- La **cancelación** invierte el signo (`'+' . $qty`).
- La **edición** = cancel old plan + apply new plan, dentro de una sola
  transacción.

### 4.6 Constructor y dependencias

`RecipeService` recibe `IngredientService` por constructor (opcional, con
default). Razón: cuando se actualiza el costo desde el form de receta, se
delega a `IngredientService::update()` para reutilizar normalización de datos
y logging consistente. No re-implementar lo de Ingredientes.

`RecipeService` **no** depende de `ProductService` — leer un producto se hace
con `fetchTable('Products')` directo, sin necesidad de orquestar nada.

---

## 5. Controller — decisión y diseño

### 5.1 Decisión: **nested bajo `ProductsController`** + `RecipesController` solo para listado

**Híbrido justificado.** Razones:

- La receta es **per-producto** (no hay "ver receta sin un producto" como
  acción de edición). Encajar `editRecipe($productId)` como acción de
  `ProductsController` evita un controller dedicado solo para tres acciones
  triviales.
- Pero el spec §3.5 lista **Recetas como módulo independiente** de la matriz
  RBAC, y la sidebar necesita un item "Recetas" como entrada visible al
  catálogo completo (§7.5 abajo).
- Solución: `RecipesController::index()` para el listado global (todos los
  productos con su estado de receta), y todas las mutaciones sobre líneas
  individuales se hacen vía `ProductsController::recipe($id)` (la pantalla de
  edición de receta del producto).

### 5.2 Acciones

#### `ProductsController` (acciones nuevas)

| Acción         | HTTP        | Ruta                                              | Permiso (`_actionToPermission`)        |
|----------------|-------------|---------------------------------------------------|----------------------------------------|
| `recipe`       | GET         | `/products/recipe/{id}`                           | `recipes` `view`                       |
| `addRecipeLine`| POST        | `/products/add-recipe-line/{id}`                  | `recipes` `create`                     |
| `updateRecipeLine` | POST    | `/products/update-recipe-line/{id}/{lineId}`      | `recipes` `edit`                       |
| `removeRecipeLine` | POST    | `/products/remove-recipe-line/{id}/{lineId}`      | `recipes` `delete`                     |

> **Importante:** estas acciones viven en `ProductsController` (porque el
> recurso padre es el producto), pero **se chequean contra el módulo
> `recipes`** — no contra `products`. Eso requiere extender
> `AppController::_enforcePermission()` para soportar **mapeo per-acción a
> módulo distinto** (hoy solo mapea per-controller). Detalle en §6.

#### `RecipesController` (solo lectura)

| Acción         | HTTP        | Ruta                                              | Permiso                                |
|----------------|-------------|---------------------------------------------------|----------------------------------------|
| `index`        | GET         | `/recipes` (CRUD estándar vía fallback)           | `recipes` `view`                       |

`index` lista todos los productos con su conteo de líneas, link a editar
receta. No tiene `add/edit/delete` porque las mutaciones viven nested bajo
producto.

### 5.3 Rutas custom (registrar **antes** de `fallbacks()` en `config/routes.php`)

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

### 5.4 Esqueleto del controller

```php
// En ProductsController:

private RecipeService $recipeService;

public function initialize(): void
{
    parent::initialize();
    $this->productService = new ProductService();
    $this->recipeService  = new RecipeService();
}

public function recipe(int $id): void
{
    $product = $this->Products->get($id);
    $lines   = $this->recipeService->getRecipeFor($id);
    $cost    = $this->recipeService->calculateRecipeCost($id);
    $availableIngredients = $this->fetchTable('Ingredients')
        ->find('nameList')
        ->toArray(); // [id => "Carne (gr)", ...] excluyendo los ya usados (ver UI §7).

    $this->set(compact('product', 'lines', 'cost', 'availableIngredients'));
    $this->set('breadcrumbs', [
        ['label' => 'Productos', 'url' => ['action' => 'index']],
        ['label' => $product->name, 'url' => ['action' => 'view', $id]],
        ['label' => 'Receta'],
    ]);
}

public function addRecipeLine(int $id)
{
    $this->request->allowMethod(['post']);
    $data = $this->request->getData();
    $data['product_id'] = $id;
    $data['update_ingredient_cost'] = !empty($data['update_ingredient_cost']);

    $result = $this->recipeService->addLine($data);
    if ($result['success']) {
        $this->Flash->success('Ingrediente agregado a la receta.');
    } else {
        foreach ($result['errors'] ?? [] as $msg) {
            $this->Flash->error($msg);
        }
    }
    return $this->redirect(['action' => 'recipe', $id]);
}

public function updateRecipeLine(int $id, int $lineId)
{
    $this->request->allowMethod(['post']);
    $quantity = (string)$this->request->getData('quantity', '');
    $result = $this->recipeService->updateLine($lineId, $quantity);
    // ... flash + redirect a recipe($id).
}

public function removeRecipeLine(int $id, int $lineId)
{
    $this->request->allowMethod(['post']);
    $result = $this->recipeService->removeLine($lineId);
    // ... flash + redirect a recipe($id).
}

protected function _actionToPermission(string $action): string
{
    return match ($action) {
        'recipe'             => 'view',
        'addRecipeLine'      => 'create',
        'updateRecipeLine'   => 'edit',
        'removeRecipeLine'   => 'delete',
        'toggleActive'       => 'edit',
        default              => parent::_actionToPermission($action),
    };
}
```

### 5.5 `RecipesController::index`

```php
public function index(): void
{
    // Lista productos con conteo de líneas de receta y costo total calculado.
    $query = $this->fetchTable('Products')
        ->find()
        ->contain([
            'ProductIngredients' => ['Ingredients'],
        ])
        ->orderBy(['Products.name' => 'ASC']);

    $products = $this->paginate($query);
    $this->set(compact('products'));
    $this->set('breadcrumbs', [['label' => 'Recetas']]);
}
```

---

## 6. RBAC integration

### 6.1 Decisión: `recipes` es **módulo propio** (no nested bajo `products`)

Justificación, con tradeoffs:

- **Pro `recipes` propio (decisión adoptada):** El spec §3.5 lo lista
  explícitamente como módulo de la matriz. Permite que un rol "Cocinero"
  vea recetas pero no edite productos (carta), o que un rol "Cajero" vea
  productos pero no toque recetas (que es información sensible de costo).
  Separación operativa real.
- **Contra (mapear bajo `products`):** Más simple — un solo módulo para
  catálogo + receta. Pero acopla dos preocupaciones distintas y contradice
  el spec.

### 6.2 Cambios concretos

**1. `AuthorizationService::MODULES`** — agregar entrada (manteniendo orden de
declaración por dominio):

```php
public const MODULES = [
    'roles'       => 'Roles',
    'users'       => 'Usuarios',
    'products'    => 'Productos',
    'customers'   => 'Clientes',
    'deliveries'  => 'Repartidores',
    'ingredients' => 'Ingredientes',
    'recipes'     => 'Recetas',
];
```

**2. `AppController::$controllerModuleMap`** — registrar el controller standalone:

```php
protected array $controllerModuleMap = [
    // ... existentes
    'Ingredients' => 'ingredients',
    'Recipes'     => 'recipes',
];
```

**3. Soporte para mapeo per-acción a módulo distinto.** Las acciones nested
(`recipe`, `addRecipeLine`, etc.) viven en `ProductsController` pero deben
chequearse contra el módulo `recipes`. Hoy `AppController::beforeFilter`
resuelve el módulo por controller. Hay dos opciones:

- **Opción A (recomendada): tabla `actionModuleMap`** en cada controller que
  necesite override.
  ```php
  // En ProductsController:
  protected array $actionModuleMap = [
      'recipe'           => 'recipes',
      'addRecipeLine'    => 'recipes',
      'updateRecipeLine' => 'recipes',
      'removeRecipeLine' => 'recipes',
  ];
  ```
  Y en `AppController::beforeFilter`, antes de resolver vía
  `$controllerModuleMap`, consultar `$this->actionModuleMap[$action] ?? null`.

- **Opción B: overridear `_resolveModule(string $action)`** como hook
  protegido en `AppController`, sobreescribible per-controller. Más PHP-y,
  pero más implícito.

**Recomendación: Opción A.** Es declarativa, fácil de auditar (`grep
actionModuleMap`) y mantiene el patrón existente de `$controllerModuleMap`.

**4. Seed de permisos.** Migración nueva
`YYYYMMDDHHMMSS_SeedRecipesPermissions.php` (timestamp posterior a
`CreateProductIngredients`). Plantilla calcada de
`SeedIngredientsPermissions`:

```sql
-- Roles no-admin: view+create+edit, sin delete (consistente con Ingredientes).
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
SELECT r.id, 'recipes', 1, 1, 1, 0, NOW(), NOW()
FROM roles r
WHERE r.is_admin = 0
  AND NOT EXISTS (SELECT 1 FROM permissions p WHERE p.role_id = r.id AND p.module = 'recipes');

-- Administrador: matriz completa (consistencia, aunque el bypass cubra).
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
SELECT r.id, 'recipes', 1, 1, 1, 1, NOW(), NOW()
FROM roles r
WHERE r.is_admin = 1
  AND NOT EXISTS (SELECT 1 FROM permissions p WHERE p.role_id = r.id AND p.module = 'recipes');
```

---

## 7. Screens & UX

Todas las vistas usan layout `default.php` y siguen `.claude/rules/DESIGN.md`.

### 7.1 `templates/Products/recipe.php` — Editor de receta por producto

**Pantalla primaria del módulo.** Capas verticales:

#### Capa 1 — Header del producto

`dr-page-header` con:
- Breadcrumbs: `Productos / {nombre} / Receta`.
- `h1.dr-page-title`: "Receta — {nombre del producto}".
- Subtítulo discreto: `code` del producto (Mono, gris) si existe.
- Acción secundaria a la derecha: `btn-secondary` "Volver al producto".

#### Capa 2 — Stat-cards (grid 3 columnas en `md+`, stack en mobile)

| Card               | Contenido                                                                 |
|--------------------|---------------------------------------------------------------------------|
| Precio de venta    | `getFormattedPrice()` del producto. Label "Precio de venta".              |
| Costo de receta    | `calculateRecipeCost()` formateado. Label "Costo total de receta".        |
| Margen estimado    | `(price - cost)` formateado, con badge verde/amarillo/rojo según margen.  |

> Si la receta está vacía, "Costo total" muestra `$0` con badge
> `badge-soft-info` "Sin receta", y "Margen estimado" muestra `—`.

#### Capa 3 — Tabla de líneas existentes

`card` que contiene una `table`:

| Columna                | Width | Align  | Contenido                                                                    |
|------------------------|-------|--------|------------------------------------------------------------------------------|
| Ingrediente            | auto  | left   | `h($line->ingredient->name)`. Link a `Ingredients::view($id)` (icono externo). |
| Unidad                 | 100px | left   | `UNIT_LABELS[$unit]`.                                                        |
| Cantidad por unidad    | 160px | right  | Input inline `step="0.001"` + botón pequeño "Actualizar" (form POST a `updateRecipeLine`). Display: `getFormattedQuantity()`. |
| Costo unitario insumo  | 140px | right  | `getFormattedUnitCost()` del ingrediente.                                    |
| Costo de la línea      | 140px | right  | `getFormattedLineCost()`. **Bold**.                                          |
| Acciones               | 90px  | center | `btn-icon` "eliminar" (`bi-trash`, text-danger) con confirm — postLink a `removeRecipeLine`. |

Fila final (footer de tabla, fondo `surface-alt`): "Total" con suma.

#### Capa 4 — Form "Agregar ingrediente"

`card` con `h2` "Agregar ingrediente". Layout:

- Fila 1 (todos a 40px):
  - **Select `ingredient_id`** — usa `findNameList`, **excluye** los ya
    presentes en la receta (el JS / el controller filtra). Si el operador
    selecciona uno ya presente, el servicio sobreescribe la cantidad (regla
    §4.3 punto 3) — pero la UI evita la confusión al excluirlos.
  - **Input `quantity`** — `step="0.001"`, `min="0.001"`. Sufijo dinámico
    con la unidad del ingrediente seleccionado (JS lee `data-unit` del option).
  - **`button-primary`** "Agregar" (única primary de la pantalla — ver
    DESIGN.md "primary una vez por pantalla". El "Volver" y el "Eliminar"
    son secondary/danger). El cálculo de margen no compite.
- Fila 2 — **Sub-form colapsable** "Actualizar costo del ingrediente":
  - Checkbox `update_ingredient_cost` con label "Actualizar costo unitario
    del ingrediente al guardar".
  - Cuando se marca, aparece input `new_unit_cost` con `step="0.01"`,
    pre-poblado con el `unit_cost` actual del ingrediente seleccionado
    (JS lo lee de `data-cost` del option, lo formatea).
  - Helper text: "Este nuevo costo aplicará a todas las recetas que usan
    este ingrediente, no solo a esta línea."

#### Capa 5 — Empty state

Cuando la receta está vacía (`$lines` está vacío), la **capa 3** se reemplaza
por un card con ilustración suave y mensaje:

> **Este producto no tiene receta.**
> Agregá ingredientes para que el inventario se descuente automáticamente al
> vender este producto.

La capa 4 (form de agregar) sigue visible debajo.

### 7.2 `templates/Recipes/index.php` — Listado global

Para que "Recetas" sea visible como navegación de primer nivel.

**Header:** `h1` "Recetas". Sin botón primary — no se crea una receta global;
se crea ingresando al producto.

**Card de filtros (40px):**
- Input `q` — buscar por nombre o código de producto.
- Select `has_recipe` — `Todas` | `Con receta` | `Sin receta`.
- Botón "Filtrar" secondary + link "Limpiar".

**Tabla:**

| Columna         | Width | Contenido                                                       |
|-----------------|-------|-----------------------------------------------------------------|
| Producto        | auto  | Link a `Products::view`. Si inactivo, badge `Inactivo`.         |
| Precio          | 120px | `getFormattedPrice()`.                                          |
| Ingredientes    | 130px | Conteo de líneas (ej. "4 ingredientes") o badge `badge-soft-info` "Sin receta". |
| Costo de receta | 140px | `RecipeService::calculateRecipeCost()` formateado, o "—".       |
| Margen          | 120px | `(price - cost)` con color (verde > 50%, amarillo 20-50%, rojo < 20%). |
| Acciones        | 140px | `btn-secondary` "Editar receta" (link a `Products::recipe`).    |

Paginación estándar 15 items.

> **Riesgo de N+1:** el `contain(['ProductIngredients' => ['Ingredients']])`
> trae todo. Para 15 items por página está bien. Si el catálogo crece >500
> productos, mover el costo a una query agregada o a una columna derivada
> con cache invalidado por save/delete de `product_ingredients`.

### 7.3 Integración en `Products::view` (vista de detalle del producto)

Agregar una sección "Receta" al final del card de detalle:

- Si tiene receta: mini-tabla resumen (nombre + cantidad), enlace
  `btn-secondary` "Editar receta" hacia `Products::recipe($id)`.
- Si no tiene: bloque vacío con `btn-secondary` "Configurar receta".

### 7.4 Integración en `Products::index`

Agregar columna "Receta" (90px, center) entre "Estado" y "Acciones":

- Badge `badge-soft-success` "Sí" si tiene líneas, `badge-soft-default` "No"
  en caso contrario.

> **Performance:** mismo riesgo N+1. Mitigación: subquery en
> `_buildIndexQuery` que cuente `product_ingredients` y use `find('counterCache')`
> o un `leftJoinWith` con `count()`. Alternativa simple para Fase 1: agregar
> `loadInto(...)` sobre los resultados paginados.

### 7.5 Sidebar

Agregar item después de "Ingredientes" en `SidebarHelper::$items`:

```php
[
    'module' => 'recipes',
    'label'  => 'Recetas',
    'icon'   => 'bi-journal-text', // o 'bi-card-list' / 'bi-clipboard-data'
    'url'    => ['controller' => 'Recipes', 'action' => 'index'],
],
```

Visible solo si `userPermissions['recipes']['view']`. Sin contador en Fase 1.

### 7.6 Decisión badge vs status (consistencia con módulo Ingredientes)

- **"Con receta" / "Sin receta"** → `badge-soft-success` / `badge-soft-default`.
  No usar `status-*` (reservados para ciclo de vida de pedido).
- **Margen alto/medio/bajo** → `badge-soft-success` / `badge-soft-warning` /
  `badge-soft-danger`. No usar `status-*`.

---

## 8. Edge cases & business rules

| Caso                                                              | Decisión                                                                                                                                                                                                       |
|-------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Duplicado `(product_id, ingredient_id)` en `addLine`              | El servicio detecta y **sobrescribe** la cantidad (no acumula, no rechaza). UI complementa excluyendo del select los ya presentes para minimizar la situación.                                                  |
| Eliminar la última línea de una receta                            | **No-op silencioso** — queda receta vacía (== sin receta). No mostrar warning. Spec §21 inventario regla 4 dice explícitamente que sin receta no hay movimiento. Es estado válido.                              |
| Cantidad `0` o negativa                                           | Bloquear en `validationDefault::greaterThan('quantity', 0)`. Mensaje en español. No "clampear".                                                                                                                |
| Cantidad excede `QUANTITY_MAX` (999999)                           | Bloquear en validación. Red de seguridad contra typos.                                                                                                                                                          |
| Actualizar costo del ingrediente vía receta vs vía Ingredientes   | **El último save gana.** Ambas operaciones son transaccionales y delegan a `IngredientService::update()`. Si dos sesiones lo hacen en simultáneo, MySQL serializa por row lock. Ningún histórico se mantiene en `ingredients.unit_cost` — esto es deliberado en `01-ingredientes.md` §3.3 (no soft-delete, no auditoría de cambios de costo en Fase 1). |
| Conversión de unidades (200 gr en receta vs 0.2 kg en stock)      | **No convertir.** Almacenar `quantity` en la unidad propia del ingrediente. Si la receta dice "200 gr de carne" y el ingrediente está en `kg`, el operador debe decidir guardar `0.2` o cambiar la unidad del ingrediente. Mostrar la unidad del ingrediente claramente en el form como sufijo del input — el operador no tiene ambigüedad. |
| Cambio de `unit` del ingrediente con líneas de receta existentes  | El módulo Ingredientes muestra warning (ya documentado en `01-ingredientes.md` §7). Receta no convierte. Esto **es un riesgo conocido** (ver §10) — la cantidad almacenada queda en la "vieja" interpretación. |
| Eliminar un producto                                              | Cascade vía FK `ON DELETE CASCADE` borra todas sus líneas de receta. No hay datos huérfanos. No hay log de auditoría (los logs viven en pedidos/ajustes, no en recetas).                                       |
| Eliminar un ingrediente                                           | Cascade vía FK `ON DELETE CASCADE` borra todas las líneas que lo referencian, sin tocar productos. Spec §10 lo permite. El log de `IngredientService::delete` puede enriquecerse en una fase posterior con conteo de líneas cascadeadas (TODO documentado en `01-ingredientes.md` §3.3). |
| Receta de un producto **inactivo**                                | **Permitido.** Editar la receta de un producto desactivado es válido — el operador puede estar preparando el menú para reactivar. La receta no descuenta stock (no se vende). Mostrar badge "Inactivo" en el header de la pantalla `recipe.php` como recordatorio, pero no bloquear ninguna acción. |
| Producto **sin receta** que se vende                              | (Para futuro `OrderService`) `buildDecrementPlan()` retorna `[]`, no se descuenta nada. Spec §21 inventario regla 4. Aplica también a venta de productos con receta vacía (todas las líneas eliminadas).        |
| Precisión decimal del `quantity`                                  | 3 decimales (matchea `STOCK_DECIMALS`). Servicio redondea a 3 decimales antes de persistir.                                                                                                                    |
| Carrera en `addLine` con `update_ingredient_cost=true`            | Toda la operación en `Connection::transactional`. Row lock implícito por el `UPDATE ingredients SET unit_cost = ...`. Si dos sesiones intentan al mismo tiempo, MySQL serializa.                                |
| Cantidad como `string` decimal vs `float`                          | Igual que en Ingredientes: aceptar ambos como input, persistir como decimal-string (lo maneja CakePHP). Cálculo de costo usa `float` con `round()` a `COST_DECIMALS` — no se acumulan saves para que el drift sea mínimo. |
| Eliminar línea mientras hay pedidos en curso que la consumieron   | Los pedidos ya entregados/en preparación tienen sus propios `order_items` (futuros), no referencian `product_ingredients` directamente. Borrar una línea no rompe históricos. La próxima venta del producto consumirá la receta actualizada (sin esa línea).                                                  |
| Reactivar un pedido cancelado tras modificar la receta            | (Para futuro `OrderService`) — el "descuento" usa la receta **actual** en el momento de la reactivación, no la del momento de creación. Decisión documentada para que el caller la respete. Spec §8.5 implica esto al hablar de "restaurar" y "volver a descontar".                                            |
| Importación masiva de recetas                                     | Fuera de alcance. Edición manual por producto.                                                                                                                                                                 |
| Soporte multi-sede                                                | Fuera de alcance. Una receta es global al producto.                                                                                                                                                            |

---

## 9. Tests to write later

> El proyecto opta-out de tests automatizados (memoria del usuario). Esta
> sección queda como **referencia** para cuando esa decisión se revierta. NO
> escribir ningún archivo de test en la implementación actual.

**Ubicaciones (convención CakePHP 5):**

- `tests/TestCase/Model/Table/ProductIngredientsTableTest.php`
- `tests/TestCase/Model/Entity/ProductIngredientTest.php`
- `tests/TestCase/Service/RecipeServiceTest.php`
- `tests/TestCase/Controller/ProductsControllerRecipeTest.php` (solo acciones nested)
- `tests/TestCase/Controller/RecipesControllerTest.php` (solo `index`)
- `tests/Fixture/ProductIngredientsFixture.php`
- Reusar `ProductsFixture`, `IngredientsFixture` ya existentes.

**Casos a cubrir:**

**Table (`ProductIngredientsTableTest`):**
- Validación rechaza `product_id` vacío, `ingredient_id` vacío, `quantity` <= 0.
- `quantity > QUANTITY_MAX` rechazado.
- `existsIn` rechaza `product_id` o `ingredient_id` que no apuntan a filas.
- `isUnique` rechaza duplicado `(product_id, ingredient_id)`.
- `findForProduct` filtra y ordena por nombre de ingrediente.
- `findForIngredient` filtra y ordena por nombre de producto.

**Entity (`ProductIngredientTest`):**
- `getLineCost` retorna `quantity * ingredient.unit_cost` redondeado.
- `getLineCost` retorna `0.0` si `ingredient` no está hidratado (no rompe).
- `getFormattedQuantity` produce "200 gr", "1,5 kg".
- `getFormattedLineCost` produce "$X".
- `_getLineCost` virtual property accesible como `$line->line_cost`.

**Service (`RecipeServiceTest`):**
- `addLine` exitoso retorna `success=true` y la línea con ingredient hidratado.
- `addLine` con `product_id` inexistente retorna error.
- `addLine` con `ingredient_id` inexistente retorna error.
- `addLine` con `quantity <= 0` retorna error.
- `addLine` con `(product_id, ingredient_id)` existente **sobreescribe** quantity.
- `addLine` con `update_ingredient_cost=true` y `new_unit_cost` válido actualiza ambos en transacción.
- `addLine` con `update_ingredient_cost=true` y `new_unit_cost` ausente retorna error y **no** inserta línea.
- `addLine` con `update_ingredient_cost=true` y `new_unit_cost < 0` retorna error y **no** inserta línea.
- `addLine` con falla en save del costo hace rollback de la línea.
- `updateLine` exitoso.
- `updateLine` con `lineId` inexistente retorna error.
- `updateLine` con `quantity <= 0` retorna error.
- `removeLine` borra y retorna success.
- `removeLine` con `lineId` inexistente retorna error.
- `getRecipeFor` retorna lista hidratada ordenada por nombre.
- `getRecipeFor` retorna `[]` si producto sin receta.
- `calculateRecipeCost` suma correctamente; retorna `0.0` si vacía.
- `hasRecipe` `true`/`false`.
- `buildDecrementPlan(productId, 2)` retorna líneas con `quantity * 2`.
- `buildDecrementPlan` retorna `[]` si producto sin receta.
- `buildDecrementPlan` con `unitsSold = 0` retorna `[]`.
- Test de integración: dos `addLine` concurrentes con `update_ingredient_cost`
  serializan (uno gana, ambos commit con valor final consistente).

**Controller (`ProductsControllerRecipeTest`):**
- GET `/products/recipe/{id}` sin login → 302 a `/login`.
- GET `/products/recipe/{id}` con rol que tiene `products.view` pero NO `recipes.view` → 403 (valida el override `actionModuleMap`).
- GET `/products/recipe/{id}` con `recipes.view` → 200, render con product, lines, cost.
- POST `/products/add-recipe-line/{id}` sin `recipes.create` → 403.
- POST `/products/add-recipe-line/{id}` con CSRF inválido → 403.
- POST `/products/add-recipe-line/{id}` exitoso → 302 + flash success.
- POST `/products/remove-recipe-line/{id}/{lineId}` con `recipes.delete` → 302 + flash.
- POST `/products/remove-recipe-line/{id}/{lineId}` sin `recipes.delete` → 403.
- Administrador bypassea matriz para todas las acciones.

**Controller (`RecipesControllerTest`):**
- GET `/recipes` sin login → 302.
- GET `/recipes` sin `recipes.view` → 403.
- GET `/recipes` con permiso → 200 con productos paginados.
- GET `/recipes?has_recipe=with` filtra correctamente.

---

## 10. Open questions / risks

1. **`actionModuleMap` per-acción es un patrón nuevo en el proyecto.** Hoy
   `AppController` solo soporta mapeo por controller. Recetas es el primer
   caso que necesita acciones de un controller chequeadas contra otro módulo.
   La opción A propuesta (§6.2 punto 3) requiere modificar `AppController`,
   no solo agregar archivos. **Alternativa más conservadora:** crear un
   `RecipesController` con sus 4 acciones (`view`, `add`, `edit`, `delete`)
   que reciba `product_id` por query string. Más controllers y URLs menos
   bonitas, pero cero cambios en `AppController`. **Recomendación adoptada:**
   modificar `AppController` para soportar `actionModuleMap` — el patrón es
   limpio y se reutilizará en futuras combinaciones (Abonos bajo CxC, ítems
   bajo Pedidos, etc.).

2. **Snapshot de costo al vender.** La receta refleja el costo **actual** del
   ingrediente. Si vendo una hamburguesa hoy con carne a $50/gr y mañana el
   costo sube a $80/gr, el dashboard que pide "costo de insumos vendidos esta
   semana" (spec §19.3) usa el costo de hoy o el de mañana? **Decisión a
   confirmar:** el spec §19.3 dice "costo de cada ingrediente consumido por
   los productos vendidos" sin precisar momento. Para fidelidad financiera,
   `OrderItem` (futuro) debería guardar snapshot del costo al vender. La
   receta solo es la **fórmula** — no la **factura**. Documentado para que
   `OrderService` lo respete.

3. **Margen como métrica en la UI de receta.** Lo agregué porque es
   información que el operador querría al armar un menú. No está pedido
   explícitamente en el spec §11. **Riesgo:** sumar UI más allá del spec
   puede generar fricción si el cliente lo lee como "no era lo que pedí".
   Lo dejo porque es valioso y barato; quitarlo es trivial.

4. **Excluir ingredientes ya presentes del select de "Agregar".** Mejora UX
   pero significa que el operador no puede "sobreescribir" desde el form de
   agregar (lo hace desde la columna de cantidad inline). Si más adelante se
   prefiere unificar (un solo form que hace add-or-update), es trivial:
   sacar la exclusión y dejar que el servicio decida.

5. **Performance del listado global de Recetas (`Recipes::index`).** El
   `contain(['ProductIngredients' => ['Ingredients']])` trae todo para
   calcular costo. Para 15 productos por página está bien (máx ~150 filas
   adicionales si cada producto tiene 10 ingredientes). Si el catálogo
   crece, considerar columna derivada `recipe_cost_cached` en `products`
   con invalidación por save/delete de `product_ingredients` (callback en
   `ProductIngredientsTable::afterSave` y `afterDelete`).

6. **Auditoría de cambios en receta.** El spec no exige auditar cambios de
   receta (sí audita pedidos §9). Pero si dos operadores se reprochan "vos
   cambiaste la receta y por eso falta stock", no hay traza. **Fuera de
   alcance Fase 1.** Si se requiere, agregar `RecipeHistoryService` siguiendo
   el patrón de `OrderHistoryService` documentado en
   `.claude/rules/ARQUITECTURE.md` §4.13.

7. **"Receta = fórmula" vs "Receta = batch".** La receta actual asume
   "cantidad por **una** unidad de producto". Si en el futuro se quiere
   modelar "esta receta produce 10 hamburguesas" (preparación por lote), el
   modelo cambia y hay que sumar un campo `yields`. **Fuera de alcance.**

8. **Cascada al borrar ingrediente afecta costos históricos.** Si borro
   "Carne" hoy, todas las líneas de receta que la usan desaparecen. Las
   ventas futuras de "Hamburguesa" ya no descontarán carne (la línea no
   existe). Las ventas pasadas no se ven afectadas (el snapshot vive en
   `order_items`, futuro). **Comportamiento esperado según spec §10.**
   Documentado para que nadie se sorprenda.

9. **Reactivación de pedido cancelado tras eliminar/cambiar receta.**
   Documentado en §8 (tabla edge cases). Es responsabilidad del futuro
   `OrderService` decidir si usa la receta "vieja" (snapshot) o la "nueva"
   (live). Documenté la decisión recomendada (live), pero queda abierto para
   el momento en que se implemente Pedidos.
