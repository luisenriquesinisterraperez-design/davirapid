# Davi Rapid — Guía de Arquitectura

Guía técnica para construir y extender el panel administrativo de Davi Rapid. Este documento define **cómo** se trabaja en el proyecto: responsabilidades por capa, patrones, convenciones e instrucciones paso a paso para tareas comunes.

- Para qué hace el sistema (módulos, reglas de negocio, flujos), ver `davirapid.md`.
- Para reglas visuales y sistema de diseño, ver `.claude/rules/DESIGN.md`.
- Para comandos rápidos, ver `CLAUDE.md`.

> Davi Rapid se construye sobre el skeleton **CakePHP 5.x** (PHP ≥ 8.2). El proyecto es greenfield: este documento es prescriptivo, no descriptivo. Define qué hay que construir y bajo qué reglas, no qué ya existe.

---

## 1. Capas de la aplicación

### 1.1 Ciclo de vida del request

```
HTTP Request
    │
    ▼
[Middleware Stack]
    ErrorHandler → HostHeader → Asset → Routing → Authentication → BodyParser → CSRF
    │
    ▼
[AppController::beforeFilter]
    1. Obtener identidad del usuario
    2. Exponer currentUser a las vistas
    3. Calcular contadores del sidebar
    4. Calcular permisos del usuario
    5. Forzar permiso para el controller/action actual
    │
    ▼
[Controller Action]
    1. Validar input
    2. Delegar a Service (lógica de negocio)
    3. Interactuar con Model (persistencia)
    4. Setear variables de vista
    │
    ▼
[View/Template]
    Layout (default.php) + template específico
    │
    ▼
HTTP Response
```

### 1.2 Responsabilidades por capa

| Capa | Hace | NO hace |
|------|------|---------|
| **Controller** | Recibe el request, valida input, delega a servicios, setea variables de vista | Lógica de negocio, queries complejas |
| **Service** | Lógica de negocio, orquestación, transacciones | Acceder al request/response directamente |
| **Table (Model)** | Asociaciones, validación de datos, custom finders, behaviors | Lógica de negocio compleja |
| **Entity** | Whitelist de campos (`$_accessible`), virtual properties, helpers de dominio | Queries a la base de datos |
| **View/Template** | HTML, formato visual | Lógica de negocio, queries |
| **Constants** | Valores de dominio reutilizables (roles, estados, tipos) | Lógica, acceso a DB |
| **Middleware** | Cross-cutting de seguridad (auth, CSRF, host) | Lógica de negocio específica |

**Regla guía:** si estás escribiendo un `if` con significado de negocio dentro de un controller, va a un service. Si estás corriendo una query desde un template, va al controller. Si estás hardcodeando un literal como `'cancelado'` en PHP, va a una constante.

---

## 2. Estructura conceptual de directorios

Esta sección explica **dónde va cada cosa**, no qué archivos existen hoy. Cuando agregues código nuevo, ubicalo en la capa correcta.

### `src/Controller/`

Un controller por recurso. Siempre extiende `AppController`. Solo HTTP.

```
src/Controller/
├── AppController.php              # Base: permisos, contadores de sidebar
├── {Resource}Controller.php       # Uno por módulo (Orders, Products, ...)
└── Trait/                         # (opcional) traits compartidos
```

- **Va aquí:** validación de input, instancia de servicios en `initialize()`, query builders compartidos como `_buildXQuery()`, asignación de variables vía `$this->set()`.
- **No va aquí:** reglas de negocio, transiciones de estado, cálculos, queries complejas.

### `src/Service/`

Un servicio por dominio de negocio. Sin acceso al request/response. Accede a tablas vía `TableRegistry::getTableLocator()->get()`.

```
src/Service/
├── {Domain}Service.php            # Lógica core del dominio
├── {Domain}PipelineService.php    # State machine (si el dominio tiene estados)
├── {Domain}FilterService.php      # Filtros de búsqueda sobre queries
└── {Domain}HistoryService.php     # Auditoría campo a campo
```

- **Va aquí:** reglas y validaciones de negocio, state machines, orquestación entre tablas, transacciones, integraciones externas.
- **No va aquí:** `$this->request`, `$this->response`, render de HTML.

### `src/Model/Entity/`

Entidades tipadas con whitelist y helpers de dominio.

- **Va aquí:** `$_accessible`, helpers que inspeccionan estado de la entidad (`isCancelled()`, `isPaid()`), virtual fields vía `_get{Field}()`.
- **No va aquí:** queries, lógica que requiere otras tablas.

### `src/Model/Table/`

Clases ORM con asociaciones, reglas de validación, behaviors y custom finders.

- **Va aquí:** `initialize()` (tabla, PK, asociaciones, behaviors), `validationDefault()` (validación de campos), custom finders.
- **No va aquí:** lógica de negocio más allá de validación de datos, orquestación multi-tabla.

> **Importante:** `Application::bootstrap()` desactiva el fallback class de `FactoryLocator` (`allowFallbackClass(false)`). Toda tabla referenciada debe tener una clase `XxxTable` concreta en `src/Model/Table/`. CakePHP no la fabrica en runtime.

### `src/Constants/`

Valores de dominio como clases `final` con `public const`. Nunca hardcodear strings o IDs de dominio en PHP.

```
src/Constants/
└── {Domain}Constants.php           # OrderConstants, RoleConstants, ...
```

### `src/Middleware/`

Middlewares custom. Hoy contiene `HostHeaderMiddleware` (validación de Host en producción). Sumar acá cualquier middleware nuevo (rate limiting, locale, etc.).

### `templates/`

Una carpeta por controller, más elementos y layouts compartidos.

```
templates/
├── layout/
│   ├── default.php                # Páginas autenticadas (sidebar + topbar)
│   ├── login.php                  # Página de login
│   └── ajax.php                   # Respuestas AJAX (sin chrome de layout)
├── element/
│   └── pagination.php             # Componente de paginación reutilizable
└── {ControllerName}/
    ├── index.php                  # Lista
    ├── add.php                    # Crear
    ├── edit.php                   # Editar
    └── view.php                   # Detalle
```

### `config/Migrations/`

Migraciones con timestamp. Clase base `Migrations\BaseMigration`.

```
config/Migrations/
└── YYYYMMDDHHMMSS_DescriptiveName.php
```

### `webroot/`

Assets públicos. CSS y JS custom viven acá; no hay build step.

```
webroot/
├── css/                            # CSS del sistema de diseño (ver DESIGN.md)
├── js/                             # JS común y por módulo
└── uploads/{entity}/{id}/          # Archivos subidos por el usuario (si aplica)
```

---

## 3. Cómo crear un módulo nuevo

Receta paso a paso. El ejemplo usa un recurso ficticio `Widgets` para ilustrar la convención sin pisar dominio real.

### 3.1 Crear la migración

Usar `Migrations\BaseMigration` (NO `AbstractMigration`). Proteger con `hasTable()`.

```php
class CreateWidgets extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('widgets')) {
            $this->table('widgets')
                ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
                ->addColumn('user_id', 'integer', ['signed' => true, 'null' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'RESTRICT'])
                ->create();
        }
    }
}
```

> **Crítico:** las columnas FK deben tener **el mismo tipo** (signed/unsigned) que la columna referenciada. Verificá la tabla destino antes de agregar la FK.

### 3.2 Crear las constantes (si aplica)

Si el módulo tiene valores de dominio (estados, tipos, opciones), creá una clase de constantes. Nombres en español si modelan terminología del negocio (`'cancelado'`, `'preparando'`); identificadores PHP en inglés.

```php
final class WidgetConstants
{
    public const STATUS_ACTIVE = 'activo';
    public const STATUS_INACTIVE = 'inactivo';
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];
}
```

### 3.3 Crear la entity

Definir `$_accessible` y agregar helpers solo si la entidad tiene estado significativo a inspeccionar.

```php
class Widget extends Entity
{
    protected array $_accessible = [
        'name' => true,
        'user_id' => true,
    ];

    public function isActive(): bool
    {
        return $this->status === WidgetConstants::STATUS_ACTIVE;
    }
}
```

### 3.4 Crear la table

Agregar asociaciones, `Timestamp`, validación y custom finders si los necesita.

```php
class WidgetsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('widgets');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Users', ['foreignKey' => 'user_id', 'joinType' => 'INNER']);
    }

    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->notEmptyString('name', 'El nombre es requerido')
            ->inList('status', WidgetConstants::STATUSES);
    }
}
```

> **Importante:** **no** sobrescribir `findList()` en CakePHP 5 — la firma es incompatible. Usar custom finders con otro nombre (`findCodeList`, `findActive`, etc.).

### 3.5 Crear el controller

Extender `AppController`, fijar paginación, instanciar servicios en `initialize()`.

```php
class WidgetsController extends AppController
{
    public $paginate = ['limit' => 15, 'maxLimit' => 15];

    public function initialize(): void
    {
        parent::initialize();
        // $this->widgetService = new WidgetService();
    }

    public function index()
    {
        $widgets = $this->paginate($this->Widgets->find()->contain(['Users']));
        $this->set(compact('widgets'));
    }
}
```

### 3.6 Registrar permisos

Tres lugares deben actualizarse:

1. **`AppController::$controllerModuleMap`** — mapear el controller al nombre de módulo.
2. **`AuthorizationService::MODULES`** — agregar el módulo al catálogo.
3. **Tabla `permissions`** — insertar filas iniciales (vía migración o seed) para los roles existentes.

Ver §5.4 para el detalle.

### 3.7 Crear templates

Seguir `.claude/rules/DESIGN.md` para consistencia visual. Usar el layout `default.php` para vistas autenticadas y el elemento `pagination.php` en listados.

### 3.8 Agregar rutas (solo si hay acciones no estándar)

CRUD estándar funciona automáticamente vía `$builder->fallbacks()` en `config/routes.php`. Solo agregar rutas custom para acciones no convencionales, **siempre antes** de `$builder->fallbacks()`.

```php
$builder->connect(
    '/orders/cancel/{id}',
    ['controller' => 'Orders', 'action' => 'cancel'],
    ['id' => '\d+', 'pass' => ['id']]
);
```

---

## 4. Patrones y convenciones

### 4.1 Constantes sobre hardcoded

Nunca usar literales para valores de dominio. Siempre referenciar constantes.

```php
// Mal
if ($order->status === 'cancelado') { ... }

// Bien
if ($order->status === OrderConstants::STATUS_CANCELLED) { ... }
```

### 4.2 Inyección de dependencias en servicios

Servicios reciben dependencias por constructor con defaults opcionales. Permite testear con mocks sin complicar la instanciación en producción.

```php
public function __construct(
    ?OrderHistoryService $history = null,
    ?StockService $stock = null,
) {
    $this->history = $history ?? new OrderHistoryService();
    $this->stock = $stock ?? new StockService();
}
```

Reglas:
- Un servicio por dominio.
- Servicios acceden a tablas vía `TableRegistry::getTableLocator()->get('TableName')`.
- Servicios **no** acceden a `$this->request` ni `$this->response`.
- Dependencias entre servicios se inyectan; no se duplica lógica entre servicios.

### 4.3 Queries reutilizables en controllers

Cuando varias acciones comparten la base de una query, extraerla a un método privado `_buildXQuery()`.

```php
private function _buildOrdersQuery(array $conditions = []): SelectQuery
{
    $query = $this->Orders->find()->contain(['Customer', 'Delivery']);
    if ($conditions) {
        $query->where($conditions);
    }
    return $query;
}
```

### 4.4 Custom finders

Patrón estándar para listas formateadas, búsquedas autenticadas o filtros reutilizables. **No** sobrescribir `findList()`.

```php
public function findCodeList(SelectQuery $query): SelectQuery
{
    return $query->formatResults(fn($r) =>
        $r->combine('id', fn($row) => $row->code . ' - ' . $row->name)
    );
}

// Uso: $this->Products->find('codeList')->toArray();
```

### 4.5 Paginación

Fijada en **15 ítems por página** en toda la aplicación.

```php
public $paginate = ['limit' => 15, 'maxLimit' => 15];
```

Usar siempre `<?= $this->element('pagination') ?>` en los templates de listado.

### 4.6 Migraciones

- Clase base: `Migrations\BaseMigration` (NO `AbstractMigration`).
- Nombre: `YYYYMMDDHHMMSS_DescriptiveName.php`.
- FKs: tipos idénticos en columnas relacionadas (signed/unsigned).
- Proteger con `$this->hasTable()` para idempotencia.
- Nombres y comentarios en inglés, igual que en código.

### 4.7 Rutas

CRUD estándar lo cubre `$builder->fallbacks()`. Solo registrar rutas custom para acciones específicas, **siempre antes** del fallback. Las URLs siguen `kebab-case`; las acciones del controller, `camelCase`.

### 4.8 Manejo de errores

Cada capa maneja errores distinto. No mezclar.

- **Controllers:** Flash + redirect. Nunca exponen detalles internos.

  ```php
  if ($this->Orders->save($order)) {
      $this->Flash->success('Pedido guardado.');
      return $this->redirect(['action' => 'index']);
  }
  $this->Flash->error('No se pudo guardar el pedido.');
  ```

- **Services:** devuelven resultados estructurados (arrays con `success`/`errors`/`warnings`). Lanzan excepciones solo en situaciones realmente excepcionales (corrupción, dependencia caída).

  ```php
  return ['success' => false, 'errors' => ['El pedido fue cancelado y no puede editarse.']];
  ```

- **Tables:** validación CakePHP en `validationDefault()`. Errores quedan disponibles en `$entity->getErrors()` tras un `patchEntity()` o `save()` fallido.
- **Excepciones HTTP:** usar las built-in (`NotFoundException`, `ForbiddenException`). `$this->Orders->get($id)` ya tira `NotFoundException` si no existe.

Reglas:
- Servicios no devuelven HTTP responses ni tiran `NotFoundException`.
- Nunca tragarse una excepción en silencio: o se loguea, o se re-lanza.
- Nunca exponer stack traces ni errores SQL al usuario.

### 4.9 Transacciones

Usar `Connection::transactional()` para operaciones que modifican varias tablas o requieren atomicidad. Auto-commit al éxito, auto-rollback ante excepción.

```php
$conn = ConnectionManager::get('default');
return $conn->transactional(function () use ($order, $data) {
    // Guardar pedido + descontar stock + crear CxC: todo en una transacción.
});
```

Reglas:
- No envolver saves de una sola tabla — CakePHP los maneja internamente.
- No usar `begin()` / `commit()` / `rollback()` manuales salvo casos puntuales (savepoints anidados).
- Obtener la conexión vía `ConnectionManager::get('default')` o `$this->Table->getConnection()`.

### 4.10 Logging

Usar `Cake\Log\Log` con placeholders estructurados.

```php
Log::info('Pedido #{id} pasó de {from} a {to}', ['id' => 1, 'from' => 'recibido', 'to' => 'preparando']);
Log::warning('Producto sin receta vendido: {sku}', ['sku' => $product->sku]);
Log::error('Falló cierre diario {date}: {msg}', ['date' => $date, 'msg' => $e->getMessage()]);
```

Qué loguear:
- Saves/deletes que fallaron en services (con ID y contexto).
- Eventos de negocio significativos (transiciones de pedido, cierres diarios, descuentos de inventario fallidos).
- Llamadas a servicios externos (éxito y fracaso).

Qué **no** loguear:
- CRUD exitoso de entidades simples (es ruido).
- Datos de request que contengan passwords o información sensible.
- Cada query (eso es DebugKit en desarrollo).

### 4.11 Validación: Tablas vs Servicios

Dos niveles, propósitos distintos. No mezclarlos.

| Nivel | Dónde | Qué valida | Ejemplos |
|-------|-------|------------|----------|
| **Formato** | `Table::validationDefault()` | Presencia, tipo, longitud, formato | `notEmptyString`, `email`, `inList`, `decimal` |
| **Negocio** | Métodos de Service | Reglas de dominio, dependientes de estado, cross-entity | "No se puede editar un pedido cancelado", "La fecha de pago es requerida si la forma es Crédito" |

Reglas:
- La validación de tabla corre automáticamente en `patchEntity()` y `save()`.
- La validación de service la invoca explícitamente el controller antes de la operación.
- Nunca poner reglas de negocio en `validationDefault()`.
- Nunca poner checks de formato en services.
- Usar constantes en `inList()`: `->inList('status', OrderConstants::STATUSES)`.

### 4.12 Convenciones de nomenclatura

CakePHP impone la mayoría por convención. Esta tabla cubre el set completo del proyecto.

| Elemento | Convención | Ejemplo |
|----------|-----------|---------|
| Tabla DB | `snake_case`, plural | `order_items` |
| Columna DB | `snake_case` | `cancelled_at` |
| FK | `singular_table_id` | `customer_id`, `role_id` |
| Entity | `PascalCase`, singular | `OrderItem` |
| Table | `PascalCase`, plural + `Table` | `OrderItemsTable` |
| Controller | `PascalCase`, plural + `Controller` | `OrderItemsController` |
| Service | `PascalCase` + `Service` | `OrderPipelineService` |
| Constants | `PascalCase` + `Constants` | `OrderConstants` |
| Constante | `UPPER_SNAKE_CASE` | `STATUS_CANCELLED` |
| Carpeta de templates | `PascalCase`, igual al controller | `templates/OrderItems/` |
| Archivo de template | `snake_case.php` | `index.php`, `add.php` |
| Migración | `YYYYMMDDHHMMSS_PascalCase.php` | `20260502120000_CreateOrders.php` |
| URL de ruta | `kebab-case` | `/orders/cancel/{id}` |
| Acción de controller | `camelCase` | `cancelOrder()` |
| Método privado de controller | `_camelCase` | `_buildOrdersQuery()` |
| Virtual field | `_get{CamelCase}()` | `_getFullName()` |
| Custom finder | `find{CamelCase}` | `findActive()` |
| CSS class custom | `.dr-kebab-case` | `.dr-stat-card` |

Convenciones críticas:
- CakePHP auto-inflexiona: controller `OrderItems` → tabla `order_items` → entity `OrderItem`.
- Las FK **deben** seguir el patrón `singular_table_id` para que las asociaciones funcionen automáticamente.
- La carpeta de templates debe coincidir **exactamente** con el nombre del controller.

### 4.13 Familias de servicios (patrón opt-in)

Dominios complejos pueden necesitar varios servicios especializados. Los nombres son consistentes para que el patrón se reconozca a primera vista. **No todos los dominios necesitan los cuatro** — crear solo los que aplican.

| Tipo | Propósito | Cuándo crearlo |
|------|-----------|----------------|
| `{Domain}Service` | Operaciones core CRUD/negocio | Casi siempre |
| `{Domain}PipelineService` | State machine: transiciones, validación, campos editables por estado | Si el dominio tiene estados con reglas de transición |
| `{Domain}FilterService` | Lógica de filtros aplicada a queries de listado | Si el listado tiene búsqueda multi-campo |
| `{Domain}HistoryService` | Auditoría campo a campo (old vs new) | Si el dominio requiere trazabilidad |

Estructura típica de un `PipelineService`:

```php
class OrderPipelineService
{
    public const STATUS_LABELS = [ /* status => label */ ];
    public const TRANSITIONS = [ /* from => to */ ];
    private const TRANSITION_REQUIREMENTS = [ /* status => [ field, value, label ] */ ];

    public function validateTransitionRequirements(Entity $e, string $from): array { /* ... */ }
    public function saveAndAdvance(Entity $e, array $data, string $role): array { /* ... */ }
}
```

Para un `HistoryService`, normalizar valores antes de comparar con `!==`:

- `DateTimeInterface` → string `Y-m-d`.
- Booleanos → cast `(bool)`.
- Strings vacíos → `null`.

Esto evita falsos positivos en la auditoría por mismatch de tipo.

---

## 5. Sistema de permisos (RBAC)

### 5.1 Cómo funciona

Toda acción de controller se chequea automáticamente contra la tabla `permissions`. El flujo:

```
AppController::beforeFilter()
    │
    ▼
_enforcePermission(user)
    │
    ▼
$controllerModuleMap[ControllerName] → nombre del módulo
_actionToPermission(action) → acción de permiso (view/add/edit/delete)
    │
    ▼
AuthorizationService::isAllowed(roleId, roleName, module, permAction)
    ├── ¿Es Administrador? → true (bypass)
    └── Otro rol? → consulta tabla `permissions`
```

`isAllowed()` mapea acciones de controller a columnas de permiso:

```php
return match ($action) {
    'view', 'index' => (bool)$perm['can_view'],
    'add'           => (bool)$perm['can_create'],
    'edit'          => (bool)$perm['can_edit'],
    'delete'        => (bool)$perm['can_delete'],
    default         => false,
};
```

### 5.2 El usuario Administrador

Davi Rapid tiene **un único usuario fijo "Administrador"** con tres reglas estructurales (ver `davirapid.md` §3 y §10):

- **Acceso total:** bypassea toda comprobación de la matriz de permisos. Su rol no se chequea contra la tabla `permissions`.
- **No se puede eliminar:** ni desde la UI ni vía endpoint. Las acciones de delete del módulo Usuarios deben rechazar este usuario explícitamente.
- **Único que gestiona Roles:** la sección "Roles" del módulo Administración solo es accesible si el usuario es el Administrador, sin importar la matriz.

Estas tres reglas son invariantes — se aplican en `AuthorizationService` y en los controllers de Usuarios y Roles.

### 5.3 Filtrado por usuario-repartidor

Cuando un usuario del sistema está vinculado a un registro de `Repartidor` (FK opcional `users.delivery_id` o equivalente), el sistema **restringe automáticamente** lo que ve en módulos operacionales:

- Solo ve los **pedidos que tiene asignados**, sin importar lo que diga su rol.
- Sus métricas (en dashboards o reportes) son las suyas, no las globales.
- La regla aplica antes de la matriz RBAC: aunque el rol diga "Ver todos los pedidos", el filtro por repartidor lo recorta.

Implementación recomendada: filtro centralizado en `AppController::beforeFilter()` o en un método compartido (`_scopeToCurrentDelivery()`) que se aplica a las queries de pedidos cuando `currentUser->delivery_id` no es nulo.

### 5.4 Cómo agregar un módulo a permisos

Tres lugares se actualizan al crear un módulo nuevo:

**1. `AppController::$controllerModuleMap`** — mapea el controller al módulo:

```php
protected array $controllerModuleMap = [
    'Orders'   => 'orders',
    'Products' => 'products',
    // ...
];
```

**2. `AuthorizationService::MODULES`** — catálogo de módulos:

```php
public const MODULES = [
    'orders'   => 'Pedidos',
    'products' => 'Productos',
    // ...
];
```

**3. Insertar permisos en la DB** (vía migración o seed). Definir el set inicial para cada rol existente:

```sql
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete)
VALUES (5, 'orders', 1, 1, 0, 0);  -- Cajero: ver + crear
```

---

## 6. Frontend y sistema de diseño

Las reglas visuales completas (paleta, tipografía, espaciado, componentes, do's & don'ts) viven en **`.claude/rules/DESIGN.md`**. Esta sección cubre solo lo operativo.

- **Una sola fuente:** Inter (todo el sistema). Máximo tres tamaños tipográficos por vista.
- **Layout fijo:** sidebar 248px + topbar 64px, contenido fluido con max-width 1440px.
- **Escala de espaciado:** múltiplos de 8px (con medio paso de 4px).
- **Altura de controles:** botones, inputs y selects en la misma fila van todos a 40px.
- **Estados de pedido** usan la familia dedicada `status-pending` / `status-preparing` / `status-on-route` / `status-delivered` / `status-cancelled`, no los `badge-*` genéricos.
- **`primary` (#E63027)** se usa **una sola vez por pantalla**, en la acción más importante. Las acciones destructivas usan `button-danger`, no `primary`.

Cuando agregues una vista nueva, leé `DESIGN.md` antes. La consistencia visual es lo que sostiene la sensación de calidad del panel.

---

## 7. Seguridad

### 7.1 Autenticación

- Plugin: `cakephp/authentication ^3.0`.
- Authenticators: `Session` + `Form`.
- Identifier: `Password` con hash bcrypt.
- Custom finder `UsersTable::findAuth()` que filtra `active = true` y precarga `Roles`.
- Requests no autenticados redirigen a `/login`.

**Lockout (regla de negocio, ver `davirapid.md` §10):** tras **5 intentos fallidos**, el usuario queda bloqueado por **15 minutos**. El contador se resetea con un login exitoso. Implementación recomendada: dos columnas en `users` (`failed_login_count`, `locked_until`) más un `LoginThrottleService` que las gestione antes de delegar al authenticator.

### 7.2 Autorización

RBAC automático vía `AuthorizationService` + tabla `permissions`. Se enforcea en `AppController::beforeFilter()` en cada request. El usuario Administrador bypassea toda la matriz. Ver §5 para el flujo completo.

### 7.3 CSRF

`CsrfProtectionMiddleware` está habilitado globalmente con `httponly: true`. Toda submission no-GET necesita el token CakePHP — `$this->Form->create(...)` lo inyecta automáticamente. Para AJAX, exponer el token vía meta tag y pasarlo en el header `X-CSRF-Token`.

### 7.4 HostHeaderMiddleware

Obligatorio en producción. Cuando `debug=false`:

- La app se niega a arrancar si `App.fullBaseUrl` no está seteado (env var `APP_FULL_BASE_URL` o `config/app.php`).
- Rechaza requests cuyo header `Host` no matchee `App.fullBaseUrl`.

No removerlo del middleware queue en `src/Application.php`. Si un deploy falla con `InternalErrorException: App.fullBaseUrl is not configured`, la solución es setear la env var, no borrar el middleware.

### 7.5 Subida de archivos

Si un módulo necesita upload (por ahora, ningún módulo del spec lo requiere explícitamente — los tickets de pedido se imprimen, no se almacenan), seguir estas convenciones:

- Carpeta destino: `webroot/uploads/{entity}/{id}/`.
- Filename con prefijo único: `{entity}_` + `uniqid()` + extensión original (nunca el nombre que envió el usuario).
- Encapsular toda la lógica de upload en un `{Domain}DocumentService` (validación de MIME, validación de tamaño, mover archivo, registrar en DB).
- Validar MIME types en allowlist explícito; tamaño máximo razonable (referencia: 10 MB).

---

## 8. Idioma y nomenclatura

- **Documentación, spec y copy de UI:** español.
- **Identificadores de código** (clases, métodos, propiedades, columnas de DB, rutas, archivos): inglés.
- **Constantes que modelan terminología del negocio:** el valor literal puede ir en español (`'cancelado'`, `'preparando'`, `'fiado'`) si así aparece en `davirapid.md` y se usa en la UI; el nombre de la constante PHP queda en inglés (`OrderConstants::STATUS_CANCELLED = 'cancelado'`).
- **Mensajes Flash, labels de formulario y errores de validación:** español.
- **Logs, comentarios técnicos y nombres de migraciones:** inglés.
