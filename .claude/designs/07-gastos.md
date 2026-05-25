# Diseño — Módulo Gastos

> Módulo CRUD pequeño del grupo **Finanzas**. Registra egresos generales del
> negocio (compras, servicios, sueldos eventuales) que NO se asocian a pedidos.
> Es alimentador directo del **Cierre Diario** (resta los gastos del día) y del
> **Dashboard** (los suma y los descuenta de utilidad neta).
>
> Referencias: `davirapid.md` §14 Gastos (fuente principal), §17 Cierre Diario,
> §19 Dashboard. Plantilla más cercana: `.claude/designs/03-ajustes-inventario.md`.
> A diferencia de Ajustes, **Gastos sí permite `edit`** (no es append-only).

---

## 1. Data model

### 1.1 Tabla `expenses`

| Columna        | Tipo                  | Null | Notas                                                              |
|----------------|-----------------------|------|--------------------------------------------------------------------|
| `id`           | int PK AI             | no   | Default-signed (consistente con `receivables`, `account_payments`). |
| `description`  | varchar(255)          | no   | Texto libre — uso real esperado: "Compra carne", "Pago internet".  |
| `amount`       | decimal(12,2)         | no   | `> 0` siempre. Sin moneda — mismo COP que el resto del sistema.    |
| `expense_date` | date                  | no   | Fecha del gasto (NO `datetime`). El día determina a qué Cierre Diario aplica. |
| `created_by`   | int unsigned          | sí   | FK `users.id` `ON DELETE SET NULL`. Preserva el evento al borrar el user. |
| `created`      | datetime              | sí   | Auditoría técnica (cuándo se ingresó el registro al sistema).       |
| `modified`     | datetime              | sí   | Última edición (se permite edit).                                   |

**Índices:**
- `idx_expenses_date` (`expense_date`) — todos los reportes y KPIs filtran por
  fecha. El listado default ordena `expense_date DESC, id DESC`.
- `idx_expenses_creator` (`created_by`) — auditoría "¿quién registró qué?".

**FKs:**
- `created_by` → `users(id)` `ON DELETE SET NULL ON UPDATE RESTRICT`.

**Decisiones clave:**

- **`expense_date` separado de `created`**: el spec §14 lista "fecha" como
  atributo del gasto, distinta del timestamp técnico. Permite registrar un
  gasto de ayer hoy (caso común: factura llegó tarde).
- **NO se asocia a pedidos** (spec §14 explícito): egresos generales. No hay
  `order_id` ni `customer_id`.
- **Sin categoría obligatoria**: el spec no la pide. Si el operador necesita
  categorizar, lo hace en `description`. Una columna `category` futura sería
  evolutiva, no Fase 1.
- **Fechas futuras permitidas (Fase 1)**: el spec no las prohíbe. Algunos
  flujos legítimos (gastos programados/pre-pagados) lo justifican. La UI
  muestra un warning visual si `expense_date > today`, pero no bloquea.
- **`amount` decimal(12,2)**: mismo patrón que `receivables.total_amount`.
  Floats en PHP son seguros en este rango — sin `bcmath` (prohibido por
  memoria del proyecto).

### 1.2 Entity `Expense`

```php
class Expense extends Entity
{
    protected array $_accessible = [
        'description' => true,
        'amount' => true,
        'expense_date' => true,
        'created_by' => true,
        'creator' => true,
    ];

    public function getFormattedAmount(): string
    {
        return '$' . number_format((float)$this->amount, 2, ',', '.');
    }

    public function getFormattedDate(): string
    {
        return $this->expense_date?->i18nFormat('dd/MM/yyyy') ?? '—';
    }

    public function isFuture(): bool
    {
        if ($this->expense_date === null) {
            return false;
        }
        $today = (new \Cake\I18n\Date())->format('Y-m-d');
        return $this->expense_date->format('Y-m-d') > $today;
    }
}
```

### 1.3 Tabla `ExpensesTable`

- `setTable('expenses')`, `setPrimaryKey('id')`, `setDisplayField('description')`.
- `addBehavior('Timestamp')` (completo — `created` + `modified`).
- `belongsTo('Creator', ['className' => 'Users', 'foreignKey' => 'created_by', 'joinType' => 'LEFT'])`.

**`validationDefault`** (formato):
- `notEmptyString('description', 'La descripción es requerida')`.
- `maxLength('description', 255, ...)`.
- `requirePresence('amount', 'create')` + `numeric` + `greaterThan(0)` + `decimal(2)`.
- `requirePresence('expense_date', 'create')` + `date(['ymd'])`.

**`buildRules`:**
- `existsIn(['created_by'], 'Users', ['allowNullableNulls' => true])`.

**Custom finders:**
- `findInDateRange(SelectQuery $q, array $opts)` — `from`/`to` `'YYYY-MM-DD'`,
  inclusivos contra `expense_date` (NO `created`, porque el cierre y el
  dashboard operan sobre la fecha de negocio).
- `findToday(SelectQuery $q)` — `expense_date = today`.
- `findThisMonth(SelectQuery $q)` — `expense_date >= primer día del mes actual`.

---

## 2. Constants — `ExpenseConstants`

```php
final class ExpenseConstants
{
    public const DESCRIPTION_MAX_LENGTH = 255;

    /**
     * Sugerencias para el datalist del formulario. Texto libre — esto sólo
     * acelera el caso frecuente.
     *
     * @var list<string>
     */
    public const DESCRIPTION_SUGGESTIONS = [
        'Compra de insumos',
        'Pago de servicios',
        'Pago de arriendo',
        'Mantenimiento',
        'Transporte',
        'Sueldos',
        'Otros',
    ];

    /** Tolerance para comparaciones decimal(12,2). */
    public const EPSILON = 0.005;

    private function __construct()
    {
    }
}
```

---

## 3. Service — `ExpenseService`

Servicio simple (sin pipeline, sin filter dedicado, sin history). Operaciones
sobre una sola tabla — **no requiere transacciones** (CakePHP las maneja
internamente para saves de single-row).

```php
final class ExpenseService
{
    use LocatorAwareTrait;

    public function create(array $data, int $userId): array;
    public function update(Expense $expense, array $data): array;
    public function delete(Expense $expense): array;
}
```

Forma de retorno estándar:
`array{success: bool, expense?: Expense, errors?: array<int, string>}`.

**`create`:**
1. Pre-validación rápida (description, amount > 0, expense_date no vacío).
2. `newEntity(... + ['created_by' => $userId > 0 ? $userId : null])`.
3. `save()` — confía en `validationDefault` para el resto.
4. `Log::info('Expense created: id={id} amount={a} date={d} user={u}', ...)`.

**`update`:**
1. `patchEntity($expense, $data)` — sin `created_by` (no se reasigna autoría).
2. `save()`.
3. `Log::info('Expense updated: id={id}', ...)`.

**`delete`:**
1. `delete($expense)`.
2. `Log::warning('Expense deleted: id={id} amount={a} date={d}', ...)` — el
   borrado afecta retroactivamente cierres ya emitidos; el warning ayuda a
   trazarlo en logs.

Sin orquestación cross-table. Sin transacciones explícitas.

---

## 4. Controller — `ExpensesController`

CRUD estándar más KPIs en `index`. Sin acciones custom — todas calzan en
`_actionToPermission` base.

**Paginación:**
```php
public array $paginate = [
    'limit' => 15, 'maxLimit' => 15,
    'order' => ['Expenses.expense_date' => 'DESC', 'Expenses.id' => 'DESC'],
    'sortableFields' => ['expense_date', 'amount'],
];
```

**Acciones:** `index`, `add`, `edit`, `view`, `delete`.

**Filtros (`_currentFilters`):**
- `q` — texto libre en `description LIKE`.
- `from`/`to` — `expense_date` inclusivo. Si `to < from` se intercambia con
  flash warning (mismo patrón que `AccountPaymentsController`).
- `sort` whitelisted `['expense_date', 'amount']`, `direction` `['asc','desc']`.

**KPIs (`_loadKpis`):**
```php
return [
    'today_amount' => sum(amount WHERE expense_date = today),
    'month_amount' => sum(amount WHERE expense_date >= first-of-month),
    'ytd_amount'   => sum(amount WHERE expense_date >= first-of-year),
];
```

**`view`:** muestra todos los campos + autor + timestamps + link a edit/delete
(según permisos).

---

## 5. RBAC — módulo `expenses`

Tres lugares estándar:

1. **`AppController::$controllerModuleMap`:**
   `'Expenses' => 'expenses'` después de `'AccountPayments' => 'account_payments'`.

2. **`AuthorizationService::MODULES`:**
   `'expenses' => 'Gastos'` después de `'account_payments'`.

3. **Seed migration** `SeedExpensesPermissions`:
   - No-admin: `view=1, create=1, edit=1, delete=0` (borrar un gasto afecta
     cierres pasados — defaultear conservador). Roles autorizados podrán
     activar `delete` desde la UI de Roles.
   - Administrador: matriz completa (bypass cubre igual).

**Sidebar:** item "Gastos" en grupo Finanzas, después de "Abonos", icono
`bi-receipt`. Filtrado automático por `userPermissions['expenses']['view']`.

---

## 6. UX

### 6.1 `index.php`

**Header (`dr-page-header`):**
- `h1` "Gastos".
- `button-primary` único: "Nuevo gasto" → `add`.

**KPI strip (3 cards):**
- "Gastos hoy" — destacado con `border-start border-3 border-danger` (rojo
  porque son egresos).
- "Total mes" — neutral.
- "Total YTD (acumulado año)" — neutral.

**Filter bar (GET):** input texto `q`, date `from`, date `to`, botón
"Filtrar", link "Limpiar" si hay filtros activos.

**Tabla:**
| Col          | Width | Align  | Contenido                                           |
|--------------|-------|--------|-----------------------------------------------------|
| Fecha        | 130px | left   | `expense_date` `dd/MM/yyyy`. Sortable.              |
| Descripción  | auto  | left   | `h($expense->description)`.                         |
| Monto        | 140px | right  | `$expense->getFormattedAmount()` en `text-danger` (egresos). Sortable. |
| Autor        | 160px | left   | `$expense->creator?->name ?? 'Usuario eliminado'`.  |
| Acciones     | 110px | right  | `btn-icon` editar (`bi-pencil`) + eliminar (`bi-trash`, `text-danger`) condicionados por permisos. |

**Empty state:**
- Sin filtros: "Aún no hay gastos registrados. [Registrar el primero]".
- Con filtros: "Sin gastos para los filtros aplicados".

### 6.2 `add.php` / `edit.php`

Layout en card único, ancho ~7 cols. Campos:
- `description`: input texto + `<datalist>` con `ExpenseConstants::DESCRIPTION_SUGGESTIONS`.
- `amount`: input `number step="0.01" min="0.01"`, prefix `$`.
- `expense_date`: input `date`, default = hoy en `add`.

**Pie:**
- `button-primary` "Guardar" (única acción principal).
- `btn-light` "Cancelar" → `index` (en add) o `view` (en edit).

Warning visual (alert info, no bloqueante) si `expense_date > today`:
*"Estás registrando un gasto con fecha futura."*

### 6.3 `view.php`

Card con `<dl>` (Fecha, Descripción, Monto, Autor, Creado, Modificado) +
botones Edit / Eliminar arriba (condicionados por permisos). Sin tablas
secundarias (los gastos no se relacionan con otras entidades).

---

## 7. Tests

| Tipo            | Path                                                     |
|-----------------|----------------------------------------------------------|
| Fixture         | `tests/Fixture/ExpensesFixture.php` (3 filas: 1 hoy, 1 mes actual, 1 mes anterior) |
| Entity test     | `tests/TestCase/Model/Entity/ExpenseTest.php`            |
| Table test      | `tests/TestCase/Model/Table/ExpensesTableTest.php`       |
| Service test    | `tests/TestCase/Service/ExpenseServiceTest.php`          |
| Controller test | `tests/TestCase/Controller/ExpensesControllerTest.php`   |

**Fixture seeds (3 filas):**
1. `id=1, description='Compra carne', amount='150000.00', expense_date=hoy, created_by=1`.
2. `id=2, description='Pago servicios', amount='80000.00', expense_date=ayer mes actual, created_by=1`.
3. `id=3, description='Arriendo', amount='1200000.00', expense_date=mes anterior, created_by=1`.

**Cobertura mínima:**

- **Entity:** `getFormattedAmount`, `getFormattedDate`, `isFuture`.
- **Table:** rechaza description vacío / amount<=0 / fecha vacía; finders
  (`findInDateRange`, `findToday`, `findThisMonth`) filtran bien.
- **Service:** `create` happy + reject 0 amount + reject empty description;
  `update` cambia campos; `delete` borra fila.
- **Controller:** auth (anon → /login), RBAC (sin permiso 403, admin bypass),
  CRUD completo (GET/POST), filtros (`?q=`, `?from=&to=`, rango invertido →
  flash + swap), KPIs visibles.

**Permissions fixture:** agregar dos filas (id=11 cajero `expenses` view+create+edit
sin delete, id=12 solo lectura `expenses` todos en 0) para reusar los users 1/2/3.

---

## 8. Edge cases

| Caso                                                | Decisión                                                                          |
|-----------------------------------------------------|-----------------------------------------------------------------------------------|
| `amount = 0` o negativo                              | Bloqueado en `validationDefault::greaterThan(0)`.                                |
| `description` vacío o solo espacios                  | `notEmptyString` + trim en el service.                                            |
| `expense_date` faltante                              | `requirePresence('expense_date', 'create')`.                                      |
| `expense_date` futura                                | Permitida (Fase 1). UI muestra alert info no bloqueante. Helper `isFuture()`.    |
| `expense_date` muy vieja (e.g. años atrás)           | Permitida. Sin validación de antigüedad — el operador puede cargar facturas viejas. |
| User eliminado tras crear el gasto                   | `created_by` → null (SET NULL). UI muestra "Usuario eliminado".                  |
| Borrar gasto de día ya cerrado                       | Permitido pero loguea WARNING. El cierre del día ya emitido queda inconsistente — eso lo aborda el módulo Cierre Diario en su lógica de re-cálculo (fuera de alcance). |
| Filtro `to < from`                                   | Intercambia + flash warning (mismo patrón que AccountPayments).                  |
| XSS en `description`                                 | `h()` en todos los renders.                                                       |
| Concurrencia (dos creates simultáneos)               | Single-row insert con AI — sin issue. Sin lock necesario.                         |
| Repartidor logueado                                  | Spec no restringe Gastos por user-repartidor. Sin scoping especial.              |

---

## 9. Notas de implementación

- **No bcmath**: usar `(float)` + `number_format(..., 2, '.', '')` cuando se
  persiste, `number_format(..., 2, ',', '.')` cuando se muestra.
- **Sin custom routes**: CRUD estándar resuelto por `$builder->fallbacks()`.
- **`expense_date` se hidrata como `Cake\I18n\Date`** (no DateTime) porque
  la columna es `date`. Verificar en templates: usar `->format('Y-m-d')` o
  `->i18nFormat('dd/MM/yyyy')`, no `->format('Y-m-d H:i:s')`.
- **`created_by` se setea solo en `create`**, nunca se patchea en `update`.
- **Phase-2 ideas (out of scope):** categorías, recurrentes (renta mensual
  automática), adjuntar factura/comprobante (subida de archivo), export CSV
  para contabilidad.
