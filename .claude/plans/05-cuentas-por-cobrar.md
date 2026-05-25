# Plan de implementación — Módulo Cuentas por Cobrar (Receivables)

> Plan paso a paso para implementar el módulo Finanzas → Cuentas por Cobrar
> según el diseño en `.claude/designs/05-cuentas-por-cobrar.md`. El módulo
> nace ahora **con el re-wire de `OrderService`** (los `Log::warning('CxC
> pending...')` actuales son placeholders intencionales que se reemplazan
> aquí).
>
> Referencias obligatorias antes de implementar:
> - `.claude/designs/05-cuentas-por-cobrar.md` (spec definitivo).
> - `.claude/plans/04-pedidos.md` (formato y patrones — el plan más reciente).
> - `.claude/plans/03-ajustes-inventario.md` (patrón append-only + RBAC seed).
> - `src/Service/OrderService.php` — los 6 sitios `Log::warning('CxC pending'`
>   que hay que re-wirear (líneas 194-199, 401-405, 408-412, 415-419,
>   505-509, 578-582, 650-654).
> - `src/Service/CustomerService.php` — `findOrCreateByPhone()` (línea 121).
>   `countDependencies()` (línea 160) usa hoy nombre de tabla
>   `accounts_receivable`; **hay que cambiarlo a `receivables`** para que la
>   regla "no eliminar cliente con CxC" funcione una vez creada esta tabla.
> - `src/Service/InventoryAdjustmentService.php` — patrón service.
> - `src/Controller/AdjustmentsController.php` — patrón controller.
> - `src/Service/AuthorizationService.php` — `MODULES` ya tiene `'orders'` y
>   `'audit'`; agregar `'receivables'`.
> - `src/Controller/AppController.php` — `$controllerModuleMap` ya tiene
>   `'Orders'` y `'OrderLogs'`; agregar `'Receivables'`.
> - `src/View/Helper/SidebarHelper.php` — array `$items` (línea 21).

---

## Decisión crítica: NO bcmath

El diseño menciona `bcsub`/`bcadd` para precision-safe arithmetic. **No
los usamos.** Razones:

- Decimal(12,2) caben holgadamente en floats PHP (precisión doble: 15-17
  dígitos significativos vs los 14 del peor caso para `99999999999.99`).
- El proyecto ya usa `round((float)x, 2)` y `number_format(...,2,'.','')`
  en `OrderService` y `OrderItem::getLineSubtotal()`. Consistencia gana.
- bcmath es opcional en muchas instalaciones PHP.

**Reglas operativas:**
- Para cálculo: `round((float)$a - (float)$b, 2)`.
- Para serializar a DB: `number_format($x, 2, '.', '')`.
- Para guardar en la entity: el resultado de los dos anteriores como string.
- `getBalance()` retorna `float` (no string), `progress_percent` retorna `int`.
- Riesgos de comparación: usar tolerancia `abs($a - $b) < 0.005` si fuera
  necesario en algún check de igualdad sobre montos. Para el módulo 5 no
  hace falta — la única comparación crítica es `paid_amount >= total_amount`
  donde un epsilon de 0.005 es seguro.

---

## 1. File manifest

Orden de creación/modificación. Timestamps: `20260524160000` y `20260524160100`.

### Migraciones (2)

1. `[CREATE] config/Migrations/20260524160000_CreateReceivables.php` — tabla
   `receivables` con FKs SIGNED a `customers`/`orders` y UNSIGNED a `users`,
   UNIQUE sobre `order_id` (idempotencia).
2. `[CREATE] config/Migrations/20260524160100_SeedReceivablesPermissions.php`
   — fila por rol; no-admin `view+create` solamente (no edit/delete por
   default, decisión consistente con `adjustments`); admin matriz completa.

### Constantes (1)

3. `[CREATE] src/Constants/ReceivableConstants.php` — STATUS_PENDIENTE,
   STATUS_PAGADO, STATUSES, STATUS_LABELS, AUTO_DESCRIPTION_TEMPLATE,
   DESCRIPTION_MAX_LENGTH.

### Entity (1)

4. `[CREATE] src/Model/Entity/Receivable.php` — whitelist, virtuals
   `balance` y `progress_percent`, predicados `isPaid`/`isPending`/`hasPayments`,
   métodos `getBalance()` (float, no bcmath), `getProgressPercent()` (int).

### Table (1)

5. `[CREATE] src/Model/Table/ReceivablesTable.php` — `setTable('receivables')`,
   `setDisplayField('description')`, Timestamp, asociaciones (`Customers`
   INNER, `Orders` LEFT, `Creator` alias=Users LEFT), validation, rules
   (`paid_amount <= total_amount`), finders (`findPendingFirst`,
   `findForCustomer`, `findOpen`).
   **Nota:** la asociación `hasMany AccountPayments` se omite hasta que
   el módulo 6 cree esa tabla — el `delete` con `dependent=true` se
   agregará entonces.

### Service (1)

6. `[CREATE] src/Service/ReceivableService.php` — métodos:
   - `createFromOrder(Order, int): array` — idempotente vía UNIQUE; NO abre
     transacción (caller hace).
   - `createManual(array, int): array` — abre transacción.
   - `markAsPaid(Receivable, int): array` — idempotente; abre transacción.
   - `delete(Receivable, int): array` — bloquea si tiene abonos.
   - `deleteForOrder(Order, int, string): array` — NO abre transacción.
   - `findOrCreateForOrder(Order, int): array` — wrapper idempotente.
   - `updateAmountForOrder(Order, int): array` — recalc total; error si
     `paid_amount > new_total`. NO abre transacción.
   - `recomputeStatus(Receivable): array` — recalcula desde abonos;
     hoy (sin módulo 6) usa `paid_amount` actual + flipea status.

### Controller (1)

7. `[CREATE] src/Controller/ReceivablesController.php` — `index`, `view`,
   `add`, `markPaid` (POST), `delete` (POST). Override
   `_actionToPermission` para mapear `markPaid → edit`.

### RBAC + navegación (3 modificaciones)

8. `[MODIFY] src/Controller/AppController.php` — agregar
   `'Receivables' => 'receivables',` al `$controllerModuleMap`.
9. `[MODIFY] src/Service/AuthorizationService.php` — agregar
   `'receivables' => 'Cuentas por Cobrar',` al `MODULES`.
10. `[MODIFY] src/View/Helper/SidebarHelper.php` — agregar item
    `receivables` con icono `bi-cash-coin` (idealmente en una sección
    "Finanzas" — por ahora junto a los demás, después de `adjustments`).

### Rutas (1 modificación)

11. `[MODIFY] config/routes.php` — agregar **antes** de `$builder->fallbacks()`:
    - `POST /receivables/mark-paid/{id}` → Receivables::markPaid.

### CustomerService fix (1 modificación)

12. `[MODIFY] src/Service/CustomerService.php` — cambiar string literal
    `'accounts_receivable'` por `'receivables'` en `countDependencies()`
    (línea 177). Sin esto, la regla "no eliminar cliente con CxC" queda
    rota tras crear la tabla.

### OrderService re-wire (1 modificación — el más importante)

13. `[MODIFY] src/Service/OrderService.php`:
    a. Constructor: agregar `?ReceivableService $receivables = null` y
       campo `private ReceivableService $receivables`.
    b. `create()` líneas 194-199: reemplazar `Log::warning` por
       `$this->receivables->createFromOrder($order, $userId)`; si
       `success=false`, `$resultBox = ['success' => false, 'errors' => $r['errors']]; return false;`.
    c. `update()` líneas 396-419 (3 ramas):
       - No-cred → cred: `createFromOrder($order, $userId)`.
       - Cred → no-cred: `deleteForOrder($order, $userId, 'payment_method_changed')`.
       - Cred → cred con total distinto: `updateAmountForOrder($order, $userId)`.
       Cada uno con return false en error.
    d. `cancel()` líneas 504-509: `deleteForOrder($order, $userId, 'order_cancelled')`.
    e. `reactivate()` líneas 578-582: `findOrCreateForOrder($order, $userId)`.
    f. `delete()` líneas 650-654: `deleteForOrder($order, $userId, 'order_deleted')`.

### Templates (3)

14. `[CREATE] templates/Receivables/index.php` — KPI strip (3 cards),
    filtros (status select / customer_id / from / to / q), tabla, badges.
15. `[CREATE] templates/Receivables/view.php` — layout 2 cols: cliente +
    pedido a la izquierda; card de saldo con barra + botones a la derecha.
16. `[CREATE] templates/Receivables/add.php` — form simple: customer
    select (no autocomplete por simplicidad), total, descripción.

### Tests (6 archivos según diseño §11)

17. `[CREATE] tests/Fixture/ReceivablesFixture.php`.
18. `[CREATE] tests/TestCase/Model/Entity/ReceivableTest.php`.
19. `[CREATE] tests/TestCase/Model/Table/ReceivablesTableTest.php`.
20. `[CREATE] tests/TestCase/Service/ReceivableServiceTest.php`.
21. `[CREATE] tests/TestCase/Controller/ReceivablesControllerTest.php`.
22. `[CREATE] tests/TestCase/Service/OrderServiceCxCIntegrationTest.php`.

### Cierre

23. `[RUN] php bin/cake.php migrations migrate`.
24. `[RUN] php bin/cake.php migrations dump`.
25. `[RUN] composer cs-check` (cs-fix una vez si hace falta).
26. `[RUN] php -l` sobre cada archivo PHP nuevo.
27. `[RUN] php bin/cake.php routes | grep -i receivable`.
28. `[RUN] grep -n "CxC pending" src/Service/OrderService.php` — debe
    devolver nada.
29. HTTP smoke: anonymous GET /receivables → 302.

---

## 2. Step-by-step execution

### Paso 1 — `CreateReceivables` migration

`Migrations\BaseMigration`. Proteger con `hasTable`.

Columnas: `id` (default signed PK), `customer_id` int NOT NULL signed,
`order_id` int NULL signed, `total_amount` decimal(12,2) NOT NULL,
`paid_amount` decimal(12,2) NOT NULL default '0.00', `description`
varchar(255) NOT NULL, `status` varchar(16) NOT NULL default 'pendiente',
`created_by` int NULL unsigned, `created`/`modified` datetime null.

Índices:
- `idx_rec_status_created` (status, created).
- `idx_rec_customer` (customer_id).
- `idx_rec_order` (order_id).
- UNIQUE `uniq_rec_order_id` (order_id). MySQL trata NULLs como
  distintos por default → permite múltiples filas con `order_id=NULL`
  (CxC manuales) y bloquea duplicados para mismo pedido.

FKs:
- `customer_id` → `customers(id)` DELETE RESTRICT, UPDATE RESTRICT,
  constraint `fk_rec_customer`.
- `order_id` → `orders(id)` DELETE SET_NULL, UPDATE RESTRICT,
  constraint `fk_rec_order`.
- `created_by` → `users(id)` DELETE SET_NULL, UPDATE RESTRICT,
  constraint `fk_rec_creator`.

`down()`: drop seguro.

**Acceptance:** `migrations migrate` corre limpio; `SHOW CREATE TABLE
receivables` muestra los 3 FKs y los 4 índices.

### Paso 2 — `SeedReceivablesPermissions`

Calco de `SeedAdjustmentsPermissions`. Module = `'receivables'`. No-admin
view+create (no edit, no delete — operaciones sensibles). Admin matriz
completa por consistencia.

### Paso 3 — `ReceivableConstants`

Final class con: STATUS_PENDIENTE='pendiente', STATUS_PAGADO='pagado',
STATUSES array, STATUS_LABELS map, AUTO_DESCRIPTION_TEMPLATE =
`'Pedido #%d - %s'`, DESCRIPTION_MAX_LENGTH = 255.

### Paso 4 — `Receivable` entity

```php
class Receivable extends Entity {
    protected array $_accessible = [
        'customer_id' => true, 'order_id' => true,
        'total_amount' => true, 'paid_amount' => true,
        'description' => true, 'status' => true,
        'created_by' => true,
        'customer' => true, 'order' => true, 'creator' => true,
    ];
    protected array $_virtual = ['balance', 'progress_percent'];

    public function isPaid(): bool { ... }
    public function isPending(): bool { ... }
    public function hasPayments(): bool {
        return (float)$this->paid_amount > 0.0;
    }
    public function getBalance(): float {
        return round((float)$this->total_amount - (float)$this->paid_amount, 2);
    }
    public function getProgressPercent(): int {
        $total = (float)$this->total_amount;
        if ($total <= 0.0) { return 100; }
        return (int)min(100, round(((float)$this->paid_amount / $total) * 100));
    }
    protected function _getBalance(): float { return $this->getBalance(); }
    protected function _getProgressPercent(): int { return $this->getProgressPercent(); }
}
```

### Paso 5 — `ReceivablesTable`

`initialize`: setTable, displayField 'description', Timestamp, belongsTo
`Customers` INNER, `Orders` LEFT, `Creator` (className=Users, fk=created_by)
LEFT.

`validationDefault`:
- requirePresence customer_id + integer.
- requirePresence total_amount + numeric + greaterThan 0 + decimal(precision=2).
- numeric paid_amount + greaterThanOrEqual 0.
- notEmptyString description + maxLength 255.
- inList status STATUSES.

`buildRules`:
- existsIn customer_id Customers.
- existsIn order_id Orders allowNullableNulls.
- existsIn created_by Users allowNullableNulls.
- Regla custom `paid_amount_within_total`:
  ```php
  $rules->add(function ($entity) {
      return (float)$entity->paid_amount <= (float)$entity->total_amount + 0.005;
  }, 'paidWithinTotal', ['errorField' => 'paid_amount',
     'message' => 'El monto abonado no puede superar el total.']);
  ```

Finders:
- `findPendingFirst`: `orderBy(["CASE WHEN Receivables.status = 'pendiente'
  THEN 0 ELSE 1 END" => 'ASC', 'Receivables.created' => 'DESC'])` — portable
  vía expresión literal.
- `findForCustomer(opts: ['customer_id' => int])`.
- `findOpen`: `where status = pendiente`.

### Paso 6 — `ReceivableService`

Constructor sin args (sin dependencias por ahora).

**`createFromOrder(Order $order, int $userId): array`** — NO abre transacción:
1. Guard: `$order->payment_method !== PAYMENT_CREDIT` → `success=true, receivable=null`.
2. Guard: `(float)$order->total <= 0` → `success=false, errors=['El total del pedido debe ser > 0 para crear CxC.']`.
3. Lookup `find()->where(order_id=...)->first()`. Si existe → return existing
   (`success=true, receivable=$existing, idempotent='reused'`).
4. Build entity (status pendiente, paid_amount '0.00', description vía
   `sprintf(AUTO_DESCRIPTION_TEMPLATE, $order->id, $order->customer?->name ?? $order->customer_name ?? 'Cliente')`).
5. `save()`. Si falla:
   - Re-lookup por order_id (race condition vía UNIQUE) → si existe, return.
   - Sino, return errors.
6. `Log::info('CxC created: id={id} order={o} amount={a}', ...)`.
7. Return `success=true, receivable=$rec`.

**`createManual(array $data, int $userId): array`** — abre transacción.
Validate input manual: customer_id > 0, total_amount > 0, description no vacío.
Fetch customer (exists + is_active). Build entity sin order_id, save, log.

**`markAsPaid(Receivable $rec, int $userId): array`** — abre transacción.
Idempotente (si ya pagado, return success=true). Set
`paid_amount = total_amount`, `status = STATUS_PAGADO`, save, `Log::warning`.

**`delete(Receivable $rec, int $userId): array`** — abre transacción.
Bloquea si `hasPayments()` con mensaje "No se puede eliminar: la cuenta
tiene abonos registrados." (cuando módulo 6 exista, FK cascade cubrirá).

**`deleteForOrder(Order, int, string $reason): array`** — NO abre transacción.
Lookup por order_id. Si no existe → `success=true` (no-op). Si tiene
pagos → `Log::warning` con monto voided. Delete. Return success.

**`findOrCreateForOrder(Order, int)`** — delega a `createFromOrder`.

**`updateAmountForOrder(Order $order, int $userId): array`** — NO abre transacción.
1. Lookup por order_id. Si no existe → llamar `createFromOrder` y return.
2. Si `(float)$rec->paid_amount > (float)$order->total + 0.005` → return
   error "El nuevo total ($X) es menor que lo ya abonado ($Y). Anule abonos primero."
3. `$rec->total_amount = number_format((float)$order->total, 2, '.', '');`
4. Re-derivar `status`: si `paid >= total` → PAGADO, sino PENDIENTE.
5. Save, log, return.

**`recomputeStatus(Receivable $rec): array`** — abre transacción + SELECT
FOR UPDATE. Hoy (sin módulo 6 — tabla `account_payments` no existe),
delegar a recomputar `status` desde `paid_amount` actual (no SUM). Cuando
módulo 6 exista, expandir a leer `account_payments`. Documentar en
docblock.

### Paso 7 — `ReceivablesController`

```php
class ReceivablesController extends AppController {
    public array $paginate = [
        'limit' => 15, 'maxLimit' => 15,
        'order' => [
            "CASE WHEN Receivables.status = 'pendiente' THEN 0 ELSE 1 END" => 'ASC',
            'Receivables.created' => 'DESC',
        ],
        'sortableFields' => ['created', 'total_amount', 'status'],
    ];

    private ReceivableService $service;
    private ReceivablesTable $Receivables;

    public function initialize(): void {
        parent::initialize();
        $this->service = new ReceivableService();
        $this->Receivables = $this->fetchTable('Receivables');
    }

    protected function _actionToPermission(string $action): string {
        return match ($action) {
            'markPaid' => 'edit',
            default => parent::_actionToPermission($action),
        };
    }

    public function index() { ... filters + query + KPI ... }
    public function view(int $id) { ... }
    public function add() { GET render; POST → service->createManual }
    public function markPaid(int $id) { allowMethod post; service->markAsPaid }
    public function delete(int $id) { allowMethod post/delete; service->delete }
}
```

Helpers privados:
- `_currentFilters()`: `status` default 'pendiente', `customer_id`, `from`, `to`, `q`.
- `_buildIndexQuery($filters)`: contain Customers/Orders/Creator + filters.
- `_loadKpis()`: 3 queries — total pendiente, pagado este mes (proxy:
  `SUM(paid_amount) WHERE status='pagado' AND MONTH(modified)=current`),
  clientes con deuda distinct.

### Paso 8 — AppController

Insertar línea tras `'OrderLogs' => 'audit'`:
```php
'Receivables' => 'receivables',
```

### Paso 9 — AuthorizationService

Insertar línea tras `'audit' => 'Auditoría'`:
```php
'receivables' => 'Cuentas por Cobrar',
```

### Paso 10 — SidebarHelper

Insertar item tras `adjustments` y antes de `customers`:
```php
[
    'module' => 'receivables',
    'label' => 'Cuentas por Cobrar',
    'icon' => 'bi-cash-coin',
    'url' => ['controller' => 'Receivables', 'action' => 'index'],
],
```

### Paso 11 — routes.php

Insertar antes del bloque de auditoría:
```php
$builder->connect(
    '/receivables/mark-paid/{id}',
    ['controller' => 'Receivables', 'action' => 'markPaid'],
    ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST'],
);
```

### Paso 12 — CustomerService fix

En `countDependencies()`, cambiar:
```php
if (in_array('accounts_receivable', $existing, true)) {
    // ... 'SELECT COUNT(*) ... FROM accounts_receivable ...'
}
```
A:
```php
if (in_array('receivables', $existing, true)) {
    // ... 'SELECT COUNT(*) ... FROM receivables ...'
}
```

### Paso 13 — OrderService re-wire (CRITICAL)

Ver checklist completa en §3 de este plan.

### Paso 14-16 — Templates

`index.php`: copia patrón de `Adjustments/index.php` + KPI strip arriba +
tabla con columnas (#, Fecha, Cliente, Descripción, Total, Abonado, Saldo,
Estado, Acciones).

`view.php`: 2-col layout. Izq: cards Cliente, Pedido (si order_id), Timeline
abonos placeholder. Der: card saldo grande, botones markPaid/delete,
metadata.

`add.php`: form simple — customer select (find('list')), monto, descripción.

### Paso 17-22 — Tests

Cubrir según diseño §11. Detalles en §4 abajo.

### Paso 23-29 — Cierre

Ejecutar y verificar.

---

## 3. OrderService re-wire instructions (full)

### 3.1 Constructor

```php
private ReceivableService $receivables;

public function __construct(
    ?OrderHistoryService $history = null,
    ?RecipeService $recipes = null,
    ?IngredientService $ingredients = null,
    ?CustomerService $customers = null,
    ?ReceivableService $receivables = null,   // NEW
) {
    $this->history = $history ?? new OrderHistoryService();
    $this->recipes = $recipes ?? new RecipeService();
    $this->ingredients = $ingredients ?? new IngredientService();
    $this->customers = $customers ?? new CustomerService();
    $this->receivables = $receivables ?? new ReceivableService();
}
```

### 3.2 `create()` — líneas 193-199

Reemplazar:
```php
if ($order->isCredit()) {
    Log::warning('CxC pending: order #{id} ...', [...]);
}
```
Por:
```php
if ($order->isCredit()) {
    $cxcResult = $this->receivables->createFromOrder($order, $userId);
    if (empty($cxcResult['success'])) {
        $resultBox = [
            'success' => false,
            'errors' => $cxcResult['errors'] ?? ['No se pudo crear la cuenta por cobrar.'],
        ];

        return false;
    }
}
```

### 3.3 `update()` — líneas 396-419

Reemplazar las 3 ramas `Log::warning(...)` por:
```php
$newPaymentMethod = (string)$order->payment_method;
$newTotal = (string)$order->total;
if (
    $oldPaymentMethod !== OrderConstants::PAYMENT_CREDIT
    && $newPaymentMethod === OrderConstants::PAYMENT_CREDIT
) {
    $cxcResult = $this->receivables->createFromOrder($order, $userId);
    if (empty($cxcResult['success'])) {
        $resultBox = ['success' => false, 'errors' => $cxcResult['errors'] ?? ['Error CxC.']];
        return false;
    }
} elseif (
    $oldPaymentMethod === OrderConstants::PAYMENT_CREDIT
    && $newPaymentMethod !== OrderConstants::PAYMENT_CREDIT
) {
    $cxcResult = $this->receivables->deleteForOrder($order, $userId, 'payment_method_changed');
    if (empty($cxcResult['success'])) {
        $resultBox = ['success' => false, 'errors' => $cxcResult['errors'] ?? ['Error CxC.']];
        return false;
    }
} elseif (
    $oldPaymentMethod === OrderConstants::PAYMENT_CREDIT
    && $newTotal !== $oldTotal
) {
    $cxcResult = $this->receivables->updateAmountForOrder($order, $userId);
    if (empty($cxcResult['success'])) {
        $resultBox = ['success' => false, 'errors' => $cxcResult['errors'] ?? ['Error CxC.']];
        return false;
    }
}
```

### 3.4 `cancel()` — líneas 504-509

Reemplazar por:
```php
if ($order->isCredit()) {
    $cxcResult = $this->receivables->deleteForOrder($order, $userId, 'order_cancelled');
    if (empty($cxcResult['success'])) {
        $resultBox = ['success' => false, 'errors' => $cxcResult['errors'] ?? ['Error CxC.']];

        return false;
    }
}
```

### 3.5 `reactivate()` — líneas 578-582

Reemplazar por:
```php
if ($order->isCredit()) {
    $cxcResult = $this->receivables->findOrCreateForOrder($order, $userId);
    if (empty($cxcResult['success'])) {
        $resultBox = ['success' => false, 'errors' => $cxcResult['errors'] ?? ['Error CxC.']];

        return false;
    }
}
```

### 3.6 `delete()` — líneas 650-654

Reemplazar por:
```php
if ($order->isCredit()) {
    $cxcResult = $this->receivables->deleteForOrder($order, $userId, 'order_deleted');
    if (empty($cxcResult['success'])) {
        $resultBox = ['success' => false, 'errors' => $cxcResult['errors'] ?? ['Error CxC.']];

        return false;
    }
}
```

### 3.7 Imports

Agregar `use App\Service\ReceivableService;` al top.

### 3.8 Verification post-rewire

```bash
grep -n "CxC pending" src/Service/OrderService.php   # should return nothing
grep -n "ReceivableService" src/Service/OrderService.php   # should show import + use
```

---

## 4. Test plan

(Tests requested. Fixtures kept minimal.)

### 4.1 `ReceivablesFixture`

4 filas:
1. Pendiente, manual, amount=100000, paid=0.
2. Pendiente desde orden, amount=50000, paid=20000.
3. Pagado, amount=30000, paid=30000.
4. Pendiente manual con customer compartido con fila 1.

### 4.2 `ReceivableTest` (entity)

- `isPaid` / `isPending`.
- `getBalance()` = total - paid.
- `getProgressPercent()` para 0/40/100%.
- `hasPayments()` boolean por umbral 0.

### 4.3 `ReceivablesTableTest`

- Validation: customer_id required, total_amount > 0, status inList.
- Rule `paid_amount <= total_amount`.
- `findOpen` filtra pendientes.
- `findForCustomer` con customer_id.
- `findPendingFirst` orden correcto (pendientes primero).

### 4.4 `ReceivableServiceTest`

- `createFromOrder` no-cred → no-op.
- `createFromOrder` cred → crea.
- `createFromOrder` cred + ya existe → reutiliza (idempotente).
- `createManual` ok / error sin customer.
- `markAsPaid` cambia status + paid=total.
- `markAsPaid` ya pagado → idempotente success.
- `delete` bloquea si hasPayments.
- `delete` ok si paid=0.
- `deleteForOrder` no existe → success.
- `deleteForOrder` con pagos → log warning + borra.
- `updateAmountForOrder` sube/baja monto.
- `updateAmountForOrder` paid > new_total → error.

### 4.5 `ReceivablesControllerTest`

- GET /receivables → 200 si autenticado.
- GET /receivables → 302 si anonymous.
- GET /receivables/view/{id} → 200.
- POST /receivables/add con datos válidos → 302 + crea fila.
- POST /receivables/mark-paid/{id} → 302 + cambia status.
- POST /receivables/delete/{id} → 302 + borra (sin pagos).
- POST /receivables/delete/{id} con pagos → 302 + flash error.

### 4.6 `OrderServiceCxCIntegrationTest`

Re-wire:
- Crear pedido contado → 0 CxC.
- Crear pedido crédito → 1 CxC con total correcto.
- Cancelar pedido crédito → CxC eliminada.
- Eliminar pedido crédito → CxC eliminada.
- Update payment_method efectivo→crédito → CxC creada.
- Update payment_method crédito→efectivo → CxC eliminada.
- Update total crédito → CxC actualizada.
- Update total crédito + paid > new_total → falla.
- Reactivar pedido crédito cancelado → CxC recreada.

---

## 5. Verification checklist

1. `php bin/cake.php migrations migrate` → "All Done".
2. `php bin/cake.php migrations dump`.
3. `composer cs-check` → clean en archivos nuevos.
4. `php -l` cada archivo nuevo.
5. `php bin/cake.php routes | grep -i receivable` → muestra ruta custom + fallbacks.
6. `curl -I http://localhost/receivables` → 302 (sin sesión).
7. `grep -n "CxC pending" src/Service/OrderService.php` → vacío.
8. `grep -n "ReceivableService" src/Service/OrderService.php` → al menos 3 matches.

---

## 6. Risks / gotchas

1. **NO bcmath.** Diseño menciona bcsub/bcadd; usamos floats nativos.
   Decimal(12,2) cabe en floats sin pérdida hasta ~$10 mil millones.
   Comparaciones con epsilon 0.005 donde haga falta.

2. **Doble conteo en dashboard.** Tres números potencialmente confusos:
   `orders.total` (sales bruto), `receivables.paid_amount`
   (eventualmente reemplazado por `SUM(account_payments)`),
   `orders.payment_method`. Regla del spec: "fiado no es ingreso hasta
   que se abona". El dashboard (módulo 9) deberá sumar:
   `(orders.total WHERE payment_method != credito AND status != cancelled)
   + SUM(account_payments.amount today)`.
   **Por ahora (sin módulo 6),** `receivables.paid_amount` es 0 excepto
   cuando `markAsPaid` lo iguala al total — esa transición es manual y
   se loguea con warning para auditoría. Documentar como TODO.

3. **FK customer signed vs unsigned.** `customers.id` y `orders.id` son
   SIGNED (default Phinx); `users.id` es UNSIGNED. Las FKs de
   `receivables` deben respetar esto:
   - `customer_id` int signed (sin `signed=false`).
   - `order_id` int signed (sin `signed=false`).
   - `created_by` int unsigned (`'signed' => false`).

4. **UNIQUE order_id permite NULL múltiples.** MySQL trata NULLs como
   distintos por default en UNIQUE — múltiples CxC manuales (sin pedido)
   conviven; una sola CxC por pedido.

5. **Race condition en createFromOrder.** Lookup + insert no es atómico,
   pero el UNIQUE protege a nivel DB. Tras error de save, re-lookup
   para retornar la fila ganadora.

6. **CustomerService usa nombre tabla viejo.** `accounts_receivable` →
   `receivables`. Si no se corrige, `Customer::delete` con CxC pendientes
   pasaría el guard (count = 0 porque la tabla no existe), permitiendo
   borrar clientes con deuda. La FK RESTRICT atajaría con error feo SQL.
   Mitigación: fix en CustomerService.

7. **markAsPaid sin abonos reales = "ingreso fantasma".** Decisión
   documentada: el dashboard nunca debe sumar `receivables.paid_amount`
   como ingreso — solo `SUM(account_payments)`. Cuando módulo 6 exista,
   esta regla se refuerza naturalmente.

8. **`recomputeStatus` sin módulo 6.** Hoy es no-op semánticamente —
   solo flipea `status` según el `paid_amount` actual (que solo cambia
   vía `markAsPaid` o `updateAmountForOrder`). El método existe ya con
   la firma final para que módulo 6 lo extienda sin romper callers.

9. **OrderService test fixtures.** El integration test necesita cargar
   fixtures de Orders/OrderItems/Products/Ingredients/Customers/Roles/Users.
   Reusar fixtures existentes; el ReceivablesFixture solo agrega datos
   propios.

10. **`updateAmountForOrder` flujo de creación implícita.** Si en
    `update()` el pedido era no-crédito y pasa a crédito, llamamos
    `createFromOrder` (rama A). Si ya era crédito pero cambia total,
    llamamos `updateAmountForOrder` (rama C). `updateAmountForOrder`
    también puede crear si no encuentra CxC (defensive — caso raro pero
    posible si la CxC fue borrada out-of-band).
