# Diseño — Módulo Pedidos (Orders) + Auditoría (Order Logs)

> Documento de diseño técnico del módulo más grande y central de Davi Rapid.
> Es el núcleo operativo: toca Customers, Deliveries, Products, Recipes
> (descontando stock de Ingredients), y siembra Cuentas por Cobrar al
> grabar pedidos a crédito.
>
> Referencias: `davirapid.md` §8 (Pedidos), §9 (Auditoría), §13 (flujo
> integrado), §15 (CxC), §7 (filtro repartidor), §21 (reglas globales);
> `.claude/rules/ARQUITECTURE.md` §4.13 (familias de servicios), §4.11
> (validación tabla vs servicio), §5 (RBAC); `.claude/rules/DESIGN.md`
> (familia `status-*` para ciclo de vida del pedido).
> Diseños previos consumidos: `01-ingredientes.md` (`IngredientService::adjustStock`),
> `02-recetas.md` (`RecipeService::buildDecrementPlan`), `03-ajustes-inventario.md`
> (patrón de service transaccional).

---

## 0. Resumen de decisiones críticas

| Decisión                                                                 | Valor                                                                                                                                          |
|--------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------|
| `shipping_cost` vive en **`orders`**, no en `order_items`                | Es una sola cuota por pedido; modelar en `order_items` invita a duplicación/inconsistencia.                                                    |
| `subtotal` y `total` se **persisten** en `orders` (snapshot)             | El precio del producto puede cambiar después del pedido; el total histórico debe ser inmutable. Cálculo en service, no columna generada en DB. |
| `price_at_sale` en cada `order_items` (snapshot)                         | Mismo motivo: precios cambian; el ticket impreso de hoy debe poder reimprimirse mañana idéntico.                                               |
| Auditoría (`order_logs`) **NO** cascadea desde `orders`                   | El spec §9 lo exige explícitamente. `order_id` con `ON DELETE SET NULL` + `order_id_snapshot` para preservar referencia.                       |
| `OrderService` orquesta CRUD + inventario + CxC                           | `OrderPipelineService` solo transiciones de estado puras (sin efectos de stock).                                                               |
| Reactivación (`cancelado → recibido`) y cancelación **NO** pasan por pipeline | Tienen efectos de inventario complejos; viven en `OrderService::cancel` / `reactivate`.                                                       |
| Scoping de repartidor: helper `_scopeToRepartidor()` en `AppController`   | Reusable por otros módulos futuros (CxC, reportes); el filtro corre **antes** que el RBAC.                                                     |
| Módulo de auditoría: clave RBAC **separada** (`audit`)                    | Spec §9: solo Admin. Aislarlo permite que el bypass del admin baste, sin tener que mezclarlo con `orders`.                                     |
| Tickets: vista HTML imprimible con `window.print()`                       | Sin PDF, sin impresora dedicada en Fase 1. La estética se controla con `@media print`.                                                         |
| `group_id` del spec = `order.id`                                          | No se necesita una columna extra: el pedido **es** el grupo; sus `order_items` son las líneas.                                                 |

---

## 1. Data model

### 1.1 Tabla `orders`

| Columna             | Tipo                 | Null | Default              | Notas                                                                                                            |
|---------------------|----------------------|------|----------------------|------------------------------------------------------------------------------------------------------------------|
| `id`                | int unsigned, PK, AI | no   | —                    | Consistente con resto del esquema (`signed=false`). Sirve como "group id" del spec §8.1.                          |
| `customer_id`       | int unsigned         | sí   | null                 | FK → `customers.id` **ON DELETE SET NULL**. Pedidos en efectivo pueden no tener cliente; los a crédito sí siempre.|
| `delivery_id`       | int unsigned         | sí   | null                 | FK → `deliveries.id` **ON DELETE SET NULL**. NULL si `type = local`; obligatorio si `type = domicilio`.          |
| `user_id`           | int unsigned         | sí   | null                 | FK → `users.id` **ON DELETE SET NULL**. Cajero/creador del pedido. NULL si el usuario fue eliminado.             |
| `type`              | varchar(10)          | no   | —                    | `'local'` o `'domicilio'`. `inList` contra `OrderConstants::TYPES`.                                              |
| `status`            | varchar(15)          | no   | `'recibido'`         | `recibido` / `preparando` / `en_camino` / `entregado` / `cancelado`. `inList`.                                   |
| `payment_method`    | varchar(20)          | no   | —                    | `efectivo` / `nequi` / `daviplata` / `transferencia` / `credito`. `inList`.                                      |
| `customer_name`     | varchar(150)         | sí   | null                 | Snapshot del nombre al momento del pedido (para preservar histórico aunque editen el cliente).                   |
| `customer_phone`    | varchar(30)          | sí   | null                 | Snapshot del teléfono. Obligatorio si `payment_method = credito` (validado en servicio).                          |
| `customer_address`  | varchar(255)         | sí   | null                 | Snapshot de la dirección. Obligatorio si `type = domicilio` (validado en servicio).                              |
| `shipping_cost`     | decimal(12,2)        | no   | `'0.00'`             | Cero para `local`. Aplica una sola vez al pedido completo (spec §8.2).                                            |
| `subtotal`          | decimal(12,2)        | no   | `'0.00'`             | Suma de `(price_at_sale * quantity)` de todas las líneas. Persistido como snapshot.                              |
| `total`             | decimal(12,2)        | no   | `'0.00'`             | `subtotal + shipping_cost`. Persistido para consultas baratas en index/dashboard.                                |
| `notes`             | text                 | sí   | null                 | Observaciones del operador (alergias, instrucciones de entrega).                                                 |
| `delivered_at`      | datetime             | sí   | null                 | Se setea cuando `status` transiciona a `entregado` (spec §8.5).                                                  |
| `cancelled_at`      | datetime             | sí   | null                 | Se setea cuando `status` transiciona a `cancelado`. Se limpia al reactivar.                                      |
| `cancelled_by`      | int unsigned         | sí   | null                 | FK → `users.id` **ON DELETE SET NULL**. Quién canceló.                                                            |
| `created`           | datetime             | no   | —                    | `Timestamp` behavior. Fecha del pedido (visible al cliente y en el dashboard).                                   |
| `modified`          | datetime             | sí   | null                 | `Timestamp` behavior.                                                                                            |

**Índices:**

- `idx_orders_status_created` (`status`, `created`) — filtros del index (estado + fecha) y dashboard (`status != cancelado`).
- `idx_orders_created` (`created`) — ordenamiento por defecto en index y cierre diario.
- `idx_orders_delivery_id` (`delivery_id`) — scoping por repartidor (regla §7).
- `idx_orders_customer_id` (`customer_id`) — historial del cliente y joins de CxC.
- `idx_orders_type` (`type`) — filtro local vs domicilio.
- `idx_orders_payment_method` (`payment_method`) — desglose financiero del dashboard.
- `idx_orders_delivered_at` (`delivered_at`) — ranking de repartidores por período.

**Justificación de columnas clave:**

- **Snapshots de cliente (`customer_name`, `customer_phone`, `customer_address`)**: el spec §6 contempla edición y eliminación de clientes. Si dependiéramos siempre de `customer_id` para mostrar nombre/tel en un ticket viejo, perderíamos datos cuando un cliente cambie de teléfono o sea eliminado. Snapshots cuestan 200 bytes/pedido y preservan trazabilidad. Si `customer_id` no es null y los datos del cliente actual difieren del snapshot, la UI puede ofrecer "actualizar al actual".
- **`subtotal` + `total` materializados**: evita recalcular en cada listado (caro con `contain order_items`). El recalculo se hace en service en cada `save`/`update`/`cancel`/`reactivate`. La integridad la garantiza el flujo transaccional, no la DB.
- **`status` con default `'recibido'`**: spec §8.4 — el ciclo arranca siempre en `recibido`.
- **Sin columna `group_id` separada**: el `order.id` ya identifica al grupo. Las líneas (`order_items`) cuelgan del pedido. Crear una columna extra sería redundante.

### 1.2 Tabla `order_items`

| Columna          | Tipo                 | Null | Default | Notas                                                                                       |
|------------------|----------------------|------|---------|---------------------------------------------------------------------------------------------|
| `id`             | int unsigned, PK, AI | no   | —       | —                                                                                           |
| `order_id`       | int unsigned         | no   | —       | FK → `orders.id` **ON DELETE CASCADE**. Las líneas mueren con el pedido.                    |
| `product_id`     | int unsigned         | sí   | null    | FK → `products.id` **ON DELETE SET NULL**. Si el producto se elimina, mantenemos el snapshot.|
| `product_name`   | varchar(120)         | no   | —       | Snapshot del nombre — el ticket histórico debe ser estable.                                  |
| `quantity`       | decimal(10,3)        | no   | —       | Cantidad vendida (decimal por consistencia con `Ingredient.stock_quantity`).                |
| `price_at_sale`  | decimal(12,2)        | no   | —       | Snapshot del precio unitario al momento del pedido. **No** se actualiza si el producto cambia de precio. |
| `line_subtotal`  | decimal(12,2)        | no   | —       | `price_at_sale * quantity`. Persistido para consultas baratas. Se recalcula en service.      |
| `notes`          | varchar(255)         | sí   | null    | Notas por línea ("sin cebolla", "extra picante"). Opcional.                                  |
| `created`        | datetime             | no   | —       | `Timestamp`.                                                                                 |
| `modified`       | datetime             | sí   | null    | `Timestamp`.                                                                                 |

**Índices:**

- `idx_order_items_order_id` (`order_id`) — implícito en la FK; necesario para `contain`.
- `idx_order_items_product_id` (`product_id`) — agregaciones del dashboard ("top productos").

**Justificación:**

- **`product_id` nullable + SET NULL**: spec §5 dice "no se puede eliminar un producto con ventas asociadas, se debe desactivar". O sea: en condiciones normales, `product_id` no será null. Pero la columna debe permitir null por defensa en profundidad (si por alguna ruta administrativa se borra), preservando el snapshot.
- **`line_subtotal` materializado**: facilita ordenar y agrupar; siempre se recalcula desde `price_at_sale * quantity` en el service, nunca confiar en valores POSTeados.
- **`quantity` decimal(10,3)**: la mayoría de pedidos serán enteros (2 hamburguesas), pero permitir decimales habilita casos como "0.5 kg de papas" si el catálogo evoluciona. Cuesta nada modelarlo así desde el inicio.

### 1.3 Tabla `order_logs`

| Columna             | Tipo                 | Null | Default | Notas                                                                                              |
|---------------------|----------------------|------|---------|----------------------------------------------------------------------------------------------------|
| `id`                | int unsigned, PK, AI | no   | —       | —                                                                                                  |
| `order_id`          | int unsigned         | sí   | null    | FK → `orders.id` **ON DELETE SET NULL**. Cuando el pedido se borra, el log queda huérfano pero vive.|
| `order_id_snapshot` | int unsigned         | no   | —       | Copia del ID al insertar. Sobrevive al delete del pedido.                                          |
| `user_id`           | int unsigned         | sí   | null    | FK → `users.id` **ON DELETE SET NULL**. Quién hizo el cambio.                                       |
| `user_name_snapshot`| varchar(120)         | no   | —       | Snapshot del nombre del autor. Si el usuario es eliminado, se sigue viendo en la UI.               |
| `kind`              | varchar(30)          | no   | —       | `created` / `state_changed` / `field_changed` / `cancelled` / `reactivated` / `deleted` / `item_added` / `item_removed` / `item_changed`. `inList` contra `OrderLogConstants::KINDS`. |
| `description`       | varchar(500)         | no   | —       | Texto humano: "Estado: de 'preparando' a 'entregado'", "Método de pago: de 'efectivo' a 'credito'". |
| `created`           | datetime             | no   | —       | `Timestamp` (solo `created`, no `modified` — append-only).                                          |

**Índices:**

- `idx_order_logs_order_id_snapshot_created` (`order_id_snapshot`, `created`) — timeline de un pedido.
- `idx_order_logs_created` (`created`) — vista global de auditoría (admin).
- `idx_order_logs_user_id` (`user_id`) — "todos los cambios hechos por X" (futuro).

**Sin `modified`**: append-only por diseño (spec §9 "bitácora inmutable").

### 1.4 Decisiones de migración

```php
class CreateOrders extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('orders')) {
            $this->table('orders', ['signed' => false, 'collation' => 'utf8mb4_unicode_ci'])
                ->addColumn('customer_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('delivery_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('user_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('type', 'string', ['limit' => 10, 'null' => false])
                ->addColumn('status', 'string', ['limit' => 15, 'null' => false, 'default' => 'recibido'])
                ->addColumn('payment_method', 'string', ['limit' => 20, 'null' => false])
                ->addColumn('customer_name', 'string', ['limit' => 150, 'null' => true])
                ->addColumn('customer_phone', 'string', ['limit' => 30, 'null' => true])
                ->addColumn('customer_address', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('shipping_cost', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => false, 'default' => '0.00'])
                ->addColumn('subtotal', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => false, 'default' => '0.00'])
                ->addColumn('total', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => false, 'default' => '0.00'])
                ->addColumn('notes', 'text', ['null' => true])
                ->addColumn('delivered_at', 'datetime', ['null' => true])
                ->addColumn('cancelled_at', 'datetime', ['null' => true])
                ->addColumn('cancelled_by', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('created', 'datetime', ['null' => false])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addIndex(['status', 'created'], ['name' => 'idx_orders_status_created'])
                ->addIndex(['created'], ['name' => 'idx_orders_created'])
                ->addIndex(['delivery_id'], ['name' => 'idx_orders_delivery_id'])
                ->addIndex(['customer_id'], ['name' => 'idx_orders_customer_id'])
                ->addIndex(['type'], ['name' => 'idx_orders_type'])
                ->addIndex(['payment_method'], ['name' => 'idx_orders_payment_method'])
                ->addIndex(['delivered_at'], ['name' => 'idx_orders_delivered_at'])
                ->addForeignKey('customer_id', 'customers', 'id', ['delete' => 'SET_NULL', 'update' => 'RESTRICT'])
                ->addForeignKey('delivery_id', 'deliveries', 'id', ['delete' => 'SET_NULL', 'update' => 'RESTRICT'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'RESTRICT'])
                ->addForeignKey('cancelled_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'RESTRICT'])
                ->create();
        }
    }
}
```

Tres migraciones separadas (`CreateOrders`, `CreateOrderItems`, `CreateOrderLogs`) en orden cronológico para que las FKs apunten a tablas ya existentes.

---

## 2. Constants

### 2.1 `OrderConstants`

```php
final class OrderConstants
{
    // --- Tipos ---
    public const TYPE_LOCAL     = 'local';
    public const TYPE_DOMICILIO = 'domicilio';

    public const TYPES = [self::TYPE_LOCAL, self::TYPE_DOMICILIO];

    public const TYPE_LABELS = [
        self::TYPE_LOCAL     => 'Local',
        self::TYPE_DOMICILIO => 'Domicilio',
    ];

    // --- Estados (ciclo de vida del pedido) ---
    public const STATUS_RECEIVED  = 'recibido';
    public const STATUS_PREPARING = 'preparando';
    public const STATUS_ON_ROUTE  = 'en_camino';
    public const STATUS_DELIVERED = 'entregado';
    public const STATUS_CANCELLED = 'cancelado';

    public const STATUSES = [
        self::STATUS_RECEIVED,
        self::STATUS_PREPARING,
        self::STATUS_ON_ROUTE,
        self::STATUS_DELIVERED,
        self::STATUS_CANCELLED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_RECEIVED  => 'Recibido',
        self::STATUS_PREPARING => 'Preparando',
        self::STATUS_ON_ROUTE  => 'En camino',
        self::STATUS_DELIVERED => 'Entregado',
        self::STATUS_CANCELLED => 'Cancelado',
    ];

    /**
     * Clase CSS de la familia `status-*` definida en DESIGN.md
     * (no usar `badge-*` para estados de pedido — regla DESIGN).
     */
    public const STATUS_CSS_CLASS = [
        self::STATUS_RECEIVED  => 'status-pending',
        self::STATUS_PREPARING => 'status-preparing',
        self::STATUS_ON_ROUTE  => 'status-on-route',
        self::STATUS_DELIVERED => 'status-delivered',
        self::STATUS_CANCELLED => 'status-cancelled',
    ];

    /**
     * Estados terminales (no admiten más transiciones automáticas).
     * `cancelado` es semi-terminal porque admite `reactivate`.
     */
    public const TERMINAL_STATUSES = [self::STATUS_DELIVERED];

    /**
     * Estados desde los que se puede editar líneas/cliente/etc.
     * Spec §8.5: cancelado no se edita; entregado tampoco (read-only).
     */
    public const EDITABLE_STATUSES = [self::STATUS_RECEIVED, self::STATUS_PREPARING];

    // --- Métodos de pago ---
    public const PAYMENT_CASH         = 'efectivo';
    public const PAYMENT_NEQUI        = 'nequi';
    public const PAYMENT_DAVIPLATA    = 'daviplata';
    public const PAYMENT_TRANSFER     = 'transferencia';
    public const PAYMENT_CREDIT       = 'credito';

    public const PAYMENT_METHODS = [
        self::PAYMENT_CASH,
        self::PAYMENT_NEQUI,
        self::PAYMENT_DAVIPLATA,
        self::PAYMENT_TRANSFER,
        self::PAYMENT_CREDIT,
    ];

    public const PAYMENT_LABELS = [
        self::PAYMENT_CASH      => 'Efectivo',
        self::PAYMENT_NEQUI     => 'Nequi',
        self::PAYMENT_DAVIPLATA => 'Daviplata',
        self::PAYMENT_TRANSFER  => 'Transferencia',
        self::PAYMENT_CREDIT    => 'Crédito (Fiado)',
    ];

    /** Métodos que se consideran "ingreso real" al cobrarse en el momento. */
    public const PAYMENT_METHODS_CASH_LIKE = [
        self::PAYMENT_CASH,
        self::PAYMENT_NEQUI,
        self::PAYMENT_DAVIPLATA,
        self::PAYMENT_TRANSFER,
    ];

    // --- Decimales ---
    public const MONEY_DECIMALS    = 2;   // COP no usa decimales en la práctica, pero los modelamos.
    public const QUANTITY_DECIMALS = 3;   // Consistente con `Ingredient.stock_quantity`.

    // --- Límites ---
    public const NOTES_MAX_LENGTH        = 65000; // text columna; cap soft.
    public const LINE_NOTES_MAX_LENGTH   = 255;
    public const MAX_ITEMS_PER_ORDER     = 50;    // Defensivo contra payloads abusivos.

    /**
     * Estados desde los que SE PUEDE cancelar.
     * Spec §8.4 stateDiagram: recibido / preparando / en_camino.
     */
    public const CANCELLABLE_FROM = [
        self::STATUS_RECEIVED,
        self::STATUS_PREPARING,
        self::STATUS_ON_ROUTE,
    ];

    private function __construct() {}
}
```

### 2.2 `OrderLogConstants`

```php
final class OrderLogConstants
{
    public const KIND_CREATED        = 'created';
    public const KIND_STATE_CHANGED  = 'state_changed';
    public const KIND_FIELD_CHANGED  = 'field_changed';
    public const KIND_ITEM_ADDED     = 'item_added';
    public const KIND_ITEM_REMOVED   = 'item_removed';
    public const KIND_ITEM_CHANGED   = 'item_changed';
    public const KIND_CANCELLED      = 'cancelled';
    public const KIND_REACTIVATED    = 'reactivated';
    public const KIND_DELETED        = 'deleted';

    public const KINDS = [
        self::KIND_CREATED, self::KIND_STATE_CHANGED, self::KIND_FIELD_CHANGED,
        self::KIND_ITEM_ADDED, self::KIND_ITEM_REMOVED, self::KIND_ITEM_CHANGED,
        self::KIND_CANCELLED, self::KIND_REACTIVATED, self::KIND_DELETED,
    ];

    public const KIND_LABELS = [
        self::KIND_CREATED       => 'Creado',
        self::KIND_STATE_CHANGED => 'Cambio de estado',
        self::KIND_FIELD_CHANGED => 'Cambio de campo',
        self::KIND_ITEM_ADDED    => 'Producto agregado',
        self::KIND_ITEM_REMOVED  => 'Producto removido',
        self::KIND_ITEM_CHANGED  => 'Producto modificado',
        self::KIND_CANCELLED     => 'Cancelado',
        self::KIND_REACTIVATED   => 'Reactivado',
        self::KIND_DELETED       => 'Eliminado',
    ];

    /**
     * Icono Bootstrap-Icons por kind para el timeline.
     */
    public const KIND_ICONS = [
        self::KIND_CREATED       => 'bi-plus-circle',
        self::KIND_STATE_CHANGED => 'bi-arrow-right-circle',
        self::KIND_FIELD_CHANGED => 'bi-pencil',
        self::KIND_ITEM_ADDED    => 'bi-bag-plus',
        self::KIND_ITEM_REMOVED  => 'bi-bag-dash',
        self::KIND_ITEM_CHANGED  => 'bi-bag-check',
        self::KIND_CANCELLED     => 'bi-x-octagon',
        self::KIND_REACTIVATED   => 'bi-arrow-clockwise',
        self::KIND_DELETED       => 'bi-trash',
    ];

    private function __construct() {}
}
```

---

## 3. Entities

### 3.1 `Order`

```php
class Order extends Entity
{
    protected array $_accessible = [
        'customer_id' => true,
        'delivery_id' => true,
        'user_id' => true,
        'type' => true,
        'status' => true,
        'payment_method' => true,
        'customer_name' => true,
        'customer_phone' => true,
        'customer_address' => true,
        'shipping_cost' => true,
        'subtotal' => true,
        'total' => true,
        'notes' => true,
        'delivered_at' => true,
        'cancelled_at' => true,
        'cancelled_by' => true,
        // Asociaciones (hidratables vía patchEntity)
        'order_items' => true,
        'customer' => true,
        'delivery' => true,
        'user' => true,
    ];

    protected array $_virtual = ['display_status', 'item_count', 'is_credit'];

    // --- State predicates ---
    public function isCancelled(): bool { return $this->status === OrderConstants::STATUS_CANCELLED; }
    public function isDelivered(): bool { return $this->status === OrderConstants::STATUS_DELIVERED; }
    public function isDomicilio(): bool { return $this->type === OrderConstants::TYPE_DOMICILIO; }
    public function isLocal(): bool { return $this->type === OrderConstants::TYPE_LOCAL; }
    public function isCredit(): bool { return $this->payment_method === OrderConstants::PAYMENT_CREDIT; }
    public function isEditable(): bool {
        return in_array($this->status, OrderConstants::EDITABLE_STATUSES, true);
    }
    public function isCancellable(): bool {
        return in_array($this->status, OrderConstants::CANCELLABLE_FROM, true);
    }

    // --- Transitions (delegates to pipeline matrix) ---
    public function canTransitionTo(string $newStatus): bool {
        $allowed = OrderPipelineService::TRANSITIONS[$this->status] ?? [];
        if (!in_array($newStatus, $allowed, true)) {
            return false;
        }
        if ($newStatus === OrderConstants::STATUS_ON_ROUTE && !$this->isDomicilio()) {
            return false;
        }
        return true;
    }

    // --- Display helpers ---
    public function getDisplayStatus(): string {
        return OrderConstants::STATUS_LABELS[$this->status] ?? $this->status;
    }
    public function getStatusCssClass(): string {
        return OrderConstants::STATUS_CSS_CLASS[$this->status] ?? 'status-pending';
    }
    public function getCustomerName(): string {
        return (string)($this->customer_name ?: $this->customer?->name ?: 'Sin nombre');
    }
    public function getCustomerPhone(): string {
        return (string)($this->customer_phone ?: $this->customer?->phone ?: '');
    }
    public function getItemCount(): int {
        return is_array($this->order_items) ? count($this->order_items) : 0;
    }
    public function getItemsSummary(int $maxNames = 1): string {
        $items = $this->order_items ?? [];
        if (empty($items)) return '—';
        $first = $items[0];
        $base = trim($first->quantity . ' × ' . ($first->product_name ?? '?'));
        $rest = count($items) - 1;
        return $rest > 0 ? sprintf('%s (+%d más)', $base, $rest) : $base;
    }

    // --- Virtual accessors ---
    protected function _getDisplayStatus(): string { return $this->getDisplayStatus(); }
    protected function _getItemCount(): int        { return $this->getItemCount(); }
    protected function _getIsCredit(): bool        { return $this->isCredit(); }
}
```

### 3.2 `OrderItem`

```php
class OrderItem extends Entity
{
    protected array $_accessible = [
        'order_id' => true,
        'product_id' => true,
        'product_name' => true,
        'quantity' => true,
        'price_at_sale' => true,
        'line_subtotal' => true,
        'notes' => true,
        'product' => true,
    ];

    protected array $_virtual = ['computed_subtotal'];

    /** Subtotal recalculado a partir de cantidad × precio (defensa anti-tampering). */
    public function getLineSubtotal(): float {
        return round(((float)$this->quantity) * ((float)$this->price_at_sale), OrderConstants::MONEY_DECIMALS);
    }

    public function getFormattedQuantity(): string {
        return rtrim(rtrim(number_format((float)$this->quantity, 3, '.', ''), '0'), '.');
    }

    protected function _getComputedSubtotal(): float { return $this->getLineSubtotal(); }
}
```

### 3.3 `OrderLog`

```php
class OrderLog extends Entity
{
    protected array $_accessible = [
        'order_id' => true,
        'order_id_snapshot' => true,
        'user_id' => true,
        'user_name_snapshot' => true,
        'kind' => true,
        'description' => true,
    ];

    public function getFormattedDate(): string {
        return $this->created?->i18nFormat('dd/MM/yyyy HH:mm') ?? '';
    }

    public function getKindLabel(): string {
        return OrderLogConstants::KIND_LABELS[$this->kind] ?? $this->kind;
    }

    public function getIcon(): string {
        return OrderLogConstants::KIND_ICONS[$this->kind] ?? 'bi-circle';
    }

    public function isOrphan(): bool {
        return $this->order_id === null;
    }
}
```

---

## 4. Tables

### 4.1 `OrdersTable`

```php
class OrdersTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('orders');
        $this->setPrimaryKey('id');
        $this->setDisplayField('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Customers',  ['foreignKey' => 'customer_id', 'joinType' => 'LEFT']);
        $this->belongsTo('Deliveries', ['foreignKey' => 'delivery_id', 'joinType' => 'LEFT']);
        $this->belongsTo('Users',      ['foreignKey' => 'user_id',     'joinType' => 'LEFT']);
        $this->belongsTo('CancelledByUser', [
            'className'  => 'Users',
            'foreignKey' => 'cancelled_by',
            'joinType'   => 'LEFT',
        ]);
        $this->hasMany('OrderItems', [
            'foreignKey' => 'order_id',
            'dependent'  => true,   // cascade en delete via ORM
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('OrderLogs', [
            'foreignKey' => 'order_id',
            'dependent'  => false,  // los logs NO se borran con el pedido (spec §9)
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->notEmptyString('type', 'El tipo es requerido')
            ->inList('type', OrderConstants::TYPES, 'Tipo inválido')
            ->notEmptyString('status', 'El estado es requerido')
            ->inList('status', OrderConstants::STATUSES, 'Estado inválido')
            ->notEmptyString('payment_method', 'El método de pago es requerido')
            ->inList('payment_method', OrderConstants::PAYMENT_METHODS, 'Método de pago inválido')
            ->maxLength('customer_name', CustomerConstants::NAME_MAX_LENGTH)
            ->maxLength('customer_phone', CustomerConstants::PHONE_MAX_LENGTH)
            ->maxLength('customer_address', CustomerConstants::ADDRESS_MAX_LENGTH)
            ->numeric('shipping_cost')
            ->greaterThanOrEqual('shipping_cost', 0, 'El costo de envío no puede ser negativo')
            ->numeric('subtotal')
            ->greaterThanOrEqual('subtotal', 0)
            ->numeric('total')
            ->greaterThanOrEqual('total', 0)
            ->allowEmptyString('notes');
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn(['customer_id'], 'Customers', ['allowNullableNulls' => true]));
        $rules->add($rules->existsIn(['delivery_id'], 'Deliveries', ['allowNullableNulls' => true]));
        $rules->add($rules->existsIn(['user_id'],     'Users',      ['allowNullableNulls' => true]));
        return $rules;
    }

    // ---- Custom finders ----

    /** Pedidos visibles (no cancelados). Default del listado. */
    public function findVisible(SelectQuery $query): SelectQuery
    {
        return $query->where(['Orders.status !=' => OrderConstants::STATUS_CANCELLED]);
    }

    /** Filtra por uno o varios estados. */
    public function findByState(SelectQuery $query, array $opts): SelectQuery
    {
        $statuses = (array)($opts['status'] ?? []);
        return $statuses ? $query->where(['Orders.status IN' => $statuses]) : $query;
    }

    /** Solo pedidos de un repartidor concreto (regla §7). */
    public function findForRepartidor(SelectQuery $query, array $opts): SelectQuery
    {
        $deliveryId = (int)($opts['delivery_id'] ?? 0);
        return $deliveryId > 0
            ? $query->where(['Orders.delivery_id' => $deliveryId])
            : $query->where('1=0'); // safety: si no hay id, no devolver nada
    }

    /** Rango de fechas inclusivo. */
    public function findInDateRange(SelectQuery $query, array $opts): SelectQuery
    {
        if (!empty($opts['from'])) {
            $query->where(['Orders.created >=' => $opts['from'] . ' 00:00:00']);
        }
        if (!empty($opts['to'])) {
            $query->where(['Orders.created <=' => $opts['to'] . ' 23:59:59']);
        }
        return $query;
    }

    /** Con items hidratados — usado en view, edit, ticket. */
    public function findWithItems(SelectQuery $query): SelectQuery
    {
        return $query->contain(['OrderItems' => ['Products']]);
    }

    /** Para dashboard: solo entregados/preparando/en_camino del día. */
    public function findActiveToday(SelectQuery $query): SelectQuery
    {
        $today = date('Y-m-d');
        return $query
            ->where(['Orders.status !=' => OrderConstants::STATUS_CANCELLED])
            ->where(['Orders.created >=' => $today . ' 00:00:00'])
            ->where(['Orders.created <=' => $today . ' 23:59:59']);
    }
}
```

### 4.2 `OrderItemsTable`

```php
class OrderItemsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('order_items');
        $this->setPrimaryKey('id');
        $this->setDisplayField('product_name');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Orders',   ['foreignKey' => 'order_id',   'joinType' => 'INNER']);
        $this->belongsTo('Products', ['foreignKey' => 'product_id', 'joinType' => 'LEFT']);
    }

    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->notEmptyString('product_name')
            ->maxLength('product_name', 120)
            ->numeric('quantity')
            ->greaterThan('quantity', 0, 'La cantidad debe ser mayor a 0')
            ->numeric('price_at_sale')
            ->greaterThanOrEqual('price_at_sale', 0)
            ->numeric('line_subtotal')
            ->greaterThanOrEqual('line_subtotal', 0)
            ->maxLength('notes', OrderConstants::LINE_NOTES_MAX_LENGTH)
            ->allowEmptyString('notes');
    }

    public function findTopProducts(SelectQuery $query, array $opts): SelectQuery
    {
        // Agregación para dashboard: top N productos por unidades vendidas.
        $limit = (int)($opts['limit'] ?? 5);
        return $query
            ->select([
                'product_id',
                'product_name',
                'units' => $query->func()->sum('quantity'),
                'revenue' => $query->func()->sum('line_subtotal'),
            ])
            ->innerJoinWith('Orders', fn($q) => $q->where(['Orders.status !=' => OrderConstants::STATUS_CANCELLED]))
            ->groupBy(['product_id', 'product_name'])
            ->orderBy(['units' => 'DESC'])
            ->limit($limit);
    }
}
```

### 4.3 `OrderLogsTable`

```php
class OrderLogsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('order_logs');
        $this->setPrimaryKey('id');
        $this->setDisplayField('description');
        $this->addBehavior('Timestamp', ['events' => ['Model.beforeSave' => ['created' => 'new']]]);

        $this->belongsTo('Orders', ['foreignKey' => 'order_id', 'joinType' => 'LEFT']);
        $this->belongsTo('Users',  ['foreignKey' => 'user_id',  'joinType' => 'LEFT']);
    }

    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->integer('order_id_snapshot')
            ->requirePresence('order_id_snapshot', 'create')
            ->notEmptyString('kind')
            ->inList('kind', OrderLogConstants::KINDS)
            ->notEmptyString('description')
            ->maxLength('description', 500);
    }

    public function findForOrder(SelectQuery $query, array $opts): SelectQuery
    {
        $orderId = (int)($opts['order_id'] ?? 0);
        return $query
            ->where(['OrderLogs.order_id_snapshot' => $orderId])
            ->orderBy(['OrderLogs.created' => 'DESC', 'OrderLogs.id' => 'DESC']);
    }

    public function findChronological(SelectQuery $query): SelectQuery
    {
        return $query->orderBy(['OrderLogs.created' => 'DESC', 'OrderLogs.id' => 'DESC']);
    }
}
```

---

## 5. Service layer

Cuatro servicios per ARQUITECTURE §4.13. Composición:

```
OrdersController
   ├─ OrderService               (CRUD + inventario + CxC)
   │     ├─ OrderHistoryService  (auditoría field-by-field)
   │     ├─ RecipeService        (buildDecrementPlan)
   │     ├─ IngredientService    (adjustStock)
   │     ├─ CustomerService      (findOrCreateByPhone)
   │     └─ ReceivableService    (createFromOrder — diseño módulo 5)
   ├─ OrderPipelineService       (transiciones puras)
   │     └─ OrderHistoryService  (log de cambios de estado)
   └─ OrderFilterService         (where-clauses sobre queries)
```

### 5.1 `OrderService` — CRUD + inventario + CxC

```php
final class OrderService
{
    use LocatorAwareTrait;

    public function __construct(
        private ?OrderHistoryService $history = null,
        private ?RecipeService $recipes = null,
        private ?IngredientService $ingredients = null,
        private ?CustomerService $customers = null,
        private ?ReceivableService $receivables = null,
    ) {
        $this->history     ??= new OrderHistoryService();
        $this->recipes     ??= new RecipeService();
        $this->ingredients ??= new IngredientService();
        $this->customers   ??= new CustomerService();
        // ReceivableService aún no existe — se inyecta cuando se diseñe módulo 5.
        // Mientras tanto, $this->receivables es null y los pedidos a crédito
        // generan un log pero no la CxC (TODO documentado).
    }
}
```

#### `create(array $data, int $userId): array`

**Contrato:** crea el pedido, sus líneas, descuenta stock, opcionalmente crea cliente y CxC, registra log. Todo en una transacción.

```text
1. Validación previa al service (defensa rápida, sin tocar DB):
   - data.type ∈ TYPES.
   - data.payment_method ∈ PAYMENT_METHODS.
   - data.items es array no vacío y |items| <= MAX_ITEMS_PER_ORDER.
   - Cada item tiene product_id (int>0) y quantity (decimal>0).
   - Si type=domicilio: delivery_id presente y >0, customer_address no vacío, shipping_cost>=0.
   - Si type=local: shipping_cost=0 (forzar).
   - Si payment_method=credito: customer_phone no vacío (necesario para CxC).
   Retornar ['success'=>false, 'errors'=>[...]] si falla.

2. Pre-load: productos por IDs (find with where IN) para validar existencia y obtener
   price actuales. Si algún product_id no existe → error.

3. Abrir transacción:
   a. Si payment_method=credito y customer_id no provisto:
        customer = customerService->findOrCreateByPhone(phone, name, address).
        customer_id = customer->id.
        // (snapshots customer_name/phone/address vienen del input, no del customer DB)
   b. Construir Order entity con snapshots y user_id=$userId, status='recibido'.
      subtotal/total se computan; shipping_cost se respeta o se fuerza a 0 según type.
   c. Construir OrderItem entities a partir de $data['items']:
        - product_name = snapshot de Product.name.
        - price_at_sale = Product.price (NUNCA el price del POST).
        - line_subtotal = price_at_sale * quantity (recalculado, no POST).
   d. order->subtotal = sum(line_subtotal).
      order->total = order->subtotal + order->shipping_cost.
   e. ordersTable->save(order, ['associated' => ['OrderItems']]).
      Si falla → rollback, return errors.
   f. Para cada item, descontar stock:
        plan := recipeService->buildDecrementPlan(item.product_id, item.quantity).
        Si plan está vacío (producto sin receta): Log::info, continuar (no es error — spec §21).
        Para cada (ingredient_id, qty) en plan:
            ingredient = fetchTable('Ingredients')->get(ingredient_id).
            result := ingredients->adjustStock(ingredient, "-{qty}", "Pedido #{order.id}").
            Si !result.success → rollback con stockError.
   g. Si payment_method=credito y $this->receivables !== null:
        receivables->createFromOrder(order, customer_id) (servicio futuro).
        Si falla → rollback.
      Si $this->receivables === null:
        Log::warning('CxC pending: order #{id} credit payment, ReceivableService not wired yet').
   h. history->logCreated(order, $userId, "Pedido creado por {user.name}").
   i. Log::info('Order created: id={id} type={t} method={m} total={tot}', ...).
   j. return true (commit).

4. Re-fetch order con items y customer/delivery hidratados.
5. Retornar ['success'=>true, 'order'=>$order].
```

#### `update(Order $order, array $data, int $userId): array`

**Contrato:** edición campo a campo + recálculo de líneas + restauración/descuento de stock. **Solo permitida si `$order->isEditable()`** (spec §8.5: no se edita un pedido cancelado ni entregado).

```text
1. Si !order->isEditable() → return ['success'=>false, 'errors'=>['No se puede editar...']].

2. Tomar snapshot del estado actual ANTES de modificar (para el diff):
   $snapshot = [
       'type' => order->type,
       'payment_method' => order->payment_method,
       'shipping_cost' => order->shipping_cost,
       'customer_id' => order->customer_id,
       'delivery_id' => order->delivery_id,
       'notes' => order->notes,
       'items' => map(order_items, fn($i) => ['product_id'=>$i->product_id, 'quantity'=>$i->quantity])
   ];

3. Transacción:
   a. Restaurar stock de items ACTUALES:
        Para cada item viejo: plan = recipeService->buildDecrementPlan(product_id, quantity).
        Para cada (ing, qty) en plan: ingredients->adjustStock(ing, "+{qty}", "Edición pedido #{id} (restauración)").
   b. Borrar items actuales: ordersTable->OrderItems->deleteAll(['order_id' => order->id]).
   c. Patch order entity con $data (sin tocar id/status/created/user_id).
   d. Construir nuevos OrderItems desde $data['items'] (mismo flujo que create paso 3c).
   e. Recalcular subtotal/total.
   f. Manejar transición de payment_method:
        - Si pasó de NO-crédito a crédito: crear CxC (receivables->createFromOrder).
        - Si pasó de crédito a NO-crédito: receivables->cancelFromOrder(order_id) — bloquea si hay abonos.
        - Si era crédito y total cambió: receivables->adjustForOrder(order, new_total).
   g. Save order with associated OrderItems.
   h. Descontar stock de los NUEVOS items: mismo loop que create paso 3f.
   i. Diff entre $snapshot y order actual → history->logFieldChanges(order, userId, $snapshot).
      history->logItemsReplaced(order, userId, $snapshot['items'], new_items_summary).
   j. Log::info.
   k. commit.

4. Retornar ['success'=>true, 'order'=>$order].
```

#### `cancel(Order $order, int $userId, ?string $reason = null): array`

```text
1. Si !order->isCancellable() → error 'Estado actual no admite cancelación'.

2. Transacción:
   a. Para cada item: plan = recipeService->buildDecrementPlan(product_id, qty).
      Para cada (ing, qty): ingredients->adjustStock(ing, "+{qty}", "Cancelación pedido #{id}").
   b. order->status = 'cancelado'.
      order->cancelled_at = now.
      order->cancelled_by = userId.
   c. Si era crédito: receivables->cancelFromOrder(order->id). Bloquea si hay abonos.
   d. ordersTable->save(order).
   e. history->logCancelled(order, userId, "Pedido cancelado" . ($reason ? ": $reason" : '')).
   f. Log::info.
   g. commit.
```

#### `reactivate(Order $order, int $userId): array`

```text
1. Si order->status !== 'cancelado' → error.

2. Transacción:
   a. Para cada item: plan = recipeService->buildDecrementPlan.
      Para cada (ing, qty): ingredients->adjustStock(ing, "-{qty}", "Reactivación pedido #{id}").
      Si falla (stock insuficiente) → rollback con error claro:
          'No se puede reactivar: stock insuficiente para {ing}'.
   b. order->status = 'recibido'.
      order->cancelled_at = null.
      order->cancelled_by = null.
   c. Si era crédito: receivables->reactivateFromOrder(order->id) (recrea CxC).
   d. ordersTable->save(order).
   e. history->logReactivated(order, userId, "Pedido reactivado").
   f. Log::info.
   g. commit.
```

#### `delete(Order $order, int $userId): array`

```text
1. Si order es crédito y receivables->hasPayments(order->id) → bloquear:
   'No se puede eliminar: tiene abonos registrados. Cancelalo en su lugar.'

2. Transacción:
   a. Si !order->isCancelled():
        // Restaurar stock primero, igual que cancel.
        Para cada item: ingredients->adjustStock(+, "Eliminación pedido #{id}").
   b. Si era crédito: receivables->cancelFromOrder(order->id) (sin abonos, seguro).
   c. // GUARDAR el log ANTES del delete (el log usa order_id, y el cascade lo va a poner null).
      history->logDeleted(order, userId, "Pedido eliminado").
   d. ordersTable->delete(order). Esto cascadea order_items (dependent=true);
      order_logs NO cascadea (dependent=false), order_id queda en NULL,
      order_id_snapshot preserva la referencia.
   e. Log::warning.
   f. commit.
```

### 5.2 `OrderPipelineService` — state machine

```php
final class OrderPipelineService
{
    /** Transiciones permitidas, ignorando reglas dependientes del type. */
    public const TRANSITIONS = [
        OrderConstants::STATUS_RECEIVED  => [OrderConstants::STATUS_PREPARING, OrderConstants::STATUS_CANCELLED],
        OrderConstants::STATUS_PREPARING => [OrderConstants::STATUS_ON_ROUTE, OrderConstants::STATUS_DELIVERED, OrderConstants::STATUS_CANCELLED],
        OrderConstants::STATUS_ON_ROUTE  => [OrderConstants::STATUS_DELIVERED, OrderConstants::STATUS_CANCELLED],
        OrderConstants::STATUS_DELIVERED => [], // terminal
        OrderConstants::STATUS_CANCELLED => [OrderConstants::STATUS_RECEIVED], // reactivación manual
    ];

    /**
     * Reglas adicionales dependientes del tipo (local/domicilio).
     * Map (status_destino => callable(Order):bool).
     */
    private const TYPE_DEPENDENT_RULES = [
        OrderConstants::STATUS_ON_ROUTE => 'requiresDomicilio',
    ];

    public function __construct(
        private ?OrderHistoryService $history = null,
        private ?OrderService $orders = null,
    ) {
        $this->history ??= new OrderHistoryService();
    }

    public function canTransition(Order $order, string $newStatus): bool
    {
        $from = $order->status;
        if (!isset(self::TRANSITIONS[$from])) return false;
        if (!in_array($newStatus, self::TRANSITIONS[$from], true)) return false;

        // Regla: en_camino solo aplica a domicilio.
        if ($newStatus === OrderConstants::STATUS_ON_ROUTE && !$order->isDomicilio()) {
            return false;
        }
        // Regla: preparando → entregado solo para local (domicilio debe pasar por en_camino).
        if ($from === OrderConstants::STATUS_PREPARING
            && $newStatus === OrderConstants::STATUS_DELIVERED
            && $order->isDomicilio()) {
            return false;
        }
        return true;
    }

    /**
     * Avanza el estado del pedido. Cancel/reactivate NO usan este método
     * (tienen efectos de inventario, viven en OrderService).
     */
    public function advance(Order $order, string $newStatus, int $userId): array
    {
        if (in_array($newStatus, [OrderConstants::STATUS_CANCELLED, OrderConstants::STATUS_RECEIVED], true)
            && $order->status !== OrderConstants::STATUS_RECEIVED) {
            return ['success' => false, 'errors' => [
                'Usar OrderService::cancel() o reactivate() para esta transición.'
            ]];
        }

        if (!$this->canTransition($order, $newStatus)) {
            return ['success' => false, 'errors' => [sprintf(
                'No se puede pasar de "%s" a "%s"%s.',
                OrderConstants::STATUS_LABELS[$order->status] ?? $order->status,
                OrderConstants::STATUS_LABELS[$newStatus] ?? $newStatus,
                $newStatus === OrderConstants::STATUS_ON_ROUTE ? ' (solo aplica a domicilio)' : '',
            )]];
        }

        $previousStatus = $order->status;
        $order->status = $newStatus;

        if ($newStatus === OrderConstants::STATUS_DELIVERED) {
            $order->delivered_at = new \Cake\I18n\DateTime();
        }

        $ordersTable = $this->fetchTable('Orders');
        if (!$ordersTable->save($order)) {
            return ['success' => false, 'errors' => $this->flattenErrors($order->getErrors())];
        }

        $this->history->logStateChanged($order, $userId, $previousStatus, $newStatus);

        Log::info('Order state changed: id={id} from={from} to={to}', [
            'id' => $order->id, 'from' => $previousStatus, 'to' => $newStatus,
            'scope' => ['orders'],
        ]);

        return ['success' => true, 'order' => $order];
    }

    /** Estados a los que SE PUEDE avanzar desde el actual (para UI). */
    public function nextValidStates(Order $order): array
    {
        return array_values(array_filter(
            self::TRANSITIONS[$order->status] ?? [],
            fn(string $s) => $this->canTransition($order, $s)
        ));
    }
}
```

### 5.3 `OrderHistoryService` — auditoría field-by-field

Métodos públicos:

| Método                                                                                         | Uso                                                                          |
|------------------------------------------------------------------------------------------------|------------------------------------------------------------------------------|
| `logCreated(Order $o, int $uid, string $extra = '')`                                            | kind=created; desc = "Pedido creado por {user_name}. {extra}".               |
| `logStateChanged(Order $o, int $uid, string $from, string $to)`                                  | kind=state_changed; desc = "Estado: de '{label_from}' a '{label_to}'".       |
| `logFieldChange(Order $o, int $uid, string $field, mixed $oldVal, mixed $newVal)`               | kind=field_changed; auto-normaliza valores antes de comparar.                |
| `logFieldChanges(Order $o, int $uid, array $snapshot)`                                          | Loop sobre cada campo del snapshot; llama a logFieldChange por cada diff.    |
| `logItemAdded(Order $o, int $uid, OrderItem $item)`                                              | kind=item_added; desc = "Agregado: 2 × Hamburguesa Especial".                |
| `logItemRemoved(Order $o, int $uid, OrderItem $item)`                                            | kind=item_removed.                                                            |
| `logItemsReplaced(Order $o, int $uid, array $oldItems, array $newItems)`                         | kind=item_changed; emite un solo log con un diff legible.                    |
| `logCancelled(Order $o, int $uid, string $reason = '')`                                          | kind=cancelled.                                                               |
| `logReactivated(Order $o, int $uid)`                                                             | kind=reactivated.                                                             |
| `logDeleted(Order $o, int $uid)`                                                                 | kind=deleted; SE LLAMA ANTES del delete físico.                              |

**Normalización en `logFieldChange`** (regla §4.13 ARQUITECTURE):

```php
private function normalize(mixed $value): mixed
{
    if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d H:i:s');
    if (is_bool($value)) return (bool)$value;
    if ($value === '') return null;
    if (is_numeric($value)) return (string)number_format((float)$value, 2, '.', '');
    return $value;
}
```

Solo se loguea si `normalize($old) !== normalize($new)`. Esto evita falsos positivos cuando, p. ej., el snapshot guarda `'10.00'` y la entidad después es float `10.0`.

**Persistencia:**

```php
private function persist(Order $order, int $userId, string $kind, string $description): void
{
    $userName = '—';
    if ($userId > 0) {
        $u = $this->fetchTable('Users')->find()->where(['Users.id' => $userId])->first();
        if ($u) $userName = $u->name ?: $u->username;
    }

    $log = $this->fetchTable('OrderLogs')->newEntity([
        'order_id'           => $order->id,
        'order_id_snapshot'  => $order->id,
        'user_id'            => $userId > 0 ? $userId : null,
        'user_name_snapshot' => $userName,
        'kind'               => $kind,
        'description'        => mb_substr($description, 0, 500),
    ]);

    if (!$this->fetchTable('OrderLogs')->save($log)) {
        Log::error('Failed to persist order log: {errors}', [
            'errors' => json_encode($log->getErrors()), 'scope' => ['orders', 'audit'],
        ]);
        // No tirar excepción — la falla de auditoría no debe abortar el flujo del pedido.
        // El error queda en el log de sistema para revisión.
    }
}
```

### 5.4 `OrderFilterService` — where-clauses sobre queries

```php
final class OrderFilterService
{
    /** @param array<string, mixed> $filters */
    public function apply(SelectQuery $query, array $filters): SelectQuery
    {
        // Estado
        $status = $filters['status'] ?? 'visible';
        if ($status === 'visible') {
            $query->where(['Orders.status !=' => OrderConstants::STATUS_CANCELLED]);
        } elseif ($status === 'all') {
            // no filtro
        } elseif (in_array($status, OrderConstants::STATUSES, true)) {
            $query->where(['Orders.status' => $status]);
        }

        // Tipo
        $type = $filters['type'] ?? 'all';
        if (in_array($type, OrderConstants::TYPES, true)) {
            $query->where(['Orders.type' => $type]);
        }

        // Método de pago
        $method = $filters['payment_method'] ?? 'all';
        if (in_array($method, OrderConstants::PAYMENT_METHODS, true)) {
            $query->where(['Orders.payment_method' => $method]);
        }

        // Repartidor (cuando un admin lo elige; el scoping forzado vive en AppController)
        if (!empty($filters['delivery_id'])) {
            $query->where(['Orders.delivery_id' => (int)$filters['delivery_id']]);
        }

        // Cliente: search por nombre o teléfono (snapshot O cliente actual)
        if (!empty($filters['customer'])) {
            $needle = '%' . $filters['customer'] . '%';
            $query->where([
                'OR' => [
                    'Orders.customer_name LIKE'  => $needle,
                    'Orders.customer_phone LIKE' => $needle,
                ],
            ]);
        }

        // Fechas
        if (!empty($filters['from'])) {
            $query->where(['Orders.created >=' => $filters['from'] . ' 00:00:00']);
        }
        if (!empty($filters['to'])) {
            $query->where(['Orders.created <=' => $filters['to'] . ' 23:59:59']);
        }

        // Search general (id, customer name/phone, product name in items)
        if (!empty($filters['q'])) {
            $q = trim((string)$filters['q']);
            // Si es numérico, intentar match exacto por id.
            if (ctype_digit($q)) {
                $query->where(['Orders.id' => (int)$q]);
            } else {
                $needle = '%' . $q . '%';
                $query->where([
                    'OR' => [
                        'Orders.customer_name LIKE'  => $needle,
                        'Orders.customer_phone LIKE' => $needle,
                    ],
                ]);
            }
        }

        return $query;
    }
}
```

---

## 6. Controllers

### 6.1 `OrdersController`

```php
class OrdersController extends AppController
{
    public array $paginate = [
        'limit' => 15, 'maxLimit' => 15,
        'order' => ['Orders.created' => 'DESC', 'Orders.id' => 'DESC'],
        'sortableFields' => ['created', 'id', 'total', 'status'],
    ];

    /** Override del mapeo acción→permiso para acciones custom. */
    protected array $actionModuleMap = [
        // Todas estas acciones pertenecen al módulo 'orders'; el mapeo a acción RBAC va en _actionToPermission.
    ];

    private OrderService $orderService;
    private OrderPipelineService $pipeline;
    private OrderFilterService $filters;

    public function initialize(): void
    {
        parent::initialize();
        $this->orderService = new OrderService();
        $this->pipeline = new OrderPipelineService();
        $this->filters = new OrderFilterService();
    }

    protected function _actionToPermission(string $action): string
    {
        return match ($action) {
            'index', 'view', 'ticket' => 'view',
            'add' => 'create',
            'edit', 'advance', 'reactivate' => 'edit',
            'cancel' => 'edit', // cancelar es una edición del estado, no un delete
            'delete' => 'delete',
            default => parent::_actionToPermission($action),
        };
    }
}
```

**Acciones:**

| Acción      | Verbo HTTP    | Permiso  | Notas                                                                              |
|-------------|---------------|----------|------------------------------------------------------------------------------------|
| `index`     | GET           | view     | Listado con KPI strip y filtros; scoping repartidor aplicado en `_buildQuery`.     |
| `view`      | GET           | view     | Detalle + items + timeline de logs (últimos 5).                                    |
| `add`       | GET/POST      | create   | Formulario complejo; ver §9.2.                                                     |
| `edit`      | GET/POST      | edit     | Solo si `order->isEditable()`. Si no, redirect con flash.                          |
| `advance`   | POST          | edit     | Recibe `to_status`; delega a `pipeline->advance`.                                  |
| `cancel`    | POST          | edit     | Delega a `orderService->cancel`.                                                   |
| `reactivate`| POST          | edit     | Delega a `orderService->reactivate`.                                               |
| `delete`    | POST/DELETE   | delete   | Hard-delete; bloqueado si tiene abonos.                                            |
| `ticket`    | GET           | view     | HTML imprimible (layout 'ticket'); accesible solo a quien puede ver el pedido.     |

**Scoping de repartidor** se aplica en `_buildIndexQuery()` y como **guard** en `view`/`edit`/`ticket`/`cancel`/`advance`/`delete` — si el `currentUser->delivery_id` no matchea `order->delivery_id`, tirar `ForbiddenException`.

```php
private function _ensureRepartidorAccess(Order $order): void
{
    $current = $this->Authentication->getIdentity()?->getOriginalData();
    $deliveryId = $current?->delivery_id ?? null;
    if ($deliveryId !== null && (int)$order->delivery_id !== (int)$deliveryId) {
        throw new ForbiddenException('No tenés acceso a este pedido.');
    }
}
```

### 6.2 `OrderLogsController` (auditoría)

```php
class OrderLogsController extends AppController
{
    protected array $controllerModuleMap = []; // override

    /** Esta clase usa el módulo 'audit' SIEMPRE. */
    protected function _actionToPermission(string $action): string { return 'view'; }

    public function initialize(): void
    {
        parent::initialize();
        $this->controllerModuleMap = ['OrderLogs' => 'audit'];
    }

    public array $paginate = [
        'limit' => 25, 'maxLimit' => 50,
        'order' => ['OrderLogs.created' => 'DESC', 'OrderLogs.id' => 'DESC'],
    ];

    /** GET /order-logs?order_id=N (filtrable) o sin filtro (global) */
    public function index() { ... }

    /** GET /order-logs/view/N — detalle de un log puntual (raro; opcional) */
    public function view(int $id) { ... }
}
```

Como `audit` solo lo accede Admin, el bypass de `AuthorizationService::isAllowed()` (línea 44 del servicio actual) le da acceso. Para roles no-admin, no se siembra ningún permiso de `audit`, así que el `isAllowed` devolverá `false` → `ForbiddenException`. Esto es exactamente lo que spec §9 pide.

### 6.3 Rutas (`config/routes.php`)

CRUD estándar (`/orders`, `/orders/view/:id`, etc.) lo cubre `fallbacks()`. Las acciones custom requieren registro explícito **antes** del fallback:

```php
$builder->scope('/', function (RouteBuilder $routes) {
    // ... rutas existentes ...

    // Orders
    $routes->connect('/orders/advance/{id}',
        ['controller' => 'Orders', 'action' => 'advance'],
        ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST']
    );
    $routes->connect('/orders/cancel/{id}',
        ['controller' => 'Orders', 'action' => 'cancel'],
        ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST']
    );
    $routes->connect('/orders/reactivate/{id}',
        ['controller' => 'Orders', 'action' => 'reactivate'],
        ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST']
    );
    $routes->connect('/orders/ticket/{id}',
        ['controller' => 'Orders', 'action' => 'ticket'],
        ['id' => '\d+', 'pass' => ['id'], '_method' => 'GET']
    );

    // Audit log (admin only enforcement vía AuthorizationService)
    $routes->connect('/audit',
        ['controller' => 'OrderLogs', 'action' => 'index']
    );

    $routes->fallbacks(); // siempre al final
});
```

---

## 7. RBAC integration

### 7.1 `AppController::$controllerModuleMap`

```php
protected array $controllerModuleMap = [
    // existentes ...
    'Adjustments' => 'adjustments',
    // nuevos:
    'Orders'      => 'orders',
    'OrderLogs'   => 'audit',
];
```

### 7.2 `AuthorizationService::MODULES`

```php
public const MODULES = [
    // existentes ...
    'adjustments' => 'Ajustes de Inventario',
    // nuevos:
    'orders'      => 'Pedidos',
    'audit'       => 'Auditoría',
];
```

### 7.3 Seed de permisos

Dos migraciones: `SeedOrdersPermissions` y `SeedAuditPermissions`.

**`SeedOrdersPermissions`** — todos los roles no-admin reciben `view` + `create` + `edit` (operación cotidiana) pero **no** `delete` por default (operativo conservador):

```sql
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
SELECT r.id, 'orders', 1, 1, 1, 0, NOW(), NOW()
FROM roles r
WHERE r.is_admin = 0
  AND NOT EXISTS (
    SELECT 1 FROM permissions p WHERE p.role_id = r.id AND p.module = 'orders'
  );

-- Admin matrix completa
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
SELECT r.id, 'orders', 1, 1, 1, 1, NOW(), NOW()
FROM roles r
WHERE r.is_admin = 1
  AND NOT EXISTS (
    SELECT 1 FROM permissions p WHERE p.role_id = r.id AND p.module = 'orders'
  );
```

**`SeedAuditPermissions`** — **NO** sembrar para roles no-admin. El admin lo accede vía bypass. La columna `audit` simplemente no aparece en la matriz de Roles hasta que un admin decida abrirlo a otro rol (decisión consciente, no default).

---

## 8. Repartidor scoping

### 8.1 Helper centralizado en `AppController`

Reutilizable por Orders y por módulos futuros (CxC del repartidor, dashboard personal del repartidor, etc.):

```php
/**
 * Si el usuario actual está vinculado a un repartidor (delivery_id != null),
 * restringe la query a sus propios pedidos. Regla §21 acceso 4.
 */
protected function _scopeToRepartidor(SelectQuery $query, string $alias = 'Orders'): SelectQuery
{
    $current = $this->Authentication->getIdentity()?->getOriginalData();
    $deliveryId = $current?->delivery_id ?? null;
    if ($deliveryId !== null) {
        $query->where(["{$alias}.delivery_id" => (int)$deliveryId]);
    }
    return $query;
}

/**
 * Para guards de view/edit: si el currentUser es repartidor y el pedido no
 * es suyo, tirar 403.
 */
protected function _enforceRepartidorAccess(Order $order): void
{
    $current = $this->Authentication->getIdentity()?->getOriginalData();
    $deliveryId = $current?->delivery_id ?? null;
    if ($deliveryId !== null && (int)$order->delivery_id !== (int)$deliveryId) {
        throw new ForbiddenException('No tenés acceso a este pedido.');
    }
}
```

### 8.2 Aplicación

| Punto                              | Uso                                                                  |
|------------------------------------|----------------------------------------------------------------------|
| `OrdersController::_buildIndexQuery` | `_scopeToRepartidor($query)` antes de aplicar filtros del user.    |
| `OrdersController::view/edit/etc.` | `_enforceRepartidorAccess($order)` después de `$this->Orders->get()`.|
| `OrdersController::add`            | Si user es repartidor, **bloquear**: un repartidor no crea pedidos. Tirar 403 o redirect con flash. (Decisión: bloquear; repartidor solo consulta.) |
| Dashboard (módulo futuro)          | Vista personalizada del repartidor — usar el mismo scope.             |

### 8.3 Orden de chequeos

```
beforeFilter
   └─ enforcePermission (RBAC matrix or admin bypass)
       └─ action handler
           └─ _scopeToRepartidor (restringe queries de listado)
           └─ _enforceRepartidorAccess (guard en acciones puntuales)
```

El scoping **complementa** al RBAC: aunque el rol diga "ver todos los pedidos", la verificación por repartidor recorta. **Pero** el rol también debe permitir la acción base — si el rol dice "no ver pedidos" y el user es repartidor, no ve nada (correcto: el sistema lo trata primero como user del sistema, segundo como repartidor).

---

## 9. Screens & UX

Todas usan `default.php` salvo `ticket` que usa `ticket.php` (layout sin sidebar/topbar, optimizado para impresión).

### 9.1 `index.php` — Listado de pedidos

**Header:**

- `h1.dr-page-title`: "Pedidos".
- `button-primary` único: "Nuevo pedido" → `/orders/add`. Solo visible si `userPermissions.orders.create` y user NO es repartidor.

**KPI strip (4 `stat-card` en grid):**

| Card                    | Valor                                                                 |
|-------------------------|-----------------------------------------------------------------------|
| Pedidos hoy             | `count(orders where status != cancelled AND date(created) = today)`   |
| Ventas hoy              | `sum(total where ...same)` formateado COP                              |
| En preparación          | `count(orders where status = preparando)`                              |
| En camino               | `count(orders where status = en_camino)`                               |

Si el user es repartidor, los KPIs cambian a su contexto:

| Card                | Valor                                                                  |
|---------------------|------------------------------------------------------------------------|
| Mis entregas hoy    | `count(delivered today by me)`                                          |
| Mis ganancias hoy   | `sum(shipping_cost of my delivered today)`                              |
| Pendientes          | `count(my orders where status IN (recibido, preparando, en_camino))`   |
| Cancelados (hoy)    | `count(my cancelled today)`                                            |

**Filtros (card horizontal, controles a 40px):**

1. Input `q` — search general (id, nombre, teléfono). `placeholder="Buscar #ID o cliente"`. 280px.
2. Select `status` — opciones: `Visibles` (default), `Todos`, `Recibido`, `Preparando`, `En camino`, `Entregado`, `Cancelado`. 160px.
3. Select `type` — `Todos`, `Local`, `Domicilio`. 140px.
4. Select `payment_method` — `Todos`, `Efectivo`, `Nequi`, `Daviplata`, `Transferencia`, `Crédito`. 160px.
5. Select `delivery_id` — `Todos`, lista de repartidores. **Oculto si user es repartidor.** 200px.
6. Input `from` (date). 160px.
7. Input `to` (date). 160px.
8. `button-secondary` "Filtrar". `link.btn-light` "Limpiar" si hay filtros activos.

**Tabla (`card` + `dr-table`):**

| Columna           | Width  | Alineación | Contenido                                                                                                |
|-------------------|--------|------------|----------------------------------------------------------------------------------------------------------|
| #                 | 70px   | left       | `<a href="/orders/view/{id}">#{id}</a>` mono.                                                            |
| Fecha             | 140px  | left       | `$order->created->i18nFormat('dd/MM HH:mm')`. Sortable.                                                   |
| Cliente           | auto   | left       | `getCustomerName()` + small mute con teléfono.                                                            |
| Tipo              | 100px  | center     | Badge `badge-soft-info` "Local" o `badge-soft-primary` "Domicilio".                                       |
| Productos         | auto   | left       | `$order->getItemsSummary()` — "2 × Hamburguesa (+1 más)".                                                |
| Total             | 110px  | right      | `$this->Number->currency($order->total, 'COP', ['places' => 0])`. Sortable.                              |
| Pago              | 110px  | left       | Label del método (`PAYMENT_LABELS[order->payment_method]`).                                              |
| Repartidor        | 130px  | left       | `$order->delivery?->name ?? '—'`. **Oculto si user es repartidor.**                                       |
| Estado            | 130px  | center     | Chip de la familia `status-*` (`<span class="{getStatusCssClass}">{getDisplayStatus}</span>`).           |
| Acciones          | 140px  | right      | Botones-ícono: Ver (bi-eye), Ticket (bi-printer), Avanzar (bi-arrow-right), Cancelar (bi-x).             |

**Botones de acción por estado:**

| Estado actual    | Botones visibles (además de Ver/Ticket)                                        |
|------------------|--------------------------------------------------------------------------------|
| recibido         | Avanzar → "Preparar", Cancelar                                                  |
| preparando       | Avanzar → "En camino" (si domicilio) o "Entregar" (si local), Cancelar          |
| en_camino        | Avanzar → "Entregar", Cancelar                                                  |
| entregado        | (ninguno — terminal)                                                            |
| cancelado        | Reactivar (solo si user puede `edit`), Eliminar (si user puede `delete`)        |

**Empty state:** "No hay pedidos visibles. ¿Querés crear el primero?" + button-primary.

**Footer:** `<?= $this->element('pagination') ?>`.

### 9.2 `add.php` — Crear pedido

Form complejo de 5 secciones. Usar `<form>` con submit clásico (no SPA); JS opcional para autocompletes y previsualización.

**Section 1 — Cliente**

```
┌──────────────────────────────────────────────────────────────┐
│ Cliente                                                       │
├──────────────────────────────────────────────────────────────┤
│  Teléfono:    [_________________] 🔍   (autocomplete async)   │
│  Nombre:      [_________________]                              │
│  Dirección:   [_________________]      (visible si Domicilio) │
│                                                                │
│  ☑ Cliente existente: Juan Pérez (#42)  [Quitar vínculo]      │
└──────────────────────────────────────────────────────────────┘
```

- El teléfono es input-first (workflow del cajero: "¿De parte de quién?"). Datalist o autocomplete asíncrono a `/customers/search.json?q={phone}` que devuelve `[{id, name, phone, address}]`. Al elegir, autocompleta nombre/dirección y guarda `customer_id` en un hidden.
- Si el cajero escribe un teléfono sin match → `customer_id` queda null; al guardar a crédito, `findOrCreateByPhone` se encarga.
- Para `Local` la dirección puede ocultarse vía JS, pero el campo igual va en el form (puede quedar vacío).

**Section 2 — Tipo de pedido**

```
┌────────────────────────────────────────────┐
│ Tipo                                        │
│  ⊙ Local      ⊙ Domicilio                  │
│                                             │
│ ── visible si Domicilio ──                  │
│  Repartidor: [select_____________________▾] │
│  Costo de envío: [______] COP               │
└────────────────────────────────────────────┘
```

Radios grandes (chips visuales con icono). Mostrar/ocultar campos de domicilio con JS; sin JS, se ven siempre y la validación del backend bloquea si faltan en domicilio.

**Section 3 — Productos (líneas repeatable)**

```
┌─────────────────────────────────────────────────────────────────────┐
│ Productos                                            [+ Agregar]    │
├─────────────────────────────────────────────────────────────────────┤
│ ┌─────────────────────────────────────────────────────────────────┐│
│ │ Producto: [autocomplete...▾]  Cantidad: [__] Subtotal: $14.000 [✕]││
│ └─────────────────────────────────────────────────────────────────┘│
│ ┌─────────────────────────────────────────────────────────────────┐│
│ │ Producto: [Hamburguesa Doble ▾]  Cantidad: [2] Subtotal: $30.000 [✕]││
│ │ Notas: [sin cebolla__________]                                    ││
│ └─────────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────────┘
```

- Autocomplete sobre `/products/search.json?q={...}&active=1` (solo productos activos; spec §5).
- Al seleccionar, JS lee `data-price` del option y calcula `subtotal` en vivo.
- Cantidad: `step="1"` por default; permitir override con `0.5` si product es vendible por peso (out of scope para fase 1, default entero).
- Botón "Agregar" añade fila (template literal en JS clonando una `<template>` server-rendered).
- Botón [✕] elimina fila (mínimo 1 fila siempre).
- Sin JS: el server-side render comienza con 1 fila vacía; submit con productos vacíos falla en validación.

**Section 4 — Método de pago**

```
┌──────────────────────────────────────────┐
│ Método de pago                            │
│  ⊙ Efectivo  ⊙ Nequi  ⊙ Daviplata        │
│  ⊙ Transferencia  ⊙ Crédito (Fiado)      │
│                                            │
│  ⚠ Si elegís Crédito, se genera una       │
│  Cuenta por Cobrar a nombre del cliente.  │
└──────────────────────────────────────────┘
```

Radios visuales (chips). Si Crédito, alerta soft naranja recordando la consecuencia.

**Section 5 — Resumen + acciones**

```
┌──────────────────────────────────────────┐
│ Resumen                                   │
│  Subtotal:        $30.000                 │
│  Envío:           $ 5.000                 │
│  ──────────                               │
│  Total:           $35.000  ← tipografía xl│
│                                            │
│  Notas del pedido: [_______________]       │
│                                            │
│  [Cancelar]  [Guardar pedido]             │
└──────────────────────────────────────────┘
```

- "Guardar pedido" = `button-primary` único de la pantalla.
- "Cancelar" = `button-tertiary`, navega a `/orders`.

### 9.3 `view.php` — Detalle del pedido

```
┌────────────────────────────────────────────────────────────────────┐
│ Pedido #1234   [status chip: PREPARANDO]      [Imprimir ticket]   │
│ Creado el 24/05/2026 14:32 por jhon                                 │
├────────────────────────────────────────────────────────────────────┤
│ Acciones:                                                            │
│ [Avanzar a "En camino"]  [Cancelar]                                 │
│ (botones según next_valid_states; reactivar y eliminar si aplica)   │
├──────────────────────┬─────────────────────────────────────────────┤
│ Cliente              │ Entrega                                       │
│ Juan Pérez            │ Tipo: Domicilio                              │
│ +57 312 555 1234      │ Dirección: Calle 123 #45                     │
│ Cliente #42 ↗         │ Repartidor: María Gómez                      │
│                       │ Costo envío: $5.000                          │
├──────────────────────┴─────────────────────────────────────────────┤
│ Productos                                                            │
│  ┌────────────────────────────────┬───────┬────────┬──────────┐    │
│  │ Producto                        │ Cant. │ Precio │ Subtotal │    │
│  ├────────────────────────────────┼───────┼────────┼──────────┤    │
│  │ Hamburguesa Doble                │   2   │ 15.000 │   30.000 │    │
│  │ Papas grandes                    │   1   │  8.000 │    8.000 │    │
│  └────────────────────────────────┴───────┴────────┴──────────┘    │
│                                              Subtotal:   $38.000     │
│                                              Envío:       $5.000     │
│                                              Total:      $43.000     │
│  Pago: Crédito  (CxC #17 ↗)                                          │
│  Notas: "Entregar antes de las 9pm"                                  │
├────────────────────────────────────────────────────────────────────┤
│ Historial reciente                                  [Ver todo →]    │
│ ● Cambio de estado: de 'Recibido' a 'Preparando' — por jhon, 14:35  │
│ ● Pedido creado — por jhon, 14:32                                   │
└────────────────────────────────────────────────────────────────────┘
```

- Header con el ID grande, chip de status, autor.
- Barra de acciones que muestra solo lo permitido según `pipeline->nextValidStates($order)` y permisos del user.
- Card de Cliente con link a `/customers/view/{id}` si `customer_id`.
- Card de Entrega solo si `isDomicilio`.
- Tabla de items.
- Sección de "Historial reciente": últimos 5 logs en formato timeline (icono + descripción + autor + fecha relativa). Link "Ver todo" → `/audit?order_id={id}` (admin only) o `/orders/view/{id}#history` con paginación local.

### 9.4 `edit.php` — Editar pedido

Reusa la estructura de `add.php` con los campos pre-rellenados. **Solo accesible si `order->isEditable()`** (`recibido` o `preparando`); en otro caso, redirect con flash explicativo.

Adiciones vs `add`:

- Alerta amarilla al tope: "Editar un pedido restaurará los insumos actuales y descontará los nuevos. Si cambiás método de pago, también se ajustará la cuenta por cobrar."
- Si el pedido es a crédito y tiene abonos, **bloquear** el cambio de método de pago (deshabilitar el radio "Efectivo/...").

### 9.5 `ticket.php` (layout dedicado)

Layout sin sidebar/topbar, monospace, max-width 280px (ancho típico de ticket térmico 80mm).

```
╔════════════════════════════════╗
       DAVI RAPID
       Calle 100 #50-10
       NIT: 900.123.456-7
       Tel: 555-1234
════════════════════════════════
  PEDIDO #1234       24/05 14:32
════════════════════════════════
Cliente: Juan Pérez
Tel: +57 312 555 1234
Dirección: Calle 123 #45
Tipo: DOMICILIO
Repartidor: María Gómez
────────────────────────────────
2 × Hamburguesa Doble   30.000
1 × Papas grandes        8.000
────────────────────────────────
Subtotal:               38.000
Envío:                   5.000
TOTAL:                  43.000
────────────────────────────────
Pago: Crédito (Fiado)

Notas: "Entregar antes de las 9pm"
════════════════════════════════
  ¡Gracias por su compra!
╚════════════════════════════════╝

           [Imprimir]
```

Auto-print al cargar:

```html
<script>
  if (new URL(window.location).searchParams.get('autoprint') !== '0') {
    window.addEventListener('load', () => setTimeout(() => window.print(), 300));
  }
</script>
```

Botón "Imprimir" visible solo en pantalla (`@media print { .no-print { display: none; } }`).

Datos del negocio (logo, NIT, dirección, teléfono): leídos de `config/app.php` o de una futura tabla `business_info`. **Fase 1:** hardcoded en el template con TODO para parametrizar.

### 9.6 Sidebar / navegación

- Item "Pedidos" en grupo **Operación**, icono `bi-bag` o `bi-receipt`. Visible si `userPermissions.orders.view`.
- Counter opcional al lado del item: `count(orders status IN (recibido, preparando, en_camino))` calculado en `AppController::beforeFilter` y expuesto vía `sidebarCounters['orders'] = N`. Refresca en cada request — barato.
- Item "Auditoría" en grupo **Administración**, icono `bi-clipboard-data`. Visible solo si `isAdministrator`.

### 9.7 Order logs UI

**Vista timeline embedded en `view.php`** (últimos 5):

```
● [icon] Estado: de 'Recibido' a 'Preparando'    — jhon, hace 12 min
● [icon] Pedido creado                            — jhon, hace 15 min
```

**Vista global `/audit`** (admin only):

- Tabla compacta: Fecha, #Pedido (link si !orphan), Autor, Tipo, Descripción.
- Filtros: order_id (input numérico), user_id (select), kind (select), date range.
- Sin acción de delete (auditoría es inmutable).

---

## 10. State machine — tabla completa

| from         | acción         | to            | side effects                                                                                       | quién la dispara             |
|--------------|----------------|---------------|----------------------------------------------------------------------------------------------------|-------------------------------|
| (none)       | create         | recibido      | + crear order + items, − stock vía recipes, (+ CxC si crédito), log `created`                       | OrderService::create          |
| recibido     | advance        | preparando    | log `state_changed`                                                                                | OrderPipelineService::advance |
| recibido     | advance/cancel | cancelado     | + stock restaurado, − CxC si era crédito (bloquea si hay abonos), set cancelled_at/by, log `cancelled` | OrderService::cancel       |
| preparando   | advance        | en_camino     | log `state_changed`. **Solo si type=domicilio.**                                                    | OrderPipelineService::advance |
| preparando   | advance        | entregado     | set delivered_at, log `state_changed`. **Solo si type=local.**                                      | OrderPipelineService::advance |
| preparando   | cancel         | cancelado     | igual que recibido→cancelado                                                                       | OrderService::cancel          |
| en_camino    | advance        | entregado     | set delivered_at, log `state_changed`. **Solo si type=domicilio** (es la única ruta hacia entregado).| OrderPipelineService::advance |
| en_camino    | cancel         | cancelado     | igual                                                                                              | OrderService::cancel          |
| entregado    | (nada)         | terminal      | —                                                                                                  | —                              |
| cancelado    | reactivate     | recibido      | − stock re-descontado (bloquea si insuficiente), + CxC recreada si era crédito, clear cancelled_at/by, log `reactivated` | OrderService::reactivate |
| cancelado    | delete         | (sin fila)    | − pedido + items borrados (dependent), logs huérfanos preservados, log `deleted` ANTES del delete físico, (revisar abonos) | OrderService::delete |

**Decisiones:**

- `preparando → entregado` está habilitado **solo** para local. Domicilio debe pasar por `en_camino`. La regla la enforcea `OrderPipelineService::canTransition`.
- `cancelado → entregado` no existe — hay que reactivar primero (regreso a `recibido`).
- No hay reactivación automática de `entregado`; un pedido entregado es definitivo. Si fue marcado por error, la corrección es operativa (no técnica).

---

## 11. Inventory integration

### 11.1 Pseudocódigo de `OrderService::create`

```text
function create(data, userId) {
    // 1. Validar input (formato)
    err = validateCreateInput(data); if (err) return ['success'=>false, 'errors'=>err];

    // 2. Pre-load productos para snapshots y validación de existencia
    productIds = pluck(data.items, 'product_id');
    products = fetchTable('Products')->find()->where(['id IN'=>productIds])->indexBy('id')->toArray();
    foreach (productIds as $pid) {
        if (!isset($products[$pid])) return error("Producto #{$pid} no encontrado");
        if (!$products[$pid]->is_available) return error("Producto '{$products[$pid]->name}' no está disponible");
    }

    $conn = ConnectionManager::get('default');
    $resultBox = ['success'=>false, 'errors'=>['Error desconocido']];

    $conn->transactional(function () use (...) use (&$resultBox) {

        // 3. Resolver/crear cliente si crédito
        $customerId = data.customer_id ?? null;
        if (data.payment_method === PAYMENT_CREDIT && $customerId === null) {
            $cust = $this->customers->findOrCreateByPhone([
                'phone' => data.customer_phone,
                'name'  => data.customer_name,
                'address' => data.customer_address,
            ]);
            $customerId = $cust->id;
        }

        // 4. Construir Order
        $order = ordersTable->newEntity([
            'customer_id'      => $customerId,
            'delivery_id'      => data.type === TYPE_DOMICILIO ? data.delivery_id : null,
            'user_id'          => $userId,
            'type'             => data.type,
            'status'           => STATUS_RECEIVED,
            'payment_method'   => data.payment_method,
            'customer_name'    => data.customer_name,
            'customer_phone'   => data.customer_phone,
            'customer_address' => data.type === TYPE_DOMICILIO ? data.customer_address : null,
            'shipping_cost'    => data.type === TYPE_DOMICILIO ? data.shipping_cost : '0.00',
            'notes'            => data.notes,
        ]);

        // 5. Construir items con snapshots y subtotal
        $items = [];
        $subtotal = 0.0;
        foreach (data.items as $line) {
            $prod = $products[$line['product_id']];
            $qty = (float)$line['quantity'];
            $price = (float)$prod->price;
            $lineSubtotal = round($price * $qty, 2);
            $subtotal += $lineSubtotal;
            $items[] = orderItemsTable->newEntity([
                'product_id'    => $prod->id,
                'product_name'  => $prod->name,
                'quantity'      => number_format($qty, 3, '.', ''),
                'price_at_sale' => number_format($price, 2, '.', ''),
                'line_subtotal' => number_format($lineSubtotal, 2, '.', ''),
                'notes'         => $line['notes'] ?? null,
            ]);
        }
        $order->subtotal = number_format($subtotal, 2, '.', '');
        $order->total    = number_format($subtotal + (float)$order->shipping_cost, 2, '.', '');
        $order->order_items = $items;

        // 6. Persist
        if (!ordersTable->save($order, ['associated' => ['OrderItems']])) {
            $resultBox = ['success'=>false, 'errors'=>flattenErrors($order->getErrors())];
            return false;
        }

        // 7. Descontar stock vía recipes
        foreach (data.items as $line) {
            $plan = $this->recipes->buildDecrementPlan($line['product_id'], (int)$line['quantity']);
            if (empty($plan)) {
                Log::info('Product without recipe sold: id={pid}', ['pid'=>$line['product_id']]);
                continue;
            }
            foreach ($plan as $step) {
                $ing = fetchTable('Ingredients')->get($step['ingredient_id']);
                $r = $this->ingredients->adjustStock($ing, '-' . $step['quantity'], "Pedido #{$order->id}");
                if (!$r['success']) {
                    $resultBox = ['success'=>false, 'errors'=>$r['errors']];
                    return false; // rollback
                }
            }
        }

        // 8. CxC si crédito
        if ($order->isCredit() && $this->receivables !== null) {
            $r = $this->receivables->createFromOrder($order, $customerId);
            if (!$r['success']) { $resultBox = ['success'=>false, 'errors'=>$r['errors']]; return false; }
        } elseif ($order->isCredit()) {
            Log::warning('CxC not created: ReceivableService not wired yet for order #{id}', ['id'=>$order->id]);
        }

        // 9. Auditoría
        $this->history->logCreated($order, $userId);

        Log::info('Order created: id={id} type={t} method={m} total={tot}', [
            'id'=>$order->id,'t'=>$order->type,'m'=>$order->payment_method,
            'tot'=>$order->total,'scope'=>['orders'],
        ]);

        $resultBox = ['success'=>true, 'order'=>$order];
        return true;
    });

    return $resultBox;
}
```

### 11.2 Pseudocódigo de `cancel`

```text
function cancel(order, userId, reason='') {
    if (!order->isCancellable()) return error('Estado actual no admite cancelación');

    $conn->transactional(function () {
        // 1. Restaurar stock
        foreach ($order->order_items as $item) {
            $plan = recipes->buildDecrementPlan($item->product_id, (int)$item->quantity);
            foreach ($plan as $step) {
                $ing = fetchTable('Ingredients')->get($step['ingredient_id']);
                $r = ingredients->adjustStock($ing, '+'.$step['quantity'], "Cancelación pedido #{$order->id}");
                if (!$r['success']) return false; // raro, pero rollback
            }
        }

        // 2. Cancelar CxC si crédito (bloquea si hay abonos)
        if ($order->isCredit() && $this->receivables !== null) {
            $r = $this->receivables->cancelFromOrder($order->id);
            if (!$r['success']) return false; // probable causa: hay abonos
        }

        // 3. Mutar order
        $order->status = STATUS_CANCELLED;
        $order->cancelled_at = new DateTime();
        $order->cancelled_by = $userId;
        if (!ordersTable->save($order)) return false;

        // 4. Auditoría
        $this->history->logCancelled($order, $userId, $reason);

        return true;
    });
}
```

### 11.3 Pseudocódigo de `reactivate`

Idéntico a `create` pero solo para los items existentes y sin recrear el pedido. La validación crítica es que `adjustStock` no falle por stock insuficiente; si falla, se aborta con mensaje claro.

### 11.4 Pseudocódigo de `update`

Restaurar viejo + persistir nuevo + descontar nuevo. La regla §21 inventario 3 lo demanda. Estructura:

```text
function update(order, data, userId) {
    if (!order->isEditable()) return error('No editable en estado actual');

    $oldSnapshot = snapshot(order);
    $oldItems = clone(order->order_items);

    $conn->transactional(function () {
        // 1. Restaurar stock viejo
        restoreStockFor($oldItems);

        // 2. Recrear items
        orderItemsTable->deleteAll(['order_id'=>$order->id]);
        buildAndAttachItems($order, data['items']);

        // 3. Recalcular totales
        recomputeTotals($order, data);

        // 4. Manejar transición de payment_method
        if ($oldSnapshot['payment_method'] !== $order->payment_method) {
            handlePaymentMethodTransition($order, $oldSnapshot['payment_method']);
        }

        // 5. Persistir order
        ordersTable->save($order, ['associated'=>['OrderItems']]);

        // 6. Descontar stock nuevo
        decrementStockFor($order->order_items);

        // 7. Auditoría: diff campo a campo + items
        $this->history->logFieldChanges($order, $userId, $oldSnapshot);
        $this->history->logItemsReplaced($order, $userId, $oldItems, $order->order_items);
    });
}
```

---

## 12. Edge cases & business rules

| Caso                                                          | Decisión                                                                                                                                                |
|---------------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------|
| Domicilio sin `delivery_id`                                   | Bloquear en service (`validateCreateInput`). Mensaje: "Asigná un repartidor para pedidos a domicilio".                                                  |
| Domicilio sin `customer_address`                              | Bloquear en service. Mensaje: "La dirección es obligatoria para domicilio".                                                                              |
| Domicilio con `shipping_cost = 0`                             | Permitir (el negocio puede absorberlo); warning soft en UI ("¿Sin envío?") pero no bloquea.                                                              |
| Local con `delivery_id != null` (UI manipulada)               | Service fuerza `delivery_id = null` y `shipping_cost = 0` para type=local (sanitización).                                                                 |
| Producto sin receta                                            | Spec §21 inventario 4: no se hace movimiento. `Log::info` con `product_id`. NO es error.                                                                 |
| Producto inactivo (`is_available = false`)                    | Bloquear en pre-validación de create. En edit: si ya estaba en el pedido y sigue inactivo, permitir (no romper edición de pedidos viejos); si se agrega uno nuevo, bloquear. |
| Stock insuficiente para una línea                              | `adjustStock` retorna `success=false`; service propaga, rollback completo, flash con mensaje específico de qué ingrediente faltó.                       |
| Edit: paso de no-crédito a crédito                             | Service crea CxC en mismo flujo. Requiere `customer_phone`.                                                                                              |
| Edit: paso de crédito a no-crédito                             | Service cancela CxC asociada. **Bloquea si hay abonos** (`receivables->hasPayments`).                                                                    |
| Edit: cambio de `total` de pedido a crédito (ya existe CxC)   | `receivables->adjustForOrder(order, new_total)` actualiza la CxC.                                                                                        |
| Delete con abonos                                              | Bloquear. Mensaje: "Tiene abonos; cancelalo en su lugar (mantiene historial y CxC con saldo)".                                                            |
| Concurrencia: dos cajeros venden el último ingrediente         | `adjustStock` ya usa `SELECT ... FOR UPDATE` por ingrediente. Los dos creates se serializan; el segundo falla con stock insuficiente y rollback.        |
| Multi-product order ticket impreso por línea                   | El template `ticket.php` recibe el `order` completo y renderiza todas las líneas. Spec §8.6: ticket individual (1 línea) o grupal (todo). Fase 1 solo grupal; individual opcional vía `?line={id}`. |
| Auto-creación de cliente con teléfono sin formato              | Normalizar en `findOrCreateByPhone`: strip whitespace y caracteres no-dígito **salvo `+`**. Match exacto contra `customers.phone`. La regla §6: "el cliente se crea automáticamente". |
| Cliente eliminado tras crear el pedido                         | `customer_id` queda null por SET NULL. Snapshot `customer_name/phone/address` preserva los datos. UI muestra "Cliente eliminado" en gris donde correspondía el link. |
| Repartidor eliminado tras asignación                           | `delivery_id` queda null por SET NULL. UI muestra "Repartidor eliminado". Métricas del repartidor borrado quedan huérfanas; dashboard las agrupa en bucket "Sin repartidor" o las excluye. |
| Usuario creador eliminado                                       | `user_id` null + log preserva `user_name_snapshot`. UI muestra "Usuario eliminado" en lugar del autor.                                                  |
| Reactivar pedido cuyo producto ya no existe                    | Producto SET NULL pero snapshot `product_name` preservado en `order_items`. No hay receta porque no hay producto → no se descuenta nada. Warning en log. |
| Reactivar pedido con stock insuficiente para algún ingrediente | Rollback total. Flash: "No se puede reactivar: stock insuficiente para Carne (requiere 200gr, disponible 50gr). Registrá un ajuste de inventario primero." |
| Pedido creado con `customer_id` provisto + snapshots vacíos    | Hidratar snapshots desde el cliente al guardar. Decisión en service: snapshots siempre populados, vienen del input o del cliente referenciado.          |
| Concurrencia en cambio de estado                                | Dos usuarios marcando "entregado" simultáneamente: el segundo encuentra `status=entregado` y `canTransition` falla — mensaje claro. No requiere lock pesado. |
| Timezone del `delivered_at`                                    | Almacenar UTC (default Cake). UI con `i18nFormat` respeta `App.defaultTimezone`.                                                                          |
| Borrar un pedido cuyo único log es `created`                   | El log permanece huérfano (`order_id=null`, `order_id_snapshot=N`). Se agrega un nuevo log `deleted` antes del delete físico.                            |
| Volver a leer un log de un pedido borrado                       | `OrderLog::isOrphan()` retorna true. UI muestra "(Pedido eliminado)" en lugar de link.                                                                  |
| Edit que cambia el `type` de local a domicilio                  | Service permite, valida que se haya provisto `delivery_id` + `customer_address`. Log de auditoría: `field_changed type local→domicilio`.                |
| Multi-decimal en `quantity` ('2.5' kg de papas)                | DB acepta decimal(10,3). UI por default usa `step="1"`; pasar a `step="0.001"` solo si la línea de negocio lo requiere (fuera de alcance).             |
| Cancel masivo                                                   | Out of scope. Si en algún momento se pide, agregar `cancelMany(array $ids)` que itera y reporta éxitos/fallas por id.                                   |
| Pedido con 0 productos en submit                                | Bloqueo en `validateCreateInput`. "Agregá al menos un producto".                                                                                         |
| Items duplicados (mismo `product_id` 2 veces)                  | Permitir: cajero puede haber clickeado "agregar" dos veces. Service no agrupa automáticamente; cada fila persiste independiente.                        |
| Notas con XSS                                                    | Todos los renders en templates usan `h()`. Las notas también van escapadas en el ticket impreso.                                                        |

---

## 13. Tests to write later

> El proyecto opta-out de tests automatizados (memoria del usuario). Esta
> sección queda como **referencia** para cuando esa decisión se revierta.
> **No** escribir ningún archivo de test en la implementación actual.

**Archivos esperados:**

```
tests/TestCase/Model/Entity/OrderTest.php
tests/TestCase/Model/Entity/OrderItemTest.php
tests/TestCase/Model/Entity/OrderLogTest.php
tests/TestCase/Model/Table/OrdersTableTest.php
tests/TestCase/Model/Table/OrderItemsTableTest.php
tests/TestCase/Model/Table/OrderLogsTableTest.php
tests/TestCase/Service/OrderServiceTest.php
tests/TestCase/Service/OrderPipelineServiceTest.php
tests/TestCase/Service/OrderHistoryServiceTest.php
tests/TestCase/Service/OrderFilterServiceTest.php
tests/TestCase/Controller/OrdersControllerTest.php
tests/TestCase/Controller/OrderLogsControllerTest.php
tests/Fixture/OrdersFixture.php
tests/Fixture/OrderItemsFixture.php
tests/Fixture/OrderLogsFixture.php
```

**Casos a cubrir (por servicio):**

**`OrderService`:**

- `create` exitoso (local, sin receta) → 1 fila, 0 movimientos de stock, log created.
- `create` exitoso (local, con receta) → 1 fila, N movimientos de stock, log created.
- `create` exitoso (domicilio + crédito) → fila + items + stock + cliente auto-creado + CxC creada (mock).
- `create` con domicilio sin `delivery_id` → error.
- `create` con producto inexistente → error.
- `create` con producto inactivo → error.
- `create` con stock insuficiente → rollback total (no orden, no items, no movimientos de stock).
- `create` con CustomerService mock que falla → rollback.
- `create` con items vacíos → error.
- `create` con `> MAX_ITEMS_PER_ORDER` → error.
- `update` exitoso (cambia qty) → stock viejo restaurado + nuevo descontado.
- `update` en pedido cancelado → error.
- `update` cambia método de pago crédito→efectivo con abonos → bloquea.
- `update` con stock insuficiente en items nuevos → rollback (los viejos no quedan restaurados — la transacción los revierte).
- `cancel` exitoso → stock restaurado + status cambiado + log + CxC cancelada.
- `cancel` con abonos en CxC → bloquea.
- `cancel` pedido ya cancelado → error.
- `cancel` pedido entregado → error.
- `reactivate` exitoso → stock re-descontado + status='recibido' + CxC recreada si era crédito.
- `reactivate` con stock insuficiente → error claro, sin tocar nada.
- `delete` exitoso → fila + items borrados, log `deleted` persistido como huérfano.
- `delete` con abonos → bloquea.
- `delete` no-cancelado → restaura stock primero, luego borra.

**`OrderPipelineService`:**

- `canTransition` matriz completa (cada combinación from→to, true/false).
- `canTransition` para `preparando→entregado`: true solo si local.
- `canTransition` para `preparando→en_camino`: true solo si domicilio.
- `advance` exitoso: log + delivered_at seteado si `entregado`.
- `advance` con transición inválida → error.
- `advance` con cancel → rechaza (debe usar OrderService).
- `nextValidStates` para cada estado.

**`OrderHistoryService`:**

- `logCreated` persiste con kind correcto.
- `logFieldChange` ignora cambios cosméticos (string vs número formateado).
- `logFieldChange` detecta cambio real.
- `logFieldChange` normaliza DateTimeInterface.
- `logDeleted` persiste antes del delete físico.
- Log falla → no aborta el flujo (test con mock que retorna false en save).

**`OrderFilterService`:**

- Filtro `status=visible` excluye cancelados.
- Filtro `status=all` no filtra.
- Filtro `type=domicilio` correcto.
- Filtro `q` numérico hace match exacto por id.
- Filtro `q` string busca en name y phone.
- Rangos de fecha inclusivos.

**`OrdersController`:**

- GET `/orders` sin permiso → 403.
- GET `/orders` repartidor scope aplica.
- GET `/orders/view/N` repartidor ajeno → 403.
- POST `/orders/add` con datos válidos → 302 + orden creada.
- POST `/orders/cancel/N` sin permiso edit → 403.
- POST `/orders/advance/N` con transición inválida → 302 + flash error.
- GET `/orders/ticket/N` repartidor ajeno → 403.
- GET `/orders/edit/N` con pedido entregado → 302 + flash.

**`OrderLogsController`:**

- GET `/audit` user no-admin → 403 (sin seed de permisos audit).
- GET `/audit` user admin → 200.
- GET `/audit?order_id=N` filtra correctamente.

---

## 14. Open questions / risks

1. **`ReceivableService` aún no existe** (módulo 5 / CxC). Mientras tanto:
   - Pedidos a crédito se persisten y descuentan stock, pero la CxC NO se crea (solo se loguea).
   - Cuando se implemente, OrderService inyectará el service y los pedidos viejos que están en crédito necesitarán una migración de respaldo que itere `WHERE payment_method='credito' AND id NOT IN (SELECT order_id FROM accounts_receivable)` y cree las CxC retroactivamente. **Riesgo:** si se difiere demasiado, ese backfill se vuelve costoso. **Mitigación:** acordar implementar módulo 5 inmediatamente después de Pedidos.

2. **Ticket grupal vs individual.** El spec §8.6 menciona ambos. Decisión Fase 1: solo grupal. Si el negocio insiste, agregar `?line={id}` al ticket que renderiza una sola línea. Riesgo bajo.

3. **Datos del negocio en el ticket (logo, NIT, dirección, teléfono).** No hay tabla `business_info` en el spec. Fase 1: hardcoded en `templates/Orders/ticket.php` con comentario TODO. Fase 2: agregar tabla `business_info` (1 fila) o entry en `config/app.php`. **Riesgo:** un cambio de NIT o teléfono requiere editar código. Bajo: cambia rara vez.

4. **Performance del index con miles de pedidos.** El listado con `contain(['OrderItems'])` puede ser costoso. Mitigación: en `_buildIndexQuery` para `index`, NO contener `OrderItems` por default — el resumen se construye con un solo SELECT extra de "primer item por order" (subquery o left join lateral). Para `view`/`edit`/`ticket`, sí contener. **Decisión final:** `index` muestra `getItemsSummary()` que es lazy → si no hay items hidratados, la summary es "N productos" basada en un counter `item_count` que se puede materializar en la tabla `orders` (`item_count int` actualizado en save). Pendiente refinar en implementación.

5. **`actionModuleMap` vs `_actionToPermission` para acciones custom.** Las acciones `advance`, `cancel`, `reactivate`, `ticket` viven en `OrdersController` y pertenecen al módulo `orders`. No necesitan `actionModuleMap` (queda vacío). Solo se override `_actionToPermission` para mapear cada una a la acción RBAC correcta. Patrón consistente con `UsersController::unlock`.

6. **¿Debería cancelar un pedido pasar también por el pipeline?** Diseño actual: NO, vive en `OrderService::cancel`. Razón: tiene efectos de inventario complejos. Pero esto crea **dos caminos** para cambiar estado: pipeline (para `recibido↔preparando↔en_camino↔entregado`) y service (para `cancelar`/`reactivar`). Alternativa: pipeline también orquesta cancel/reactivate, llamando internamente al `OrderService`. Pros: un solo entrypoint. Contras: rompe la separación (pipeline puro vs orquestador). **Decisión:** mantener dos caminos; documentar claramente en la API. Si más adelante hay un tercer estado con side-effects (p. ej. `devuelto`), revisar.

7. **Auto-creación de cliente: ¿qué pasa si el teléfono ya existe con otro nombre?** Service usa `findOrCreateByPhone` — si encuentra el teléfono, devuelve ese cliente IGNORANDO el `name` del input. **Pregunta:** ¿debería actualizar el nombre con el del input? Decisión: NO en Fase 1 (`findOrCreateByPhone` ya tiene esta semántica). El snapshot del pedido sí lleva el nombre del input, así que el ticket muestra lo que el cajero escribió. Si el negocio quiere "merge" inteligente, evoluciona en Fase 2.

8. **Normalización del teléfono.** Hoy `CustomersTable` tiene un finder `byPhone` (visto en `CustomerService`). Probablemente hace match exacto. Si el cajero escribe `312 555 1234` y en DB está `+573125551234`, no matchea → cliente duplicado. **Riesgo:** clientes duplicados en operación real. **Mitigación:** introducir `customers.phone_normalized` (columna calculada en service: solo dígitos, sin `+` ni espacios), indexada, usada para el match. Out of scope estricto del módulo de Pedidos pero **debería coordinarse** con quien mantenga Customers. Documentar como dependencia.

9. **Permiso `delete` para Orders.** El seed lo deja en 0 (conservador). El admin puede borrar; los demás roles requieren toggle explícito en /roles. La cancelación (que sí se puede hacer con `edit`) cubre el 90% del caso de uso operativo (deshacer error). El delete real es para limpieza administrativa rara.

10. **Repartidor que crea pedidos.** Decisión: bloquear. Un repartidor no es cajero; si por alguna razón el rol del user le da `orders.create`, el guard `_enforceRepartidorAccess` en `add` rechaza. **Alternativa:** permitirle crear pedidos pero asignárselos a sí mismo. Out of scope para Fase 1; no es un workflow descripto en el spec.

11. **Idempotencia de `add`.** Doble-submit crea dos pedidos. Mitigación leve: deshabilitar botón submit con JS al primer click. Mitigación fuerte (futura): token de idempotencia por sesión.

12. **Auditoría del item-level diff legible.** `logItemsReplaced` debe producir texto humano: "Cambió: 2×Hamburguesa eliminado, 1×Papas agregado". Si el diff es grande, el `description` se trunca a 500 chars. Si es realmente importante preservar todo, una opción futura es agregar columna `data_json` a `order_logs` para guardar el diff completo serializado. Por ahora, 500 chars alcanza para casos típicos.
