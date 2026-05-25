# Diseño — Módulo Abonos (AccountPayments)

> Documento de diseño técnico para el módulo Finanzas → Abonos. Los abonos
> son pagos parciales (o totales) que un cliente hace contra una Cuenta por
> Cobrar (CxC). Son **el único mecanismo real** mediante el cual una deuda
> a crédito se convierte en ingreso registrado por el negocio.
>
> Referencias: `davirapid.md` §16 (Abonos — primaria), §15 (CxC), §17
> (Cierre Diario — los abonos del día cuentan como ingreso), §19 (Dashboard
> — el crédito no es ingreso hasta abonarse), §3/§10 (regla transversal);
> `.claude/designs/05-cuentas-por-cobrar.md` (contrato `recomputeStatus`,
> integraciones); `src/Service/ReceivableService.php`;
> `src/Constants/OrderConstants.php` (métodos de pago compartidos);
> `.claude/rules/ARQUITECTURE.md`, `.claude/rules/DESIGN.md`.

---

## 1. Data model

### 1.1 Tabla `account_payments`

| Columna           | Tipo                 | Null | Default | Notas                                                                                       |
|-------------------|----------------------|------|---------|---------------------------------------------------------------------------------------------|
| `id`              | int unsigned, PK, AI | no   | —       | `signed=false`, consistente con todas las PKs del proyecto.                                 |
| `receivable_id`   | int unsigned         | no   | —       | FK → `receivables.id` **ON DELETE CASCADE**. Si la CxC se borra (cancel/delete de pedido), los abonos se borran con ella; ese borrado se loguea en `ReceivableService::deleteForOrder`. |
| `amount`          | decimal(12,2)        | no   | —       | Monto del abono. Siempre > 0.                                                               |
| `payment_method`  | varchar(20)          | no   | —       | Validado vía `inList` contra `AccountPaymentConstants::PAYMENT_METHODS` (cash/nequi/daviplata/transferencia). **`credito` rechazado** — no se paga deuda con deuda. |
| `notes`           | TEXT                 | sí   | null    | Observaciones libres del cajero ("Pagó con billete grande, vuelto en cuenta").              |
| `created_by`      | int unsigned         | sí   | null    | FK → `users.id` **ON DELETE SET NULL**. Quien registró el abono.                            |
| `created`         | datetime             | sí   | null    | Behavior `Timestamp`. Sirve como fecha del abono (lo que cuenta en Cierre y Dashboard).     |

**Decisión: append-only.** No hay `modified`. Un abono es un evento contable
inmutable — si el operario se equivocó, **borra y vuelve a crear**. Esto
simplifica auditoría (timeline lineal), evita recomputos retroactivos sobre
`recomputeStatus`, y alinea con la naturaleza event-sourced de Cierre Diario
(§17): los cierres pasados no deben mutar cuando alguien edita un abono
viejo. **Trade-off:** un typo en `notes` exige delete+create; aceptable.

**Índices:**

- `idx_ap_receivable_created` (`receivable_id`, `created`) — soporta el
  timeline en `ReceivablesController::view` (todos los abonos de una CxC,
  ordenados cronológicamente) y el SUM por receivable (cubre el lookup).
- `idx_ap_created` (`created`) — agregados diarios para Cierre Diario
  (§17): `SUM(amount) WHERE DATE(created) = :today`.
- `idx_ap_method` (`payment_method`) — desglose por método en Cierre y en
  la KPI "abonos por método" del index.
- `idx_ap_creator` (`created_by`) — auditoría "abonos registrados por X".
- FK `receivable_id` → `receivables(id)` `ON DELETE CASCADE`.
- FK `created_by` → `users(id)` `ON DELETE SET NULL`.

**Justificación de columnas clave:**

- **`payment_method` como varchar + `inList`** (no enum DB ni FK a tabla
  separada). Mismo criterio que `orders.payment_method`: catálogo cerrado
  por código (`AccountPaymentConstants::PAYMENT_METHODS`), validado a nivel
  app, fácil de extender sin migración.
- **`created` como única timestamp.** El abono **es** el evento; no hay
  ciclo de vida posterior. Cierre Diario y Dashboard agrupan por
  `DATE(created)`.
- **`notes` TEXT, no varchar.** Es opcional y rara vez se usa; cuando se
  usa, puede ser descriptivo. TEXT evita estimar longitud arbitraria.

---

## 2. Constants — `AccountPaymentConstants`

```php
final class AccountPaymentConstants
{
    /**
     * Métodos válidos para abonar. Subconjunto de OrderConstants::PAYMENT_METHODS
     * EXCLUYENDO 'credito' — no se paga deuda generando más deuda.
     *
     * @var list<string>
     */
    public const PAYMENT_METHODS = [
        OrderConstants::PAYMENT_CASH,
        OrderConstants::PAYMENT_NEQUI,
        OrderConstants::PAYMENT_DAVIPLATA,
        OrderConstants::PAYMENT_TRANSFER,
    ];

    /** @var array<string, string> */
    public const PAYMENT_LABELS = [
        OrderConstants::PAYMENT_CASH       => 'Efectivo',
        OrderConstants::PAYMENT_NEQUI      => 'Nequi',
        OrderConstants::PAYMENT_DAVIPLATA  => 'Daviplata',
        OrderConstants::PAYMENT_TRANSFER   => 'Transferencia',
    ];

    /** Tolerancia para comparaciones de igualdad en decimal(12,2). */
    public const EPSILON = 0.005;

    public const NOTES_MAX_LENGTH = 65000;

    private function __construct() {}
}
```

**Decisión:** reusar las constantes de `OrderConstants` (mismos códigos en
DB) para que un mismo método signifique lo mismo en pedidos y abonos.
Cuando un reporte cruce ambos (Cierre Diario suma ventas no-crédito + abonos
del día por método), el agrupamiento es trivial. **No** duplicar literales.

---

## 3. Entity helpers — `AccountPayment`

```php
class AccountPayment extends Entity
{
    protected array $_accessible = [
        'receivable_id'  => true,
        'amount'         => true,
        'payment_method' => true,
        'notes'          => true,
        'created_by'     => true,
        'receivable'     => true,
        'creator'        => true,
    ];

    public function getFormattedAmount(): string
    {
        return '$' . number_format((float)$this->amount, 2, ',', '.');
    }

    public function getMethodLabel(): string
    {
        return AccountPaymentConstants::PAYMENT_LABELS[$this->payment_method]
            ?? ucfirst((string)$this->payment_method);
    }
}
```

Sin virtuals — los dos getters se llaman explícitamente desde templates
(no requieren serialización JSON automática). Sin más helpers porque el
abono no tiene estado: existe o no existe.

---

## 4. Table — `AccountPaymentsTable`

**`initialize()`:**

- `setTable('account_payments')`, `setPrimaryKey('id')`,
  `setDisplayField('id')` (no hay nombre — se identifica por id+fecha).
- `addBehavior('Timestamp', ['events' => ['Model.beforeSave' => ['created' => 'new']]])`
  — solo `created`, sin `modified`.
- Asociaciones:
  - `belongsTo('Receivables', ['foreignKey' => 'receivable_id', 'joinType' => 'INNER'])`.
  - `belongsTo('Creator', ['className' => 'Users', 'foreignKey' => 'created_by', 'joinType' => 'LEFT'])`.

**`validationDefault()`:**

```text
- requirePresence('receivable_id', 'create'); integer('receivable_id')
- requirePresence('amount', 'create')
- numeric('amount'); greaterThan('amount', 0, 'El monto debe ser > 0')
- decimal('amount', 2)
- requirePresence('payment_method', 'create')
- inList('payment_method', AccountPaymentConstants::PAYMENT_METHODS,
        'Método de pago inválido (no se puede abonar con crédito).')
- allowEmptyString('notes')
- maxLength('notes', AccountPaymentConstants::NOTES_MAX_LENGTH)
```

**`buildRules()`:**

- `existsIn(['receivable_id'], 'Receivables')`.
- `existsIn(['created_by'], 'Users', ['allowNullableNulls' => true])`.

**Custom finders:**

- `findForReceivable(SelectQuery $q, array $opts): SelectQuery` — recibe
  `['receivable_id' => int]`, filtra y ordena `created DESC` (timeline más
  reciente primero).
- `findInDateRange(SelectQuery $q, array $opts): SelectQuery` — recibe
  `['from' => 'YYYY-MM-DD', 'to' => 'YYYY-MM-DD']`. Usa
  `DATE(created) BETWEEN ?`. Soporta cualquiera de los dos como opcional.
- `findToday(SelectQuery $q): SelectQuery` — atajo:
  `DATE(created) = CURDATE()` (portable: `created >= today_start AND created < tomorrow_start` para SQLite/MySQL).
- `findByMethod(SelectQuery $q, array $opts): SelectQuery` — recibe
  `['payment_method' => string]`. Para agregados del Cierre Diario.

---

## 5. Service — `AccountPaymentService`

Sigue el patrón de `IngredientService` / `InventoryAdjustmentService` /
`ReceivableService`: métodos devuelven `array{success: bool, payment?: ...,
receivable?: ..., errors?: string[]}`; operaciones multi-paso envueltas en
`Connection::transactional()`.

### 5.1 Constructor

```php
public function __construct(?ReceivableService $receivables = null)
{
    $this->receivables = $receivables ?? new ReceivableService();
}
```

`ReceivableService` se inyecta para poder mockearlo en tests; el default
permite uso directo en producción sin orquestación externa.

### 5.2 Métodos públicos

| Método                                                  | Propósito                                                         | Transacción propia |
|---------------------------------------------------------|-------------------------------------------------------------------|--------------------|
| `create(array $data, int $userId): array`               | Registra un abono nuevo → recomputa CxC.                          | SÍ                 |
| `delete(AccountPayment $p, int $userId): array`         | Borra un abono → recomputa CxC (puede demote pagado→pendiente).   | SÍ                 |

No hay `update` — append-only (§1.1). No hay `recomputeAll` — la
consistencia se mantiene por cada operación; un comando CLI futuro
(`bin/cake receivables:recompute_all`) sería para reparar drift.

#### `create(array $data, int $userId): array`

```text
1. Validar input mínimo (receivable_id, amount > 0, payment_method).
   - Si payment_method == 'credito' → return success=false,
     errors=['No se puede abonar con método Crédito.']  (defensa explícita
     antes del inList, para mensaje claro).
2. Lookup de la CxC (sin lock todavía):
   - Si no existe → return success=false, errors=['La cuenta no existe.'].
   - Si $rec->isPaid() → return success=false,
     errors=['La cuenta ya está pagada. No se admiten más abonos.'].
3. Abrir transacción:
   a. Re-leer la CxC CON FOR UPDATE (lock pesimista) — SELECT ... FROM
      receivables WHERE id = ? FOR UPDATE. Esto serializa abonos concurrentes
      sobre la misma CxC y previene la race "dos abonos leen paid=100, ambos
      escriben paid=150".
   b. Idempotencia bajo lock: si $rec->isPaid() AHORA → return success=false
      (otro request ganó la carrera y completó el pago).
   c. Validación de overpayment:
      - $currentPaid = (float)$rec->paid_amount.
      - $newAmount = (float)$data['amount'].
      - $total = (float)$rec->total_amount.
      - Si ($currentPaid + $newAmount) > ($total + EPSILON):
        - $remaining = $total - $currentPaid.
        - return success=false, errors=[
          sprintf('El monto excede el saldo de $%s.', number_format($remaining, 2))
        ].  (Fase 1: rechazo estricto. Fase 2 opcional: permitir
        sobre-pago y dejar leftover como crédito a favor.)
   d. Construir entity del abono:
      - receivable_id, amount (number_format 2 decimales), payment_method,
        notes (trim, null si vacío), created_by = $userId|null.
   e. save() del abono. Si falla → return false (rollback).
   f. Recalcular paid_amount sumando desde DB con LOCK:
      $newPaid = $accountPaymentsTable->find()
                    ->where(['receivable_id' => $rec->id])
                    ->select(['s' => $q->func()->sum('amount')])
                    ->first()?->s ?? '0.00';
      (La SUM corre dentro del lock de la CxC; el INSERT recién hecho
      está en la transacción actual y por tanto visible a la propia
      conexión.)
   g. $rec->paid_amount = number_format((float)$newPaid, 2, '.', '').
   h. $rec->status = ((float)$newPaid + EPSILON >= $total)
                       ? STATUS_PAGADO : STATUS_PENDIENTE.
   i. save($rec). Si falla → return false.
   j. Log::info si hubo flip a 'pagado'.
   k. Log::info('Abono creado: id={id} rec={r} amount={a} method={m}').
4. Commit.
5. return ['success' => true, 'payment' => $p, 'receivable' => $rec].
```

**Decisión sobre el cálculo de SUM:** se reusa la query, no se hace
`$currentPaid + $newAmount` aritméticamente. Razón: si en el futuro
`recomputeStatus` se llama desde otros lados, la fuente única de verdad
queda en la SUM. Native PHP floats sobre decimal(12,2) son seguros (12
dígitos, lejos del límite IEEE 754 de ~15 dígitos significativos); usamos
EPSILON 0.005 (mitad del paso menor) para comparaciones.

**Decisión sobre por qué no delegamos a `ReceivableService::recomputeStatus`
existente:** ese método actualmente NO suma `account_payments` (ver §8 —
hay que extenderlo). Una vez extendido, podríamos llamarlo y eliminar
los pasos f-i de aquí. **Trade-off elegido:** mantener el SUM+save dentro
del mismo `transactional` del create de abono evita una segunda transacción
anidada (CakePHP las maneja con savepoints, pero agrega ruido). El service
hace todo el trabajo bajo un solo lock.

#### `delete(AccountPayment $p, int $userId): array`

```text
1. Capturar $receivableId = $p->receivable_id (antes del delete).
2. Abrir transacción:
   a. Re-leer la CxC con FOR UPDATE (lock).
   b. $accountPaymentsTable->delete($p). Si falla → return false.
   c. Recalcular paid_amount:
      $newPaid = SUM(account_payments.amount WHERE receivable_id = $receivableId).
      (Si era el único abono → 0.00.)
   d. $rec->paid_amount = number_format((float)$newPaid, 2, '.', '').
   e. Edge: demote pagado → pendiente.
      Si $rec->status === STATUS_PAGADO && $newPaid + EPSILON < $total:
        $rec->status = STATUS_PENDIENTE.
        Log::warning('CxC demoted from pagado: id={r} new_paid={p}').
      Else if $newPaid + EPSILON >= $total:
        $rec->status = STATUS_PAGADO  (sigue pagado).
      Else:
        $rec->status = STATUS_PENDIENTE.
   f. save($rec).
   g. Log::warning('Abono eliminado: id={p} rec={r} amount={a} by user={u}').
3. Commit.
4. return ['success' => true, 'receivable' => $rec].
```

**Crítico:** el `Log::warning` (no info) refleja que borrar un abono es
una operación auditable — el dinero "se desingresa" del Cierre Diario si
el borrado ocurre el mismo día.

### 5.3 Validación: tabla vs servicio

| Capa     | Reglas                                                                                                       |
|----------|--------------------------------------------------------------------------------------------------------------|
| Tabla    | Presencia/`numeric`/`gt 0`/`decimal`/`inList`/`existsIn`/longitud de notes. Defensa de FK.                   |
| Servicio | Lock FOR UPDATE sobre la CxC, idempotencia ("ya pagada"), rechazo de overpayment, rechazo de `credito`, recomputación de `paid_amount` y `status`, demote en delete. |

---

## 6. Controller — `AccountPaymentsController`

**URL pública:** `/account-payments`.

### 6.1 Acciones y mapeo de permisos

| Acción   | HTTP         | Permiso (`_actionToPermission`) | Notas                                                              |
|----------|--------------|----------------------------------|--------------------------------------------------------------------|
| `index`  | GET          | `view`                           | Listado global "abonos recientes" con KPI y filtros.               |
| `add`    | GET/POST     | `add`                            | Form. GET acepta `?receivable_id=X` para preselección.             |
| `delete` | POST/DELETE  | `delete`                         | Borrado individual de abono. Recomputa CxC (puede demote).         |

**No hay `edit`** (append-only — §1.1).
**No hay `view`** — recomendado: el detalle de un abono cabe en una fila de
la tabla del index o en el timeline del view de CxC. Si en el futuro se
necesita una pantalla dedicada (p.ej. recibo imprimible), se agrega.

### 6.2 Paginación y ordenamiento

```php
public array $paginate = [
    'limit' => 15,
    'maxLimit' => 15,
    'order' => ['AccountPayments.created' => 'DESC'],
    'sortableFields' => ['created', 'amount', 'payment_method'],
];
```

### 6.3 Filtros del index

```text
{
  from:           'YYYY-MM-DD' | ''   (default: hoy menos 30 días)
  to:             'YYYY-MM-DD' | ''   (default: hoy)
  q:              search libre sobre customer.name / customer.phone
  payment_method: 'efectivo' | 'nequi' | 'daviplata' | 'transferencia' | ''
  customer_id:    int | ''            (filtra por cliente exacto, via join receivables)
}
```

`_buildIndexQuery($filters)`:

- `contain(['Receivables' => ['Customers'], 'Creator'])` siempre.
- Rango de fechas sobre `AccountPayments.created`.
- `payment_method` exacto si presente.
- `customer_id`: `WHERE Receivables.customer_id = :id`.
- `q`: `WHERE (Customers.name LIKE %q% OR Customers.phone LIKE %q%)`.

### 6.4 KPI strip (en `index`)

Tres tarjetas sobre la tabla — siempre calculadas sobre **hoy** (no
respetan filtros, son visión operativa):

| KPI                          | Cálculo                                                                |
|------------------------------|------------------------------------------------------------------------|
| **Abonos hoy**               | `SUM(amount) WHERE DATE(created) = today`. Formato moneda.             |
| **Total mes**                | `SUM(amount) WHERE YEAR(created)=current AND MONTH(created)=current`.  |
| **# transacciones hoy**      | `COUNT(*) WHERE DATE(created) = today`.                                |

Método privado `_loadKpis()` — un solo round-trip si se compila como una
sola query con `CASE WHEN`. Cacheable a futuro (TTL 60s).

### 6.5 Acciones detalladas

**`add()`:**

- GET:
  - Query param `?receivable_id=X` → precarga la CxC y muestra "Saldo
    actual: $X" como hint.
  - Si no hay `receivable_id`, render con picker de CxC (autocomplete por
    cliente nombre/teléfono mostrando CxC pendientes).
  - Solo se ofrecen CxC con `status = pendiente` en el picker.
- POST:
  - `$result = $service->create($data, $currentUser['id'])`.
  - Si `success`: Flash success con "Abono registrado. Saldo restante:
    $X" y redirect a `/receivables/view/{receivable_id}` (el contexto del
    operario es la CxC, no el listado global).
  - Si error: Flash error con primer mensaje, re-render form.

**`delete($id)`:**

- `allowMethod(['post', 'delete'])`.
- `$payment = $this->AccountPayments->get($id)` (tira `NotFoundException`).
- `$result = $service->delete($payment, $currentUser['id'])`.
- Redirect a `referer()` (típicamente `/receivables/view/X` o
  `/account-payments`).

---

## 7. RBAC integration

### 7.1 `AppController::$controllerModuleMap`

```php
'AccountPayments' => 'account_payments',
```

### 7.2 `AuthorizationService::MODULES`

```php
'account_payments' => 'Abonos',
```

### 7.3 Seed migration

Archivo: `config/Migrations/YYYYMMDDHHMMSS_SeedAccountPaymentsPermissions.php`.
Mismo patrón que `SeedReceivablesPermissions`:

- No-admin (Cajero típico): `can_view=1, can_create=1, can_edit=0,
  can_delete=0`. El borrado de abonos queda como acción privilegiada
  (afecta Cierre Diario retroactivamente si se borra un abono del día).
- Admin: `1,1,1,1` (bypass real igual).
- Mesero: `can_view=1` solamente (puede consultar pero no registrar).

### 7.4 Sidebar (sección Finanzas)

Posición dentro del grupo **Finanzas**:

1. Gastos
2. Cuentas por Cobrar
3. **Abonos** ← aquí (icono `bi-cash-stack` o `bi-piggy-bank`).
4. Cierre Diario

Visible solo si `userPermissions['account_payments']['view']`. Sin badge
counter — los abonos son un flujo, no una bandeja pendiente.

---

## 8. Re-wire de `ReceivablesController`, `ReceivablesTable` y `ReceivableService`

Cambios concretos en código existente cuando se implemente este módulo.

### 8.1 `ReceivablesTable::initialize()` — habilitar la asociación

En `src/Model/Table/ReceivablesTable.php` (líneas 44-45 actuales,
comentadas como "Module 6 will plug in"):

```php
// Descomentar y dejar activo:
$this->hasMany('AccountPayments', [
    'foreignKey' => 'receivable_id',
    'dependent' => true,
    'cascadeCallbacks' => true,
]);
```

`dependent + cascadeCallbacks` es defensivo — la FK CASCADE en DB ya
asegura el borrado físico, pero `cascadeCallbacks` dispara los callbacks
de ORM (útil si se agregan behaviors auditando deletes en el futuro).

### 8.2 `ReceivableService::recomputeStatus` — extender para SUM real

Hoy (líneas 449-488) solo re-deriva `status` desde el `paid_amount`
existente sin sumar abonos. Extender:

```text
1. Abrir transacción (ya lo hace).
2. SELECT ... FROM receivables WHERE id = ? FOR UPDATE (agregar lock).
3. $sum = AccountPayments->find()->where(['receivable_id' => $rec->id])
            ->select(['s' => $q->func()->sum('amount')])->first()?->s ?? '0.00';
4. $rec->paid_amount = number_format((float)$sum, 2, '.', '').
5. $rec->status = ((float)$sum + EPSILON >= (float)$rec->total_amount)
                    ? STATUS_PAGADO : STATUS_PENDIENTE.
6. save() (ya lo hace).
7. Log::info si hubo flip de estado.
```

**Importante (docblock):** documentar que `markAsPaid` sigue siendo válido
para "saldar sin movimiento monetario" (p.ej. condonación de deuda) —
setea `paid_amount = total_amount` sin crear abono. Si luego se crean
abonos reales, `recomputeStatus` (llamado por `AccountPaymentService`)
sobrescribirá `paid_amount` con la SUM real, lo cual puede demote la CxC
de `pagado` a `pendiente` si la SUM real es menor. **Esa es la conducta
correcta**: los abonos son la fuente de verdad; `markAsPaid` es un flag de
último recurso. Documentar en el docblock de `markAsPaid`:

> *Use only for non-monetary settlement (debt forgiveness, write-off).
> Real partial payments must go through AccountPaymentService::create —
> abonos take precedence over this flag.*

### 8.3 `ReceivablesController::view` — render del timeline

- Cambiar el `contain` para cargar abonos:
  ```php
  $rec = $this->Receivables->get($id, contain: [
      'Customers', 'Orders', 'Creator',
      'AccountPayments' => fn($q) => $q->contain(['Creator'])->orderBy(['AccountPayments.created' => 'DESC']),
  ]);
  ```
- En el template, reemplazar el empty state "Próximamente — Módulo 6" por:
  - Si `$rec->account_payments` está vacío: empty state "Aún no hay abonos
    registrados" + botón habilitado "Registrar abono".
  - Si tiene abonos: timeline (ver §9.3).

### 8.4 `ReceivablesController::view` — botón "Registrar abono"

Hoy disabled con tooltip "Próximamente". Cambiar a link activo:

```php
<?= $this->Html->link('Registrar abono',
    ['controller' => 'AccountPayments', 'action' => 'add', '?' => ['receivable_id' => $rec->id]],
    ['class' => 'btn btn-primary']) ?>
```

Visible solo si `$rec->isPending()` y el usuario tiene permiso
`account_payments.add`.

### 8.5 (Opcional, futuro) `OrderService` — uso de abonos del día

No es un cambio de este módulo, pero queda anotado para Cierre Diario
(§17): los KPI de "ingresos reales" deben sumar `account_payments` del
día, NO `receivables.paid_amount`. Documentado también en §12 del diseño
05.

---

## 9. Screens & UX

Todas las vistas usan `default.php` + componentes de `DESIGN.md`.

### 9.1 `index.php` — Abonos recientes con KPI strip

**Encabezado:**
- `h1`: "Abonos".
- `button-primary`: "Registrar abono" → `/account-payments/add` (única
  acción primaria de la pantalla).

**KPI strip (`stat-card` × 3, grid 3 columnas en md+):**

```text
[ Abonos hoy: $480.000 ]   [ Total mes: $12.400.000 ]   [ # hoy: 23 ]
```

La tarjeta "Abonos hoy" lleva borde-left `primary-soft` (acento de marca,
no relleno saturado — regla DESIGN).

**Card de filtros:**
1. Input `from` (date, default hoy-30).
2. Input `to` (date, default hoy).
3. Customer picker (autocomplete por nombre/teléfono).
4. Select `payment_method` (Todos / Efectivo / Nequi / Daviplata / Transferencia).
5. Input `q` (búsqueda libre cliente).
6. Botón "Filtrar" (`btn-secondary`).
7. Link "Limpiar" si hay filtro activo.

**Tabla:**

| Columna       | Width | Alineación | Contenido                                                                |
|---------------|-------|------------|--------------------------------------------------------------------------|
| Fecha/Hora    | 140px | left       | `created->i18nFormat('dd/MM HH:mm')`.                                    |
| Cliente       | auto  | left       | `$p->receivable->customer->name`. Tel debajo en `text-muted`.            |
| CxC #         | 90px  | left       | Link a `/receivables/view/{id}` con texto `#{receivable_id}`.            |
| Descripción   | auto  | left       | `h($p->receivable->description)` truncada a 60 chars.                    |
| Monto         | 120px | right      | `$this->Number->currency($p->amount)`. Bold.                              |
| Método        | 120px | center     | Badge `badge-soft-info` con `$p->getMethodLabel()`.                       |
| Autor         | 130px | left       | `$p->creator?->username ?? '—'`.                                          |
| Acciones      | 70px  | right      | `btn-icon` Eliminar (si permiso) con confirm.                            |

**Badges de método:** todos usan `badge-soft-info` (azul tenue) excepto
`efectivo` que usa `badge-soft-success` (verde) — refuerza la noción
"efectivo = ingreso inmediato".

**Empty state:**
- Sin filtros: "No hay abonos registrados. [Registrar abono]".
- Con filtros: "Sin resultados para los filtros aplicados".

### 9.2 `add.php` — Registrar abono

Card único, una columna (max-width 640px):

- **CxC picker** (input con autocomplete `/receivables/search.json?q=...`
  filtrado a `status=pendiente`).
  - Si llegó vía `?receivable_id=X`: campo readonly con la CxC ya
    seleccionada, mostrando "{Cliente} — Pedido #N — Saldo $X".
  - Sin preselección: autocomplete muestra "{Cliente} ({tel}) — $saldo".
- **Hint de saldo** (debajo del picker, dinámico):
  ```text
  Saldo actual: $300.000
  ```
  En `text-muted` con icono `bi-info-circle`. Se actualiza vía JS cuando
  cambia la selección de CxC.
- **Monto** (`amount`): input numérico, `step="0.01"`, `min="0.01"`,
  `max` = saldo de la CxC seleccionada (HTML5 client-side, el server
  re-valida). Sufijo `$`. Atajo botón "Pagar todo" que copia el saldo
  exacto al input.
- **Método de pago**: grupo de **radios horizontales** (no select — son
  4 opciones, mejor visibilidad), uno por método. Default: Efectivo.
- **Observaciones**: textarea 3 rows, opcional.

Pie:
- `btn-primary` "Registrar abono".
- `btn-light` "Cancelar" → `referer()` o `/account-payments`.

**Flash de éxito (en redirect):**
> "Abono registrado por $150.000. Saldo restante: $150.000."

Si el abono completó la CxC:
> "Abono registrado por $300.000. La cuenta ha sido marcada como pagada."

### 9.3 Integración en `ReceivablesController::view` — Timeline

Reemplazar el placeholder actual del módulo 6 por un timeline real,
dentro de la columna izquierda (2/3) del view:

```text
┌─────────────────────────────────────────────────────────┐
│ Timeline de abonos                  [+ Registrar abono] │
├─────────────────────────────────────────────────────────┤
│ ● 12/05 14:32  $150.000  Efectivo   por Juan   [×]      │
│ ● 10/05 09:15  $100.000  Nequi      por María  [×]      │
│ ● 03/05 18:40   $50.000  Daviplata  por Juan   [×]      │
└─────────────────────────────────────────────────────────┘
```

Cada fila:
- Punto coloreado (gris) a la izquierda — refuerza la cronología.
- Fecha + hora compacta.
- Monto bold.
- Badge del método (mismo estilo que el index).
- Autor (`creator->username`).
- `btn-icon` eliminar (si permiso `account_payments.delete`) con
  confirmación: "¿Eliminar este abono de $X? La cuenta volverá a quedar
  pendiente si era el último abono que la saldó."

**Botón "Registrar abono"** (header del card):
- Visible si `$rec->isPending()` y permiso `account_payments.add`.
- Link a `/account-payments/add?receivable_id={id}`.

### 9.4 Confirmaciones

- **delete de abono (pendiente)**: "¿Eliminar este abono de $X? Se
  recalculará el saldo de la cuenta."
- **delete de abono (CxC pagada)**: "Este abono completó la cuenta. Al
  eliminarlo, la cuenta volverá a estado **Pendiente**. ¿Continuar?"
  (Mensaje diferenciado calculado en el template inspeccionando si
  borrar este abono dejaría `paid_amount < total`.)

---

## 10. Edge cases

| Caso                                                              | Decisión                                                                                                                |
|-------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------|
| Monto > saldo actual (overpayment)                                 | **Fase 1: rechazar** con mensaje "El monto excede el saldo de $X". Validado en `AccountPaymentService::create` paso 3c, bajo lock. Fase 2 opcional: permitir y dejar leftover como crédito a favor del cliente. |
| Abono sobre CxC ya pagada (status=`pagado`)                        | Rechazado con "La cuenta ya está pagada. No se admiten más abonos.". Chequeado dos veces: pre-lock (UX rápida) y bajo lock (consistencia ante concurrencia). |
| Borrar último abono de una CxC `pagado` → demote a `pendiente`     | Soportado en `delete` paso e: si tras la SUM `paid_amount < total`, status flips a `pendiente`. Log warning con el demote. |
| Borrar abono cuyo monto NO afecta el status (CxC sigue pagada)     | Permitido. `recomputeStatus` deja el status como estaba (sigue `pagado` porque siguen sumando ≥ total). Solo se actualiza `paid_amount`. |
| Concurrencia: dos abonos simultáneos sobre la misma CxC            | `SELECT ... FOR UPDATE` sobre la fila de `receivables` en el paso 3a serializa las transacciones. La segunda espera; cuando entra, ve el `paid_amount` actualizado por la primera y aplica el nuevo abono encima correctamente (o rechaza por overpayment si la primera completó). |
| Concurrencia: `markAsPaid` admin + abono concurrente               | Mismo lock. `markAsPaid` también debe adquirir FOR UPDATE (extender `ReceivableService` — §8.2). El último gana; si gana el abono, el `paid_amount` queda con la SUM real (no la igualdad forzada por `markAsPaid`). Aceptable: refleja la realidad de los abonos. |
| `payment_method = 'credito'`                                       | Rechazado dos veces: (a) explícitamente en service paso 1 con mensaje específico, (b) por `inList` en validación de tabla con mensaje genérico. La doble defensa garantiza mensaje claro al usuario. |
| Abono sobre CxC borrada                                            | Imposible vía UI (los pickers solo muestran CxC con `status=pendiente`). Si llega vía API/replay: `existsIn` falla → error claro. Si la CxC se borra **durante** la transacción del abono: FK CASCADE no aplica al INSERT — el INSERT fallaría por FK constraint si la CxC ya no existe. |
| Pedido cancelado borra CxC con abonos                              | FK CASCADE borra los abonos automáticamente. `ReceivableService::deleteForOrder` ya loguea el monto voided (diseño 05 §5.2). **Implicación para Cierre Diario:** si el delete ocurre el mismo día del abono, el ingreso "desaparece" del Cierre — esto es la conducta correcta (el pedido se canceló, su crédito no es ingreso). |
| Pedido cancelado un día después del abono                          | Mismo CASCADE. El Cierre Diario de hoy ya cerró sin los abonos borrados — el reporte histórico queda inconsistente con la realidad actual. **Mitigación:** Cierre Diario debe ser un snapshot inmutable (no recalcula); ver §17. **Trade-off documentado.** |
| Usuario que crea el abono se elimina después (`created_by`)         | FK SET NULL. El abono persiste con `created_by = null`. UI muestra "—" en columna Autor. Auditoría preservada en logs. |
| Abono con `amount` exactamente igual al saldo (boundary)            | `paid_amount + amount == total` (con EPSILON 0.005) → CxC flipea a `pagado`. Sin overpayment. Caso esperado para "Pagar todo". |
| `notes` con caracteres especiales / inyección                       | El input pasa por `h()` en render. `notes` es TEXT, sin parsing. Sin riesgo XSS. |
| Filtrado por usuario-repartidor                                     | NO aplica directamente. Los abonos son de Finanzas, no de Operación. Pero si en el futuro se quiere "abonos sobre pedidos de mi repartidor", agregar filtro condicional con join a `receivables.order_id → orders.delivery_id`. |
| Date boundary (abono creado 23:59:59 vs Cierre del día)             | Cierre Diario debe usar el rango `[date 00:00:00, date+1 00:00:00)` o `DATE(created) = :date` consistentemente. Documentar en §17. |

---

## 11. Tests (referencia — el proyecto opta-out hoy)

> El usuario opta-out de tests automatizados (memoria). Sección informativa
> para cuando esa decisión se revierta. **No** escribir tests ahora.

**Archivos esperados:**

1. `tests/TestCase/Model/Entity/AccountPaymentTest.php` —
   `getFormattedAmount`, `getMethodLabel` (incluido fallback para método desconocido).
2. `tests/TestCase/Model/Table/AccountPaymentsTableTest.php` — validación
   (`amount > 0`, `inList` rechaza `credito`, `existsIn`), finders
   (`findForReceivable`, `findInDateRange`, `findToday`, `findByMethod`).
3. `tests/TestCase/Service/AccountPaymentServiceTest.php` — `create`
   (happy path, rechazo overpayment, rechazo método `credito`, rechazo CxC
   pagada, flip a `pagado` cuando completa), `delete` (recomputa SUM,
   demote `pagado`→`pendiente`, deja `pagado` si sigue alcanzando),
   **test de concurrencia** simulado con dos threads/conexiones que crean
   abonos sobre la misma CxC — uno debe ganar, el otro debe rechazar
   overpayment si juntos exceden el total.
4. `tests/TestCase/Controller/AccountPaymentsControllerTest.php` —
   RBAC en cada acción (`view`, `add`, `delete`), `add` con
   `?receivable_id=X` precarga correctamente, POST rechaza overpayment
   con flash error, `delete` POST recomputa y redirige, **rechaza
   `payment_method=credito`**.
5. `tests/Fixture/AccountPaymentsFixture.php`.
6. `tests/TestCase/Service/ReceivableAccountPaymentIntegrationTest.php` —
   integración punta a punta: crear CxC desde pedido a crédito → crear
   abono parcial → verificar `paid_amount` actualizado y `status=pendiente`
   → crear abono que completa → verificar `status=pagado` → eliminar
   último abono → verificar demote a `pendiente`.

---

## 12. Open questions / risks

1. **Overpayment estricto vs leftover credit.** Fase 1 rechaza el abono
   si excede el saldo. La alternativa "permitir y registrar crédito a
   favor" requiere modelar saldos negativos en `paid_amount > total_amount`
   (hoy bloqueado por regla `paid_amount <= total_amount` en
   `ReceivablesTable::buildRules` — diseño 05). **Decisión actual:**
   esperar pedido explícito de Finanzas; el escenario real es raro
   (el cajero conoce el saldo antes de cobrar) y el atajo "Pagar todo"
   en la UI hace overpayments casi imposibles por accidente.

2. **Inmutabilidad y Cierre Diario.** Borrar un abono de un día ya
   cerrado deja el Cierre histórico inconsistente con la realidad actual.
   El diseño 05 ya señala que el Cierre Diario debe ser snapshot
   inmutable. **Decisión pendiente** (módulo 8): ¿el snapshot copia los
   abonos a una tabla `daily_close_snapshots` o solo guarda los totales
   agregados? Recomendación: totales agregados (más simple, suficiente
   para reportes históricos; el detalle queda en logs).

3. **`recomputeStatus` con SUM vs cálculo incremental en
   `AccountPaymentService::create`.** Hoy el create hace SUM bajo lock
   (paso 3f) en lugar de `paid_amount + new_amount`. La SUM es más
   robusta (resiste cualquier drift) pero menos eficiente cuando hay
   muchos abonos por CxC (la SUM escanea todos). Para Davi Rapid (CxC
   típica con 1-5 abonos) es trivial. **Si una CxC acumulara >100
   abonos**, considerar incremental + reconciliación periódica.

4. **Comparación con epsilon vs bcmath.** Usamos `(float)` + EPSILON
   0.005 por simplicidad (decimal(12,2) cabe en float sin pérdida hasta
   ~$10 mil millones). Si en el futuro se modelan multi-moneda o
   decimales más finos, migrar a `bcmath` (`bccomp`, `bcadd`, `bcsub`)
   centralizadamente en una clase utilitaria de Money.
