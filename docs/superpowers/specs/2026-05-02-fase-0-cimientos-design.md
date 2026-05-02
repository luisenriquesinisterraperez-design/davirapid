# Fase 0 — Cimientos · Diseño

**Fecha:** 2026-05-02
**Estado:** Borrador para revisión
**Alcance:** Cimientos del proyecto Davi Rapid Admin — cleanup del skeleton, sistema de diseño, layout, autenticación, RBAC, módulos Roles y Usuarios.

---

## 0. Contexto y decisiones de alcance

`davirapid.md` describe un sistema de 14 módulos funcionales con dependencias profundas. Ese alcance es demasiado grande para un único spec. Se descompuso en 5 fases bottom-up:

- **Fase 0 — Cimientos** (este spec): design system, layout, auth, RBAC, Roles, Usuarios.
- **Fase 1 — Datos de referencia:** Productos, Clientes, Repartidores, Ingredientes, Recetas.
- **Fase 2 — Operación:** Pedidos (state machine + impresión), Auditoría, Ajustes de Inventario.
- **Fase 3 — Finanzas:** Gastos, CxC, Abonos, Cierre Diario.
- **Fase 4 — Análisis:** Dashboard.

Cada fase es un spec → plan → implementación independiente.

### Decisiones tomadas durante el brainstorming

| # | Decisión | Valor |
|---|---|---|
| 1 | Alcance del sistema de diseño en Fase 0 | Mínimo viable — solo lo que las pantallas reales de Fase 0 necesitan |
| 2 | Distribución de Bootstrap | Vendored en `webroot/` + Bootstrap Icons + Inter (offline-friendly) |
| 3 | UI de Roles | Matriz funcional desde el día 0, alimentada por el catálogo `MODULES` |
| 4.1 | Base de datos dev | MariaDB ya configurada en `.env` (`easypanel.stokmaster.com.co`) |
| 4.2 | Alcance de auth | Login + logout + lockout (5 fallos / 15 min). Sin recuperación, sin signup |
| 4.3 | `users.delivery_id` en Fase 0 | NO se incluye; se agrega vía migración en Fase 1 |
| 5 | Tests automatizados | **Sin tests** — el proyecto se construye sin PHPUnit / TDD |
| Seed Admin | Credenciales iniciales | `username=admin`, `name=Administrador`, `password=ca1ced0.DEV`, `role_id=1` |

---

## 1. Cleanup del skeleton CakePHP

### Templates a borrar
- `templates/Pages/home.php` (página de bienvenida del skeleton — se reemplaza por placeholder de dashboard)
- `templates/cell/` (carpeta vacía del skeleton)
- `templates/email/html/`, `templates/email/text/`, `templates/layout/email/` (no hay módulo de email en Fase 0)

### Templates a reemplazar (no borrar, sobreescribir)
- `templates/layout/default.php` → layout autenticado real (sidebar + topbar)

### Templates a conservar
- `templates/Error/error400.php`, `error500.php` — re-styleados con Bootstrap, mismos nombres
- `templates/element/flash/*` — Flash messages
- `templates/layout/ajax.php`, `error.php` — uso interno del framework

### Webroot a borrar
- `webroot/css/cake.css`, `home.css`, `milligram.min.css`, `normalize.min.css`, `fonts.css`
- `webroot/img/cake.icon.png`, `cake-logo.png`, `cake.logo.svg`, `cake.power.gif`

### Controllers
- `src/Controller/PagesController.php` → **reemplazar** por versión mínima con solo `home()` (placeholder dashboard que redirige a login si no hay sesión).

### Tests a borrar
- `tests/TestCase/Controller/PagesControllerTest.php`
- `tests/TestCase/ApplicationTest.php`
- `tests/schema.sql`
- Carpeta `tests/` y `phpunit.xml.dist` se conservan vacíos por si se reactivan en el futuro.

### Routes a modificar (`config/routes.php`)
- Reemplazar `$builder->connect('/', ['controller' => 'Pages', 'action' => 'display', 'home']);` por la conexión final descrita en §10.3.
- Quitar conexión a `/pages/*`.

### Archivos a NO tocar
`vendor/`, `bin/`, `config/app.php`, `config/bootstrap*.php`, `config/paths.php`, `src/Application.php` (se modifica), `src/Controller/AppController.php` (se expande), `src/Controller/ErrorController.php`, `src/Middleware/HostHeaderMiddleware.php`.

---

## 2. Sistema de diseño + Bootstrap

### Assets vendored

```
webroot/
├── css/
│   ├── vendor/
│   │   ├── bootstrap.min.css           # Bootstrap 5.3.x
│   │   └── bootstrap-icons.min.css     # Bootstrap Icons 1.11.x
│   └── davirapid.css                   # Theme + tokens + componentes propios
├── js/
│   └── vendor/
│       └── bootstrap.bundle.min.js     # Bootstrap + Popper
├── fonts/
│   ├── inter-regular.woff2
│   ├── inter-medium.woff2
│   ├── inter-semibold.woff2
│   ├── inter-bold.woff2
│   └── bootstrap-icons.woff2
└── img/                                # logos cuando estén disponibles
```

### `webroot/css/davirapid.css` — estructura

Orden importa: Bootstrap se carga primero, después `davirapid.css` re-escribe variables y agrega overrides. Como Bootstrap 5.3 expone todo vía CSS custom properties, no necesitamos Sass ni recompilar.

```css
/* 1. @font-face de Inter (font-display: swap) */

/* 2. Tokens — sobreescribiendo Bootstrap + propios */
:root {
    --bs-primary:     #E63027;   --bs-primary-rgb: 230,48,39;
    --bs-secondary:   #F26B1F;   --bs-secondary-rgb: 242,107,31;
    --bs-success:     #22A06B;
    --bs-warning:     #F2A516;
    --bs-danger:      #D32F2F;
    --bs-info:        #2A6FDB;
    --bs-body-bg:     #FAFAFA;
    --bs-body-color:  #1F1F1F;
    --bs-border-color:#E5E5E5;
    --bs-border-radius:    .5rem;   /* 8px md */
    --bs-border-radius-sm: .25rem;  /* 4px sm */
    --bs-border-radius-lg: .75rem;  /* 12px lg */
    --bs-border-radius-xl: 1rem;    /* 16px xl */
    --bs-font-sans-serif: "Inter", system-ui, -apple-system, sans-serif;
    --bs-body-font-size: .9375rem;  /* 15px body-md */

    /* Tokens propios fuera del namespace Bootstrap */
    --dr-primary-soft:   #FDE7E5;
    --dr-secondary-soft: #FDEBDD;
    --dr-success-soft:   #DDF2E8;
    --dr-warning-soft:   #FCEDC9;
    --dr-danger-soft:    #FADBDB;
    --dr-info-soft:      #D9E5F8;
    --dr-tertiary:       #FFB627;
    --dr-tertiary-soft:  #FFF1CE;
    --dr-surface-alt:    #F4F4F4;
    --dr-overlay:        rgba(0,0,0,.55);
    --dr-shadow-2: 0 4px 12px rgba(0,0,0,.08);
    --dr-shadow-3: 0 12px 32px rgba(0,0,0,.12);
    --dr-control-h: 40px;
    --dr-sidebar-w: 248px;
    --dr-topbar-h: 64px;
    --dr-content-max: 1440px;
}

/* 3. Overrides de componentes Bootstrap:
      .btn (h=40px), .form-control (h=40px), .table (header surface-alt, fila 56px),
      .alert, .nav-link, .breadcrumb, .pagination, focus-ring rojo */

/* 4. Componentes propios .dr-*:
      .dr-app-shell (grid sidebar+content),
      .dr-sidebar, .dr-sidebar-item, .dr-sidebar-item.active (con --dr-primary-soft),
      .dr-topbar,
      .dr-login-shell,
      .dr-permission-matrix */
```

### Carga en el layout

```html
<link rel="stylesheet" href="/css/vendor/bootstrap.min.css">
<link rel="stylesheet" href="/css/vendor/bootstrap-icons.min.css">
<link rel="stylesheet" href="/css/davirapid.css">
<script src="/js/vendor/bootstrap.bundle.min.js" defer></script>
```

### Componentes JS de Bootstrap utilizados

Dropdowns (menú de usuario), modales (confirmar eliminar), toasts/alerts dismissibles, collapse del sidebar en viewport chico, tooltips de iconos. **No** se escribe JS propio para estos.

### Componentes propios (CSS custom)

`.dr-app-shell`, `.dr-sidebar`, `.dr-sidebar-item`, `.dr-topbar`, `.dr-login-shell`, `.dr-permission-matrix`.

### Componentes deliberadamente fuera de Fase 0

Se construyen cuando aparezca el primer consumidor real:
- `.dr-stat-card` → Fase 4 (Dashboard)
- `.dr-status-pending`, `.dr-status-preparing`, `.dr-status-on-route`, `.dr-status-delivered`, `.dr-status-cancelled` → Fase 2 (Pedidos)
- Tabs específicos de pedido, KPI cards → Fases respectivas

---

## 3. Layout autenticado + login

### `templates/layout/default.php`

Estructura:

```
┌──────────────────────────────────────────────────────────┐
│  TOPBAR (64px) — logo · breadcrumbs · user dropdown      │
├────────────┬─────────────────────────────────────────────┤
│  SIDEBAR   │  CONTENT (max-width 1440px, padding 32px)   │
│  (248px)   │  - Flash messages                            │
│            │  - <?= $this->fetch('content') ?>            │
└────────────┴─────────────────────────────────────────────┘
```

### Sidebar items (Fase 0)

`src/View/Helper/SidebarHelper.php` itera sobre:

```php
[
    ['module' => 'users', 'label' => 'Usuarios', 'icon' => 'bi-people', 'url' => ['controller' => 'Users', 'action' => 'index']],
    ['module' => 'roles', 'label' => 'Roles',    'icon' => 'bi-shield', 'url' => ['controller' => 'Roles', 'action' => 'index']],
]
```

- Cada item se renderiza solo si `AuthorizationService::isAllowed($user, $module, 'view')` es true.
- El item activo se calcula comparando `$this->request->getParam('controller')`.
- Roles solo aparece para el Administrador (regla del spec §3 / `ARQUITECTURE.md` §5.2).

### Topbar

- Izquierda: logo Davi Rapid (placeholder textual hasta tener SVG).
- Centro: breadcrumb (variable `$breadcrumbs` que cada vista setea).
- Derecha: dropdown del usuario actual (nombre + rol + "Cerrar sesión") con Bootstrap Dropdown JS.

### Variables expuestas por `AppController::beforeFilter`

```php
$this->set('currentUser',     $userArray);          // identity como array
$this->set('currentRoleName', $roleName);           // "Administrador" o nombre libre
$this->set('isAdministrator', $isAdministrator);    // bool
$this->set('userPermissions', $matrix);             // matriz módulo => acciones
$this->set('sidebarCounters', []);                  // hook para fases siguientes
$this->set('breadcrumbs',     []);                  // default vacío
```

### `templates/layout/login.php`

- Sin sidebar ni topbar. Fondo `--bs-body-bg`.
- Card centrado, max-width 400px, sombra `--dr-shadow-2`.
- Logo, título "Iniciar sesión", form usuario + contraseña, botón `btn-primary` "Entrar".
- Mensajes de error (intentos restantes / cuenta bloqueada).
- Sin links a "registrarse" ni "olvidé mi contraseña".

### Convenciones

- `AppController::initialize()` setea layout `default` por defecto. `UsersController::login` cambia a `login`.
- Flash messages al tope del contenido con `.alert.alert-{type}.alert-dismissible`.
- Iconos: clase `bi bi-{name}` de Bootstrap Icons.

---

## 4. Esquema de base de datos

Tres migraciones en `config/Migrations/`, en orden cronológico.

### 4.1 `20260502120000_CreateRoles.php`

```
roles
├── id          int unsigned PK auto_increment
├── name        varchar(60)  NOT NULL UNIQUE
├── is_admin    tinyint(1)   NOT NULL default 0
├── created     datetime     NULL
└── modified    datetime     NULL
```

`is_admin` es la **bandera estructural** del rol Administrador. Solo una fila puede tener `is_admin=1`. La lógica del bypass chequea esta bandera, no el nombre ni el id.

### 4.2 `20260502120100_CreatePermissions.php`

```
permissions
├── id           int unsigned PK auto_increment
├── role_id      int unsigned NOT NULL  FK → roles.id  ON DELETE CASCADE
├── module       varchar(40)  NOT NULL              # 'users', 'roles', ...
├── can_view     tinyint(1)   NOT NULL default 0
├── can_create   tinyint(1)   NOT NULL default 0
├── can_edit     tinyint(1)   NOT NULL default 0
├── can_delete   tinyint(1)   NOT NULL default 0
├── created      datetime     NULL
└── modified     datetime     NULL

UNIQUE KEY uniq_role_module (role_id, module)
```

`module` es string libre, no FK — el catálogo de módulos vive en código (`AuthorizationService::MODULES`).

### 4.3 `20260502120200_CreateUsers.php`

```
users
├── id                    int unsigned PK auto_increment
├── username              varchar(60)  NOT NULL UNIQUE
├── name                  varchar(120) NOT NULL
├── password              varchar(255) NOT NULL                # bcrypt
├── role_id               int unsigned NOT NULL  FK → roles.id  ON DELETE RESTRICT
├── active                tinyint(1)   NOT NULL default 1
├── failed_login_count    int unsigned NOT NULL default 0
├── locked_until          datetime     NULL
├── last_login_at         datetime     NULL
├── created               datetime     NULL
└── modified              datetime     NULL

INDEX idx_users_username     (username)
INDEX idx_users_locked_until (locked_until)
```

- `role_id` NOT NULL: el spec §18 dice "un usuario sin rol no puede iniciar sesión".
- `ON DELETE RESTRICT` en `role_id`: no se puede borrar un rol con usuarios asignados.
- `delivery_id` NO existe en Fase 0 — Fase 1 agregará columna + FK.

### Reglas técnicas

- Clase base: `Migrations\BaseMigration`.
- FKs `int unsigned` matcheando exactamente la PK destino.
- `Timestamp` behavior maneja `created`/`modified` automáticamente.
- Charset/collation: `utf8mb4` / `utf8mb4_unicode_ci` explícito por tabla.
- Idempotencia: `if (!$this->hasTable(...))`.

---

## 5. Autenticación

### 5.1 Configuración del plugin

`Application` implementa `AuthenticationServiceProviderInterface` y expone:

```php
$service = new AuthenticationService([
    'unauthenticatedRedirect' => Router::url(['controller' => 'Users', 'action' => 'login']),
    'queryParam' => 'redirect',
]);

$service->loadIdentifier('Authentication.Password', [
    'fields'   => ['username' => 'username', 'password' => 'password'],
    'resolver' => [
        'className' => 'Authentication.Orm',
        'userModel' => 'Users',
        'finder'    => 'auth',
    ],
    'passwordHasher' => ['className' => 'Authentication.Default'],
]);

$service->loadAuthenticator('Authentication.Session');
$service->loadAuthenticator('Authentication.Form', [
    'fields'   => ['username' => 'username', 'password' => 'password'],
    'loginUrl' => '/login',
]);
```

`AuthenticationMiddleware` se agrega al middleware queue después de Routing y antes de BodyParser.

### 5.2 `UsersTable::findAuth()`

```php
public function findAuth(SelectQuery $query): SelectQuery
{
    return $query->where(['Users.active' => true])->contain(['Roles']);
}
```

Si el usuario es `active=false`, el identifier no lo encuentra y el login falla con el mismo mensaje genérico que un password incorrecto — no exponemos la diferencia entre "usuario inactivo" y "credenciales malas".

### 5.3 `LoginThrottleService`

Vive en `src/Service/LoginThrottleService.php`. Constantes:

```php
public const MAX_ATTEMPTS = 5;
public const LOCKOUT_MIN  = 15;
```

Tres métodos:

- `checkLockout(string $username): ?array` — antes de validar credenciales. Devuelve `null` si está habilitado, o `['locked' => true, 'minutes_left' => N]` si está bloqueado. Si `locked_until` ya cumplió, resetea contadores y deja pasar.
- `recordSuccess(int $userId): void` — resetea `failed=0`, `locked=null`, `last_login_at=now()`.
- `recordFailure(string $username): array` — incrementa contador; al llegar a 5 setea `locked_until = now()+15min`. Para usernames inexistentes, NO toca DB y devuelve `['attempts_left' => null]`.

**Ataque a usernames inexistentes:** no se crean filas falsas ni se bloquea nada; mismo mensaje genérico para no filtrar la diferencia.

**Reset implícito:** cuando `locked_until` ya pasó, el siguiente intento de login resetea contadores on-demand. No hay job nocturno.

### 5.4 `UsersController::login()` y `::logout()`

```php
public function login()
{
    $this->viewBuilder()->setLayout('login');
    $this->Authentication->allowUnauthenticated(['login']);

    $username = (string)$this->request->getData('username', '');

    if ($this->request->is('post')) {
        $lockInfo = $this->throttle->checkLockout($username);
        if ($lockInfo !== null) {
            $this->Flash->error("Cuenta bloqueada. Intentá de nuevo en {$lockInfo['minutes_left']} minutos.");
            return null;
        }

        $result = $this->Authentication->getResult();
        if ($result?->isValid()) {
            $this->throttle->recordSuccess($result->getData()->id);
            return $this->redirect(['controller' => 'Pages', 'action' => 'home']);
        }

        $info = $this->throttle->recordFailure($username);
        $msg = $info['attempts_left'] !== null && $info['attempts_left'] > 0
            ? "Credenciales inválidas. Te quedan {$info['attempts_left']} intentos."
            : 'Credenciales inválidas.';
        $this->Flash->error($msg);
    }
}

public function logout()
{
    $this->Authentication->logout();
    return $this->redirect(['action' => 'login']);
}
```

`AppController::beforeFilter` permite `login` y `logout` sin chequear permisos.

### 5.5 Logging

- `Log::info('User {username} logged in', [...])` en `recordSuccess`.
- `Log::warning('Failed login for {username} ({attempts}/5)', [...])` en `recordFailure`.
- `Log::warning('Account locked for {username} until {until}', [...])` al 5to fallo.
- `Log::info('Login attempt for unknown username {username}', [...])` para username inexistente.

---

## 6. RBAC: AuthorizationService + AppController

### 6.1 `src/Service/AuthorizationService.php`

```php
final class AuthorizationService
{
    public const MODULES = [
        'roles' => 'Roles',
        'users' => 'Usuarios',
    ];

    public const ACTIONS = ['view', 'create', 'edit', 'delete'];

    public function isAllowed(array $user, string $module, string $action): bool
    {
        // 1. Bypass del Administrador (rol con is_admin=1)
        if (!empty($user['role']['is_admin'])) {
            return true;
        }
        // 2. Módulo desconocido = denegado
        if (!array_key_exists($module, self::MODULES)) {
            return false;
        }
        // 3. Roles solo lo gestiona el Administrador (defensa más allá del bypass)
        if ($module === 'roles') {
            return false;
        }
        // 4. Lookup en DB con cache por proceso (clave role_id)
        $perm = $this->loadPermissionsFor((int)$user['role_id'])[$module] ?? null;
        if ($perm === null) {
            return false;
        }
        return match ($action) {
            'view'   => (bool)$perm['can_view'],
            'create' => (bool)$perm['can_create'],
            'edit'   => (bool)$perm['can_edit'],
            'delete' => (bool)$perm['can_delete'],
            default  => false,
        };
    }

    public function matrixFor(array $user): array { /* itera MODULES + ACTIONS */ }
}
```

**Invariantes:**
- El Administrador siempre pasa, aunque `permissions` esté vacío.
- Roles solo accesible al Administrador, sin importar la fila en `permissions`.
- Módulos sin entrada en `MODULES` se rechazan.

### 6.2 `AppController` — wiring

```php
class AppController extends Controller
{
    protected array $controllerModuleMap = [
        'Roles' => 'roles',
        'Users' => 'users',
    ];

    protected array $publicActions = [
        'Users' => ['login', 'logout'],
    ];

    public AuthorizationService $authorization;
    public LoginThrottleService $throttle;

    public function initialize(): void
    {
        parent::initialize();
        $this->loadComponent('Flash');
        $this->loadComponent('Authentication.Authentication');
        $this->authorization = new AuthorizationService();
        $this->throttle      = new LoginThrottleService();
    }

    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);
        $controller = $this->request->getParam('controller');
        $action     = $this->request->getParam('action');

        if (in_array($action, $this->publicActions[$controller] ?? [], true)) {
            return null;
        }

        $identity = $this->Authentication->getIdentity();
        if ($identity === null) {
            return $this->redirect(['controller' => 'Users', 'action' => 'login']);
        }

        $userArray = $identity->getOriginalData()->toArray();

        $this->set('currentUser',     $userArray);
        $this->set('currentRoleName', $userArray['role']['name']  ?? '—');
        $this->set('isAdministrator', !empty($userArray['role']['is_admin']));
        $this->set('userPermissions', $this->authorization->matrixFor($userArray));
        $this->set('sidebarCounters', []);
        $this->set('breadcrumbs',     []);

        $module = $this->controllerModuleMap[$controller] ?? null;
        if ($module === null) {
            return null;
        }

        $permAction = $this->_actionToPermission($action);
        if (!$this->authorization->isAllowed($userArray, $module, $permAction)) {
            throw new ForbiddenException('No tenés permiso para realizar esta acción.');
        }
        return null;
    }

    protected function _actionToPermission(string $action): string
    {
        return match ($action) {
            'index', 'view' => 'view',
            'add'           => 'create',
            'edit'          => 'edit',
            'delete'        => 'delete',
            default         => 'view',
        };
    }
}
```

Acciones custom (ej. `unlock`, `cancel`) sobreescriben `_actionToPermission` en el controller específico.

### 6.3 Cómo se registra un módulo nuevo

Tres lugares, todos obligatorios:

1. `AppController::$controllerModuleMap` — agregar `'Products' => 'products'`.
2. `AuthorizationService::MODULES` — agregar `'products' => 'Productos'`.
3. Migración + seed insertando filas iniciales en `permissions` para los roles existentes.

### 6.4 Pantalla 403

`ForbiddenException` se renderiza con `templates/Error/error400.php` re-styleada (alert grande, icono `bi-shield-lock`, link "Volver al inicio").

---

## 7. Módulo Roles

### 7.1 Estructura

```
src/
├── Controller/RolesController.php
├── Model/
│   ├── Entity/Role.php
│   ├── Entity/Permission.php
│   ├── Table/RolesTable.php
│   └── Table/PermissionsTable.php
└── Service/
    └── RolePermissionService.php

templates/Roles/
├── index.php
├── add.php
├── edit.php
└── view.php
```

### 7.2 Entities

**`Role`:**
```php
protected array $_accessible = [
    'name' => true,
    'permissions' => true,    // saveAssociated cascade
    // is_admin NO accesible — solo se setea en seed
];

public function isAdministrator(): bool { return (bool)$this->is_admin; }
```

**`Permission`:**
```php
protected array $_accessible = [
    'role_id' => true, 'module' => true,
    'can_view' => true, 'can_create' => true, 'can_edit' => true, 'can_delete' => true,
];
```

### 7.3 Tables

**`RolesTable`:**
- `hasMany('Permissions', ['dependent' => true, 'cascadeCallbacks' => true])`.
- `Timestamp` behavior.
- Validación: `name` requerido, único, 2–60 chars.
- Reglas: unicidad de `name`.
- Custom finder `findAssignable(SelectQuery $q): SelectQuery` — `Roles.is_admin = false` (para selects de Usuarios).

**`PermissionsTable`:**
- `belongsTo('Roles')`.
- `Timestamp` behavior.
- Validación: `module` debe estar en `array_keys(AuthorizationService::MODULES)`.
- Reglas: única `(role_id, module)`.

### 7.4 `RolePermissionService::syncMatrix(int $roleId, array $matrix): array`

Encapsula la lógica de sincronizar la matriz del form con las filas de `permissions`. Operación transaccional:

1. Para cada módulo del catálogo:
   - Si la fila `(role_id, module)` existe: UPDATE flags.
   - Si no existe y al menos un flag es true: INSERT.
   - Si no existe y todos son false: skip (no se crean filas vacías).
2. Módulos que llegan en el form pero no están en `MODULES`: ignorados (defensa contra tampering).
3. Jerarquía implícita "sin Ver no hay nada más": si llega `can_create=1` con `can_view=0`, se corrige a `can_view=1` antes de guardar.

### 7.5 `RolesController`

`index`, `view`, `add`, `edit`, `delete` (CRUD + matriz).

- `add` y `edit`: separan `permissions` del payload, hacen `save` del rol, llaman `syncMatrix`.
- `edit` rechaza el rol Administrador con `ForbiddenException`.
- `delete` rechaza el rol Administrador. Captura `PersistenceFailedException` (ON DELETE RESTRICT cuando hay usuarios asignados) y la traduce a `Flash::error`.
- Pagination: `['limit' => 15, 'maxLimit' => 15]`.
- Helpers privados `_buildMatrixFromRole(Role $role)` y `_emptyMatrix()` para alimentar las views.

### 7.6 Matriz visual en `edit.php` / `add.php`

```
┌───────────────────────────────────────────────────────┐
│  Editar rol: [Cajero          ]                       │
│                                                       │
│  Permisos por módulo                                  │
│  ┌───────────┬──────┬────────┬────────┬─────────┐     │
│  │ Módulo    │ Ver  │ Crear  │ Editar │ Eliminar│     │
│  ├───────────┼──────┼────────┼────────┼─────────┤     │
│  │ Roles     │ ☐    │ ☐      │ ☐      │ ☐       │ disabled (tooltip)
│  │ Usuarios  │ ☑    │ ☑      │ ☐      │ ☐       │     │
│  └───────────┴──────┴────────┴────────┴─────────┘     │
│                                                       │
│  [ Guardar ] [ Cancelar ]                             │
└───────────────────────────────────────────────────────┘
```

- Tabla `<table class="dr-permission-matrix">` (clase propia).
- El módulo `roles` aparece con todos los checkboxes **disabled** y tooltip "Solo el Administrador puede gestionar Roles".
- JS inline (~20 líneas, sin framework): marcar Crear/Editar/Eliminar marca Ver; desmarcar Ver desmarca todos los demás.
- Inputs con `name="permissions[users][can_view]"` etc. para `$this->request->getData('permissions')` como matriz.

### 7.7 `index.php`

Tabla con columnas: **Nombre**, **Permisos** (badge "X de Y módulos"), **Usuarios** (count), **Acciones** (`Ver`, `Editar`, `Eliminar`). El rol con `is_admin=1` muestra badge "Administrador" en `bg-primary-soft` y los botones Editar/Eliminar deshabilitados.

### 7.8 Invariantes

- El rol Administrador no se puede editar ni eliminar (controller).
- Roles solo lo manejan usuarios con `is_admin=1` (`AuthorizationService`).
- La matriz guardada siempre cumple "sin Ver no hay nada más" (service).
- El catálogo de módulos en la UI viene de `MODULES`, no de literales en el template.

---

## 8. Módulo Usuarios

### 8.1 Estructura

```
src/
├── Controller/UsersController.php          # login/logout + index/add/edit/view/delete/unlock
├── Model/
│   ├── Entity/User.php
│   └── Table/UsersTable.php
└── Service/
    └── UserService.php

templates/Users/
├── login.php  index.php  add.php  edit.php  view.php
```

### 8.2 Entity `User`

```php
class User extends Entity implements IdentityInterface
{
    protected array $_accessible = [
        'username' => true, 'name' => true, 'password' => true,
        'role_id' => true, 'active' => true,
        // failed_login_count, locked_until, last_login_at: NO accesibles
    ];
    protected array $_hidden = ['password'];

    protected function _setPassword(?string $value): ?string
    {
        if ($value === null || $value === '') return null;
        return (new DefaultPasswordHasher())->hash($value);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until > FrozenTime::now();
    }
    public function isAdministrator(): bool
    {
        return !empty($this->role) && (bool)$this->role->is_admin;
    }

    public function getIdentifier(): mixed { return $this->id; }
    public function getOriginalData(): mixed { return $this; }
}
```

El setter `_setPassword` hashea automáticamente. Si llega vacío en edit, devuelve `null` y CakePHP no actualiza la columna.

### 8.3 `UsersTable`

```php
public function initialize(array $config): void
{
    parent::initialize($config);
    $this->setTable('users');
    $this->setPrimaryKey('id');
    $this->addBehavior('Timestamp');
    $this->belongsTo('Roles', ['foreignKey' => 'role_id', 'joinType' => 'INNER']);
}

public function validationDefault(Validator $validator): Validator
{
    return $validator
        ->notEmptyString('username', 'El usuario es requerido')
        ->lengthBetween('username', [3, 60], 'El usuario debe tener entre 3 y 60 caracteres')
        ->regex('username', '/^[a-zA-Z0-9._-]+$/', 'Solo letras, números, punto, guion bajo o guion')
        ->notEmptyString('name', 'El nombre es requerido')
        ->lengthBetween('name', [2, 120])
        ->integer('role_id')->notEmptyString('role_id', 'El rol es requerido')
        ->boolean('active')
        ->add('password', 'minLength', [
            'rule' => ['minLength', 8],
            'message' => 'La contraseña debe tener al menos 8 caracteres',
            'on' => fn($context) => !empty($context['data']['password']),
        ]);
}

public function validationCreate(Validator $validator): Validator
{
    $validator = $this->validationDefault($validator);
    return $validator->notEmptyString('password', 'La contraseña es requerida');
}

public function buildRules(RulesChecker $rules): RulesChecker
{
    $rules->add($rules->isUnique(['username'], 'Ya existe un usuario con ese nombre'));
    $rules->add($rules->existsIn(['role_id'], 'Roles'));
    return $rules;
}
```

**Política de password:** mínimo 8 caracteres, sin reglas adicionales de mayúscula/símbolo (el spec no las pide). En `edit` el campo es opcional (vacío = no cambiar).

### 8.4 `UserService`

```php
final class UserService
{
    public function create(array $data): array;     // hash password, valida no asignar admin role
    public function update(int $id, array $data): array;
    public function unlock(int $id): array;
}
```

Reglas de negocio que enforcea:
- No se puede asignar el rol Administrador a un usuario nuevo.
- No se puede cambiar el rol del usuario Administrador.
- No se puede asignar el rol Administrador en update (a usuarios que no sean ya el admin).
- `unlock`: setea `failed_login_count=0`, `locked_until=null`.

### 8.5 `UsersController`

`login`/`logout` (descriptos en §5.4), `index`, `view`, `add`, `edit`, `delete`, `unlock`.

- `delete` rechaza el usuario Administrador y rechaza eliminar tu propio usuario.
- `edit`: si el target es Administrador, ofrece como `roles` solo el rol actual (no permite cambiar); el campo "Activo" queda disabled en el template.
- `unlock` es POST-only y mapea al permiso `edit` vía `_actionToPermission` sobreescrito.
- Pagination: `['limit' => 15, 'maxLimit' => 15]`.
- `index` permite filtro simple por `?q=...` (búsqueda LIKE en `username` y `name`).

### 8.6 Templates

**`index.php`** — tabla con columnas: Usuario, Nombre, Rol, Estado, Última conexión, Acciones.
- Estado "Bloqueado" se calcula con `$user->isLocked()` y ofrece botón "Desbloquear" (POST con CSRF).
- El usuario Administrador no muestra acciones.

**`add.php` / `edit.php`** — Form con: Usuario, Nombre, Contraseña (required en add, opcional en edit con label "Dejá en blanco para no cambiar"), Rol (select sin Administrador, salvo cuando se edita al admin), Activo.
- En edit del Administrador: campos Rol y Activo en disabled con tooltip.

**`view.php`** — Solo lectura con todos los datos + última conexión + estado de bloqueo. Botones "Editar", "Desbloquear" (si aplica), "Eliminar" (si no es admin).

### 8.7 Invariantes

- Usuario Administrador no se puede borrar (controller + UI).
- Usuario Administrador no permite cambiar rol ni desactivar (service + UI disabled).
- No se puede asignar rol Administrador a un usuario nuevo (service + select filtrado).
- No se puede borrar tu propio usuario (controller).
- Password se hashea automáticamente vía setter del entity.
- Password en edit es opcional; vacío = no cambiar.
- `unlock` mapea a `edit` — quien edita Usuarios puede desbloquear cuentas.

---

## 9. Seeds iniciales

### 9.1 Estrategia: Seed via migración

Seed via migración (no via Seeder separado) porque el Administrador es estructural — la app no funciona sin él, y una migración lo garantiza en cada deploy. `down()` revierte automáticamente.

### 9.2 `20260502120300_SeedAdministratorRoleAndUser.php`

```php
class SeedAdministratorRoleAndUser extends BaseMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // 1. Rol Administrador
        $this->execute(
            "INSERT INTO roles (id, name, is_admin, created, modified)
             VALUES (1, 'Administrador', 1, '{$now}', '{$now}')"
        );

        // 2. Permisos Admin (no necesarios por bypass; seedeados para consistencia visual)
        foreach (['users', 'roles'] as $module) {
            $this->execute(
                "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
                 VALUES (1, '{$module}', 1, 1, 1, 1, '{$now}', '{$now}')"
            );
        }

        // 3. Usuario admin con password hasheado en runtime
        $hash = (new DefaultPasswordHasher())->hash('ca1ced0.DEV');
        $this->execute(
            "INSERT INTO users (username, name, password, role_id, active, failed_login_count, created, modified)
             VALUES ('admin', 'Administrador', '{$hash}', 1, 1, 0, '{$now}', '{$now}')"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM users WHERE username = 'admin'");
        $this->execute("DELETE FROM permissions WHERE role_id = 1");
        $this->execute("DELETE FROM roles WHERE is_admin = 1");
    }
}
```

### 9.3 Notas

- `role_id=1` literal en el INSERT del usuario admin: depende del orden de inserción. `is_admin=1` es la verdad estructural que usa el código.
- El hash se genera en `up()`, no es hardcodeado — se regenera en cada entorno con el cost del `DefaultPasswordHasher` actual.
- `password='ca1ced0.DEV'` queda en código; es un secreto de desarrollo. Antes de producción se cambia desde la UI (acción "Editar" sobre el admin).
- Roles "Cajero", "Encargado de turno", etc. NO se seedean — el spec dice que son libres, los crea el Administrador desde la UI.

### 9.4 Verificación post-seed

- `SELECT id, name, is_admin FROM roles;` → 1 fila `(1, 'Administrador', 1)`.
- `SELECT role_id, module, ... FROM permissions;` → 2 filas con flags=1.
- `SELECT id, username, name, role_id, active FROM users;` → 1 fila `(?, 'admin', 'Administrador', 1, 1)`.
- Login en `/login` con `admin` / `ca1ced0.DEV` redirige a `/` (placeholder).
- Sidebar muestra "Usuarios" y "Roles".
- En `/roles`, fila "Administrador" muestra todos los módulos en verde.

### 9.5 Migraciones en orden

```
config/Migrations/
├── 20260502120000_CreateRoles.php
├── 20260502120100_CreatePermissions.php
├── 20260502120200_CreateUsers.php
└── 20260502120300_SeedAdministratorRoleAndUser.php
```

Ejecutables con `php bin/cake.php migrations migrate`.

---

## 10. Cross-cutting

### 10.1 Manejo de errores

| Origen | Manejo | UX |
|---|---|---|
| `NotFoundException` | CakePHP la levanta sola; `error400.php` | 404 estilada |
| `ForbiddenException` | Misma template 4xx | 403 con `bi-shield-lock` |
| `PersistenceFailedException` (delete con FK) | Try/catch en controller → `Flash::error` | Mensaje en pantalla origen |
| Excepciones inesperadas | `error500.php` en `debug=false`; trace en `debug=true` | Genérica en prod |

`templates/Error/error400.php` y `error500.php` se re-stylean con Bootstrap, manteniendo nombres del skeleton.

### 10.2 Logging

Puntos de log de Fase 0:

- `LoginThrottleService::recordSuccess` → `Log::info`.
- `LoginThrottleService::recordFailure` → `Log::warning`.
- `LoginThrottleService::recordFailure` con 5to fallo → `Log::warning('Account locked...')`.
- `LoginThrottleService::recordFailure` con username inexistente → `Log::info`.
- `UserService::create` exitoso → `Log::info`.
- `UserService::unlock` → `Log::info`.
- Try/catch de `PersistenceFailedException` en delete → `Log::warning`.

NO se loguea: lecturas exitosas, saves exitosos rutinarios, passwords, CSRF tokens.

### 10.3 `config/routes.php` final

```php
return function (RouteBuilder $routes): void {
    $routes->setRouteClass(DashedRoute::class);
    $routes->scope('/', function (RouteBuilder $builder): void {
        $builder->connect('/login',  ['controller' => 'Users', 'action' => 'login']);
        $builder->connect('/logout', ['controller' => 'Users', 'action' => 'logout']);
        $builder->connect('/',       ['controller' => 'Pages', 'action' => 'home']);
        $builder->connect(
            '/users/unlock/{id}',
            ['controller' => 'Users', 'action' => 'unlock'],
            ['id' => '\d+', 'pass' => ['id']]
        );
        $builder->fallbacks();
    });
};
```

`PagesController` se reemplaza por una versión mínima con solo `home()` (placeholder dashboard que requiere sesión y dice "Dashboard — disponible en Fase 4" con el username del usuario logueado).

### 10.4 `Application.php` — middleware queue

```php
$middlewareQueue
    ->add(new ErrorHandlerMiddleware(...))
    ->add(new HostHeaderMiddleware())
    ->add(new AssetMiddleware(['cacheTime' => Configure::read('Asset.cacheTime')]))
    ->add(new RoutingMiddleware($this))
    ->add(new AuthenticationMiddleware($this))           // NUEVO
    ->add(new BodyParserMiddleware())
    ->add(new CsrfProtectionMiddleware(['httponly' => true]));
```

`Application` implementa `AuthenticationServiceProviderInterface`.

### 10.5 `config/.env`

Agregar:
```
APP_FULL_BASE_URL=http://localhost:8765
```

Para que `HostHeaderMiddleware` no rechace requests cuando se cambie a `DEBUG=false`.

### 10.6 `composer.json`

Agregar:
```json
"require": {
    "cakephp/authentication": "^3.0"
}
```

`Application::bootstrap()` carga el plugin con `$this->addPlugin('Authentication')`.

### 10.7 Scripts útiles

`composer cs-check`, `composer cs-fix`, `vendor/bin/phpstan analyse`, `vendor/bin/psalm` quedan disponibles del skeleton. **Sin tests** queda fuera el script `test`.

---

## 11. Apéndice — Catálogo final de archivos creados/modificados

### Archivos creados

```
src/Service/AuthorizationService.php
src/Service/LoginThrottleService.php
src/Service/RolePermissionService.php
src/Service/UserService.php
src/Controller/RolesController.php
src/Controller/UsersController.php
src/Model/Entity/Role.php
src/Model/Entity/Permission.php
src/Model/Entity/User.php
src/Model/Table/RolesTable.php
src/Model/Table/PermissionsTable.php
src/Model/Table/UsersTable.php
src/View/Helper/SidebarHelper.php

config/Migrations/20260502120000_CreateRoles.php
config/Migrations/20260502120100_CreatePermissions.php
config/Migrations/20260502120200_CreateUsers.php
config/Migrations/20260502120300_SeedAdministratorRoleAndUser.php

templates/layout/login.php
templates/Roles/index.php
templates/Roles/add.php
templates/Roles/edit.php
templates/Roles/view.php
templates/Users/login.php
templates/Users/index.php
templates/Users/add.php
templates/Users/edit.php
templates/Users/view.php

webroot/css/vendor/bootstrap.min.css
webroot/css/vendor/bootstrap-icons.min.css
webroot/css/davirapid.css
webroot/js/vendor/bootstrap.bundle.min.js
webroot/fonts/inter-regular.woff2
webroot/fonts/inter-medium.woff2
webroot/fonts/inter-semibold.woff2
webroot/fonts/inter-bold.woff2
webroot/fonts/bootstrap-icons.woff2
```

### Archivos modificados

```
src/Application.php                 # AuthenticationServiceProviderInterface, middleware
src/Controller/AppController.php    # RBAC wiring, currentUser, sidebar vars
src/Controller/PagesController.php  # reemplazado por versión mínima con home()
config/routes.php                   # rutas finales
config/.env                         # APP_FULL_BASE_URL
composer.json                       # cakephp/authentication
templates/layout/default.php        # layout autenticado real
templates/Pages/home.php            # placeholder dashboard
templates/Error/error400.php        # restyle Bootstrap
templates/Error/error500.php        # restyle Bootstrap
```

### Archivos borrados

```
templates/cell/.gitkeep
templates/email/html/* templates/email/text/* templates/layout/email/*
webroot/css/cake.css webroot/css/home.css webroot/css/milligram.min.css
webroot/css/normalize.min.css webroot/css/fonts.css
webroot/img/cake.icon.png webroot/img/cake-logo.png webroot/img/cake.logo.svg
webroot/img/cake.power.gif
tests/TestCase/Controller/PagesControllerTest.php
tests/TestCase/ApplicationTest.php
tests/schema.sql
```

(`templates/Pages/home.php` no figura acá — el archivo del skeleton se sobreescribe con el placeholder de dashboard, está listado en "modificados".)

---

## 12. Alcance explícitamente fuera de Fase 0

Todo lo siguiente queda para fases posteriores y NO se implementa ahora:

- Cualquier módulo de negocio (Productos, Clientes, Repartidores, Pedidos, Inventario, Finanzas, Dashboard).
- Componentes de diseño específicos de dominio (`dr-stat-card`, `dr-status-*`, etc.).
- Recuperación de contraseña, signup, verificación por email.
- Cambio de password desde "Mi cuenta" (se hace via Edit del usuario admin desde la UI por ahora).
- Vinculación usuario↔repartidor (`users.delivery_id`).
- Filtro automático por repartidor en queries operacionales.
- Tests automatizados.
- CI/CD, Docker, deployment.
- Internacionalización (todo el copy queda en español hardcodeado).

---

## 13. Próximo paso

Tras la aprobación del usuario, este spec se convierte en un plan de implementación vía la skill `superpowers:writing-plans`. El plan dividirá el trabajo en pasos atómicos, cada uno con archivos a tocar, criterios de aceptación, y orden de ejecución.
