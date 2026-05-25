# Plan de implementación — Módulo Pedidos (Orders) + Auditoría (Order Logs)

> Plan ordenado, paso a paso, para implementar el módulo **Pedidos + Auditoría**
> según el diseño aprobado en `.claude/designs/04-pedidos.md`.
>
> Referencias obligatorias antes de implementar:
> - `.claude/designs/04-pedidos.md` (spec definitivo).
> - `.claude/plans/01-ingredientes.md`, `02-recetas.md`, `03-ajustes-inventario.md` (formato/estilo y patrones de implementación validados).
> - `.claude/rules/ARQUITECTURE.md` §3 (receta de módulo), §4 (patrones), §4.13 (familias de servicios), §5 (RBAC).
> - `.claude/rules/DESIGN.md` — familia `status-*` para estados de pedido (no usar `badge-*` genéricos en estos campos).
> - Módulos predecesores: **Ingredientes**, **Recetas**, **Ajustes** (servicios consumidos).
> - `src/Service/RecipeService.php` → `buildDecrementPlan(int $productId, int $unitsSold): array` (línea 277). Retorna `list<array{ingredient_id:int, quantity:string}>`.
> - `src/Service/IngredientService.php` → `adjustStock(Ingredient $i, string $deltaSigned, string $reason): array` (línea 113). Abre transacción interna con `SELECT ... FOR UPDATE`; los caller que envuelvan en transacción externa obtienen savepoints anidados.
> - `src/Service/CustomerService.php` → `findOrCreateByPhone(array $data): Customer` (línea 121).
> - `src/Controller/AppController.php` — `$controllerModuleMap` (línea 19), `$actionModuleMap` (línea 48), `_actionToPermission()` (línea 114).
> - `src/Service/AuthorizationService.php` — `MODULES` (línea 17). `roles` está hardcoded como admin-only en `isAllowed` (línea 54); replicaremos el patrón para `audit`.
> - `src/View/Helper/SidebarHelper.php` — array `$items` (línea 21).
> - `config/routes.php` — todas las rutas custom van **antes** de `$builder->fallbacks()`.

---

## 1. File manifest

Orden en el que tocar los archivos. Cada línea = archivo + cambio puntual.
Timestamps de migración: **posteriores** a `20260524140100_SeedAdjustmentsPermissions`
para preservar el orden cronológico.

### Migraciones (5)

1. `[CREATE] config/Migrations/20260524150000_CreateOrders.php`
   — schema `orders` (design §1.1 + §1.4).
2. `[CREATE] config/Migrations/20260524150100_CreateOrderItems.php`
   — schema `order_items` (design §1.2). FK CASCADE a `orders`, SET NULL a `products`.
3. `[CREATE] config/Migrations/20260524150200_CreateOrderLogs.php`
   — schema `order_logs` (design §1.3). FK SET NULL a `orders` y `users`; columna `order_id_snapshot` que sobrevive al delete del pedido. Sin `modified` (append-only).
4. `[CREATE] config/Migrations/20260524150300_SeedOrdersPermissions.php`
   — fila `permissions` por rol para módulo `orders`: no-admin con `view+create+edit`, sin `delete`; admin con matriz completa (design §7.3).
5. `[CREATE] config/Migrations/20260524150400_SeedAuditPermissions.php`
   — fila placeholder all-zero para todos los roles no-admin del módulo `audit` (para que la matriz UI sea consistente y el admin pueda toggle-eador a futuro); admin con `view=1` (bypass cubre igual, kept for consistency).

### Constantes (2)

6. `[CREATE] src/Constants/OrderConstants.php` — TYPES, STATUSES (con `STATUS_CSS_CLASS`), PAYMENT_METHODS (con `PAYMENT_METHODS_CASH_LIKE`), `EDITABLE_STATUSES`, `CANCELLABLE_FROM`, `MAX_ITEMS_PER_ORDER`, etc. (design §2.1).
7. `[CREATE] src/Constants/OrderLogConstants.php` — KINDS con `KIND_LABELS` y `KIND_ICONS` (design §2.2).

### Entidades (3)

8. `[CREATE] src/Model/Entity/Order.php` — `$_accessible`, virtuales (`display_status`, `item_count`, `is_credit`), predicados (`isCancelled`, `isEditable`, `isCancellable`, `isCredit`, `isDomicilio`, `isLocal`, `isDelivered`), helpers (`canTransitionTo`, `getDisplayStatus`, `getStatusCssClass`, `getCustomerName`, `getCustomerPhone`, `getItemCount`, `getItemsSummary`) (design §3.1).
9. `[CREATE] src/Model/Entity/OrderItem.php` — `$_accessible`, `getLineSubtotal()` con `round()` y `MONEY_DECIMALS`, `getFormattedQuantity()`, virtual `computed_subtotal` (design §3.2).
10. `[CREATE] src/Model/Entity/OrderLog.php` — `$_accessible`, `getFormattedDate`, `getKindLabel`, `getIcon`, `isOrphan()` (design §3.3).

### Tables (3)

11. `[CREATE] src/Model/Table/OrdersTable.php` — asociaciones (`Customers`, `Deliveries`, `Users` LEFT; `CancelledByUser` alias; `hasMany OrderItems` con `dependent=true cascadeCallbacks=true`; `hasMany OrderLogs` con `dependent=false`), validation, rules, finders (`findVisible`, `findByState`, `findForRepartidor`, `findInDateRange`, `findWithItems`, `findActiveToday`) (design §4.1).
12. `[CREATE] src/Model/Table/OrderItemsTable.php` — asociaciones (`Orders` INNER, `Products` LEFT), validation, finder `findTopProducts` (design §4.2).
13. `[CREATE] src/Model/Table/OrderLogsTable.php` — Timestamp solo para `created`, asociaciones (`Orders` LEFT, `Users` LEFT), validation, finders `findForOrder`, `findChronological` (design §4.3).

### Servicios (4)

14. `[CREATE] src/Service/OrderHistoryService.php` — auditoría field-by-field; persiste sin abortar el flujo si falla; normaliza valores antes del diff (design §5.3). Métodos: `logCreated`, `logStateChanged`, `logFieldChange`, `logFieldChanges`, `logItemAdded`, `logItemRemoved`, `logItemsReplaced`, `logCancelled`, `logReactivated`, `logDeleted`, helper privado `normalize`, helper privado `persist`.
15. `[CREATE] src/Service/OrderPipelineService.php` — state machine pura (sin efectos de inventario). Métodos: `canTransition`, `advance`, `nextValidStates`, helper privado `flattenErrors`. Constantes `TRANSITIONS` (design §5.2).
16. `[CREATE] src/Service/OrderFilterService.php` — `apply(SelectQuery, array filters): SelectQuery` (design §5.4).
17. `[CREATE] src/Service/OrderService.php` — orquestador grande. Constructor con `OrderHistoryService`, `RecipeService`, `IngredientService`, `CustomerService`, `?ReceivableService` (este último null por ahora — log warning). Métodos: `create`, `update`, `cancel`, `reactivate`, `delete`, helpers privados `validateCreateInput`, `validateUpdateInput`, `restoreStockFor`, `decrementStockFor`, `flattenErrors` (design §5.1, §11.1-11.4).

### Controllers (2)

18. `[CREATE] src/Controller/OrdersController.php` — acciones `index`, `view`, `add`, `edit`, `delete`, `cancel`, `reactivate`, `advance`, `ticket`. Helpers `_actionToPermission` (override), `_buildIndexQuery`, `_currentFilters`, `_computeKpis`, `_canCreateOrders` (bloquea si user es repartidor). Carga `OrdersTable`/`OrderItemsTable`/`OrderLogsTable` automáticamente (los nombres calzan).
19. `[CREATE] src/Controller/OrderLogsController.php` — acciones `index`, `view`. Module fijo `audit` (sobrescribe `$controllerModuleMap` localmente). Solo el admin entra vía bypass (los no-admin tienen placeholder all-zero → 403).

### RBAC + navegación (3 modificaciones puntuales)

20. `[MODIFY] src/Controller/AppController.php`:
    - Agregar `'Orders' => 'orders',` y `'OrderLogs' => 'audit',` al `$controllerModuleMap` (tras `'Adjustments' => 'adjustments',` línea ~27).
    - Agregar dos helpers `protected function _scopeToRepartidor(SelectQuery $q, string $alias = 'Orders'): SelectQuery` y `protected function _enforceRepartidorAccess(int $orderDeliveryId): void` (reusables por módulos futuros — design §8.1).
    - Importar `use Cake\ORM\Query\SelectQuery;` y `use Cake\Http\Exception\ForbiddenException;` (este último ya está).
21. `[MODIFY] src/Service/AuthorizationService.php`:
    - Agregar `'orders' => 'Pedidos',` y `'audit' => 'Auditoría',` al `MODULES`.
    - Agregar el módulo `audit` al hardcode admin-only en `isAllowed` (línea 54): `if (in_array($module, ['roles', 'audit'], true)) return false;` (para no-admin; admin bypassea en línea 44).
22. `[MODIFY] src/View/Helper/SidebarHelper.php`:
    - Insertar item `'orders'` en grupo Operación con `icon: 'bi-bag'`, `url: ['controller' => 'Orders', 'action' => 'index']`, tras `roles` o donde corresponda jerárquicamente (decisión: insertar **al tope** del listado actual, antes de `products` — los pedidos son el corazón operativo).
    - Insertar item `'audit'` al final con `icon: 'bi-clipboard-data'`, `label: 'Auditoría'`, `url: ['controller' => 'OrderLogs', 'action' => 'index']`. El helper ya filtra por `permissions[module].view`; admin lo verá vía bypass, no-admin no (placeholder all-zero).

### Rutas (1 modificación)

23. `[MODIFY] config/routes.php` — agregar **antes** de `$builder->fallbacks()`:
    - `/orders/advance/{id}` POST → Orders::advance.
    - `/orders/cancel/{id}` POST → Orders::cancel.
    - `/orders/reactivate/{id}` POST → Orders::reactivate.
    - `/orders/ticket/{id}` GET → Orders::ticket.
    - `/audit` GET → OrderLogs::index.
    - `/audit/order/{id}` GET → OrderLogs::index (pasa `order_id` por query string interno; ver §3 de este plan).

### Templates (~9 archivos)

24. `[CREATE] templates/Orders/index.php` — KPI strip (4 cards), card de filtros (`q`, `status`, `type`, `payment_method`, `delivery_id`, `from`, `to`), tabla con badges `status-*`, botones de acción por estado (design §9.1).
25. `[CREATE] templates/Orders/add.php` — form complejo de 5 secciones (cliente, tipo, productos repeatable, método de pago, resumen) (design §9.2). Incluye `<template id="order-line-tpl">` para clonar líneas vía JS sin dependencias.
26. `[CREATE] templates/Orders/edit.php` — reusa estructura de add, pre-rellena, agrega alerta amarilla "Editar restaurará insumos y descontará los nuevos" (design §9.4).
27. `[CREATE] templates/Orders/view.php` — header con #id, status chip, autor; barra de acciones según `pipeline->nextValidStates($order)`; cards Cliente/Entrega; tabla items; sección "Historial reciente" con los últimos 5 logs (design §9.3).
28. `[CREATE] templates/Orders/ticket.php` — usa `layout/ticket.php`; auto-print con script JS. Datos del negocio hardcoded con `// TODO parametrizar via tabla business_info` (design §9.5, risks 14.3).
29. `[CREATE] templates/layout/ticket.php` — layout sin sidebar/topbar; max-width 280px; monospace; CSS `@media print { .no-print { display: none; } }`.
30. `[CREATE] templates/OrderLogs/index.php` — tabla compacta (Fecha, #Pedido linkable si !orphan, Autor, Tipo, Descripción); filtros `order_id`, `user_id`, `kind`, `from`, `to` (design §9.7).
31. `[CREATE] templates/OrderLogs/view.php` — detalle simple de un log (opcional pero documentado).
32. `[CREATE] templates/element/order_status_badge.php` — render reusable del chip `status-*`. Acepta `$order` o `$status`. Usado por `index.php`, `view.php`, embedded en otros lugares.

### Tests (~12 archivos)

33. `[CREATE] tests/Fixture/OrdersFixture.php` — 4 filas: 1 local entregado en efectivo, 1 domicilio en preparación a crédito, 1 cancelado, 1 recibido (variedad para tests de filtros/transiciones).
34. `[CREATE] tests/Fixture/OrderItemsFixture.php` — líneas para las 4 órdenes anteriores.
35. `[CREATE] tests/Fixture/OrderLogsFixture.php` — algunos logs sintéticos (`created`, `state_changed`, uno huérfano con `order_id=null` para test isOrphan).
36. `[CREATE] tests/TestCase/Model/Entity/OrderTest.php`.
37. `[CREATE] tests/TestCase/Model/Entity/OrderItemTest.php`.
38. `[CREATE] tests/TestCase/Model/Entity/OrderLogTest.php`.
39. `[CREATE] tests/TestCase/Model/Table/OrdersTableTest.php`.
40. `[CREATE] tests/TestCase/Model/Table/OrderItemsTableTest.php`.
41. `[CREATE] tests/TestCase/Model/Table/OrderLogsTableTest.php`.
42. `[CREATE] tests/TestCase/Service/OrderServiceTest.php` — el más grande.
43. `[CREATE] tests/TestCase/Service/OrderPipelineServiceTest.php`.
44. `[CREATE] tests/TestCase/Service/OrderHistoryServiceTest.php`.
45. `[CREATE] tests/TestCase/Service/OrderFilterServiceTest.php`.
46. `[CREATE] tests/TestCase/Controller/OrdersControllerTest.php`.
47. `[CREATE] tests/TestCase/Controller/OrderLogsControllerTest.php`.

### Cierre

48. `[RUN] php bin/cake.php migrations migrate`.
49. `[RUN] php bin/cake.php migrations dump`.
50. `[RUN] composer cs-check`.
51. `[RUN] php -l` sobre cada archivo PHP nuevo.
52. `[RUN] php bin/cake.php routes | grep -E "(orders|audit)"` para verificar rutas custom y fallbacks.

---

## 2. Step-by-step execution

### Paso 1 — Migración `CreateOrders`

**Archivo:** `config/Migrations/20260524150000_CreateOrders.php`

`Migrations\BaseMigration`. Proteger con `if ($this->hasTable('orders')) { return; }`. Tabla con `['signed' => false]` (PK unsigned, consistente con todas las FKs apuntadas: `customers.id`, `deliveries.id`, `users.id` son todas unsigned — verificado en migraciones existentes).

Columnas (orden lógico): ver design §1.1 + bloque PHP §1.4. Reproducir literalmente.

Índices (7): `idx_orders_status_created`, `idx_orders_created`, `idx_orders_delivery_id`, `idx_orders_customer_id`, `idx_orders_type`, `idx_orders_payment_method`, `idx_orders_delivered_at`.

FKs (4):
- `customer_id` → `customers.id` ON DELETE SET_NULL, UPDATE RESTRICT, constraint `fk_orders_customer`.
- `delivery_id` → `deliveries.id` ON DELETE SET_NULL, constraint `fk_orders_delivery`.
- `user_id` → `users.id` ON DELETE SET_NULL, constraint `fk_orders_user`.
- `cancelled_by` → `users.id` ON DELETE SET_NULL, constraint `fk_orders_cancelled_by`.

`down()`: drop seguro con `hasTable`.

**Acceptance:** `php bin/cake.php migrations migrate` corre sin errores; `SHOW CREATE TABLE orders` muestra los 4 FKs con `signed=false` y los 7 índices.

---

### Paso 2 — Migración `CreateOrderItems`

**Archivo:** `config/Migrations/20260524150100_CreateOrderItems.php`

Columnas: ver design §1.2.

Índices: `idx_order_items_order_id`, `idx_order_items_product_id`.

FKs:
- `order_id` → `orders.id` ON DELETE **CASCADE**, constraint `fk_oi_order`. (Líneas mueren con el pedido.)
- `product_id` → `products.id` ON DELETE SET_NULL, constraint `fk_oi_product`. (Si se borra el producto, el snapshot del nombre sobrevive.)

**Acceptance:** FK CASCADE verificable con `SHOW CREATE TABLE order_items`.

---

### Paso 3 — Migración `CreateOrderLogs`

**Archivo:** `config/Migrations/20260524150200_CreateOrderLogs.php`

Columnas: ver design §1.3. **Sin** columna `modified` (append-only).

`order_id_snapshot` es `int unsigned NOT NULL` — preserva la referencia aunque el pedido se borre.

Índices (3): `idx_order_logs_order_id_snapshot_created`, `idx_order_logs_created`, `idx_order_logs_user_id`.

FKs:
- `order_id` → `orders.id` ON DELETE **SET_NULL** (clave del spec §9: logs sobreviven al delete), constraint `fk_ol_order`.
- `user_id` → `users.id` ON DELETE SET_NULL, constraint `fk_ol_user`.

**Acceptance:** Borrar un pedido en SQL deja la fila en `order_logs` con `order_id=NULL` y `order_id_snapshot` intacto.

---

### Paso 4 — Migración `SeedOrdersPermissions`

**Archivo:** `config/Migrations/20260524150300_SeedOrdersPermissions.php`

Calco de `SeedAdjustmentsPermissions` con las diferencias:

- Module: `'orders'`.
- No-admin: `can_view=1, can_create=1, can_edit=1, can_delete=0` (operativo cotidiano; delete es excepcional, opt-in por toggle en /roles).
- Admin: matriz completa `(1,1,1,1)`.

`down()`: `DELETE FROM permissions WHERE module = 'orders'`.

**Acceptance:** tras migrate, `SELECT * FROM permissions WHERE module='orders'` muestra un row por cada rol (no duplicados — el `NOT EXISTS` previene).

---

### Paso 5 — Migración `SeedAuditPermissions`

**Archivo:** `config/Migrations/20260524150400_SeedAuditPermissions.php`

Particularidad del módulo (gotcha del prompt): el módulo es **admin-only**, así que **NO** se otorgan permisos reales a no-admin. Pero la migración debe insertar **una fila placeholder all-zero** por cada rol no-admin para que la matriz UI de `/roles/edit` muestre la columna `Auditoría` con todos los checkboxes desmarcados, en lugar de omitirla y dar la impresión de que el módulo no existe.

```sql
-- Placeholder all-zero para no-admin: módulo visible en la matriz, sin permisos reales.
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
SELECT r.id, 'audit', 0, 0, 0, 0, '{$now}', '{$now}'
FROM roles r
WHERE r.is_admin = 0
  AND NOT EXISTS (
    SELECT 1 FROM permissions p WHERE p.role_id = r.id AND p.module = 'audit'
  );

-- Admin: view=1 por consistencia (el bypass cubre igual).
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
SELECT r.id, 'audit', 1, 0, 0, 0, '{$now}', '{$now}'
FROM roles r
WHERE r.is_admin = 1
  AND NOT EXISTS (
    SELECT 1 FROM permissions p WHERE p.role_id = r.id AND p.module = 'audit'
  );
```

> **Nota:** aunque un admin marcase `can_view=1` en `audit` para un rol no-admin desde `/roles/edit`, el guard hardcoded en `AuthorizationService::isAllowed` (`if (in_array($module, ['roles', 'audit'], true)) return false;`) seguiría devolviendo false. Es decir: el placeholder es solo cosmético para la UI; la regla "audit es admin-only" es **estructural** y vive en código (paso 22 abajo). Documentar este comportamiento como TODO si en el futuro se quiere abrir `audit` a roles específicos.

`down()`: `DELETE FROM permissions WHERE module = 'audit'`.

**Acceptance:** `SELECT * FROM permissions WHERE module='audit'` muestra un row por rol; todos los no-admin tienen los 4 flags en 0.

---

### Paso 6 — Constantes `OrderConstants`

**Archivo:** `src/Constants/OrderConstants.php`

Clase `final` en `App\Constants`. Reproducir el bloque del design §2.1 íntegro:

- Types (`TYPE_LOCAL`, `TYPE_DOMICILIO`) + `TYPES` + `TYPE_LABELS`.
- Statuses (`STATUS_RECEIVED`, `STATUS_PREPARING`, `STATUS_ON_ROUTE`, `STATUS_DELIVERED`, `STATUS_CANCELLED`) + `STATUSES` + `STATUS_LABELS` + `STATUS_CSS_CLASS` (mapeo a familia `status-*` del DESIGN — **NO** `badge-*`).
- `TERMINAL_STATUSES = [STATUS_DELIVERED]`. `EDITABLE_STATUSES = [STATUS_RECEIVED, STATUS_PREPARING]`. `CANCELLABLE_FROM = [STATUS_RECEIVED, STATUS_PREPARING, STATUS_ON_ROUTE]`.
- Payment methods + `PAYMENT_LABELS` + `PAYMENT_METHODS_CASH_LIKE` (todos menos crédito — usado por dashboard fase 5).
- `MONEY_DECIMALS = 2`, `QUANTITY_DECIMALS = 3`.
- `NOTES_MAX_LENGTH = 65000`, `LINE_NOTES_MAX_LENGTH = 255`, `MAX_ITEMS_PER_ORDER = 50`.
- Constructor privado vacío.

**Acceptance:** `composer cs-check` limpio.

---

### Paso 7 — Constantes `OrderLogConstants`

**Archivo:** `src/Constants/OrderLogConstants.php`

Reproducir design §2.2: 9 kinds (`created`, `state_changed`, `field_changed`, `item_added`, `item_removed`, `item_changed`, `cancelled`, `reactivated`, `deleted`) con `KIND_LABELS` (en español) y `KIND_ICONS` (Bootstrap Icons). Constructor privado.

**Acceptance:** `composer cs-check` limpio.

---

### Paso 8 — Entity `Order`

**Archivo:** `src/Model/Entity/Order.php`

Extiende `Cake\ORM\Entity`. `$_accessible` con whitelist completa (design §3.1) incluyendo asociaciones (`order_items`, `customer`, `delivery`, `user`).

`$_virtual = ['display_status', 'item_count', 'is_credit']`.

Imports: `App\Constants\OrderConstants`, `App\Service\OrderPipelineService`.

Métodos públicos (design §3.1, transcribir):

- Predicados: `isCancelled`, `isDelivered`, `isDomicilio`, `isLocal`, `isCredit`, `isEditable`, `isCancellable`.
- `canTransitionTo(string $newStatus): bool` — consulta `OrderPipelineService::TRANSITIONS` (constante pública).
- Display: `getDisplayStatus`, `getStatusCssClass`, `getCustomerName` (preferir snapshot, fallback a relación), `getCustomerPhone`, `getItemCount`, `getItemsSummary(int $maxNames = 1)`.
- Accessors virtuales: `_getDisplayStatus`, `_getItemCount`, `_getIsCredit`.

> **Nota:** `Order::canTransitionTo` lee la constante `OrderPipelineService::TRANSITIONS`. Es una dependencia hacia el service, pero la dirección es entity → service-class-constant (sin instanciar), aceptable. Mantenerlo así evita duplicar la matriz en dos lugares.

**Acceptance:** instanciar `new Order(['status' => 'preparando', 'type' => 'domicilio'])` y comprobar `isEditable() === true`, `isCancellable() === true`, `canTransitionTo('en_camino') === true`, `canTransitionTo('entregado') === false`.

---

### Paso 9 — Entity `OrderItem`

**Archivo:** `src/Model/Entity/OrderItem.php`

Reproducir design §3.2. `getLineSubtotal()` usa `round((float)qty * (float)price, OrderConstants::MONEY_DECIMALS)`. `getFormattedQuantity()` recorta ceros decimales (la mayoría de pedidos serán enteros).

**Acceptance:** `(new OrderItem(['quantity' => '2.000', 'price_at_sale' => '15000.00']))->getLineSubtotal() === 30000.00`.

---

### Paso 10 — Entity `OrderLog`

**Archivo:** `src/Model/Entity/OrderLog.php`

Reproducir design §3.3. `getFormattedDate()` usa `i18nFormat('dd/MM/yyyy HH:mm')`. `isOrphan()` chequea `$this->order_id === null`.

**Acceptance:** `(new OrderLog(['order_id' => null]))->isOrphan() === true`.

---

### Paso 11 — Table `OrdersTable`

**Archivo:** `src/Model/Table/OrdersTable.php`

`initialize()`: design §4.1. Cuidado especial:
- `hasMany('OrderItems', ['foreignKey' => 'order_id', 'dependent' => true, 'cascadeCallbacks' => true])` — para que ORM borre items en cascade.
- `hasMany('OrderLogs', ['foreignKey' => 'order_id', 'dependent' => false])` — **clave**: los logs NO deben morir con el pedido (la FK en DB es SET_NULL, y el ORM no debe intentar borrarlos).
- `belongsTo('CancelledByUser', ['className' => 'Users', 'foreignKey' => 'cancelled_by', 'joinType' => 'LEFT'])`.

`validationDefault`: presencia de `type`/`status`/`payment_method` con `inList`, `numeric` + `greaterThanOrEqual` para los 3 montos, `maxLength` para snapshots (reusando constantes de `CustomerConstants` si aplica), `allowEmptyString` para `notes`.

`buildRules`: `existsIn` para `customer_id`, `delivery_id`, `user_id` con `allowNullableNulls`.

Custom finders (design §4.1):

- `findVisible(SelectQuery $q): SelectQuery` — excluye cancelados.
- `findByState(SelectQuery $q, array $opts): SelectQuery` — acepta `status` como string o array.
- `findForRepartidor(SelectQuery $q, array $opts): SelectQuery` — filtra por `delivery_id`; si no recibe id válido, retorna query vacía con `where('1=0')`.
- `findInDateRange(SelectQuery $q, array $opts): SelectQuery` — `from`/`to` inclusivos.
- `findWithItems(SelectQuery $q): SelectQuery` — `contain(['OrderItems' => ['Products']])`.
- `findActiveToday(SelectQuery $q): SelectQuery` — para KPIs del index/dashboard.

> **NO** sobrescribir `findList()` (incompatible en CakePHP 5; usar nombres custom — regla §4.4).

**Acceptance:** `vendor/bin/phpstan analyse src/Model/Table/OrdersTable.php` limpio nivel 8.

---

### Paso 12 — Table `OrderItemsTable`

**Archivo:** `src/Model/Table/OrderItemsTable.php`

Reproducir design §4.2. Validation: `notEmptyString('product_name')`, `numeric('quantity')` + `greaterThan(0)`, `numeric('price_at_sale')` + `greaterThanOrEqual(0)`, `maxLength('notes', OrderConstants::LINE_NOTES_MAX_LENGTH)`.

Finder `findTopProducts(SelectQuery $q, array $opts): SelectQuery` con agregación SUM, JOIN a Orders filtrando cancelled, GROUP BY product_id+name, ORDER BY units DESC, LIMIT.

**Acceptance:** phpstan limpio.

---

### Paso 13 — Table `OrderLogsTable`

**Archivo:** `src/Model/Table/OrderLogsTable.php`

`Timestamp` config explícita para solo `created` (igual que `InventoryAdjustmentsTable` — gotcha replicado):

```php
$this->addBehavior('Timestamp', [
    'events' => [
        'Model.beforeSave' => ['created' => 'new'],
    ],
]);
```

Asociaciones: `Orders` LEFT, `Users` LEFT.

Validation: `integer('order_id_snapshot') + requirePresence('create')`, `inList('kind', OrderLogConstants::KINDS)`, `maxLength('description', 500)`.

Finders: `findForOrder(SelectQuery, array opts)` y `findChronological(SelectQuery)`.

**Acceptance:** save() de un log no falla por columna `modified` inexistente.

---

### Paso 14 — Service `OrderHistoryService`

**Archivo:** `src/Service/OrderHistoryService.php`

Clase `final` en `App\Service`. `use LocatorAwareTrait`. Sin constructor (no tiene dependencias).

**Imports:** `App\Constants\OrderConstants`, `App\Constants\OrderLogConstants`, `App\Model\Entity\Order`, `App\Model\Entity\OrderItem`, `Cake\Log\Log`, `Cake\ORM\Locator\LocatorAwareTrait`.

**Métodos públicos** (transcribir design §5.3, columna "Uso"):

| Método | Descripción |
|---|---|
| `logCreated(Order $o, int $uid, string $extra = ''): void` | kind=`created`; desc = `"Pedido creado por {user_name}. {extra}"`. |
| `logStateChanged(Order $o, int $uid, string $from, string $to): void` | kind=`state_changed`; desc = `"Estado: de '{label_from}' a '{label_to}'"` usando `OrderConstants::STATUS_LABELS`. |
| `logFieldChange(Order $o, int $uid, string $field, mixed $oldVal, mixed $newVal): void` | kind=`field_changed`. Llama `normalize($oldVal)` y `normalize($newVal)`; si `!==`, persiste log con desc `"{Field}: de '{old}' a '{new}'"`. |
| `logFieldChanges(Order $o, int $uid, array $snapshot): void` | Itera campos del snapshot; por cada uno llama `logFieldChange`. Lista cerrada de campos: `type`, `payment_method`, `shipping_cost`, `customer_id`, `delivery_id`, `notes`, `customer_name`, `customer_phone`, `customer_address`. |
| `logItemAdded(Order $o, int $uid, OrderItem $item): void` | kind=`item_added`; desc `"Agregado: {qty} × {product_name}"`. |
| `logItemRemoved(Order $o, int $uid, OrderItem $item): void` | kind=`item_removed`. |
| `logItemsReplaced(Order $o, int $uid, array $oldItems, array $newItems): void` | kind=`item_changed`; produce un solo log con diff legible (ver helper privado `summarizeItemsDiff`). |
| `logCancelled(Order $o, int $uid, string $reason = ''): void` | kind=`cancelled`. |
| `logReactivated(Order $o, int $uid): void` | kind=`reactivated`. |
| `logDeleted(Order $o, int $uid): void` | kind=`deleted`. **DEBE invocarse ANTES del delete físico** para que `order_id` se persista correctamente (la FK lo va a poner null tras el delete, pero el log ya está creado con el id válido y `order_id_snapshot` lo preserva). |

**Helpers privados:**

- `normalize(mixed $value): mixed` — design §5.3, normaliza DateTime → string, bool, numeric → formateado a 2 decimales, `''` → null.
- `persist(Order $order, int $userId, string $kind, string $description): void` — design §5.3 bloque inferior:
  - Si `$userId > 0`, resolver `Users::find()->where(id=...)` para obtener `name` (fallback a `username`).
  - Construir entity con `order_id`, `order_id_snapshot`, `user_id`, `user_name_snapshot`, `kind`, `description` truncado a 500 chars con `mb_substr`.
  - Save; si falla, `Log::error('Failed to persist order log: {errors}', ...)` y **NO** lanzar excepción (los fallos de auditoría no abortan el flujo del pedido — decisión deliberada del design).

- `summarizeItemsDiff(array $oldItems, array $newItems): string` — produce algo como `"Cambió: 2 × Hamburguesa eliminada, 1 × Papas agregada"`. Comparar por `product_id` (o `product_name` si product_id null); enumerar added/removed/modified. Truncar a 500 chars.

**Acceptance:** llamar `logCreated($order, 1)` con un order persistido: la tabla `order_logs` recibe una fila con `kind='created'`, `order_id` y `order_id_snapshot` iguales, `user_name_snapshot` con el nombre del usuario.

---

### Paso 15 — Service `OrderPipelineService`

**Archivo:** `src/Service/OrderPipelineService.php`

Clase `final` en `App\Service`. `use LocatorAwareTrait`.

**Imports:** `App\Constants\OrderConstants`, `App\Model\Entity\Order`, `App\Service\OrderHistoryService`, `Cake\I18n\DateTime`, `Cake\Log\Log`, `Cake\ORM\Locator\LocatorAwareTrait`.

Constante pública `TRANSITIONS` (design §5.2). Constante privada `TYPE_DEPENDENT_RULES` (opcional documental — la lógica vive inline en `canTransition`).

Constructor:
```php
public function __construct(?OrderHistoryService $history = null)
{
    $this->history = $history ?? new OrderHistoryService();
}
```

**Métodos:**

- **`canTransition(Order $order, string $newStatus): bool`** (design §5.2):
  1. `$from = $order->status`. Si `!isset(TRANSITIONS[$from])` → false.
  2. Si `!in_array($newStatus, TRANSITIONS[$from], true)` → false.
  3. Regla específica: `STATUS_ON_ROUTE` solo aplica si `$order->isDomicilio()`.
  4. Regla específica: si `$from === STATUS_PREPARING && $newStatus === STATUS_DELIVERED && $order->isDomicilio()` → false (domicilio debe pasar por en_camino).
  5. true.

- **`advance(Order $order, string $newStatus, int $userId): array`**:
  1. Guard: si `$newStatus` es `STATUS_CANCELLED` o `STATUS_RECEIVED` (reactivación), retornar error "Usar OrderService::cancel() o reactivate() para esta transición." — esas transiciones tienen efectos de inventario y viven en `OrderService`.
  2. Si `!canTransition(...)` → retornar error humanizado usando `STATUS_LABELS`.
  3. `$previousStatus = $order->status; $order->status = $newStatus;`
  4. Si `$newStatus === STATUS_DELIVERED`, setear `$order->delivered_at = new DateTime();` (nunca limpiar si ya estaba seteado en otro flujo).
  5. `$ordersTable = $this->fetchTable('Orders');`
  6. Save; si falla → retornar errors flatten.
  7. `$this->history->logStateChanged($order, $userId, $previousStatus, $newStatus);`
  8. `Log::info('Order state changed: id={id} from={from} to={to}', [..., 'scope' => ['orders']]);`
  9. Retornar `['success' => true, 'order' => $order]`.

- **`nextValidStates(Order $order): array<int, string>`**:
  ```php
  return array_values(array_filter(
      self::TRANSITIONS[$order->status] ?? [],
      fn(string $s) => $this->canTransition($order, $s),
  ));
  ```

- Helper privado `flattenErrors(array $errors): array` — copia de `IngredientService::flattenErrors`.

**Acceptance:** phpstan limpio; tests cubren la matriz completa.

---

### Paso 16 — Service `OrderFilterService`

**Archivo:** `src/Service/OrderFilterService.php`

Clase `final`. Sin estado, sin dependencias.

**Método único:** `apply(SelectQuery $query, array $filters): SelectQuery` — copiar literalmente design §5.4. Cubre:

- `status`: `'visible'` (default, excluye cancelados), `'all'` (sin filtro), o uno de `OrderConstants::STATUSES`.
- `type`: `'all'` o uno de TYPES.
- `payment_method`: `'all'` o uno de PAYMENT_METHODS.
- `delivery_id`: aplica si > 0.
- `customer`: LIKE sobre `customer_name` o `customer_phone` (snapshots).
- `from`/`to`: rango inclusivo sobre `created`.
- `q`: si ctype_digit → match exacto por `id`; else → LIKE sobre name/phone.

> **Nota:** este service **NO** aplica el filtro por repartidor (eso lo hace `AppController::_scopeToRepartidor` antes de invocar `apply`). El filtro `delivery_id` en `apply` es para cuando un admin **elige** un repartidor en el dropdown del UI.

**Acceptance:** test unitario por cada rama del switch.

---

### Paso 17 — Service `OrderService` (el más grande)

**Archivo:** `src/Service/OrderService.php`

Clase `final` en `App\Service`. `use LocatorAwareTrait`.

**Imports:**
- `App\Constants\OrderConstants`
- `App\Model\Entity\Order`
- `App\Service\OrderHistoryService`
- `App\Service\RecipeService`
- `App\Service\IngredientService`
- `App\Service\CustomerService`
- `Cake\Datasource\ConnectionManager`
- `Cake\I18n\DateTime`
- `Cake\Log\Log`
- `Cake\ORM\Locator\LocatorAwareTrait`

**Constructor:**

```php
public function __construct(
    ?OrderHistoryService $history = null,
    ?RecipeService $recipes = null,
    ?IngredientService $ingredients = null,
    ?CustomerService $customers = null,
) {
    $this->history     = $history     ?? new OrderHistoryService();
    $this->recipes     = $recipes     ?? new RecipeService();
    $this->ingredients = $ingredients ?? new IngredientService();
    $this->customers   = $customers   ?? new CustomerService();
    // ReceivableService no existe aún — fase 5. Ver gotcha §6.5.
}
```

#### `create(array $data, int $userId): array`

**Implementar exactamente el algoritmo del design §11.1.** Pasos clave:

1. **Pre-validación (`validateCreateInput`)** — privada, sin tocar DB:
   - `type` ∈ `TYPES`.
   - `payment_method` ∈ `PAYMENT_METHODS`.
   - `items` array no vacío y `count <= MAX_ITEMS_PER_ORDER`.
   - Cada item con `product_id` (int>0) y `quantity` (numeric>0).
   - Si `type === TYPE_DOMICILIO`: `delivery_id` int>0, `customer_address` no vacío, `shipping_cost` numeric>=0.
   - Si `type === TYPE_LOCAL`: forzar `shipping_cost = 0` y `delivery_id = null` (sanitización).
   - Si `payment_method === PAYMENT_CREDIT`: `customer_phone` no vacío (necesario para CxC).
   - Retornar `['success' => false, 'errors' => [...]]` si falla.

2. **Pre-load productos** (validación de existencia + obtener snapshots):
   ```php
   $productIds = array_column($data['items'], 'product_id');
   $products = $this->fetchTable('Products')->find()
       ->where(['Products.id IN' => $productIds])
       ->all()->indexBy('id')->toArray();
   ```
   Para cada `$pid`: si no está en `$products` → error "Producto #{$pid} no encontrado". Si `!$products[$pid]->is_available` → error "Producto '{name}' no está disponible".

3. **Transacción** (`ConnectionManager::get('default')->transactional(...)`):
   - a. Resolver/crear cliente si crédito y `customer_id` ausente:
     ```php
     if ($data['payment_method'] === OrderConstants::PAYMENT_CREDIT && empty($data['customer_id'])) {
         $cust = $this->customers->findOrCreateByPhone([
             'phone'   => $data['customer_phone'],
             'name'    => $data['customer_name'] ?? null,
             'address' => $data['customer_address'] ?? null,
         ]);
         $customerId = $cust->id;
     } else {
         $customerId = !empty($data['customer_id']) ? (int)$data['customer_id'] : null;
     }
     ```
   - b. Construir Order entity con snapshots, `user_id`, `status=STATUS_RECEIVED`.
   - c. Construir OrderItems con `product_name`/`price_at_sale` **desde DB** (no del POST — anti-tampering):
     ```php
     $items = [];
     $subtotal = 0.0;
     foreach ($data['items'] as $line) {
         $prod = $products[(int)$line['product_id']];
         $qty = (float)$line['quantity'];
         $price = (float)$prod->price;
         $lineSubtotal = round($price * $qty, OrderConstants::MONEY_DECIMALS);
         $subtotal += $lineSubtotal;
         $items[] = $orderItemsTable->newEntity([
             'product_id'    => $prod->id,
             'product_name'  => $prod->name,
             'quantity'      => number_format($qty, OrderConstants::QUANTITY_DECIMALS, '.', ''),
             'price_at_sale' => number_format($price, OrderConstants::MONEY_DECIMALS, '.', ''),
             'line_subtotal' => number_format($lineSubtotal, OrderConstants::MONEY_DECIMALS, '.', ''),
             'notes'         => $line['notes'] ?? null,
         ]);
     }
     $order->subtotal = number_format($subtotal, OrderConstants::MONEY_DECIMALS, '.', '');
     $order->total    = number_format($subtotal + (float)$order->shipping_cost, OrderConstants::MONEY_DECIMALS, '.', '');
     $order->order_items = $items;
     ```
   - d. `$ordersTable->save($order, ['associated' => ['OrderItems']])`. Si falla → set resultBox con errors flatten, return false (rollback).
   - e. **Descontar stock vía recipes:**
     ```php
     foreach ($data['items'] as $line) {
         $plan = $this->recipes->buildDecrementPlan((int)$line['product_id'], (int)$line['quantity']);
         if (empty($plan)) {
             Log::info('Product without recipe sold: id={pid}', ['pid' => $line['product_id'], 'scope' => ['orders']]);
             continue;
         }
         foreach ($plan as $step) {
             $ing = $this->fetchTable('Ingredients')->get((int)$step['ingredient_id']);
             $r = $this->ingredients->adjustStock($ing, '-' . $step['quantity'], "Pedido #{$order->id}");
             if (!$r['success']) {
                 $resultBox = ['success' => false, 'errors' => $r['errors']];
                 return false; // rollback
             }
         }
     }
     ```
     > **Importante:** `buildDecrementPlan` recibe `int $unitsSold`. Si el producto se vende fraccional (qty 0.5), por ahora hacemos `(int)$line['quantity']` lo que truncaría a 0 → plan vacío. Aceptable para Fase 1 (mayoría enteros). Cuando se habilite fraccional, evolucionar `buildDecrementPlan` a aceptar float (out of scope acá).
   - f. **CxC (TODO documentado):**
     ```php
     if ($order->isCredit()) {
         Log::warning('CxC pending: order #{id} credit payment, ReceivableService not wired yet', [
             'id' => $order->id, 'scope' => ['orders', 'receivables_todo'],
         ]);
         // TODO Fase 5: $this->receivables->createFromOrder($order, $customerId).
     }
     ```
   - g. `$this->history->logCreated($order, $userId);`
   - h. `Log::info('Order created: id={id} type={t} method={m} total={tot}', [...]);`
   - i. `$resultBox = ['success' => true, 'order' => $order]; return true;`

4. Tras la transacción, re-fetch el order con `contain(['OrderItems' => ['Products'], 'Customer', 'Delivery'])` y retornar.

#### `update(Order $order, array $data, int $userId): array`

Algoritmo del design §11.4:

1. Guard: `if (!$order->isEditable())` → error.
2. Snapshot del estado actual (array) y de items actuales (clone).
3. Transacción:
   - a. Restaurar stock viejo (loop sobre `$order->order_items`, `buildDecrementPlan`, `adjustStock` con signo `+`).
   - b. `$orderItemsTable->deleteAll(['order_id' => $order->id])`.
   - c. Patch order entity con `$data` (excluir `id`, `status`, `user_id`, `created`).
   - d. Reconstruir nuevos items (mismo flujo que create paso 3c).
   - e. Recalcular subtotal/total.
   - f. **Transiciones de payment_method** (TODO Fase 5 — por ahora solo loguear warning):
     - No-crédito → crédito: log warning "CxC pending creation for order #{id}".
     - Crédito → no-crédito: log warning "CxC pending cancellation for order #{id}".
     - Crédito → crédito con total cambiado: log warning "CxC pending adjustment".
   - g. Save con associated OrderItems. Si falla → rollback.
   - h. Descontar stock nuevo (mismo loop que create paso 3e).
   - i. Auditoría:
     ```php
     $this->history->logFieldChanges($order, $userId, $snapshot);
     $this->history->logItemsReplaced($order, $userId, $oldItems, $order->order_items);
     ```
   - j. `Log::info` + return true.

#### `cancel(Order $order, int $userId, ?string $reason = null): array`

Design §11.2:

1. Guard: `if (!$order->isCancellable())` → error.
2. Transacción:
   - a. Restaurar stock (loop items con signo `+`, reason `"Cancelación pedido #{id}"`).
   - b. Si crédito: TODO Fase 5 (log warning).
   - c. `$order->status = STATUS_CANCELLED; $order->cancelled_at = new DateTime(); $order->cancelled_by = $userId;`
   - d. Save; si falla → rollback.
   - e. `$this->history->logCancelled($order, $userId, $reason ?? '');`
   - f. Log + return true.

#### `reactivate(Order $order, int $userId): array`

Design §11.3:

1. Guard: `if ($order->status !== STATUS_CANCELLED)` → error.
2. Transacción:
   - a. Re-descontar stock (loop items con signo `-`). Si `adjustStock` falla por insuficiencia → rollback con error específico:
     ```php
     return ['success' => false, 'errors' => [sprintf(
         'No se puede reactivar: stock insuficiente para %s. Registrá un ajuste primero.',
         $ing->name,
     )]];
     ```
   - b. `$order->status = STATUS_RECEIVED; $order->cancelled_at = null; $order->cancelled_by = null;` (**Decisión gotcha §6.5:** se limpia `cancelled_at` al reactivar — no preservamos historia ahí, queda en `order_logs`.)
   - c. Si crédito: TODO Fase 5 (recrear CxC).
   - d. Save; si falla → rollback.
   - e. `$this->history->logReactivated($order, $userId);`

#### `delete(Order $order, int $userId): array`

Algoritmo design §11 + Fase 5 stubs:

1. TODO Fase 5: chequear abonos vía `ReceivableService::hasPayments($order->id)`. Por ahora, permitir.
2. Transacción:
   - a. Si `!$order->isCancelled()`: restaurar stock (loop items signo `+`, reason `"Eliminación pedido #{id}"`).
   - b. **`$this->history->logDeleted($order, $userId);`** — ANTES del delete físico. El log queda con `order_id` válido; tras el delete, la FK lo pone NULL, pero `order_id_snapshot` lo preserva.
   - c. `$ordersTable->delete($order);` — esto cascadea `order_items` (dependent=true). `order_logs` NO cascadea (dependent=false en ORM + ON DELETE SET NULL en DB).
   - d. `Log::warning('Order deleted: id={id}', [...]);`
   - e. Return true.

**Helper privado `flattenErrors`:** copia de patrón existente.

**Acceptance:** phpstan limpio nivel 8 sobre el archivo (puede requerir docblocks `@var` en algunas variables para tipar).

---

### Paso 18 — Controller `OrdersController`

**Archivo:** `src/Controller/OrdersController.php`

Extiende `AppController`.

**Imports clave:**
- `App\Constants\OrderConstants`
- `App\Service\OrderService`
- `App\Service\OrderPipelineService`
- `App\Service\OrderFilterService`
- `Cake\Datasource\Exception\RecordNotFoundException`
- `Cake\Http\Exception\ForbiddenException`
- `Cake\Http\Exception\BadRequestException`
- `Cake\ORM\Query\SelectQuery`

**Paginación:** 15/página, default order `Orders.created DESC, Orders.id DESC`, sortable `created, id, total, status`.

**Properties:**
```php
private OrderService $orderService;
private OrderPipelineService $pipeline;
private OrderFilterService $filters;
```

**`initialize()`:** parent + instanciar los 3 services.

**`_actionToPermission` override** (design §6.1):
```php
return match ($action) {
    'index', 'view', 'ticket' => 'view',
    'add' => 'create',
    'edit', 'advance', 'reactivate', 'cancel' => 'edit',
    'delete' => 'delete',
    default => parent::_actionToPermission($action),
};
```

**Acciones:**

- **`index()`:**
  1. `$filters = $this->_currentFilters();`
  2. `$query = $this->_buildIndexQuery($filters);`
  3. `$orders = $this->paginate($query);`
  4. `$kpis = $this->_computeKpis();` (4 métricas según si es repartidor o no — design §9.1).
  5. `$deliveries = $this->fetchTable('Deliveries')->find('list')->toArray();` (para el filtro dropdown).
  6. `$this->set(compact('orders', 'filters', 'kpis', 'deliveries'));`

- **`view(int $id)`:**
  1. Try get con `contain(['OrderItems' => ['Products'], 'Customer', 'Delivery', 'User', 'CancelledByUser'])`.
  2. `$this->_enforceRepartidorAccess($order->delivery_id);`
  3. `$logs = $this->fetchTable('OrderLogs')->find('forOrder', order_id: $id)->limit(5)->toArray();`
  4. `$nextStates = $this->pipeline->nextValidStates($order);`
  5. set y render.

- **`add()`:**
  1. **Guard repartidor:** `if (!$this->_canCreateOrders()) throw new ForbiddenException(...);` (un repartidor no crea pedidos).
  2. `$order = $this->fetchTable('Orders')->newEmptyEntity();`
  3. Si POST: extraer `$userId`, llamar `$this->orderService->create($data, $userId)`. Si success → flash + redirect. Si error → flash errors, re-render con datos POST.
  4. Cargar listas para selects: productos activos (`find list` filtrando `is_available=1`), repartidores activos, clientes (lista corta para sugerencias o autocomplete async).
  5. set y render.

- **`edit(int $id)`:**
  1. Get con `contain(['OrderItems' => ['Products']])`.
  2. `$this->_enforceRepartidorAccess($order->delivery_id);`
  3. Si `!$order->isEditable()`: flash + redirect a `view`.
  4. Si POST: `$this->orderService->update($order, $data, $userId)`. Same pattern.

- **`delete(int $id)`:**
  1. `$this->request->allowMethod(['post', 'delete']);`
  2. Get + `_enforceRepartidorAccess`.
  3. `$this->orderService->delete($order, $userId);`
  4. Flash + redirect index.

- **`cancel(int $id)`:**
  1. `$this->request->allowMethod('post');`
  2. Get + guard repartidor.
  3. `$reason = $this->request->getData('reason');`
  4. `$result = $this->orderService->cancel($order, $userId, $reason);`
  5. Flash success/error + redirect referer o `view`.

- **`reactivate(int $id)`:** similar a `cancel`.

- **`advance(int $id)`:**
  1. `$this->request->allowMethod('post');`
  2. Get + guard.
  3. `$to = (string)$this->request->getData('to_status');`
  4. `$result = $this->pipeline->advance($order, $to, $userId);`
  5. Flash + redirect (referer o view).

- **`ticket(int $id)`:**
  1. Get con full contain.
  2. `_enforceRepartidorAccess`.
  3. `$this->viewBuilder()->setLayout('ticket');`
  4. `$this->set('order', $order);`

**Helpers privados:**

- **`_buildIndexQuery(array $filters): SelectQuery`:**
  ```php
  $query = $this->fetchTable('Orders')->find()
      ->contain(['Customer', 'Delivery', 'User']);
  $this->_scopeToRepartidor($query); // herencia de AppController
  return $this->filters->apply($query, $filters);
  ```

- **`_currentFilters(): array`** — extraer y normalizar query params (`q`, `status`, `type`, `payment_method`, `delivery_id`, `from`, `to`). Si user es repartidor, **ignorar** `delivery_id` del input (su scope ya está fijo).

- **`_computeKpis(): array`** — 4 queries (today + status filter). Fase 1: cómputo en cada load (gotcha §6 del prompt: "compute" no cache). Optimización futura: materialized view o cache.

- **`_canCreateOrders(): bool`** — true si user no es repartidor (delivery_id null en identity).

- **`_enforceRepartidorAccess(?int $orderDeliveryId): void`** — hereda de AppController paso 20.

**Acceptance:** todas las acciones devuelven 200/302; sin warnings de cs-check.

---

### Paso 19 — Controller `OrderLogsController`

**Archivo:** `src/Controller/OrderLogsController.php`

Extiende `AppController`. **Particularidades:**

- Override `$controllerModuleMap = ['OrderLogs' => 'audit'];` (ya está en AppController paso 20, pero queda explicito aquí para legibilidad).
- Override `_actionToPermission`: siempre `'view'` (el módulo solo expone lectura).
- No exponer `add`/`edit`/`delete` (404 automático).

**Acciones:**

- **`index()`:**
  1. Filters: `order_id`, `user_id`, `kind`, `from`, `to`.
  2. Build query con `contain(['Users'])`, aplicar filtros con `where(...)`.
  3. `$logs = $this->paginate($query);`
  4. Catálogo de kinds y usuarios para los selects.

- **`view(int $id)`:** simple `get` + render.

**Acceptance:** no-admin → 403; admin → 200 con paginación.

---

### Paso 20 — `AppController` modifications

**Archivo:** `src/Controller/AppController.php`

**A.** Agregar al `$controllerModuleMap` tras `'Adjustments' => 'adjustments',`:
```php
'Orders'    => 'orders',
'OrderLogs' => 'audit',
```

**B.** Agregar imports en el use-block:
```php
use Cake\ORM\Query\SelectQuery;
```

**C.** Agregar dos helpers protected al final de la clase:

```php
/**
 * Si el currentUser está vinculado a un repartidor (delivery_id no nulo),
 * restringe la query al alias dado a sus propios pedidos. Regla §21 acceso 4.
 *
 * Early return si el user no es repartidor → es seguro llamarlo siempre.
 */
protected function _scopeToRepartidor(SelectQuery $query, string $alias = 'Orders'): SelectQuery
{
    $deliveryId = $this->_currentDeliveryId();
    if ($deliveryId === null) {
        return $query; // not a repartidor — no scoping needed
    }
    return $query->where(["{$alias}.delivery_id" => $deliveryId]);
}

/**
 * Guard para vistas puntuales (view/edit/cancel/...). Si el user es repartidor
 * y el delivery_id del pedido no coincide con el suyo, 403.
 */
protected function _enforceRepartidorAccess(?int $orderDeliveryId): void
{
    $deliveryId = $this->_currentDeliveryId();
    if ($deliveryId !== null && (int)$orderDeliveryId !== $deliveryId) {
        throw new ForbiddenException('No tenés acceso a este pedido.');
    }
}

private function _currentDeliveryId(): ?int
{
    $identity = $this->Authentication->getIdentity();
    if ($identity === null) {
        return null;
    }
    $delivery = $identity->get('delivery_id');
    return is_numeric($delivery) ? (int)$delivery : null;
}
```

**Acceptance:** cs-check limpio; código de Orders pasa los guards sin errores.

---

### Paso 21 — `AuthorizationService` modifications

**Archivo:** `src/Service/AuthorizationService.php`

**A.** Agregar al `MODULES` tras `'adjustments' => 'Ajustes de Inventario',`:
```php
'orders' => 'Pedidos',
'audit'  => 'Auditoría',
```

**B.** Modificar `isAllowed`. Cambiar la línea hardcoded `if ($module === 'roles') return false;` (línea ~54) por:
```php
if (in_array($module, ['roles', 'audit'], true)) {
    return false;
}
```

Esto garantiza que **incluso si** el placeholder all-zero se cambiara accidentalmente a 1, los no-admin seguirían sin acceso a auditoría (defensa en profundidad). El admin pasa por el bypass de línea 44 sin tocar este chequeo.

**Acceptance:** test unitario sobre `isAllowed`: rol no-admin con `audit.view=1` → false; admin → true.

---

### Paso 22 — Sidebar

**Archivo:** `src/View/Helper/SidebarHelper.php`

Insertar dos items en `$items`:

**A.** Item Pedidos — al inicio del array (antes de `products`) porque es el corazón operativo:
```php
[
    'module' => 'orders',
    'label' => 'Pedidos',
    'icon' => 'bi-bag',
    'url' => ['controller' => 'Orders', 'action' => 'index'],
],
```

**B.** Item Auditoría — al final del array:
```php
[
    'module' => 'audit',
    'label' => 'Auditoría',
    'icon' => 'bi-clipboard-data',
    'url' => ['controller' => 'OrderLogs', 'action' => 'index'],
],
```

> El helper filtra por `permissions[module].view`. Admin verá ambos. No-admin con `orders.view=1` verá Pedidos pero no Auditoría (placeholder all-zero + hardcode).

**Acceptance:** loguearse como Administrador → ver "Pedidos" al tope y "Auditoría" al final.

---

### Paso 23 — Rutas

**Archivo:** `config/routes.php`

Agregar **antes** de `$builder->fallbacks();`:

```php
// Pedidos: acciones de pipeline y soporte.
$builder->connect(
    '/orders/advance/{id}',
    ['controller' => 'Orders', 'action' => 'advance'],
    ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST'],
);
$builder->connect(
    '/orders/cancel/{id}',
    ['controller' => 'Orders', 'action' => 'cancel'],
    ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST'],
);
$builder->connect(
    '/orders/reactivate/{id}',
    ['controller' => 'Orders', 'action' => 'reactivate'],
    ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST'],
);
$builder->connect(
    '/orders/ticket/{id}',
    ['controller' => 'Orders', 'action' => 'ticket'],
    ['id' => '\d+', 'pass' => ['id'], '_method' => 'GET'],
);

// Auditoría: listado global y filtrado por pedido.
$builder->connect(
    '/audit',
    ['controller' => 'OrderLogs', 'action' => 'index'],
);
$builder->connect(
    '/audit/order/{id}',
    ['controller' => 'OrderLogs', 'action' => 'index'],
    ['id' => '\d+', 'pass' => ['id']],
);
```

> **Nota sobre `/audit/order/{id}`:** la ruta pasa `$id` como primer argumento posicional a `OrderLogsController::index($orderId = null)`. El controller, si recibe el arg, lo usa para filtrar: `$filters['order_id'] = $orderId;`. Alternativa: dejarlo como query string `/audit?order_id=N`. Optamos por path-positional porque queda más limpio en breadcrumbs y se mapea naturalmente al `forOrder` finder. La firma se ajusta así:
> ```php
> public function index(?int $orderId = null): void
> ```

CRUD estándar de Orders (`/orders`, `/orders/add`, `/orders/view/N`, `/orders/edit/N`, `/orders/delete/N`) lo cubre `fallbacks()`.

**Acceptance:** `php bin/cake.php routes | grep -E "(orders|audit)"` lista al menos 11 entradas (5 custom + 5 CRUD fallback + audit base).

---

### Paso 24 — Templates: `Orders/index.php`

Design §9.1. Estructura completa con `dr-page-header`, KPI strip, filtros, tabla con badges `status-*` y acciones por estado.

**Header:**
- `h1.dr-page-title` "Pedidos".
- Botón único `button-primary` "Nuevo pedido" solo si `$userPermissions['orders']['create']` y `!$isRepartidor` (helper de identidad — exponer `isRepartidor` desde AppController vía `set()`).

**KPI strip:** 4 `stat-card` en grid de 4 columnas (`md+`); en mobile colapsa a 2x2. Valores precomputados en `$kpis`. Para repartidor, cards distintos (design §9.1 segunda tabla).

**Filtros (form GET, controles 40px):**
- Input `q` (search general). 280px.
- Select `status` con opciones `visible` (default), `all`, + cada `STATUS_LABELS`. 160px.
- Select `type`: `all` + `TYPE_LABELS`. 140px.
- Select `payment_method`: `all` + `PAYMENT_LABELS`. 160px.
- Select `delivery_id`: `all` + lista (oculto si `$isRepartidor`).
- Input `from` (date), `to` (date).
- Botón `btn-secondary` "Filtrar"; link "Limpiar" condicional.

**Tabla:**
- Columnas según design §9.1.
- Status: `<?= $this->element('order_status_badge', ['order' => $order]) ?>` — usa familia `status-*`, NO `badge-*`.
- Acciones: forms postLink para `advance`/`cancel`/`reactivate`/`delete` con `confirm`. Botones-ícono `bi-eye` (view), `bi-printer` (ticket), `bi-arrow-right` (advance — abre dropdown si múltiples opciones), `bi-x` (cancel), `bi-arrow-clockwise` (reactivate), `bi-trash` (delete).

**Empty state:** según `$filters` vacíos o no.

**Footer:** `<?= $this->element('pagination') ?>`.

**Acceptance:** render sin warnings; columnas alineadas; chips de status con colores correctos.

---

### Paso 25 — Template `Orders/add.php`

Design §9.2. Form complejo de 5 secciones; reusar `<template id="order-line-tpl">` para clonar líneas con JS vanilla. Sin frameworks frontend.

**Estructura:**

1. **Cliente:** input phone, name, address (address solo visible si domicilio — controlable por JS hide/show, fallback server-side visible).
2. **Tipo:** radios visuales Local/Domicilio; bloque condicional con `delivery_id` (select) + `shipping_cost` (input).
3. **Productos:** card con header "+ Agregar línea" y lista de líneas. Cada línea = `select product` + `input qty` + `input notes (opcional, collapsible)` + botón `x`. Sin JS, render server-side con 1 línea inicial. Con JS, botón "+ Agregar" clona `<template>`.
4. **Método de pago:** radios visuales con `PAYMENT_LABELS`; alerta amarilla soft si se elige Crédito.
5. **Resumen:** subtotal/envío/total (calculados con JS en vivo; server-side recomputa en submit). Textarea `notes`. Botones `Cancelar` (tertiary) + `Guardar pedido` (primary único).

**JS inline** al final del template (sin build step):
- `addLine()` clona template.
- `removeLine(btn)` quita fila (preservar mínimo 1).
- `recomputeTotals()` itera líneas, calcula subtotal de cada (qty × price del `data-price` del option), suma + shipping_cost.

**CSRF:** `$this->Form->create()` lo inyecta automáticamente.

**Acceptance:** submit válido crea el pedido; submit con errores re-renderiza preservando datos (incluidas las líneas con sus valores).

---

### Paso 26 — Template `Orders/edit.php`

Misma estructura que `add.php`. Diferencias:

- Alerta amarilla soft al tope: "Editar un pedido restaurará los insumos actuales y descontará los nuevos."
- Botón "Guardar cambios" (primary).
- Si el pedido no es `isEditable`, el controller ya redirigió — no es necesario duplicar el guard en el template.
- `payment_method` deshabilitado si TODO Fase 5 detectara abonos (por ahora no).

**Acceptance:** edit preserva todos los datos; transición type local→domicilio funciona.

---

### Paso 27 — Template `Orders/view.php`

Design §9.3. Estructura:

- Header con `#id`, status chip (`order_status_badge` element), autor, fecha.
- Barra de acciones: por cada estado en `$nextStates`, un form postLink a `/orders/advance/{id}` con `to_status` hidden. Botones de cancel/reactivate/delete según `isCancellable`, `isCancelled`, y permisos.
- Card "Cliente" con `getCustomerName`, `getCustomerPhone`, link al cliente si `customer_id`.
- Card "Entrega" (solo si domicilio): tipo, dirección, repartidor, costo envío.
- Tabla items con subtotales y total.
- Pago + notas.
- Sección "Historial reciente": loop sobre `$logs` (últimos 5), formato timeline con icono + descripción + autor + fecha. Link "Ver todo" → `/audit/order/{id}` (visible solo si admin) o anchor local.

**Acceptance:** todas las cards renderizan; los nextStates muestran el botón correcto por estado/tipo.

---

### Paso 28 — Template `Orders/ticket.php`

Design §9.5. Layout `ticket` (paso 29).

Contenido en pseudocódigo:
```
DAVI RAPID
Calle X, Ciudad
NIT: 900.123.456-7
Tel: 555-1234
═══════════════════════
PEDIDO #{id}   {created dd/MM HH:mm}
═══════════════════════
Cliente: {name}
Tel: {phone}
Dirección: {address}
Tipo: {type}
Repartidor: {delivery.name}
─────────────────────
{loop items}
  {qty} × {name}   {line_subtotal}
{/loop}
─────────────────────
Subtotal:    {subtotal}
Envío:       {shipping_cost}
TOTAL:       {total}
─────────────────────
Pago: {payment_label}

{if notes} Notas: {notes} {/if}
═══════════════════════
¡Gracias por su compra!
```

Auto-print al cargar (excepto si `?autoprint=0`):
```html
<script>
  if (new URL(window.location).searchParams.get('autoprint') !== '0') {
    window.addEventListener('load', () => setTimeout(() => window.print(), 300));
  }
</script>
```

Datos del negocio hardcoded con `// TODO Fase 6: leer de tabla business_info o config/app.php`.

**Acceptance:** abrir `/orders/ticket/N` dispara el diálogo de impresión del navegador.

---

### Paso 29 — Layout `templates/layout/ticket.php`

Layout minimal sin sidebar/topbar. Max-width 280px. Monospace via CSS inline o utility class. `@media print { .no-print { display: none; } }`.

```php
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title><?= h($this->fetch('title')) ?: 'Ticket' ?></title>
  <style>
    body { font-family: 'JetBrains Mono', monospace; font-size: 12px; max-width: 280px; margin: 0 auto; padding: 8px; }
    .ticket-section { margin: 8px 0; }
    .ticket-sep { border-top: 1px dashed #000; margin: 4px 0; }
    @media print {
      .no-print { display: none !important; }
      body { padding: 0; }
    }
  </style>
</head>
<body>
  <?= $this->Flash->render() ?>
  <?= $this->fetch('content') ?>
  <div class="no-print" style="text-align:center;margin-top:16px;">
    <button onclick="window.print()" class="btn btn-secondary">Imprimir</button>
    <a href="<?= $this->Url->build(['controller' => 'Orders', 'action' => 'view', $order->id ?? 0]) ?>" class="btn btn-light">Volver</a>
  </div>
</body>
</html>
```

**Acceptance:** layout responde en `/orders/ticket/N` sin chrome.

---

### Paso 30 — Template `OrderLogs/index.php`

Design §9.7. Tabla compacta: Fecha, #Pedido, Autor, Tipo, Descripción.

- Si `$log->isOrphan()`: mostrar `#{order_id_snapshot} (eliminado)` en gris, sin link.
- Tipo: badge soft según `kind` (mapping libre — `created`=success, `cancelled`=warning, `deleted`=danger, etc.). Icono según `KIND_ICONS`.
- Filtros: `order_id` (input num), `user_id` (select), `kind` (select), `from`/`to` (date).
- Sin acciones (auditoría inmutable).
- Paginación 25/página.

**Acceptance:** render limpio; filtros funcionales.

---

### Paso 31 — Template `OrderLogs/view.php`

Vista simple opcional: tarjeta con icono grande del kind, descripción completa, link al pedido (si no orphan), autor.

---

### Paso 32 — Element `order_status_badge.php`

**Archivo:** `templates/element/order_status_badge.php`

Acepta `$order` o `$status` (string). Renderiza:
```php
<?php
use App\Constants\OrderConstants;
$status = isset($order) ? $order->status : ($status ?? '');
$class = OrderConstants::STATUS_CSS_CLASS[$status] ?? 'status-pending';
$label = OrderConstants::STATUS_LABELS[$status] ?? $status;
?>
<span class="<?= h($class) ?>"><?= h($label) ?></span>
```

**Acceptance:** reusable desde `index`, `view`, futuros dashboards.

---

### Paso 33 — Fixtures

**Archivo:** `tests/Fixture/OrdersFixture.php`

4 filas mínimas:
1. id=1, local entregado en efectivo, `created='2026-05-23 12:00:00'`, `delivered_at='2026-05-23 12:15:00'`.
2. id=2, domicilio preparando crédito, `customer_id=1`, `delivery_id=1`.
3. id=3, cancelado, `cancelled_at='2026-05-23 13:00:00'`, `cancelled_by=1`.
4. id=4, recibido, local, efectivo.

**Archivo:** `tests/Fixture/OrderItemsFixture.php` — 6-8 filas distribuidas entre los 4 orders (algunos con 1 item, otros con 2-3 para tests de `getItemsSummary`).

**Archivo:** `tests/Fixture/OrderLogsFixture.php` — 5 filas:
- 4 `created` (uno por order) con `user_id=1`.
- 1 `cancelled` para order 3.
- 1 huérfano: `order_id=null, order_id_snapshot=999, kind='deleted'` (para test `isOrphan`).

**Acceptance:** `phpunit --list-tests tests/TestCase/Model/Table/OrdersTableTest.php` no falla por fixture missing.

---

### Paso 34-43 — Tests (ver §4 Test plan)

Detalle en sección §4 más abajo.

---

### Paso 44 — Aplicar y volcar schema

```bash
php bin/cake.php migrations migrate
php bin/cake.php migrations dump
```

**Acceptance:** ambos comandos exitosos; `schema-dump-default.lock` actualizado con `orders`, `order_items`, `order_logs`.

---

## 3. Routes to register (resumen)

| Verbo | URL | Controller::action | Constraints |
|---|---|---|---|
| GET | `/orders` | Orders::index | — (fallback) |
| GET | `/orders/view/{id}` | Orders::view | — (fallback) |
| GET/POST | `/orders/add` | Orders::add | — (fallback) |
| GET/POST | `/orders/edit/{id}` | Orders::edit | — (fallback) |
| POST | `/orders/delete/{id}` | Orders::delete | — (fallback) |
| **POST** | **`/orders/advance/{id}`** | **Orders::advance** | `_method=POST` |
| **POST** | **`/orders/cancel/{id}`** | **Orders::cancel** | `_method=POST` |
| **POST** | **`/orders/reactivate/{id}`** | **Orders::reactivate** | `_method=POST` |
| **GET** | **`/orders/ticket/{id}`** | **Orders::ticket** | `_method=GET` |
| **GET** | **`/audit`** | **OrderLogs::index** | — |
| **GET** | **`/audit/order/{id}`** | **OrderLogs::index** | `id=\d+, pass=[id]` |

Líneas en bold = registro explícito requerido (antes del fallback).

---

## 4. Test plan

> El proyecto opta-out de tests automatizados (memoria del usuario `feedback_no_tests.md`).
> **El prompt actual SOBRESCRIBE esa preferencia y exige tests.** Implementarlos todos.

### Archivos a crear

| Tipo | Path |
|---|---|
| Fixture | `tests/Fixture/OrdersFixture.php` (4 filas) |
| Fixture | `tests/Fixture/OrderItemsFixture.php` (6-8 filas) |
| Fixture | `tests/Fixture/OrderLogsFixture.php` (5+1 huérfano) |
| Entity test | `tests/TestCase/Model/Entity/OrderTest.php` |
| Entity test | `tests/TestCase/Model/Entity/OrderItemTest.php` |
| Entity test | `tests/TestCase/Model/Entity/OrderLogTest.php` |
| Table test | `tests/TestCase/Model/Table/OrdersTableTest.php` |
| Table test | `tests/TestCase/Model/Table/OrderItemsTableTest.php` |
| Table test | `tests/TestCase/Model/Table/OrderLogsTableTest.php` |
| Service test | `tests/TestCase/Service/OrderServiceTest.php` |
| Service test | `tests/TestCase/Service/OrderPipelineServiceTest.php` |
| Service test | `tests/TestCase/Service/OrderHistoryServiceTest.php` |
| Service test | `tests/TestCase/Service/OrderFilterServiceTest.php` |
| Controller test | `tests/TestCase/Controller/OrdersControllerTest.php` |
| Controller test | `tests/TestCase/Controller/OrderLogsControllerTest.php` |

### Cobertura por archivo

**`OrderTest` (Entity):**
- `testIsCancelledReturnsTrueWhenStatusCancelled`.
- `testIsEditableForReceivedAndPreparing`, `testIsNotEditableForOnRoute`, `testIsNotEditableForDeliveredOrCancelled`.
- `testIsCancellableForReceivedPreparingOnRoute`, `testIsNotCancellableForDelivered`.
- `testCanTransitionToValidNext`, `testCanTransitionToInvalidReturnsFalse`.
- `testCanTransitionOnRouteOnlyForDomicilio`.
- `testCanTransitionPreparingToDeliveredOnlyForLocal`.
- `testGetCustomerNamePrefersSnapshot`, `testGetCustomerNameFallsBackToRelation`, `testGetCustomerNameReturnsPlaceholderIfBothNull`.
- `testGetItemsSummaryWithOneItem`, `testGetItemsSummaryWithMultiple` (formato `"2 × X (+2 más)"`), `testGetItemsSummaryEmpty`.
- `testGetStatusCssClassMapsCorrectly`.
- `testVirtualDisplayStatusAccessible`, `testVirtualIsCreditAccessible`.

**`OrderItemTest`:**
- `testGetLineSubtotalRoundsCorrectly` — `qty=2.5 price=10000 → 25000.00`.
- `testGetFormattedQuantityStripsTrailingZeros` — `'2.000' → '2'`, `'2.500' → '2.5'`.
- `testVirtualComputedSubtotalAccessible`.

**`OrderLogTest`:**
- `testIsOrphanWhenOrderIdNull`.
- `testIsNotOrphanWhenOrderIdSet`.
- `testGetKindLabelMapsCorrectly`.
- `testGetIconMapsCorrectly`.
- `testGetFormattedDateFormats`.

**`OrdersTableTest`:** fixtures `Orders, OrderItems, Customers, Deliveries, Users, Roles, Products`.
- `testValidationRejectsInvalidType`.
- `testValidationRejectsInvalidStatus`.
- `testValidationRejectsNegativeShippingCost`.
- `testRulesAllowsNullCustomerId`, `testRulesAllowsNullDeliveryId`, `testRulesAllowsNullUserId`.
- `testFindVisibleExcludesCancelled`.
- `testFindByStateSingleString`, `testFindByStateMultipleArray`.
- `testFindForRepartidorFilters`.
- `testFindForRepartidorWithoutIdReturnsEmpty`.
- `testFindInDateRangeInclusiveBounds`.
- `testFindWithItemsHydratesItems`.
- `testFindActiveTodayFiltersByDateAndStatus`.
- `testHasManyOrderItemsDependentCascade` — save order, delete order, verify items gone.
- `testHasManyOrderLogsNotDependent` — save order with log, delete order, verify log survives.
- `testTimestampSetsCreatedAndModified`.

**`OrderItemsTableTest`:**
- `testValidationRejectsZeroQuantity`.
- `testValidationRejectsNegativePrice`.
- `testValidationRejectsTooLongNotes`.
- `testFindTopProductsExcludesCancelledOrders`.
- `testFindTopProductsOrdersByUnits`.

**`OrderLogsTableTest`:**
- `testValidationRejectsInvalidKind`.
- `testValidationRequiresOrderIdSnapshot`.
- `testValidationRejectsTooLongDescription`.
- `testFindForOrderFiltersBySnapshot`.
- `testFindChronologicalOrdersByCreatedDescIdDesc`.
- `testTimestampSetsOnlyCreated` — guardar 2 veces no falla por columna `modified` inexistente.

**`OrderServiceTest`:** fixtures completos.
- `testCreateLocalWithoutRecipeSucceeds` — 1 fila, 0 movimientos stock, log `created`.
- `testCreateLocalWithRecipeMovesStock`.
- `testCreateDomicilioCreditAutoCreatesCustomer`.
- `testCreateDomicilioWithoutDeliveryIdFails`.
- `testCreateDomicilioWithoutAddressFails`.
- `testCreateCreditWithoutPhoneFails`.
- `testCreateWithUnknownProductFails`.
- `testCreateWithInactiveProductFails`.
- `testCreateWithEmptyItemsFails`.
- `testCreateWithTooManyItemsFails` — `> MAX_ITEMS_PER_ORDER`.
- `testCreateInsufficientStockRollsBack` — verifica que no quedan rows ni stock movido.
- `testCreatePersistsSnapshotsFromProductsNotFromPOST` — POST con `price` falso debe ser ignorado.
- `testCreateForcesShippingZeroForLocal` — POST con `shipping_cost=5000` y `type=local` → persiste `0.00`.
- `testCreateForcesDeliveryNullForLocal`.
- `testUpdateOnEditableOrderSucceeds` — cambia qty, stock viejo restaurado + nuevo descontado.
- `testUpdateOnCancelledOrderFails`.
- `testUpdateOnDeliveredOrderFails`.
- `testUpdateChangingPaymentMethodLogsTodoForReceivables`.
- `testUpdateInsufficientStockRollsBackAllChanges` — order vuelve al estado original.
- `testCancelRestoresStockAndSetsCancelledFields`.
- `testCancelOnDeliveredOrderFails`.
- `testCancelOnAlreadyCancelledFails`.
- `testReactivateRestoresStatusAndDecrementsStock`.
- `testReactivateWithInsufficientStockFails`.
- `testReactivateOnNonCancelledFails`.
- `testReactivateClearsCancelledAtAndCancelledBy`.
- `testDeleteOnCancelledLeavesLogOrphan` — fila order gone, fila log persiste con `order_id=null` y `order_id_snapshot` preservado.
- `testDeleteOnActiveRestoresStockBeforeDeleting`.
- `testCreateLogsCxcWarningWhenReceivablesNotWired`.

**`OrderPipelineServiceTest`:**
- `testCanTransitionMatrix` — provider con todas las combinaciones.
- `testCanTransitionOnRouteRequiresDomicilio`.
- `testCanTransitionPreparingToDeliveredOnlyForLocal`.
- `testCanTransitionFromCancelledOnlyToReceived`.
- `testCanTransitionFromDeliveredAlwaysFalse`.
- `testAdvanceSucceedsValidTransition`.
- `testAdvanceSetsDeliveredAtWhenDelivered`.
- `testAdvanceRejectsCancelDelegatedToService` — error humanizado.
- `testAdvanceRejectsInvalidTransition`.
- `testAdvancePersistsLog` — verificar fila en `order_logs`.
- `testNextValidStatesForEachState`.

**`OrderHistoryServiceTest`:**
- `testLogCreatedPersistsCorrectKind`.
- `testLogStateChangedUsesLabels`.
- `testLogFieldChangeIgnoresFalsePositiveStringVsNumber` — `'10.00'` vs `10` no genera log.
- `testLogFieldChangeDetectsRealChange`.
- `testLogFieldChangeNormalizesDateTime`.
- `testLogFieldChangesIteratesSnapshot`.
- `testLogDeletedPersistsBeforeDelete` — verifica que después del delete del order, el log queda con `order_id=null`, `order_id_snapshot=N`.
- `testLogFailureDoesNotThrow` — mock OrderLogsTable que `save()` retorna false; no excepción.
- `testUserNameSnapshotResolvedFromUserId`.
- `testUserNameSnapshotFallsBackToPlaceholderWhenUserMissing`.

**`OrderFilterServiceTest`:**
- `testStatusVisibleExcludesCancelled`.
- `testStatusAllReturnsAll`.
- `testStatusSpecificFilters`.
- `testTypeFilter`.
- `testPaymentMethodFilter`.
- `testDeliveryIdFilter`.
- `testCustomerSearch`.
- `testDateRangeInclusive`.
- `testSearchNumericExactById`.
- `testSearchStringLikeNameOrPhone`.
- `testEmptyFiltersReturnsBaseQuery`.

**`OrdersControllerTest`:** `IntegrationTestTrait`, fixtures completos.
- `testIndexRedirectsAnonymous`.
- `testIndexForbiddenWithoutPermission`.
- `testIndexOkWithPermission`.
- `testIndexAsAdministratorBypass`.
- `testIndexAppliesRepartidorScope` — fixture user con `delivery_id=1`, debe ver solo orders de delivery 1.
- `testIndexHidesDeliveryFilterForRepartidor`.
- `testIndexFiltersWork` — q, status, type, payment_method, dates.
- `testIndexKpisRendered`.
- `testAddGetShowsForm`.
- `testAddForbiddenForRepartidor` — 403 incluso con `orders.create=1`.
- `testAddPostSuccessRedirects`.
- `testAddPostWithErrorsRendersForm`.
- `testViewOkForOwnerPermission`.
- `testViewForbiddenForRepartidorOnAlienOrder`.
- `testEditOnDeliveredRedirectsToView`.
- `testEditPostSuccess`.
- `testDeleteRequiresPost`.
- `testDeleteSuccess`.
- `testCancelPostRequiresCancellableState`.
- `testCancelSuccessRestoresStock`.
- `testReactivatePostRequiresCancelled`.
- `testAdvancePostValidTransition`.
- `testAdvancePostInvalidTransitionFlashesError`.
- `testTicketGetRenders` — verifica layout `ticket`.
- `testTicketForbiddenForAlienRepartidor`.

**`OrderLogsControllerTest`:**
- `testIndexForbiddenForNonAdmin` — incluso si placeholder all-zero se manipulara a 1, el hardcode bloquea.
- `testIndexOkForAdmin`.
- `testIndexFilterByOrderIdPath` — `/audit/order/5` filtra correctamente.
- `testIndexFilterByKind`.
- `testIndexShowsOrphanLogs`.

### Mocks vs reales

- Por default, integración real (services concretos). El stack es barato y ya probado individualmente.
- Mock puntual solo para forzar paths excepcionales:
  - `OrderHistoryService` mock con `save` retornando false → test `testLogFailureDoesNotThrow`.
  - `IngredientService::adjustStock` mock retornando `success=false` → test de rollback de OrderService.
  - `RecipeService::buildDecrementPlan` mock retornando `[]` → test de "producto sin receta".

### Comandos de verificación

```bash
composer cs-check && composer cs-fix
vendor/bin/phpstan analyse
vendor/bin/psalm
php vendor/bin/phpunit tests/TestCase/Model/Entity/OrderTest.php
php vendor/bin/phpunit tests/TestCase/Model/Entity/OrderItemTest.php
php vendor/bin/phpunit tests/TestCase/Model/Entity/OrderLogTest.php
php vendor/bin/phpunit tests/TestCase/Model/Table/OrdersTableTest.php
php vendor/bin/phpunit tests/TestCase/Model/Table/OrderItemsTableTest.php
php vendor/bin/phpunit tests/TestCase/Model/Table/OrderLogsTableTest.php
php vendor/bin/phpunit tests/TestCase/Service/OrderServiceTest.php
php vendor/bin/phpunit tests/TestCase/Service/OrderPipelineServiceTest.php
php vendor/bin/phpunit tests/TestCase/Service/OrderHistoryServiceTest.php
php vendor/bin/phpunit tests/TestCase/Service/OrderFilterServiceTest.php
php vendor/bin/phpunit tests/TestCase/Controller/OrdersControllerTest.php
php vendor/bin/phpunit tests/TestCase/Controller/OrderLogsControllerTest.php
php vendor/bin/phpunit
```

---

## 5. Verification checklist

Ejecutar antes de marcar el módulo como hecho:

- [ ] `php bin/cake.php migrations migrate` corre sin errores. Tablas `orders`, `order_items`, `order_logs` creadas con todas las FKs y los índices documentados.
- [ ] `SHOW CREATE TABLE order_logs` muestra `order_id` con `ON DELETE SET NULL` y **sin** columna `modified`.
- [ ] `SHOW CREATE TABLE order_items` muestra `order_id` con `ON DELETE CASCADE`.
- [ ] `php bin/cake.php migrations dump` actualiza `schema-dump-default.lock` sin warnings.
- [ ] `composer cs-check` limpio sobre todos los archivos nuevos/modificados.
- [ ] `composer cs-fix` idempotente (no cambia nada en una segunda corrida).
- [ ] `php -l` limpio sobre cada archivo PHP nuevo:
  ```bash
  for f in src/Constants/OrderConstants.php \
           src/Constants/OrderLogConstants.php \
           src/Model/Entity/Order.php src/Model/Entity/OrderItem.php src/Model/Entity/OrderLog.php \
           src/Model/Table/OrdersTable.php src/Model/Table/OrderItemsTable.php src/Model/Table/OrderLogsTable.php \
           src/Service/OrderService.php src/Service/OrderPipelineService.php \
           src/Service/OrderHistoryService.php src/Service/OrderFilterService.php \
           src/Controller/OrdersController.php src/Controller/OrderLogsController.php \
           config/Migrations/20260524150000_CreateOrders.php \
           config/Migrations/20260524150100_CreateOrderItems.php \
           config/Migrations/20260524150200_CreateOrderLogs.php \
           config/Migrations/20260524150300_SeedOrdersPermissions.php \
           config/Migrations/20260524150400_SeedAuditPermissions.php; do
    php -l "$f" || exit 1
  done
  ```
- [ ] `vendor/bin/phpstan analyse` nivel 8 limpio sobre los nuevos archivos.
- [ ] `vendor/bin/psalm` sin nuevos errores.
- [ ] `php bin/cake.php routes | grep -E "(orders|audit)"` lista al menos:
  - `/orders` → `Orders::index`, `/orders/add`, `/orders/view/{id}`, `/orders/edit/{id}`, `/orders/delete/{id}` (fallback).
  - `/orders/advance/{id}` POST → `Orders::advance`.
  - `/orders/cancel/{id}` POST → `Orders::cancel`.
  - `/orders/reactivate/{id}` POST → `Orders::reactivate`.
  - `/orders/ticket/{id}` GET → `Orders::ticket`.
  - `/audit` → `OrderLogs::index`.
  - `/audit/order/{id}` → `OrderLogs::index`.
- [ ] Servidor dev levanta: `php bin/cake.php server -p 8765`.
- [ ] **Smoke manual logueado como Administrador:**
  1. `/orders` → empty state si DB limpia.
  2. `/orders/add` → form. Completar: cliente "Juan Pérez" tel "3001234567", tipo Local, 1 línea (Hamburguesa qty 1), método Efectivo → submit.
  3. Flash success. `/orders` lo lista con chip "Recibido" (`status-pending`), `getItemsSummary`.
  4. Abrir el pedido → `/orders/view/1`. Verificar cards Cliente/Items/Total.
  5. POST `/orders/advance/1` con `to_status=preparando` → flash + chip "Preparando" (`status-preparing`).
  6. POST `/orders/advance/1` con `to_status=entregado` (es local, salta `en_camino`) → flash + chip verde "Entregado" + `delivered_at` seteado.
  7. `/audit/order/1` → tres logs visibles (created, state_changed×2).
  8. `/orders/ticket/1` → ticket impreso (diálogo de print del navegador).
- [ ] **Smoke manual repartidor:**
  1. Loguearse como user con `delivery_id` no nulo.
  2. `/orders` → solo ve los suyos, KPIs personalizados, sin dropdown delivery.
  3. `/orders/add` → 403.
  4. `/orders/view/{ajeno}` → 403.
- [ ] **Smoke RBAC:**
  1. Rol sin `orders.view` → `/orders` 403.
  2. Rol sin `orders.delete` → no aparece botón Eliminar; POST directo → 403.
  3. Rol con `orders.view` pero sin admin → `/audit` 403 (placeholder all-zero + hardcode).
- [ ] **Smoke cascade:**
  1. Borrar un pedido → items desaparecen automáticamente (CASCADE).
  2. Logs del pedido sobreviven con `order_id=null`, visibles en `/audit` con badge "Eliminado".
- [ ] **Smoke stock integration:**
  1. Crear pedido con producto cuya receta usa 100 gr de "Carne molida".
  2. `/ingredients` → stock de Carne molida disminuyó en 100.
  3. Cancelar el pedido → stock restaurado.
  4. Reactivar → stock vuelve a bajar.
- [ ] **Smoke negative path:**
  1. Stock de Carne molida = 50; crear pedido que requiere 100 → flash error "Stock insuficiente para Carne molida"; pedido NO persistido (rollback verificable en `/orders`).
- [ ] **Smoke producto sin receta:**
  1. Producto sin recipe lines; vender 1 → pedido OK; sin movimientos de stock; log de sistema con `Log::info Product without recipe sold`.
- [ ] **Smoke crédito + cliente auto-creado:**
  1. Crear pedido con teléfono nuevo "3009999999", payment=Crédito, sin elegir cliente existente.
  2. `/customers` → aparece nuevo cliente con ese teléfono.
  3. Log de sistema con warning `CxC pending: order #N credit payment, ReceivableService not wired yet`.
- [ ] **Smoke sidebar:**
  - Item "Pedidos" al tope con icono `bi-bag` para todos los usuarios con `orders.view`.
  - Item "Auditoría" al final solo para admins.

---

## 6. Risks / gotchas

1. **FK signed/unsigned matching.** `customers.id`, `deliveries.id`, `products.id` y `users.id` son todos `int unsigned` (verificado en migraciones existentes — `CreateCustomers`, `CreateDeliveries`, `CreateProducts`, `CreateUsers`). Por lo tanto, **todas** las FKs en `orders`, `order_items` y `order_logs` deben declararse con `['signed' => false]` en la columna correspondiente, igual que la PK `id` con `$this->table(..., ['signed' => false])`. Mismatch causa el error MySQL críptico `Cannot add foreign key constraint`.

2. **Snapshots NO se autoescriben con Timestamp.** Las columnas `customer_name`, `customer_phone`, `customer_address` (orders), `product_name`, `price_at_sale`, `line_subtotal` (order_items) NO deben tener default ni autopopulation por behavior. Se setean explícitamente en `OrderService::create` (paso c) leyendo del POST + DB respectiva. Si en algún futuro se agrega un behavior que toque estos campos, romperá el invariante de "snapshot inmutable". Documentar este criterio en el docblock de cada tabla.

3. **`OrderLogs` con `ON DELETE SET NULL` para `order_id`.** Clave del spec §9 (auditoría sobrevive al delete del pedido). Verificar dos cosas:
   - DB: `ALTER TABLE order_logs SHOW CREATE` muestra `ON DELETE SET NULL`.
   - ORM: en `OrdersTable::initialize`, la asociación `hasMany('OrderLogs', ['dependent' => false])` (no `true`). Si por descuido se setea `true`, el ORM intentará borrar los logs antes del delete físico, perdiendo auditoría.

4. **`delivered_at` se setea solo al entrar en `entregado`.** Nunca se limpia (ni siquiera en reactivación). Si reactivás un pedido entregado por error... no se puede: `entregado` es terminal en la matriz `TRANSITIONS`. La reactivación parte de `cancelado`, no de `entregado`. Si en el futuro el negocio pide "deshacer entregado", agregar transición `entregado → en_camino` o `entregado → preparando` con efectos de inventario explícitos; revisar este invariante.

5. **`cancelled_at` SE limpia al reactivar.** Decisión: `reactivate` setea `cancelled_at = null` y `cancelled_by = null`. La historia queda en `order_logs` (`logCancelled` + `logReactivated`). Esto evita "ruido" de un pedido activo que dice "cancelado el dd/mm/yyyy". Si el negocio quisiera preservar la fecha de la última cancelación incluso después de reactivar, mover la decisión: NO limpiar `cancelled_at` y agregar un campo `last_cancelled_at` separado.

6. **`ReceivableService` no existe aún (Fase 5).** Por ahora:
   - `OrderService::create` con `payment_method=credito` persiste el pedido, descuenta stock, auto-crea cliente, **y emite `Log::warning('CxC pending: order #{id}...')`** con scope `['orders', 'receivables_todo']`. NO crea la CxC. NO genera flash al usuario (silencioso, es comportamiento esperado en Fase 1).
   - `cancel`/`update`/`reactivate`/`delete` con pedido a crédito tienen TODO Fase 5: log warning similar y ningún side-effect.
   - Al implementar Fase 5, se inyectará `ReceivableService` por constructor y se quitarán los TODOs.
   - **Riesgo de drift:** si Fase 5 se difiere mucho, acumular pedidos a crédito sin CxC creará deuda técnica. Mitigación: el dashboard de Fase 5 incluirá una query de respaldo `WHERE payment_method='credito' AND id NOT IN (SELECT order_id FROM accounts_receivable)` para detectar y crear CxC retroactivas.

7. **`bcmath` prohibido (memoria del usuario).** Toda matemática usa `(float)` cast + `round(..., decimals)` + `number_format(..., decimals, '.', '')`. Nunca `bcadd`/`bccomp`/`bcsub`. Si phpstan reportara pérdida de precisión, agregar tests específicos en lugar de cambiar a bcmath. El proyecto ya validó este camino en `IngredientService::adjustStock`.

8. **`_scopeToRepartidor` debe ser idempotente y seguro para non-repartidor.** El helper en `AppController` (paso 20) hace early return si `$this->_currentDeliveryId() === null`. Test cubre que un admin (sin delivery_id) llama a `_scopeToRepartidor($query)` y obtiene la query sin cambios. Si alguien lo "optimiza" a `if (...) throw` o equivalente, romperá tests del index.

9. **Multi-step transactional logic — rollback path.** `OrderService::create` toca 4 tablas en orden: `orders`, `order_items`, `ingredients` (via adjustStock), `customers` (via findOrCreateByPhone), `order_logs` (logCreated). Cualquier fallo intermedio debe revertir todo. Decisiones:
   - `findOrCreateByPhone` se llama ANTES de instanciar el order, dentro de la transacción → si el cliente nuevo ya está creado y el resto falla, la transacción lo borra. Verificado vía `Connection::transactional` (auto-rollback al retornar false).
   - `adjustStock` abre su propia transacción interna → savepoint anidado, rollback parcial; el outer transactional revierte.
   - `logCreated` es post-commit conceptualmente, pero técnicamente dentro de la transacción. Si el log falla, NO se hace rollback (decisión deliberada: la auditoría no debe abortar el flujo). El `Log::error` queda en logs de sistema.
   - **El path crítico:** si `adjustStock` falla en el ítem 3 de 5, los 2 primeros movimientos de stock deben revertirse. Test `testCreateInsufficientStockRollsBack` cubre esto.

10. **NO sobrescribir `findList()`.** CakePHP 5 cambió la firma; sobrescribirla rompe el método público base. Usar finders custom con otro nombre (`findVisible`, `findByState`, `findCodeList`, etc.) — regla §4.4 ARQUITECTURE.

11. **KPI strip en `index`: compute on-load (Fase 1).** Cuatro queries por load:
    ```sql
    SELECT COUNT(*) FROM orders WHERE status != 'cancelado' AND DATE(created) = CURDATE();
    SELECT SUM(total) FROM orders WHERE status != 'cancelado' AND DATE(created) = CURDATE();
    SELECT COUNT(*) FROM orders WHERE status = 'preparando';
    SELECT COUNT(*) FROM orders WHERE status = 'en_camino';
    ```
    Costo aceptable con los índices `idx_orders_status_created` y `idx_orders_created`. Si se vuelve cuello de botella (>50ms acumulado), Fase 2: agregar materialized view o cache de 60s. **NO** preoptimizar — el index es greenfield.

12. **`CustomerService::findOrCreateByPhone` ya existe** (verificado línea 121 de `CustomerService.php`). Reusarlo directamente. **NO** duplicar lógica de auto-creación inline en `OrderService`. Si el contrato no calza (p. ej. retorna entity vs array), envolver con un adapter en `OrderService` en lugar de modificar `CustomerService`.

13. **Multi-decimal en `quantity` de `order_items`.** DB acepta `decimal(10,3)`. UI default `step="1"`. `RecipeService::buildDecrementPlan` espera `int $unitsSold` — si en el futuro se vende fraccional (qty 0.5), evolucionar la signature a `float|int`. Por Fase 1, `OrderService::create` hace `(int)$line['quantity']` al pasar a `buildDecrementPlan`, lo que truncaría 0.5 → 0 → plan vacío → "Product without recipe sold" (falso negativo). **No bloqueante** porque la UI no expone fraccional en Fase 1; documentado como TODO.

14. **Datos del negocio en el ticket (logo, NIT, dirección, teléfono).** Hardcoded en `templates/Orders/ticket.php` con comentario `// TODO Fase 6: parametrizar via tabla business_info o config/app.php`. Si cambia el NIT/teléfono antes de Fase 6, editar el template manualmente.

15. **`audit` admin-only enforcement en TRES capas:**
    - **Migración:** placeholder all-zero para no-admin (cosmética en matriz).
    - **AuthorizationService:** hardcode `in_array($module, ['roles', 'audit'])` retorna false para no-admin (estructural).
    - **Sidebar:** filtrado por `permissions.audit.view`, que para no-admin será 0 (visual).
    Si alguien quita una de las tres, el escudo sigue parado. Si alguien quita las tres, el módulo se abre a cualquier rol con view=1. Defensa en profundidad deliberada.

16. **Concurrencia: dos cajeros confirmando "entregado" simultáneamente.** El segundo encuentra `status=entregado` y `canTransition` retorna false → flash error humanizado "No se puede pasar de 'Entregado' a 'Entregado'". No requiere optimistic locking pesado en Fase 1. Si se vuelve un problema operativo (improbable — los pedidos se confirman en distintos sectores físicos), agregar `version` column + `unique constraint` o usar `SELECT ... FOR UPDATE` en `advance`.

17. **`schema-dump-default.lock` viene modificado en working tree.** `git status` muestra `M config/Migrations/schema-dump-default.lock` de trabajo previo. Hacer `migrations dump` UNA SOLA VEZ al final del módulo y commitear el lock junto con todos los cambios.

18. **`Authentication->getIdentity()?->get('delivery_id')` retorna mixed.** El helper `_currentDeliveryId` debe castear seguro:
    ```php
    $delivery = $identity->get('delivery_id');
    return is_numeric($delivery) ? (int)$delivery : null;
    ```
    El cast a `(int)` directo de null daría `0`, que es un `delivery_id` válido (problemático). El check `is_numeric` lo evita.

19. **`OrderHistoryService::persist` no debe abortar el flujo.** Decisión deliberada: si la auditoría falla (corruption, deadlock raro), el pedido se persiste igual. El error queda en `Log::error` con scope `['orders', 'audit']`. Test `testLogFailureDoesNotThrow` enforcea este invariante. Si en el futuro el negocio quiere transaccionalidad estricta (auditoría falla → rollback del pedido), cambiar `persist` a tirar excepción y eliminar el try-catch de los caller.

20. **`getItemsSummary` en index sin items hidratados.** Si la query del index NO hace `contain(['OrderItems'])`, `getItemsSummary` retornará `'—'`. Decisión Fase 1: contener `OrderItems` con `select` mínimo (`['order_id', 'product_name', 'quantity']`) para que el summary funcione sin costos prohibitivos. Trade-off: query más pesada vs UI vacía. Probar con `EXPLAIN` en dataset realista antes de descartar.

---

## 7. Phasing

El módulo es grande (5 migraciones, 17 archivos de src/, 9 templates, 13 tests) pero **se recomienda implementarlo como una sola fase atómica**, no sub-fases. Razones:

- **Atomicidad funcional:** los pedidos sin auditoría son inutilizables (spec §9 lo exige); auditoría sin pedidos no tiene sentido. Mergearlos juntos evita un estado intermedio incoherente.
- **Atomicidad transaccional:** `OrderService::create` toca recipes + ingredients + customers + logs en una sola transacción; partir la implementación generaría una fase con services parcialmente funcionales (mock returns) que igual habría que reescribir.
- **RBAC y sidebar son micro-cambios:** modificar `AppController`, `AuthorizationService` y `SidebarHelper` toma minutos; no justifica una fase separada.
- **Templates dependen del controller que dependen del service:** el orden natural es bottom-up dentro de la misma fase.

**Posible excepción justificada:** si el equipo tiene una restricción de PR-size (e.g. límite de 1500 líneas por PR), partir así:

- **Fase 4a (data layer + service skeleton):** pasos 1-17 (migraciones, constants, entities, tables, services). PR auto-contenido — los services pueden testearse en aislamiento con fixtures sin tocar UI ni rutas. ~1200 líneas.
- **Fase 4b (controller + rutas + templates + sidebar):** pasos 18-32 + 20-23 (rutas/RBAC/sidebar). Depende de 4a. ~1500 líneas.
- **Fase 4c (tests):** pasos 33-43. Depende de 4a y 4b. ~2000 líneas.

Pero el default es **una sola fase**. Solo partir si el reviewer lo solicita explícitamente.
