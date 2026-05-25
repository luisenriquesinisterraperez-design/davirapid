# Diseño — Módulo Cuentas por Cobrar (Receivables)

> Documento de diseño técnico para el módulo Finanzas → Cuentas por Cobrar.
> Las CxC nacen automáticamente desde pedidos a crédito (`payment_method =
> credito`) o se crean manualmente para registrar deuda directa a un cliente.
> El módulo 6 (Abonos) consumirá esta capa para registrar pagos parciales.
>
> Referencias: `davirapid.md` §15 (CxC), §16 (Abonos), §3/§10 (regla "el
> crédito no es ingreso hasta que se abona"); `.claude/rules/ARQUITECTURE.md`
> (capas, familia de servicios, validación tabla vs servicio);
> `.claude/rules/DESIGN.md`; `.claude/designs/04-pedidos.md` (OrderService —
> el re-wire de los `Log::warning('CxC pending` se hace en §8 de este diseño);
> `.claude/designs/03-ajustes-inventario.md` (layout de referencia).

---

## 1. Data model

### 1.1 Tabla `receivables`

| Columna         | Tipo                 | Null | Default     | Notas                                                                                  |
|-----------------|----------------------|------|-------------|----------------------------------------------------------------------------------------|
| `id`            | int unsigned, PK, AI | no   | —           | `signed=false`, consistente con todas las PKs del proyecto.                            |
| `customer_id`   | int unsigned         | no   | —           | FK → `customers.id` **ON DELETE RESTRICT**. La deuda sobrevive al intento de borrar al cliente; se exige saldarla o eliminar la CxC primero. |
| `order_id`      | int unsigned         | sí   | null        | FK → `orders.id` **ON DELETE SET NULL**. Si el pedido se borra, la CxC queda huérfana pero histórica (referencia perdida, no la deuda). |
| `total_amount`  | decimal(12,2)        | no   | —           | Monto adeudado original. No cambia tras creación (excepto re-wire por update de pedido, ver §8). |
| `paid_amount`   | decimal(12,2)        | no   | 0.00        | **Denormalizado** — suma de abonos. Mantenido por `recomputeStatus()`. Acelera el listado (sin GROUP BY). |
| `description`   | varchar(255)         | no   | —           | "Pedido #123" para CxC automática; texto del usuario para manual.                      |
| `status`        | varchar(16)          | no   | `pendiente` | `pendiente` \| `pagado`. Derivable de `paid_amount >= total_amount` pero materializado para filtro/index. |
| `created_by`    | int unsigned         | sí   | null        | FK → `users.id` **ON DELETE SET NULL**. Quien creó la CxC (usuario humano o el del pedido). |
| `created`       | datetime             | sí   | null        | Behavior `Timestamp`.                                                                  |
| `modified`      | datetime             | sí   | null        | Behavior `Timestamp`. Cambia con cada `recomputeStatus`.                               |

**Índices:**

- `idx_rec_status_created` (`status`, `created`) — soporta el listado por
  defecto (pendientes primero, ordenadas por fecha).
- `idx_rec_customer` (`customer_id`) — "deudas de este cliente".
- `idx_rec_order` (`order_id`) — lookup desde pedido + idempotencia.
- `uniq_rec_order_id` UNIQUE en `order_id` **WHERE order_id IS NOT NULL**
  (MySQL: índice único con NULLs múltiples permitidos por default; en
  MariaDB/PostgreSQL es comportamiento estándar). Garantiza una sola CxC
  por pedido — previene duplicados ante doble-save o reintentos del wizard
  de pedidos. **Decisión:** declarar como índice único nativo MySQL (los
  NULL se consideran distintos), no como partial index.
- FK `customer_id` → `customers(id)` `ON DELETE RESTRICT ON UPDATE RESTRICT`.
- FK `order_id` → `orders(id)` `ON DELETE SET NULL ON UPDATE RESTRICT`.
- FK `created_by` → `users(id)` `ON DELETE SET NULL ON UPDATE RESTRICT`.

**Justificación de columnas clave:**

- **`paid_amount` denormalizado.** El listado de CxC es la pantalla más
  consultada del módulo Finanzas; recalcular `SUM(account_payments.amount)
  GROUP BY receivable_id` por cada fila es caro y N+1-prone. El trade-off es
  mantener consistencia con `recomputeStatus()` — método único responsable
  de actualizar este campo, llamado por `AccountPaymentService` (módulo 6).
- **`status` materializado.** Igual razón: filtros `WHERE status = 'pendiente'`
  golpean el índice. Recalculable en cualquier momento con
  `paid_amount >= total_amount`.
- **`order_id` nullable + UNIQUE.** Soporta CxC manual (sin pedido) y
  garantiza idempotencia para CxC automática (un pedido → una CxC).
- **`total_amount` NO cambia tras update de pedido** salvo flujo explícito
  (ver §8 cuando el monto del pedido cambia siendo crédito). Las dos opciones
  eran: (a) update in-place, (b) borrar y recrear. Elegimos (a) si no hay
  abonos; (b) implícito en delete+create del re-wire si los hay (con error).
- **`description` 255 chars.** Margen amplio para texto libre en manual,
  formato estándar `"Pedido #N - {customer_name}"` en automático.

### 1.2 Entity `Receivable`

```php
class Receivable extends Entity
{
    protected array $_accessible = [
        'customer_id'  => true,
        'order_id'     => true,
        'total_amount' => true,
        'paid_amount'  => true,
        'description'  => true,
        'status'       => true,
        'created_by'   => true,
        'customer'     => true,
        'order'        => true,
        'creator'      => true,
    ];

    protected array $_virtual = ['balance', 'progress_percent'];

    public function isPaid(): bool
    {
        return $this->status === ReceivableConstants::STATUS_PAGADO;
    }

    public function isPending(): bool
    {
        return $this->status === ReceivableConstants::STATUS_PENDIENTE;
    }

    public function getBalance(): string
    {
        // Devuelve string para evitar pérdidas de precisión con floats.
        return bcsub((string)$this->total_amount, (string)$this->paid_amount, 2);
    }

    public function getProgressPercent(): int
    {
        $total = (float)$this->total_amount;
        if ($total <= 0) { return 100; }
        return (int)min(100, round(((float)$this->paid_amount / $total) * 100));
    }

    public function hasPayments(): bool
    {
        return (float)$this->paid_amount > 0;
    }

    protected function _getBalance(): string { return $this->getBalance(); }
    protected function _getProgressPercent(): int { return $this->getProgressPercent(); }
}
```

### 1.3 Tabla `ReceivablesTable`

**`initialize()`:**

- `setTable('receivables')`, `setPrimaryKey('id')`,
  `setDisplayField('description')`.
- `addBehavior('Timestamp')`.
- Asociaciones:
  - `belongsTo('Customers', ['foreignKey' => 'customer_id', 'joinType' => 'INNER'])`.
  - `belongsTo('Orders', ['foreignKey' => 'order_id', 'joinType' => 'LEFT'])`
    — LEFT por nullable y por `ON DELETE SET NULL`.
  - `belongsTo('Creator', ['className' => 'Users', 'foreignKey' => 'created_by',
    'joinType' => 'LEFT'])`.
  - `hasMany('AccountPayments', ['foreignKey' => 'receivable_id',
    'dependent' => true, 'cascadeCallbacks' => true])` — placeholder
    para el módulo 6 (la tabla aún no existe; declarar la asociación
    cuando ese módulo se cree, o usar `try/catch` defensivo).

**`validationDefault()`:**

```text
- requirePresence('customer_id', 'create'); integer('customer_id')
- requirePresence('total_amount', 'create')
- numeric('total_amount'); greaterThan('total_amount', 0, 'El monto debe ser > 0')
- decimal('total_amount', 2)
- numeric('paid_amount'); greaterThanOrEqual('paid_amount', 0)
- notEmptyString('description', 'La descripción es requerida')
- maxLength('description', 255)
- inList('status', ReceivableConstants::STATUSES)
```

**`buildRules()`:**

- `existsIn(['customer_id'], 'Customers')`.
- `existsIn(['order_id'], 'Orders', ['allowNullableNulls' => true])`.
- `existsIn(['created_by'], 'Users', ['allowNullableNulls' => true])`.
- Regla custom: `paid_amount <= total_amount` (defensive — el service ya lo
  controla, pero un guard adicional ante manipulación directa).

**Custom finders:**

- `findPendingFirst(SelectQuery $q): SelectQuery` — `orderBy(['FIELD(status,
  "pendiente","pagado")' => 'ASC', 'created' => 'DESC'])`. En CakePHP se
  expresa con `orderBy([... 'CASE WHEN status="pendiente" THEN 0 ELSE 1
  END' ...])` o usar dos queries UNION; elegimos la expresión `CASE` para
  portabilidad SQLite (tests futuros).
- `findForCustomer(SelectQuery $q, array $opts): SelectQuery` — recibe
  `['customer_id' => int]`, filtra y aplica `findPendingFirst`.
- `findOpen(SelectQuery $q): SelectQuery` — `WHERE status = 'pendiente'`,
  útil para KPI y para el dashboard.

---

## 2. Constants — `ReceivableConstants`

```php
final class ReceivableConstants
{
    public const STATUS_PENDIENTE = 'pendiente';
    public const STATUS_PAGADO    = 'pagado';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDIENTE,
        self::STATUS_PAGADO,
    ];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_PENDIENTE => 'Pendiente',
        self::STATUS_PAGADO    => 'Pagado',
    ];

    /** Formato estándar para la descripción de CxC automáticas desde Pedidos. */
    public const AUTO_DESCRIPTION_TEMPLATE = 'Pedido #%d - %s';

    public const DESCRIPTION_MAX_LENGTH = 255;
}
```

**Decisión:** valores literales en español (mismo criterio que
`OrderConstants`). El template `AUTO_DESCRIPTION_TEMPLATE` centraliza el
formato para no hardcodearlo en `OrderService` y `ReceivableService`.

---

## 3. Entity helpers

Cubierto en §1.2. Resumen:

| Helper                | Retorno | Uso                                                                  |
|-----------------------|---------|----------------------------------------------------------------------|
| `isPaid()`            | bool    | Guards en service/controller (`if ($rec->isPaid())`).                |
| `isPending()`         | bool    | Listados y filtros.                                                  |
| `getBalance()`        | string  | Saldo restante con precisión decimal (bcsub, no float).              |
| `getProgressPercent()`| int     | Barra de progreso del view (0-100).                                  |
| `hasPayments()`       | bool    | Guard para `delete()` — bloquea si hay abonos.                       |
| Virtual `balance`     | string  | Auto-serialización a JSON / lectura desde templates.                 |
| Virtual `progress_percent` | int | Idem.                                                                 |

---

## 4. Table — cubierto en §1.3

---

## 5. Service — `ReceivableService`

Sigue el patrón calcado de `IngredientService` / `InventoryAdjustmentService`:
métodos retornan `array{success: bool, receivable?: ..., errors?: string[]}`;
operaciones multi-paso se envuelven en `Connection::transactional()`; los
métodos llamados desde un caller que ya abrió transacción **no** abren la
propia (documentado en cada método).

### 5.1 Constructor

```php
public function __construct() {}
```

Sin dependencias hoy. Cuando exista `AccountPaymentService` (módulo 6),
inyectarlo es opcional — la dirección de la relación es invertida (Payments
llama a Receivables, no al revés).

### 5.2 Métodos públicos

| Método                                                            | Propósito                                                                                       | Transacción propia |
|-------------------------------------------------------------------|-------------------------------------------------------------------------------------------------|-------|
| `createFromOrder(Order $order, int $userId): array`               | Llamado por `OrderService::create` (y `update` no-credito→credito). Idempotente vía UNIQUE.     | NO (caller envuelve) |
| `createManual(array $data, int $userId): array`                   | Alta manual desde `/receivables/add`.                                                            | SÍ |
| `markAsPaid(Receivable $rec, int $userId): array`                 | Marca manualmente como pagado (sin registrar abono — flag puro).                                | SÍ |
| `delete(Receivable $rec, int $userId): array`                     | Bloquea si `paid_amount > 0`. Cascade abonos vía FK (cuando exista módulo 6).                    | SÍ |
| `recomputeStatus(Receivable $rec): array`                         | Recalcula `paid_amount` desde `SUM(account_payments)` + flipea `status`. Usa `FOR UPDATE`.       | SÍ |
| `findOrCreateForOrder(Order $order, int $userId): array`          | Idempotencia explícita: si existe, retorna; si no, crea. Para reintentos/replay.                 | NO (caller envuelve) |
| `deleteForOrder(Order $order, int $userId, string $reason): array`| Llamado por `OrderService::cancel/delete/update`. Loguea si había abonos antes de borrar.        | NO (caller envuelve) |

#### `createFromOrder(Order $order, int $userId): array`

```text
1. Guard: $order->payment_method === PAYMENT_CREDIT. Si no, return success=true
   con receivable=null (no-op, llamada defensiva).
2. Guard: $order->total > 0.
3. Lookup por order_id (UNIQUE). Si existe → return success=true, receivable=existente
   (idempotencia barata; logs idempotent="reused").
4. Construir entity:
   - customer_id = $order->customer_id
   - order_id = $order->id
   - total_amount = $order->total
   - paid_amount = 0
   - description = sprintf(AUTO_DESCRIPTION_TEMPLATE, $order->id, $order->customer?->name ?? 'Cliente')
   - status = STATUS_PENDIENTE
   - created_by = $userId
5. save() — si falla por validación → return success=false, errors=...
6. save() — si falla por UNIQUE race condition → re-lookup, retornar existente (idempotencia).
7. Log::info('CxC created from order #{id} amount={a}').
8. return success=true, receivable=$rec.
```

**Importante:** este método **no abre transacción propia**. El caller
(`OrderService::create`) ya está dentro de `$connection->transactional()`.
Si esta operación falla, el caller debe retornar `false` desde la closure
para abortar el rollback (ver §8.1).

#### `createManual(array $data, int $userId): array`

```text
1. Validar input mínimo (customer_id presente, total_amount > 0, description).
2. Fetch del customer → verificar que existe y NO está eliminado.
3. Abrir transacción:
   a. Construir entity (sin order_id, con description del usuario).
   b. save(). Si falla → return false (rollback).
   c. Log::info.
   d. return true.
4. Retornar resultado estructurado.
```

#### `markAsPaid(Receivable $rec, int $userId): array`

```text
1. Guard: !$rec->isPaid() (idempotente — si ya está pagado, return success=true).
2. Abrir transacción con SELECT ... FOR UPDATE sobre la fila (lock).
3. $rec->status = STATUS_PAGADO; $rec->paid_amount = $rec->total_amount (forzar igualdad).
4. save(). Log::warning('CxC #{id} marked paid manually by user {u}').
5. Commit.
```

**Decisión sobre `paid_amount`:** al marcar como pagado manualmente sin
abonos, igualamos `paid_amount = total_amount` para mantener invariante
"status=pagado ⇒ balance=0". Si después se registra un abono real (módulo 6)
sobre esta CxC, `recomputeStatus` lo recalculará pero se mantendrá `pagado`
porque la suma seguirá siendo ≥ total. **Trade-off:** se pierde la
distinción "pagado por abonos" vs "pagado manual". Mitigación: el flag
manual queda en logs + `modified` reciente sin abonos backing.

#### `delete(Receivable $rec, int $userId): array`

```text
1. Guard: !$rec->hasPayments() — si tiene abonos, return success=false,
   errors=['No se puede eliminar: la cuenta tiene abonos registrados. Anule los abonos primero.'].
2. Abrir transacción:
   a. delete($rec). Cascade borra account_payments (cuando módulo 6 exista).
   b. Log::warning('CxC #{id} deleted by user {u} amount={a}').
   c. return true.
```

#### `recomputeStatus(Receivable $rec): array`

```text
1. Abrir transacción con SELECT ... FOR UPDATE sobre la fila (clave para evitar
   race condition entre dos abonos concurrentes).
2. $sum = $accountPaymentsTable->find()->where(['receivable_id' => $rec->id])
              ->select(['s' => $q->func()->sum('amount')])->first()?->s ?? 0;
3. $rec->paid_amount = $sum.
4. $rec->status = $sum >= $rec->total_amount ? STATUS_PAGADO : STATUS_PENDIENTE.
5. save(). Si falla → return false (rollback).
6. Log::info si hubo flip de estado.
7. Commit.
```

**Crítico:** este es el método que `AccountPaymentService` (módulo 6)
llamará tras cada `create` y `delete` de abono. El `FOR UPDATE` evita que
dos abonos concurrentes lean el mismo `paid_amount` viejo y ambos persistan
una versión obsoleta.

#### `findOrCreateForOrder(Order $order, int $userId): array`

Wrapper sobre `createFromOrder` — explicita la intención "idempotente" para
replays (jobs en cola, reintentos del wizard de pedidos). Internamente
delega a `createFromOrder` que ya es idempotente vía UNIQUE.

#### `deleteForOrder(Order $order, int $userId, string $reason): array`

```text
1. $rec = $receivablesTable->find()->where(['order_id' => $order->id])->first();
2. Si !$rec → return success=true (no había CxC, no-op).
3. Si $rec->hasPayments():
   - Log::warning('CxC voided with payments: cxc={cxc} order={o} paid={p} reason={r}').
   - Decisión: SE BORRA IGUAL. Razón: si el pedido se cancela/borra, la CxC
     debe desaparecer por consistencia con la regla "pedido cancelado no es
     ingreso" del spec §3. Los abonos cascade-borran (FK) y se loguea el monto
     voided para auditoría.
4. delete($rec). Log::warning('CxC #{id} auto-deleted: order #{o} {reason}').
5. return success=true.
```

**Trade-off:** borrar CxC con abonos es destructivo. Pero la regla de
negocio "cancelar pedido devuelve stock y anula crédito" en el spec lo
exige. La alternativa (bloquear cancel si hay abonos) confundiría al
operario que ya cobró parcial. Mitigación: log de warning con todos los
montos antes del borrado para reconstruir si hace falta.

### 5.3 Validación: tabla vs servicio

| Capa     | Reglas                                                                                          |
|----------|-------------------------------------------------------------------------------------------------|
| Tabla    | Presencia/tipo/longitud/`numeric`/`gt 0`/`inList`/`existsIn`. Defensa de `paid_amount <= total`. |
| Servicio | Idempotencia (lookup por `order_id`), guards de negocio (no borrar con abonos, no operar sobre cancelled order), recomputación de status, orquestación con `AccountPaymentService`. |

---

## 6. Controller — `ReceivablesController`

**URL pública:** `/receivables`.

### 6.1 Acciones y mapeo de permisos

| Acción      | HTTP       | Permiso (`_actionToPermission`) | Notas                                                           |
|-------------|------------|----------------------------------|------------------------------------------------------------------|
| `index`     | GET        | `view`                           | Listado con filtros + KPI strip.                                 |
| `view`      | GET        | `view`                           | Detalle: cliente, saldo, abonos placeholder, link a pedido.      |
| `add`       | GET/POST   | `add`                            | Alta manual.                                                     |
| `markPaid`  | POST       | `edit` (override)                | Acción custom: marcar como pagada manualmente.                   |
| `delete`    | POST       | `delete`                         | Bloquea si tiene abonos.                                         |

**Override de `_actionToPermission`:**

```php
protected function _actionToPermission(string $action): string
{
    return match ($action) {
        'markPaid' => 'edit',
        default    => parent::_actionToPermission($action),
    };
}
```

### 6.2 Paginación y ordenamiento

```php
public array $paginate = [
    'limit' => 15,
    'maxLimit' => 15,
    'order' => [
        'CASE WHEN Receivables.status = "pendiente" THEN 0 ELSE 1 END' => 'ASC',
        'Receivables.created' => 'DESC',
    ],
    'sortableFields' => ['created', 'total_amount', 'status'],
];
```

### 6.3 Filtros

```text
{
  status:       'pendiente' | 'pagado' | 'all'  (default 'pendiente')
  customer_id:  int | ''
  from:         'YYYY-MM-DD' | ''
  to:           'YYYY-MM-DD' | ''
  q:            search text (busca en description, customer.name, customer.phone)
}
```

`_buildIndexQuery($filters)`:

- `contain(['Customers', 'Orders', 'Creator'])` siempre.
- `WHERE status = :status` si `!== 'all'`. Default `pendiente`.
- `WHERE customer_id = :id` si presente.
- Rango fechas sobre `created`.
- `q`: `WHERE (description LIKE %q% OR Customers.name LIKE %q% OR Customers.phone LIKE %q%)`.

### 6.4 KPI strip (en `index`)

Tres tarjetas pequeñas arriba de la tabla, calculadas independientemente
de los filtros (visión global del día):

| KPI                       | Cálculo                                                                          |
|---------------------------|----------------------------------------------------------------------------------|
| **Total pendiente**       | `SUM(total_amount - paid_amount) WHERE status = 'pendiente'`. Formato moneda.    |
| **Pagado este mes**       | `SUM(paid_amount) WHERE MONTH(modified) = current` (aproximación, refinar con join a `account_payments.created` cuando exista módulo 6). |
| **Clientes con deuda**    | `COUNT(DISTINCT customer_id) WHERE status = 'pendiente'`.                        |

Los KPI se calculan en un método privado `_loadKpis()` que retorna un array
plano. Cacheable en futuro con `Cache` (TTL 60s); hoy en cada request.

### 6.5 Acciones detalladas

**`view($id)`:**

- `$rec = $this->Receivables->get($id, contain: ['Customers', 'Orders', 'Creator',
  'AccountPayments' => ['Users']])` (la última falla silenciosa hasta módulo 6 — usar
  contain condicional).
- Set `$rec` y `$customerOtherDebts` (otras CxC pendientes del mismo cliente).

**`add()`:**

- GET: render form con customer picker (autocomplete por nombre/teléfono).
- POST: `$result = $service->createManual($data, $currentUser['id'])`. Flash + redirect.

**`markPaid($id)`:**

- `allowMethod(['post'])`.
- Get CxC, llamar `$service->markAsPaid($rec, $userId)`. Flash + redirect a `view`.

**`delete($id)`:**

- `allowMethod(['post', 'delete'])`.
- `$service->delete($rec, $userId)`. Si falla por abonos, flash error con mensaje del service.

---

## 7. RBAC integration

### 7.1 `AppController::$controllerModuleMap`

```php
'Receivables' => 'receivables',
```

### 7.2 `AuthorizationService::MODULES`

```php
'receivables' => 'Cuentas por Cobrar',
```

### 7.3 Seed migration

Archivo: `config/Migrations/YYYYMMDDHHMMSS_SeedReceivablesPermissions.php`.
Mismo patrón que `SeedAdjustmentsPermissions`:

- No-admin: `can_view = 1`, `can_create = 1`, `can_edit = 0`, `can_delete = 0`.
- Admin: matriz completa (`1,1,1,1`) — bypass real igual.

Roles típicos con `can_create = 1`: Cajero, Administrador.
Roles con solo `can_view`: Mesero (puede consultar deuda del cliente al
tomar el pedido, no crear/editar CxC manuales).

### 7.4 Sidebar (sección Finanzas)

Posicionar bajo el grupo **Finanzas** del sidebar, en este orden:

1. Gastos
2. **Cuentas por Cobrar** ← aquí (icono `bi-cash-coin` o `bi-wallet2`).
3. Abonos (módulo 6, futuro).
4. Cierre Diario.

Visible solo si `userPermissions['receivables']['view']`. Badge counter
opcional con `COUNT(*) WHERE status = 'pendiente'` (puede ser ruido visual
si hay decenas de CxC; decisión: mostrar solo si count > 0, max display "99+").

---

## 8. Re-wire de `OrderService` — instrucciones para el implementador

Cambios concretos a aplicar tras crear `ReceivableService`. Los `Log::warning('CxC
pending...')` actuales son **placeholders intencionales** que deben
reemplazarse uno por uno.

### 8.1 Inyección por constructor

```php
public function __construct(
    ?OrderHistoryService $history = null,
    ?StockService $stock = null,
    ?ReceivableService $receivables = null,   // ← nuevo
) {
    $this->history = $history ?? new OrderHistoryService();
    $this->stock = $stock ?? new StockService();
    $this->receivables = $receivables ?? new ReceivableService();
}
```

### 8.2 En `create()` (alrededor de la línea 193-200 actual)

**Reemplazar:**
```php
if ($order->isCredit()) {
    Log::warning('CxC pending: ...');
}
```

**Por:**
```php
if ($order->isCredit()) {
    $cxcResult = $this->receivables->createFromOrder($order, $userId);
    if (!$cxcResult['success']) {
        $resultBox = ['success' => false, 'errors' => $cxcResult['errors']];
        return false;  // aborta la transacción del caller
    }
}
```

**Crítico:** el `return false` desde la closure transactional **deshace**
todo lo anterior (orden persistido, stock descontado). La CxC es parte
atómica de la creación del pedido a crédito — si no se puede crear la CxC,
no debe persistir el pedido.

### 8.3 En `cancel()` (alrededor de la línea 506)

**Reemplazar:**
```php
if ($order->isCredit()) {
    Log::warning('CxC pending cancellation ...');
}
```

**Por:**
```php
if ($order->isCredit()) {
    $cxcResult = $this->receivables->deleteForOrder($order, $userId, 'order_cancelled');
    if (!$cxcResult['success']) {
        $resultBox = ['success' => false, 'errors' => $cxcResult['errors']];
        return false;
    }
}
```

El `deleteForOrder` ya loguea internamente si había abonos.

### 8.4 En `delete()` (alrededor de la línea 651)

Mismo patrón que `cancel()`, con `reason = 'order_deleted'`.

### 8.5 En `update()` (alrededor de líneas 396-420) — cambio de payment_method

```php
$newPaymentMethod = (string)$order->payment_method;

// Caso A: no-crédito → crédito (crear CxC nueva).
if ($oldPaymentMethod !== OrderConstants::PAYMENT_CREDIT
    && $newPaymentMethod === OrderConstants::PAYMENT_CREDIT) {
    $cxcResult = $this->receivables->createFromOrder($order, $userId);
    if (!$cxcResult['success']) { $resultBox = [...]; return false; }
}

// Caso B: crédito → no-crédito (eliminar CxC).
if ($oldPaymentMethod === OrderConstants::PAYMENT_CREDIT
    && $newPaymentMethod !== OrderConstants::PAYMENT_CREDIT) {
    $cxcResult = $this->receivables->deleteForOrder($order, $userId, 'payment_method_changed');
    if (!$cxcResult['success']) { $resultBox = [...]; return false; }
}

// Caso C: sigue siendo crédito pero cambió el total → actualizar total_amount.
if ($oldPaymentMethod === OrderConstants::PAYMENT_CREDIT
    && $newPaymentMethod === OrderConstants::PAYMENT_CREDIT
    && $oldTotal !== $order->total) {
    // El service expone un método auxiliar (a sumar): updateAmountForOrder().
    // Si existen abonos > nuevo total, error.
    $cxcResult = $this->receivables->updateAmountForOrder($order, $userId);
    if (!$cxcResult['success']) { $resultBox = [...]; return false; }
}
```

**Nota:** `updateAmountForOrder` no está en §5.2; **agregar** a la
implementación. Lógica: si `paid_amount > new_total` → error claro.
Sino, set `total_amount = new_total` y `recomputeStatus`.

### 8.6 En `reactivate()` (alrededor de la línea 579)

```php
if ($order->isCredit()) {
    $cxcResult = $this->receivables->findOrCreateForOrder($order, $userId);
    if (!$cxcResult['success']) { $resultBox = [...]; return false; }
}
```

Usar `findOrCreateForOrder` (no `createFromOrder` directo) porque tras
reactivación es posible que el flujo previo dejara una CxC remanente; la
idempotencia evita el conflicto UNIQUE.

---

## 9. Screens & UX

Todas las vistas usan `default.php` + componentes de `DESIGN.md`.

### 9.1 `index.php` — Listado con KPI strip

**Encabezado:**
- `h1`: "Cuentas por Cobrar".
- `button-primary`: "Nueva deuda" (manual; única acción primaria de la pantalla).

**KPI strip (`stat-card` x 3, grid 3 columnas en md+):**

```text
[ Total pendiente: $1.250.000 ]  [ Pagado este mes: $480.000 ]  [ Clientes con deuda: 14 ]
```

Tarjeta de "Total pendiente" con borde-left `primary-soft` (acento de marca,
no relleno).

**Card de filtros:**
1. Select `status` (Pendientes / Pagadas / Todas), default Pendientes.
2. Customer picker (autocomplete por nombre/teléfono).
3. Input `from` (date).
4. Input `to` (date).
5. Input `q` (búsqueda libre).
6. Botón "Filtrar" (`btn-secondary`).
7. Link "Limpiar" si hay filtro activo.

**Tabla:**

| Columna       | Width | Alineación | Contenido                                                                |
|---------------|-------|------------|--------------------------------------------------------------------------|
| #             | 60px  | left       | `$rec->id`. Si hay `order_id`, ícono `bi-receipt` con tooltip "Pedido #X". |
| Fecha         | 110px | left       | `created->i18nFormat('dd/MM/yyyy')`.                                     |
| Cliente       | auto  | left       | Link a `/customers/view/{id}`. Tel debajo en `text-muted`.              |
| Descripción   | auto  | left       | `h($rec->description)`.                                                  |
| Total         | 120px | right      | `$this->Number->currency($rec->total_amount)`.                            |
| Abonado       | 120px | right      | `$this->Number->currency($rec->paid_amount)`. Mini barra de progreso.   |
| Saldo         | 120px | right      | `$this->Number->currency($rec->getBalance())`. Bold si > 0.             |
| Estado        | 110px | center     | Badge: `badge-soft-warning` (Pendiente) / `badge-soft-success` (Pagado). |
| Acciones      | 100px | right      | `btn-icon` Ver + (si pendiente) `btn-icon` Marcar pagado.                |

**Badges:**
- Pendiente → `badge-soft-warning` (naranja, esperando acción).
- Pagado → `badge-soft-success` (verde, completo).

**Empty state:**
- Sin filtros + status=pendiente: "No hay cuentas pendientes. [Registrar deuda]".
- Filtros aplicados: "Sin resultados para los filtros aplicados".

### 9.2 `view.php` — Detalle de CxC

Layout en 2 columnas (md+):

**Columna izquierda (2/3):**

1. **Card del cliente** (compacta): foto/avatar, nombre, teléfono, email.
   Botón "Ver cliente" → `/customers/view/{id}`.
2. **Card de detalles del pedido** (si `order_id`): #, fecha, productos
   resumidos, link "Ver pedido". Si no, card "Deuda manual" con descripción.
3. **Timeline de abonos** (placeholder hasta módulo 6):
   - Si tiene abonos: lista de cada abono (fecha, monto, método, registrado por).
   - Si no tiene: empty state "Aún no hay abonos registrados".
   - Botón "Registrar abono" disabled con tooltip "Próximamente — Módulo 6".

**Columna derecha (1/3):**

1. **Card de saldo (la grande):**
   ```text
   Total adeudado:    $500.000
   Pagado:            $200.000  (40%)
   ───────────────────────────
   Saldo:             $300.000  ← grande, color rojo si > 0, verde si 0
   ```
   Barra de progreso debajo (40% verde).
2. **Botones de acción:**
   - `btn-primary` "Registrar abono" (disabled "Próximamente").
   - `btn-secondary` "Marcar como pagado" (si pendiente, confirm).
   - `btn-danger` "Eliminar CxC" (si !hasPayments(), confirm).
3. **Metadata** (texto muted): "Creada el X por Y. Última actualización Z".

### 9.3 `add.php` — Alta manual

Card único, una columna:

- **Customer picker** (input con autocomplete `/customers/search.json?q=...`).
  Muestra resultado con nombre + tel. Si no hay match, link "Crear nuevo cliente".
- **Monto** (`total_amount`): input numérico, `step="0.01"`, `min="0.01"`, sufijo `$`.
- **Descripción**: textarea 3 rows, helper "ej. 'Mercado del 12/05', 'Préstamo'".

Pie:
- `btn-primary` "Registrar deuda".
- `btn-light` "Cancelar" → `/receivables`.

### 9.4 Confirmaciones

- **markPaid**: "¿Marcar esta cuenta como pagada manualmente? Esta acción
  no registra un abono, solo cambia el estado. Se recomienda registrar
  abonos reales (Módulo 6)."
- **delete sin abonos**: "¿Eliminar esta cuenta por cobrar? Esta acción
  no se puede deshacer."
- **delete con abonos** (no llega al confirm — el botón aparece disabled
  con tooltip "No se puede eliminar: tiene abonos registrados").

---

## 10. Edge cases

| Caso                                                          | Decisión                                                                                                              |
|---------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------|
| Duplicate CxC para mismo pedido                                | UNIQUE constraint sobre `order_id` lo previene a nivel DB. `createFromOrder` hace lookup previo (idempotencia barata) y maneja race condition con re-lookup tras error de UNIQUE. |
| Cliente con CxC intenta ser eliminado                          | FK RESTRICT bloquea. `CustomerService::delete` debe capturar y mostrar mensaje claro: "No se puede eliminar: tiene cuentas por cobrar (pendientes o pagadas)". |
| `markAsPaid` sobre CxC ya pagada                               | Idempotente: return success=true, no-op, log debug.                                                                   |
| `paid_amount > total_amount` (sobre-pago accidental)            | Tabla rechaza por regla `paid_amount <= total_amount`. Si llega vía `recomputeStatus` (suma de abonos > total), el módulo 6 debe bloquear el abono ANTES de llamar `recomputeStatus`. |
| Race condition en `recomputeStatus` (dos abonos concurrentes)   | `SELECT ... FOR UPDATE` sobre la fila serializa. Sin lock, dos abonos podrían leer `paid_amount=100` y ambos guardar `150` (perdiendo uno). |
| Deuda manual a cliente con CxC pendiente existente              | Permitido. Cada deuda es una fila separada (puede tener varias CxC un mismo cliente). El listado por cliente las muestra todas. |
| Pedido cancelado con CxC con abonos                            | `deleteForOrder` la borra igual + loguea warning con monto de abonos voided. Cascade FK borra abonos. Decisión documentada en §5.2. |
| Pedido eliminado (no cancelado) con CxC                        | Mismo flujo que cancel — `deleteForOrder` desde `OrderService::delete`.                                              |
| Update de pedido cambia total siendo crédito + abonos > nuevo total | `updateAmountForOrder` retorna error claro: "El nuevo total ($X) es menor que lo ya abonado ($Y). Anule abonos primero o mantenga el total". |
| Tax/discount changes (futuro)                                   | Cuando `OrderService` soporte impuestos/descuentos, `total_amount` recalculado vía `updateAmountForOrder`.            |
| Customer hard-deleted (cascada manual antes del RESTRICT)       | RESTRICT lo impide. Si se borra `customer_id` desde DB (out-of-band), las CxC quedan huérfanas. Service `view` debe manejar `customer=null` defensivamente. |
| Filtrado por usuario-repartidor                                 | NO aplica. Las CxC son visibles según RBAC, no según repartidor. (Si en el futuro se quisiera que el repartidor vea solo CxC de sus pedidos, agregar filtro condicional similar al de Pedidos.) |

---

## 11. Tests (referencia — el proyecto opta-out hoy)

> El usuario opta-out de tests automatizados (memoria). Esta sección queda
> como **referencia** para cuando esa decisión se revierta. **No** escribir
> tests en la implementación actual.

**Archivos esperados:**

1. `tests/TestCase/Model/Entity/ReceivableTest.php` — `isPaid`, `isPending`,
   `getBalance` (precisión bcsub), `getProgressPercent`, `hasPayments`.
2. `tests/TestCase/Model/Table/ReceivablesTableTest.php` — validación,
   `findPendingFirst` (orden correcto), `findForCustomer`, `findOpen`,
   `buildRules` para `paid_amount <= total_amount`.
3. `tests/TestCase/Service/ReceivableServiceTest.php` — `createFromOrder`
   idempotente, `createManual`, `markAsPaid` (idempotente), `delete`
   (bloqueado con abonos), `recomputeStatus` con FOR UPDATE, `deleteForOrder`
   con/sin abonos (log warning).
4. `tests/TestCase/Controller/ReceivablesControllerTest.php` — RBAC en cada
   acción, filtros del index, KPI strip, `markPaid` POST, delete bloqueado.
5. `tests/TestCase/Service/OrderServiceCxCIntegrationTest.php` — **NUEVO**,
   cubre el re-wire:
   - Pedido crédito creado ⇒ CxC creada con monto correcto.
   - Pedido no-crédito creado ⇒ NO se crea CxC.
   - Pedido crédito cancelado ⇒ CxC eliminada (sin abonos).
   - Pedido crédito cancelado con abonos ⇒ CxC eliminada + log warning + abonos cascade.
   - Pedido crédito eliminado ⇒ CxC eliminada.
   - Update payment_method efectivo→crédito ⇒ CxC creada.
   - Update payment_method crédito→efectivo ⇒ CxC eliminada.
   - Update total siendo crédito + abonos > nuevo total ⇒ error.
   - Pedido reactivado ⇒ CxC recreada o reutilizada (idempotencia).
6. `tests/Fixture/ReceivablesFixture.php`.
7. `tests/TestCase/Controller/CustomersControllerCxCBlockTest.php` —
   `Customer::delete` con CxC pendiente debe fallar con mensaje claro.

---

## 12. Open questions / risks

1. **`paid_amount` denormalizado vs cálculo on-the-fly.** Elegimos
   denormalizado por performance del listado. Riesgo: drift si alguien
   modifica `account_payments` fuera de `AccountPaymentService`. **Mitigación:**
   documentar la invariante + utility CLI `bin/cake receivables:recompute_all`
   para reparar drift (futuro).

2. **`markAsPaid` manual sin abono real.** Iguala `paid_amount = total_amount`
   pero no crea registro de abono. Esto produce "ingreso fantasma" si el
   dashboard suma `paid_amount` de CxC pagadas como ingresos. **Decisión:**
   el dashboard debe sumar `account_payments.amount` (módulo 6), NO
   `receivables.paid_amount`. Documentar la regla cuando se diseñe el
   dashboard.

3. **Cascade de delete de pedido con abonos.** Hoy `OrderService::delete`
   con pedido a crédito + abonos borra todo (CxC + abonos). Alternativa
   más conservadora: bloquear el delete del pedido si tiene CxC con
   abonos, forzar al usuario a anular abonos primero. **Trade-off:** UX
   más confusa vs auditoría más limpia. Decisión actual: borrar y loguear
   (consistente con la regla "cancelar pedido devuelve stock y anula
   crédito"). Reevaluar si finanzas pide audit trail más estricto.

4. **Customer picker performance.** El autocomplete `/customers/search.json`
   sin índice fulltext puede ser lento con muchos clientes. **Mitigación:**
   agregar índice MySQL `FULLTEXT(name, phone)` o cambiar a `LIKE 'q%'`
   (prefijo, usa índice btree estándar). No bloqueante para Fase 1.

5. **CxC con `order_id` cuya FK queda NULL** (pedido borrado). El listado
   muestra `—` en columna pedido. ¿Permitir filtrar por "CxC huérfanas"?
   No por ahora — caso raro, evidencia disponible en logs.

6. **Concurrencia entre `markAsPaid` y `recomputeStatus`.** Si admin
   ejecuta `markAsPaid` mientras un abono se está procesando, podríamos
   tener: markAsPaid setea `paid_amount=total`; abono concurrente hace
   `recomputeStatus` que recalcula desde SUM y sobrescribe. **Mitigación:**
   ambos métodos usan `FOR UPDATE`, se serializan. El último gana — si
   es el `recomputeStatus`, la cuenta queda con `paid_amount = SUM real`
   y `status` derivado. Aceptable (el estado refleja la realidad de los
   abonos).
