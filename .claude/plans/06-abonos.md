# Plan de implementación — Módulo Abonos (AccountPayments)

> Plan paso a paso para implementar el módulo Finanzas → Abonos según
> `.claude/designs/06-abonos.md`. Los abonos son pagos parciales (o totales)
> contra una CxC y son la única vía mediante la cual una deuda a crédito se
> convierte en ingreso registrado.
>
> Referencias obligatorias antes de implementar:
> - `.claude/designs/06-abonos.md` (spec definitivo).
> - `.claude/plans/05-cuentas-por-cobrar.md` (formato y patrones).
> - `src/Service/ReceivableService.php` (extender `recomputeStatus`,
>   reusar `flattenErrors`, mantener la disciplina de transacciones).
> - `src/Model/Table/ReceivablesTable.php` (líneas 44-49 — habilitar la
>   asociación comentada `hasMany AccountPayments`).
> - `src/Controller/ReceivablesController.php::view` (línea 87 — extender
>   contain con AccountPayments + Creator).
> - `templates/Receivables/view.php` (líneas 95-107 y 158-162 — reemplazar
>   los botones "Próximamente" deshabilitados por links reales + renderear
>   timeline de abonos).
> - `src/Service/InventoryAdjustmentService.php` (patrón append-only).
> - `src/Constants/OrderConstants.php` (reusar PAYMENT_CASH/NEQUI/etc).
> - `src/View/Helper/SidebarHelper.php` (insertar item).
> - `src/Service/AuthorizationService.php` + `src/Controller/AppController.php`
>   (registrar módulo).

---

## Decisión crítica: NO bcmath

Mismo criterio que módulo 5. Decimal(12,2) cabe en floats; comparaciones
con epsilon 0.005. `round((float)$a + (float)$b, 2)` para cálculos;
`number_format($x, 2, '.', '')` para serializar a DB.

---

## 1. File manifest

Orden de creación/modificación. Timestamps: `20260525120000` y `20260525120100`.

### Migraciones (2)

1. `[CREATE] config/Migrations/20260525120000_CreateAccountPayments.php` —
   tabla `account_payments` con FK CASCADE a `receivables` (asegura cleanup
   cuando una CxC se borra) y FK SET NULL a `users` para `created_by`.
2. `[CREATE] config/Migrations/20260525120100_SeedAccountPaymentsPermissions.php`
   — fila por rol. No-admin Cajero `view+create`. Admin matriz completa.

### Constantes (1)

3. `[CREATE] src/Constants/AccountPaymentConstants.php` — `PAYMENT_METHODS`
   (subset de OrderConstants sin `credito`), `PAYMENT_LABELS`, `EPSILON`,
   `NOTES_MAX_LENGTH`.

### Entity (1)

4. `[CREATE] src/Model/Entity/AccountPayment.php` — whitelist + helpers
   `getFormattedAmount()` y `getMethodLabel()`. Sin virtuals: los helpers
   son explícitos en templates.

### Table (1)

5. `[CREATE] src/Model/Table/AccountPaymentsTable.php` —
   `setTable('account_payments')`, displayField=`id`, Timestamp solo `created`,
   belongsTo `Receivables` INNER + `Creator` (alias Users) LEFT, validation
   (numeric/gt0/inList/maxLength), buildRules (existsIn FKs), finders
   (`findForReceivable`, `findInDateRange`, `findToday`, `findByMethod`).

### Service (1)

6. `[CREATE] src/Service/AccountPaymentService.php` — métodos:
   - `create(array $data, int $userId): array` — abre transacción, FOR UPDATE
     lock sobre la CxC, valida overpayment, persiste, recalcula SUM,
     actualiza `paid_amount` y `status`.
   - `delete(AccountPayment $p, int $userId): array` — abre transacción,
     FOR UPDATE lock, borra, recalcula SUM, demote `pagado→pendiente` si
     procede, log warning.

   Constructor: `__construct(?ReceivableService $receivables = null)` para
   permitir mock (aunque hoy no se invoca a ReceivableService desde aquí;
   se mantiene la inyección por simetría con el resto del proyecto y
   futuro uso).

### Controller (1)

7. `[CREATE] src/Controller/AccountPaymentsController.php` — `index`, `add`,
   `delete`. Sin `view` (cabe en el index/timeline). Sin `edit` (append-only).

### RBAC + navegación (3 modificaciones)

8. `[MODIFY] src/Controller/AppController.php` — agregar
   `'AccountPayments' => 'account_payments',` al `$controllerModuleMap`.
9. `[MODIFY] src/Service/AuthorizationService.php` — agregar
   `'account_payments' => 'Abonos',` al `MODULES`.
10. `[MODIFY] src/View/Helper/SidebarHelper.php` — insertar item `account_payments`
    con icono `bi-cash-stack` justo después de `receivables`.

### Rutas

No se requieren rutas custom. El fallback (`$builder->fallbacks()`)
cubre `/account-payments/{index,add,delete}` con los HTTP methods estándar
(el controller hace `allowMethod(['post','delete'])` en delete).

### Re-wire (3 modificaciones)

11. `[MODIFY] src/Model/Table/ReceivablesTable.php` — descomentar y
    habilitar la asociación `hasMany AccountPayments` (líneas 44-49) con
    `foreignKey='receivable_id'`, `dependent=true`, `cascadeCallbacks=true`.

12. `[MODIFY] src/Service/ReceivableService.php::recomputeStatus` (líneas
    449-488) — reemplazar la implementación actual (que solo re-deriva
    `status` desde `paid_amount` existente) por una que SUMa
    `account_payments.amount` bajo FOR UPDATE sobre la fila de
    `receivables`, escribe `paid_amount` con el resultado, y flipea
    `status` con epsilon 0.005. Mantener el log de flips a `pagado` y el
    log informativo de demote.

    Adicionalmente: actualizar el docblock de `markAsPaid` para advertir
    que `recomputeStatus` (vía abonos) sobrescribe el `paid_amount` y
    puede revertir el flag manual.

13. `[MODIFY] src/Controller/ReceivablesController.php::view` (línea 87) —
    extender el `contain` para cargar `AccountPayments` ordenados por
    `created DESC` con `Creator`. Sin pasar opciones extra al template;
    el view ya tiene `$receivable` disponible.

### Templates

14. `[CREATE] templates/AccountPayments/index.php` — KPI strip (3 cards:
    abonos hoy / total mes / # transacciones hoy), filtros (from/to/q/
    payment_method/customer_id), tabla, badges por método.

15. `[CREATE] templates/AccountPayments/add.php` — form mínimo con campo
    CxC (select con CxC pendientes etiquetadas con cliente + saldo, si
    llega `?receivable_id=X` queda preseleccionada y se muestra hint de
    saldo), input `amount` con max=saldo, radios para método (4 opciones,
    default Efectivo), textarea `notes`.

16. `[MODIFY] templates/Receivables/view.php`:
    - Reemplazar el bloque "Aún no hay abonos registrados" + botón
      deshabilitado (líneas 95-107) por: si `$receivable->account_payments`
      está vacío, mostrar empty state + link real "Registrar abono"; si
      hay abonos, renderear timeline con dot, fecha, monto, badge método,
      autor, botón eliminar (con confirmación contextualizada).
    - Reemplazar el botón "Registrar abono (próximamente)" deshabilitado
      en el footer del card de saldo (líneas 158-162) por un
      `Html->link` real a `/account-payments/add?receivable_id={id}`,
      visible solo si `$receivable->isPending()` y el usuario tiene
      permiso `account_payments.create`.

### Tests (5 archivos)

17. `[CREATE] tests/Fixture/AccountPaymentsFixture.php`.
18. `[CREATE] tests/TestCase/Model/Entity/AccountPaymentTest.php`.
19. `[CREATE] tests/TestCase/Model/Table/AccountPaymentsTableTest.php`.
20. `[CREATE] tests/TestCase/Service/AccountPaymentServiceTest.php`.
21. `[CREATE] tests/TestCase/Controller/AccountPaymentsControllerTest.php`.
22. `[MODIFY] tests/Fixture/PermissionsFixture.php` — agregar permisos
    `account_payments` para Cajero (view+create) y Solo lectura (sin
    acceso) para que los tests de controller con `loginAs(2)`/`loginAs(3)`
    funcionen.

### Cierre

23. `[RUN] php bin/cake.php migrations migrate`.
24. `[RUN] php bin/cake.php migrations dump`.
25. `[RUN] composer cs-check` (cs-fix si hace falta sobre nuevos archivos).
26. `[RUN] php -l` sobre cada PHP nuevo.
27. `[RUN] php bin/cake.php routes | grep account-payments`.
28. `[RUN] grep -n "Próximamente" templates/Receivables/view.php` → vacío.
29. HTTP smoke: anonymous GET `/account-payments` → 302 a `/users/login`.

---

## 2. Step-by-step execution

### Paso 1 — Migration `CreateAccountPayments`

`Migrations\BaseMigration`. Proteger con `hasTable`. Columnas:

- `id` PK default (signed) — consistente con `receivables`.
- `receivable_id` integer NOT NULL **signed** (matchea `receivables.id`).
- `amount` decimal(12,2) NOT NULL.
- `payment_method` varchar(20) NOT NULL.
- `notes` text null.
- `created_by` integer signed=false null (matchea `users.id` UNSIGNED).
- `created` datetime null.

Índices: `idx_ap_receivable_created (receivable_id, created)`,
`idx_ap_created (created)`, `idx_ap_method (payment_method)`,
`idx_ap_creator (created_by)`.

FKs:
- `receivable_id` → `receivables(id)` DELETE CASCADE UPDATE RESTRICT,
  constraint `fk_ap_receivable`.
- `created_by` → `users(id)` DELETE SET_NULL UPDATE RESTRICT,
  constraint `fk_ap_creator`.

`down()`: drop con `hasTable` guard.

**Acceptance:** `migrations migrate` aplica limpio sin tocar `receivables`
ni `users`; la tabla nueva muestra 4 índices + 2 FKs.

### Paso 2 — `SeedAccountPaymentsPermissions`

Calco de `SeedReceivablesPermissions`:
- No-admin `view=1, create=1, edit=0, delete=0`.
- Admin matriz completa (bypass igual cubre).
- `down()` borra todas las filas del módulo.

### Paso 3 — `AccountPaymentConstants`

`final class AccountPaymentConstants` con:
- `PAYMENT_METHODS = [OrderConstants::PAYMENT_CASH, ::PAYMENT_NEQUI, ::PAYMENT_DAVIPLATA, ::PAYMENT_TRANSFER]`
  (excluye `PAYMENT_CREDIT`).
- `PAYMENT_LABELS = [...]` con etiquetas legibles.
- `EPSILON = 0.005`.
- `NOTES_MAX_LENGTH = 65000`.
- Constructor privado.

### Paso 4 — Entity `AccountPayment`

- `$_accessible` con receivable_id, amount, payment_method, notes,
  created_by + asociaciones `receivable` y `creator`.
- `getFormattedAmount(): string` → `'$' . number_format((float)$this->amount, 2, ',', '.')`.
- `getMethodLabel(): string` → `AccountPaymentConstants::PAYMENT_LABELS[$method] ?? ucfirst($method)`.

### Paso 5 — `AccountPaymentsTable`

- `initialize()`: setTable + setPrimaryKey + setDisplayField('id') +
  Timestamp con events solo `created`.
- `belongsTo('Receivables', ['foreignKey'=>'receivable_id','joinType'=>'INNER'])`.
- `belongsTo('Creator', ['className'=>'Users','foreignKey'=>'created_by','joinType'=>'LEFT'])`.
- `validationDefault`: requirePresence + integer en `receivable_id`;
  requirePresence + numeric + greaterThan(0) + decimal(2) en `amount`;
  requirePresence + inList(PAYMENT_METHODS) en `payment_method` con
  mensaje claro mencionando "no se puede abonar con crédito";
  allowEmptyString + maxLength(NOTES_MAX_LENGTH) en `notes`.
- `buildRules`: `existsIn(['receivable_id'], 'Receivables')`,
  `existsIn(['created_by'], 'Users', ['allowNullableNulls' => true])`.
- Finders:
  - `findForReceivable(opts: ['receivable_id'=>int])` → where + orderBy created DESC.
  - `findInDateRange(opts: ['from'=>'YYYY-MM-DD','to'=>'YYYY-MM-DD'])`
    → mismo patrón que `InventoryAdjustmentsTable::findInDateRange`.
  - `findToday()` → atajo `created >= today AND created < tomorrow`
    (portable MySQL/SQLite).
  - `findByMethod(opts: ['payment_method'=>string])`.

### Paso 6 — `AccountPaymentService`

```php
final class AccountPaymentService
{
    use LocatorAwareTrait;

    public function __construct(?ReceivableService $receivables = null)
    {
        $this->receivables = $receivables ?? new ReceivableService();
    }
}
```

#### `create(array $data, int $userId): array`

```
1. validateInput($data) — receivable_id > 0, amount > 0, payment_method en lista.
   Si payment_method === PAYMENT_CREDIT → error explícito con mensaje claro.
2. Lookup CxC sin lock (UX rápido):
   - Si no existe → error "La cuenta no existe.".
   - Si isPaid() → error "La cuenta ya está pagada.".
3. ConnectionManager::get('default')->transactional(function () {
     a. SELECT ... FROM receivables WHERE id = ? FOR UPDATE  (re-leer bajo lock).
     b. Idempotencia: si isPaid() ahora → error "ya pagada".
     c. Overpayment: si paid+amount > total + EPSILON → error con saldo restante.
     d. Build entity { receivable_id, amount=number_format(...,2,'.',''),
        payment_method, notes (trim/null), created_by }.
     e. Save abono. Si falla → flattenErrors.
     f. $newPaid = SUM(amount) WHERE receivable_id=? (incluye el INSERT recién hecho
        — la propia conexión ve sus writes pre-commit).
     g. $rec->paid_amount = number_format($newPaid, 2, '.', '').
     h. $rec->status = (newPaid + EPSILON >= total) ? PAGADO : PENDIENTE.
     i. Save $rec. Si falla → flattenErrors.
     j. Log info siempre + log info adicional si hubo flip a pagado.
     k. resultBox = { success: true, payment, receivable }.
   });
4. Return resultBox.
```

Detalles importantes:
- El FOR UPDATE se construye con `$receivables->find()->where(...)->epilog('FOR UPDATE')`
  (sintaxis de CakePHP 5; opcionalmente `->modifier(...)` no aplica). En
  SQLite el `FOR UPDATE` se ignora silenciosamente — no rompe los tests.
- La SUM se hace con `$accountPaymentsTable->find()->select(['s'=>$q->func()->sum('amount')])->where([...])->first()`.
- Si falla cualquier paso dentro del callback, `return false` deja el rollback.

#### `delete(AccountPayment $p, int $userId): array`

```
1. Capturar $receivableId = $p->receivable_id.
2. transactional({
     a. SELECT receivables WHERE id=? FOR UPDATE.
     b. Si no existe (CASCADE ya borró) → resultBox success true, return true.
     c. $accountPayments->delete($p). Si falla → error.
     d. $newPaid = SUM(amount) WHERE receivable_id=$receivableId (sin el borrado).
     e. $rec->paid_amount = number_format($newPaid, 2, '.', '').
     f. Demote logic:
        - si era PAGADO y newPaid + EPSILON < total → status = PENDIENTE + Log::warning('CxC demoted').
        - si newPaid + EPSILON >= total → status = PAGADO.
        - sino → status = PENDIENTE.
     g. Save $rec.
     h. Log::warning('Abono eliminado: id={p} rec={r} amount={a} user={u}').
   });
```

#### Helpers privados

- `validateInput(array $data): list<string>` — defensive pre-DB validation.
- `flattenErrors(array $errors): list<string>` — copia/paste de
  `ReceivableService` (privado, no se comparte).
- `sumPaymentsForReceivable(int $receivableId): float` — encapsula la SUM
  por simetría con `recomputeStatus`.

### Paso 7 — `AccountPaymentsController`

```php
class AccountPaymentsController extends AppController {
    public array $paginate = [
        'limit' => 15, 'maxLimit' => 15,
        'order' => ['AccountPayments.created' => 'DESC', 'AccountPayments.id' => 'DESC'],
        'sortableFields' => ['created', 'amount', 'payment_method'],
    ];

    private AccountPaymentService $service;
    private AccountPaymentsTable $AccountPayments;

    public function initialize(): void { ... }

    public function index(): void {
        // _currentFilters() → {from, to, q, payment_method, customer_id}
        // _buildIndexQuery() contain Receivables.Customers + Creator
        // _loadKpis() → {today_amount, month_amount, today_count}
        // customers list para el select del filtro
    }

    public function add() {
        $payment = $this->AccountPayments->newEmptyEntity();
        $preselectId = (int)$this->request->getQuery('receivable_id', 0);
        $hint = null;
        if ($preselectId > 0) {
            $payment->receivable_id = $preselectId;
            $rec = $this->fetchTable('Receivables')->find()
                ->where(['Receivables.id' => $preselectId])
                ->contain(['Customers'])->first();
            if ($rec) { $hint = ['receivable' => $rec, 'balance' => $rec->getBalance()]; }
        }
        if ($this->request->is(['post','put'])) {
            $result = $this->service->create((array)$this->request->getData(), $userId);
            if ($result['success']) {
                $rec = $result['receivable'];
                $balance = $rec->getBalance();
                $msg = $balance <= 0.005
                    ? 'Abono registrado. La cuenta ha sido marcada como pagada.'
                    : sprintf('Abono registrado. Saldo restante: $%s.',
                        number_format($balance, 2, ',', '.'));
                $this->Flash->success($msg);
                return $this->redirect([
                    'controller' => 'Receivables', 'action' => 'view',
                    $rec->id ?? $preselectId,
                ]);
            }
            foreach ($result['errors'] ?? ['No se pudo registrar el abono.'] as $msg) {
                $this->Flash->error($msg);
            }
            $payment = $this->AccountPayments->patchEntity($payment, (array)$this->request->getData());
        }
        // pickerOptions: solo CxC pendientes con cliente
        $receivablesList = $this->fetchTable('Receivables')->find()
            ->where(['Receivables.status' => ReceivableConstants::STATUS_PENDIENTE])
            ->contain(['Customers'])
            ->orderBy(['Receivables.created' => 'DESC'])
            ->all();
        $this->set(compact('payment', 'receivablesList', 'hint'));
        $this->set('paymentMethods', AccountPaymentConstants::PAYMENT_LABELS);
    }

    public function delete(int $id) {
        $this->request->allowMethod(['post','delete']);
        $payment = $this->AccountPayments->get($id);
        $result = $this->service->delete($payment, $userId);
        // ... Flash + redirect a referer() o /account-payments
    }
}
```

`_loadKpis()`: tres queries (today/month sum, today count). Hoy ranges
calculados con `DateTimeImmutable` consistentes con el filtrado.

### Paso 8 — AppController

Agregar entrada `'AccountPayments' => 'account_payments'` después de
`'Receivables' => 'receivables'`.

### Paso 9 — AuthorizationService

Agregar `'account_payments' => 'Abonos'` después de `'receivables'`.

### Paso 10 — SidebarHelper

Insertar item después del de `receivables`:

```php
[
    'module' => 'account_payments',
    'label' => 'Abonos',
    'icon' => 'bi-cash-stack',
    'url' => ['controller' => 'AccountPayments', 'action' => 'index'],
],
```

### Paso 11 — `ReceivablesTable` re-wire

Reemplazar el bloque comentado por:

```php
$this->hasMany('AccountPayments', [
    'foreignKey' => 'receivable_id',
    'dependent' => true,
    'cascadeCallbacks' => true,
]);
```

### Paso 12 — `ReceivableService::recomputeStatus` re-wire

Hoy (líneas 449-488) solo re-deriva el status desde el paid_amount sin
tocar abonos. Reemplazar el cuerpo del `transactional` por:

```text
a. $accountPayments = $this->fetchTable('AccountPayments').
b. SELECT receivables WHERE id=$rec->id FOR UPDATE (lock pesimista).
c. $sum = $accountPayments->find()
     ->select(['s' => $q->func()->sum('AccountPayments.amount')])
     ->where(['AccountPayments.receivable_id' => $rec->id])
     ->first()?->s ?? '0.00';
d. $newPaid = (float)$sum.
e. $oldStatus = $rec->status.
f. $rec->paid_amount = number_format($newPaid, 2, '.', '').
g. $rec->status = ($newPaid + EPSILON >= (float)$rec->total_amount)
       ? STATUS_PAGADO : STATUS_PENDIENTE.
h. save($rec).
i. Si oldStatus !== nuevo status → Log::info con el flip.
```

Importante:
- El lock FOR UPDATE en el `recomputeStatus` y en
  `AccountPaymentService::create` apunta al mismo recurso (la fila de
  `receivables`), garantizando serialización entre `markAsPaid`, abonos
  concurrentes y `recomputeStatus`.
- Actualizar el docblock de `markAsPaid` advirtiendo que un futuro
  `recomputeStatus` (desde abonos) puede sobrescribir el `paid_amount`.

### Paso 13 — `ReceivablesController::view` re-wire

Cambiar el `contain` por:

```php
$receivable = $this->Receivables->get($id, contain: [
    'Customers',
    'Orders',
    'Creator',
    'AccountPayments' => function ($q) {
        return $q->contain(['Creator'])->orderBy(['AccountPayments.created' => 'DESC']);
    },
]);
```

Sin más cambios; el template usa `$receivable->account_payments`.

### Paso 14 — `templates/AccountPayments/index.php`

Espejo de `templates/Receivables/index.php`:
- Page header con título "Abonos" + `btn-primary` "Registrar abono" si
  hay permiso `create`.
- KPI strip (3 cards: "Abonos hoy" / "Total mes" / "# transacciones hoy").
- Card de filtros (date range, customer select, payment_method select,
  search q libre).
- Tabla con columnas: # / Fecha / Cliente (link a customer view) /
  CxC (link a receivables view) / Descripción / Monto (right) / Método
  (badge) / Autor / Acciones (delete con confirm).
- Empty state contextualizado a si hay filtros activos.
- `$this->element('pagination')`.

### Paso 15 — `templates/AccountPayments/add.php`

Card único, max-width 640px:
- Hidden `_csrfToken` automático vía `Form->create`.
- Si `$hint` presente: bloque informativo con cliente, descripción CxC, saldo actual.
- Select `receivable_id` (opciones = CxC pendientes etiquetadas con
  cliente + saldo); si hay `$hint`, render como hidden + readonly text;
  sino render como select normal.
- Input `amount` (number step=0.01 min=0.01, max=balance si hay hint).
- Radios horizontales `payment_method` (4 opciones, default
  `OrderConstants::PAYMENT_CASH`).
- Textarea `notes` (3 rows).
- Botones: `btn-primary` "Registrar abono" + `btn-light` "Cancelar"
  (link a referer o `/account-payments`).

### Paso 16 — `templates/Receivables/view.php` re-wire

**Cambio 1 (bloque card "Abonos", líneas 95-107):**

Reemplazar el botón deshabilitado y el texto "Próximamente" por:

```php
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Abonos</strong>
        <?php if (
            $receivable->isPending()
            && !empty($userPermissions['account_payments']['create'])
        ): ?>
            <?= $this->Html->link(
                '<i class="bi bi-plus-lg"></i> Registrar abono',
                ['controller' => 'AccountPayments', 'action' => 'add',
                 '?' => ['receivable_id' => $receivable->id]],
                ['escape' => false, 'class' => 'btn btn-sm btn-primary'],
            ) ?>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($receivable->account_payments)): ?>
            <p class="text-muted mb-0">Aún no hay abonos registrados.</p>
        <?php else: ?>
            <ul class="list-unstyled mb-0">
                <?php foreach ($receivable->account_payments as $abono): ?>
                    <li class="d-flex align-items-center py-2 border-bottom">
                        <span class="me-2 text-muted">●</span>
                        <span class="me-3 text-muted small" style="min-width: 110px;">
                            <?= $abono->created !== null
                                ? h($abono->created->i18nFormat('dd/MM HH:mm')) : '—' ?>
                        </span>
                        <strong class="me-3" style="min-width: 100px;">
                            <?= h($abono->getFormattedAmount()) ?>
                        </strong>
                        <span class="badge badge-soft-info me-3">
                            <?= h($abono->getMethodLabel()) ?>
                        </span>
                        <span class="text-muted small me-auto">
                            por <?= h($abono->creator?->name ?? '—') ?>
                        </span>
                        <?php if (!empty($userPermissions['account_payments']['delete'])): ?>
                            <?= $this->Form->postLink(
                                '<i class="bi bi-x-lg"></i>',
                                ['controller' => 'AccountPayments', 'action' => 'delete',
                                 $abono->id],
                                [
                                    'escape' => false,
                                    'class' => 'btn btn-icon btn-light text-danger',
                                    'title' => 'Eliminar abono',
                                    'confirm' => sprintf(
                                        '¿Eliminar este abono de %s? '
                                        . 'Se recalculará el saldo de la cuenta.',
                                        $abono->getFormattedAmount(),
                                    ),
                                ],
                            ) ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
```

**Cambio 2 (botón en card de saldo, líneas 158-162):**

Reemplazar:

```php
<button type="button" class="btn btn-primary" disabled
        title="Próximamente — Módulo de Abonos">
    Registrar abono (próximamente)
</button>
```

por:

```php
<?php if (
    $receivable->isPending()
    && !empty($userPermissions['account_payments']['create'])
): ?>
    <?= $this->Html->link(
        'Registrar abono',
        ['controller' => 'AccountPayments', 'action' => 'add',
         '?' => ['receivable_id' => $receivable->id]],
        ['class' => 'btn btn-primary'],
    ) ?>
<?php endif; ?>
```

### Paso 17 — `AccountPaymentsFixture`

3 filas mínimas; basadas en `ReceivablesFixture` (CxC #2 tiene
paid_amount=5000):
- id=1, receivable_id=2, amount=3000.00, method=efectivo, by user 1,
  created 2026-05-23 13:30.
- id=2, receivable_id=2, amount=2000.00, method=nequi, by user 1,
  created 2026-05-23 14:00.
- id=3, receivable_id=3, amount=30000.00, method=transferencia, by user 1,
  created 2026-05-22 15:00. (CxC #3 está marcada `pagado` en el fixture).

Esto da una semilla coherente con la CxC #2 (paid=5000=3000+2000).

### Paso 18 — Permissions fixture update

Agregar dos filas a `PermissionsFixture` (ids 9 y 10):
- role_id=2 (Cajero): account_payments view+create.
- role_id=3 (Solo lectura): account_payments todos 0.

### Pasos 19-22 — Tests

Cubrir lo descrito en §4 de este plan.

### Paso 23-29 — Cierre

Ejecutar y verificar lo listado en §5.

---

## 3. Re-wire instructions (full)

### 3.1 `src/Model/Table/ReceivablesTable.php`

Buscar el bloque comentado (líneas 44-49):

```php
        // Module 6 (Abonos / AccountPayments) will plug in:
        // $this->hasMany('AccountPayments', [
        //     'foreignKey' => 'receivable_id',
        //     'dependent' => true,
        //     'cascadeCallbacks' => true,
        // ]);
```

Reemplazar por la asociación activa:

```php
        $this->hasMany('AccountPayments', [
            'foreignKey' => 'receivable_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
```

### 3.2 `src/Service/ReceivableService.php::recomputeStatus`

Reemplazar el cuerpo del closure dentro del `transactional` (líneas
456-479) por:

```php
$accountPayments = $this->fetchTable('AccountPayments');

// Pessimistic lock on the receivable row.
$receivables->find()
    ->where(['Receivables.id' => $rec->id])
    ->epilog('FOR UPDATE')
    ->first();

$sumRow = $accountPayments->find()
    ->select(['s' => $accountPayments->find()->func()->sum('AccountPayments.amount')])
    ->where(['AccountPayments.receivable_id' => $rec->id])
    ->first();
$newPaid = (float)($sumRow?->s ?? 0);

$oldStatus = $rec->status;
$rec->paid_amount = number_format($newPaid, 2, '.', '');
$rec->status = $newPaid + 0.005 >= (float)$rec->total_amount
    ? ReceivableConstants::STATUS_PAGADO
    : ReceivableConstants::STATUS_PENDIENTE;

if (!$receivables->save($rec)) {
    $resultBox = [
        'success' => false,
        'errors' => $this->flattenErrors($rec->getErrors()),
    ];
    return false;
}

if ($oldStatus !== $rec->status) {
    Log::info('CxC status flipped via recompute: id={id} from={f} to={t} paid={p}', [
        'id' => $rec->id, 'f' => $oldStatus, 't' => $rec->status,
        'p' => $rec->paid_amount, 'scope' => ['receivables'],
    ]);
}

$resultBox = ['success' => true, 'receivable' => $rec];
return true;
```

Adicionalmente: en el docblock de `markAsPaid` agregar una nota:

```text
* Use only for non-monetary settlement (debt forgiveness, write-off).
* Real partial payments must go through AccountPaymentService::create —
* abonos take precedence and recomputeStatus() will overwrite paid_amount
* with the real SUM, potentially reverting this flag.
```

### 3.3 `src/Controller/ReceivablesController.php::view`

Cambiar el `contain` actual:

```php
$receivable = $this->Receivables->get($id, contain: [
    'Customers',
    'Orders',
    'Creator',
]);
```

por:

```php
$receivable = $this->Receivables->get($id, contain: [
    'Customers',
    'Orders',
    'Creator',
    'AccountPayments' => function ($q) {
        return $q->contain(['Creator'])->orderBy(['AccountPayments.created' => 'DESC']);
    },
]);
```

### 3.4 `templates/Receivables/view.php`

Detallado en Paso 16 (§2). Dos bloques a reemplazar; ambos eliminan
botones disabled "Próximamente — Módulo de Abonos".

### 3.5 Verificación post-rewire

```bash
grep -n "Próximamente" templates/Receivables/view.php   # debe ser vacío
grep -n "account_payments" templates/Receivables/view.php   # ≥ 2 matches
grep -n "AccountPayments" src/Service/ReceivableService.php   # ≥ 1 match
grep -n "hasMany('AccountPayments'" src/Model/Table/ReceivablesTable.php   # 1 match
```

---

## 4. Test plan

### 4.1 `AccountPaymentsFixture` (3 filas)

Compatibles con `ReceivablesFixture` (la CxC #2 tiene paid=5000):
- id=1, receivable_id=2, amount=3000, method=efectivo, user=1.
- id=2, receivable_id=2, amount=2000, method=nequi, user=1.
- id=3, receivable_id=3, amount=30000, method=transferencia, user=1.

### 4.2 `AccountPaymentTest` (entity)

- `getFormattedAmount()` para varios montos.
- `getMethodLabel()` para método conocido y desconocido (fallback).

### 4.3 `AccountPaymentsTableTest`

- Validation: amount > 0, amount required, inList rechaza `credito`,
  payment_method requerido, notes maxLength.
- `existsIn(receivable_id, Receivables)`.
- `findForReceivable` filtra y ordena.
- `findInDateRange` con from/to.
- `findToday` filtra a hoy.
- `findByMethod` filtra por método exacto.

### 4.4 `AccountPaymentServiceTest`

- `create` happy path: persiste + actualiza paid_amount de la CxC.
- `create` con monto que completa la CxC → status flipea a `pagado`.
- `create` rechaza overpayment (paid + amount > total).
- `create` rechaza método `credito` con mensaje específico.
- `create` rechaza si la CxC ya está pagada.
- `create` rechaza si la CxC no existe (receivable_id inexistente).
- `delete` recomputa paid_amount.
- `delete` de un abono que mantiene paid >= total → status sigue `pagado`.
- `delete` del último abono de una CxC `pagado` → demote a `pendiente`.
- Integración con `recomputeStatus`: verificar que tras varios creates,
  el paid_amount almacenado coincide con la SUM real.

### 4.5 `AccountPaymentsControllerTest`

- GET /account-payments anonymous → 302 a login.
- GET /account-payments como admin → 200 + contiene "Abonos".
- GET /account-payments como Cajero (role 2) con permiso → 200.
- GET /account-payments como Solo lectura sin permiso → 403.
- GET /account-payments/add?receivable_id=2 → 200 + muestra saldo.
- POST /account-payments/add válido → 302 + crea fila + recomputa CxC.
- POST /account-payments/add con `payment_method=credito` → flash error
  específico + no crea fila.
- POST /account-payments/add con overpayment → flash error con saldo + no crea.
- POST /account-payments/delete/{id} → 302 + borra + recomputa.
- DELETE method coverage opcional (allowMethod los acepta).

### 4.6 (No nuevo) Test del re-wire

El test existente `OrderServiceCxCIntegrationTest` no requiere cambios
(la creación de CxC sigue funcionando igual; los abonos son módulo
aparte). `ReceivableServiceTest` puede no requerir nuevos casos para
`recomputeStatus` extendido si solo se prueba indirectamente vía
`AccountPaymentServiceTest` (preferencia: indirección, evita duplicar
fixture wiring).

---

## 5. Verification checklist

1. `php bin/cake.php migrations migrate` → "All Done".
2. `php bin/cake.php migrations dump`.
3. `composer cs-check` → clean en archivos nuevos.
4. `php -l` cada archivo nuevo.
5. `php bin/cake.php routes | grep account-payments` → muestra fallbacks
   `/account-payments/{action}` (no hay rutas custom).
6. `curl -I http://localhost/account-payments` → 302 a login.
7. `grep -n "Próximamente" templates/Receivables/view.php` → vacío.
8. `grep -n "AccountPayments" src/Service/ReceivableService.php` → ≥ 1.
9. `grep -n "hasMany('AccountPayments'" src/Model/Table/ReceivablesTable.php` → 1.

---

## 6. Risks / gotchas

1. **`epilog('FOR UPDATE')` en SQLite.** SQLite ignora la cláusula
   silenciosamente — los tests pasan, pero la serialización solo es real
   en MySQL/Postgres. Documentar en el service.

2. **SUM dentro del mismo INSERT.** El `SUM(amount)` post-save incluye
   el INSERT recién hecho porque corre en la misma conexión y
   transacción (los writes son visibles a la propia conexión incluso
   pre-commit). Validado por convención de PHPUnit con el connection
   default.

3. **`epilog` viene después de `where`.** Orden importante: el query
   builder de Cake aplica el epilog al final. La forma `->where(...)
   ->epilog('FOR UPDATE')->first()` es la correcta.

4. **Permissions fixture.** El controller test usa loginAs(2)/(3) que
   esperan permisos preexistentes; sin agregar las filas a
   `PermissionsFixture` los tests del módulo nuevo fallan con 403
   inesperados.

5. **CASCADE elimina abonos al cancelar pedido.** Cuando OrderService
   borra la CxC (cancel/delete/payment-method-changed), la FK CASCADE
   elimina los abonos físicamente. El warning de
   `ReceivableService::deleteForOrder` ya menciona el monto voided —
   los abonos individuales no se loguean uno a uno (decisión documentada
   en el design §10).

6. **`recomputeStatus` antes existía sin SUM.** El método sigue siendo
   llamable desde el controller (no se usa hoy, pero podría exponerse
   en una "Recalcular" admin). Tras el re-wire, llamarlo recomputa
   desde la SUM real; los CxC marcados con `markAsPaid` sin abonos
   reales verán su `paid_amount` reseteado a 0 si se invoca
   `recomputeStatus` sobre ellos. Documentado en el docblock.

7. **Idempotencia bajo lock.** El check `isPaid()` dentro del closure
   tras el FOR UPDATE evita la race "dos transacciones leen 'pendiente'
   y ambas insertan el abono que completa la CxC". El segundo ve la
   fila ya pagada y rechaza con mensaje claro.

8. **`/account-payments/delete/{id}` por fallback.** El fallback de
   CakePHP cubre `/{controller}/{action}/*` (`DashedRoute`); como
   `AccountPayments` se inflexiona a `account-payments`, la URL final
   funciona. No se requiere ruta custom porque `allowMethod` en el
   controller acepta `POST` (que es lo que envía `Form->postLink`).

9. **JSON/AJAX no implementado.** El picker de CxC en `add.php` es un
   `<select>` server-rendered con CxC pendientes. No hay endpoint
   `search.json` (mencionado en el diseño §9.2) — se difiere a una
   fase futura si hace falta. El select limita a CxC pendientes y se
   acompaña del input `customer_id` opcional en `index` para
   filtrado posterior.

10. **Inyección de `ReceivableService` en `AccountPaymentService`.**
    Se mantiene la dependencia en el constructor por simetría (todos
    los services del proyecto que tocan otros dominios lo hacen) y
    futuro uso. Hoy el service no invoca a `ReceivableService` — toda
    la lógica vive dentro del propio `transactional`. La decisión está
    documentada en el spec §5.1.
