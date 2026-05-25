<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\OrderConstants;
use App\Model\Entity\Order;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Throwable;

/**
 * OrderService — CRUD + inventory + (future) receivables orchestration.
 *
 * Every mutation runs inside a transaction; failures of any nested service
 * (recipe plan, stock adjust, customer auto-create) trigger a full rollback.
 *
 * Credit-payment orders create/update/delete a matching Receivable (CxC)
 * via ReceivableService, atomically inside the order's transaction. Failure
 * to handle CxC aborts the order operation.
 */
final class OrderService
{
    use LocatorAwareTrait;

    private OrderHistoryService $history;
    private RecipeService $recipes;
    private IngredientService $ingredients;
    private CustomerService $customers;
    private ReceivableService $receivables;

    /**
     * @param \App\Service\OrderHistoryService|null $history
     * @param \App\Service\RecipeService|null $recipes
     * @param \App\Service\IngredientService|null $ingredients
     * @param \App\Service\CustomerService|null $customers
     * @param \App\Service\ReceivableService|null $receivables
     */
    public function __construct(
        ?OrderHistoryService $history = null,
        ?RecipeService $recipes = null,
        ?IngredientService $ingredients = null,
        ?CustomerService $customers = null,
        ?ReceivableService $receivables = null,
    ) {
        $this->history = $history ?? new OrderHistoryService();
        $this->recipes = $recipes ?? new RecipeService();
        $this->ingredients = $ingredients ?? new IngredientService();
        $this->customers = $customers ?? new CustomerService();
        $this->receivables = $receivables ?? new ReceivableService();
    }

    // -------------------- create --------------------

    /**
     * @param array<string, mixed> $data
     * @return array{success: bool, order?: \App\Model\Entity\Order, errors?: list<string>}
     */
    public function create(array $data, int $userId): array
    {
        $errors = $this->validateCreateInput($data);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $productIds = array_values(array_unique(array_map(
            static fn($line) => (int)($line['product_id'] ?? 0),
            (array)($data['items'] ?? []),
        )));
        if ($productIds === []) {
            return ['success' => false, 'errors' => ['Agregá al menos un producto.']];
        }

        $productsTable = $this->fetchTable('Products');
        /** @var array<int, \App\Model\Entity\Product> $products */
        $products = $productsTable->find()
            ->where(['Products.id IN' => $productIds])
            ->all()
            ->indexBy('id')
            ->toArray();

        foreach ($productIds as $pid) {
            if (!isset($products[$pid])) {
                return ['success' => false, 'errors' => [sprintf('Producto #%d no encontrado.', $pid)]];
            }
            if (!$products[$pid]->is_active) {
                return ['success' => false, 'errors' => [sprintf(
                    "Producto '%s' no está disponible.",
                    $products[$pid]->name,
                )]];
            }
        }

        $resultBox = ['success' => false, 'errors' => ['Error desconocido.']];
        $connection = ConnectionManager::get('default');
        $ordersTable = $this->fetchTable('Orders');
        $orderItemsTable = $this->fetchTable('OrderItems');

        try {
            $connection->transactional(function () use (
                $data,
                $products,
                $userId,
                $ordersTable,
                $orderItemsTable,
                &$resultBox,
            ): bool {
                // Resolve customer for credit payments.
                $customerId = !empty($data['customer_id']) ? (int)$data['customer_id'] : null;
                if (
                    ($data['payment_method'] ?? '') === OrderConstants::PAYMENT_CREDIT
                    && $customerId === null
                ) {
                    $customer = $this->customers->findOrCreateByPhone([
                        'phone' => (string)($data['customer_phone'] ?? ''),
                        'name' => (string)($data['customer_name'] ?? 'Cliente'),
                        'address' => $data['customer_address'] ?? null,
                    ]);
                    $customerId = $customer->id;
                }

                $type = (string)$data['type'];
                $isDomicilio = $type === OrderConstants::TYPE_DOMICILIO;
                $shippingCost = $isDomicilio
                    ? number_format((float)($data['shipping_cost'] ?? 0), OrderConstants::MONEY_DECIMALS, '.', '')
                    : '0.00';

                /** @var \App\Model\Entity\Order $order */
                $order = $ordersTable->newEntity([
                    'customer_id' => $customerId,
                    'delivery_id' => $isDomicilio ? (int)$data['delivery_id'] : null,
                    'user_id' => $userId > 0 ? $userId : null,
                    'type' => $type,
                    'status' => OrderConstants::STATUS_RECEIVED,
                    'payment_method' => (string)$data['payment_method'],
                    'customer_name' => isset($data['customer_name']) ? (string)$data['customer_name'] : null,
                    'customer_phone' => isset($data['customer_phone']) ? (string)$data['customer_phone'] : null,
                    'customer_address' => $isDomicilio
                        ? (isset($data['customer_address']) ? (string)$data['customer_address'] : null)
                        : null,
                    'shipping_cost' => $shippingCost,
                    'notes' => isset($data['notes']) ? (string)$data['notes'] : null,
                ]);

                // Build items with snapshots from DB (anti-tampering: never trust POST price).
                $items = [];
                $subtotalFloat = 0.0;
                foreach ((array)$data['items'] as $line) {
                    $pid = (int)($line['product_id'] ?? 0);
                    $prod = $products[$pid];
                    $qty = (float)($line['quantity'] ?? 0);
                    $price = (float)$prod->price;
                    $lineSubtotal = round($price * $qty, OrderConstants::MONEY_DECIMALS);
                    $subtotalFloat += $lineSubtotal;

                    $items[] = $orderItemsTable->newEntity([
                        'product_id' => $prod->id,
                        'product_name' => $prod->name,
                        'quantity' => number_format($qty, OrderConstants::QUANTITY_DECIMALS, '.', ''),
                        'price_at_sale' => number_format($price, OrderConstants::MONEY_DECIMALS, '.', ''),
                        'line_subtotal' => number_format($lineSubtotal, OrderConstants::MONEY_DECIMALS, '.', ''),
                        'notes' => isset($line['notes']) && trim((string)$line['notes']) !== ''
                            ? (string)$line['notes']
                            : null,
                    ]);
                }

                $order->subtotal = number_format($subtotalFloat, OrderConstants::MONEY_DECIMALS, '.', '');
                $order->total = number_format(
                    $subtotalFloat + (float)$order->shipping_cost,
                    OrderConstants::MONEY_DECIMALS,
                    '.',
                    '',
                );
                $order->order_items = $items;

                if (!$ordersTable->save($order, ['associated' => ['OrderItems']])) {
                    $resultBox = [
                        'success' => false,
                        'errors' => $this->flattenErrors($order->getErrors()),
                    ];

                    return false;
                }

                // Decrement stock via recipe plans.
                $stockError = $this->decrementStockFor((array)$data['items'], (int)$order->id);
                if ($stockError !== null) {
                    $resultBox = ['success' => false, 'errors' => $stockError];

                    return false;
                }

                // Create CxC for credit-payment orders (atomic with order).
                if ($order->isCredit()) {
                    $cxcResult = $this->receivables->createFromOrder($order, $userId);
                    if (empty($cxcResult['success'])) {
                        $resultBox = [
                            'success' => false,
                            'errors' => $cxcResult['errors']
                                ?? ['No se pudo crear la cuenta por cobrar.'],
                        ];

                        return false;
                    }
                }

                $this->history->logCreated($order, $userId);

                Log::info(
                    'Order created: id={id} type={t} method={m} total={tot}',
                    [
                        'id' => $order->id,
                        't' => $order->type,
                        'm' => $order->payment_method,
                        'tot' => $order->total,
                        'scope' => ['orders'],
                    ],
                );

                $resultBox = ['success' => true, 'order' => $order];

                return true;
            });
        } catch (Throwable $e) {
            Log::error('OrderService::create threw: {msg}', [
                'msg' => $e->getMessage(),
                'scope' => ['orders'],
            ]);

            return ['success' => false, 'errors' => ['No se pudo crear el pedido: ' . $e->getMessage()]];
        }

        if (!empty($resultBox['success']) && isset($resultBox['order'])) {
            /** @var \App\Model\Entity\Order $order */
            $order = $resultBox['order'];
            /** @var \App\Model\Entity\Order $hydrated */
            $hydrated = $ordersTable->get($order->id, [
                'contain' => ['OrderItems' => ['Products'], 'Customers', 'Deliveries', 'Users'],
            ]);
            $resultBox['order'] = $hydrated;
        }

        return $resultBox;
    }

    // -------------------- update --------------------

    /**
     * @param array<string, mixed> $data
     * @return array{success: bool, order?: \App\Model\Entity\Order, errors?: list<string>}
     */
    public function update(Order $order, array $data, int $userId): array
    {
        if (!$order->isEditable()) {
            return ['success' => false, 'errors' => [
                'No se puede editar un pedido en estado actual.',
            ]];
        }

        $errors = $this->validateUpdateInput($data);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        // Pre-load products for the new items.
        $productIds = array_values(array_unique(array_map(
            static fn($line) => (int)($line['product_id'] ?? 0),
            (array)($data['items'] ?? []),
        )));
        if ($productIds === []) {
            return ['success' => false, 'errors' => ['Agregá al menos un producto.']];
        }

        $productsTable = $this->fetchTable('Products');
        /** @var array<int, \App\Model\Entity\Product> $products */
        $products = $productsTable->find()
            ->where(['Products.id IN' => $productIds])
            ->all()
            ->indexBy('id')
            ->toArray();
        foreach ($productIds as $pid) {
            if (!isset($products[$pid])) {
                return ['success' => false, 'errors' => [sprintf('Producto #%d no encontrado.', $pid)]];
            }
        }

        // Snapshot for audit.
        $snapshot = [
            'type' => $order->type,
            'payment_method' => $order->payment_method,
            'shipping_cost' => $order->shipping_cost,
            'customer_id' => $order->customer_id,
            'delivery_id' => $order->delivery_id,
            'notes' => $order->notes,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'customer_address' => $order->customer_address,
        ];
        $oldItems = [];
        foreach ((array)($order->order_items ?? []) as $oi) {
            $oldItems[] = [
                'product_id' => $oi->product_id,
                'product_name' => $oi->product_name,
                'quantity' => (string)$oi->quantity,
            ];
        }
        $oldPaymentMethod = (string)$order->payment_method;
        $oldTotal = (string)$order->total;

        $resultBox = ['success' => false, 'errors' => ['Error desconocido.']];
        $connection = ConnectionManager::get('default');
        $ordersTable = $this->fetchTable('Orders');
        $orderItemsTable = $this->fetchTable('OrderItems');

        try {
            $connection->transactional(function () use (
                $order,
                $data,
                $userId,
                $products,
                $oldItems,
                $oldPaymentMethod,
                $oldTotal,
                $snapshot,
                $ordersTable,
                $orderItemsTable,
                &$resultBox,
            ): bool {
                // 1. Restore stock for current items.
                $restoreError = $this->restoreStockFor($oldItems, (int)$order->id);
                if ($restoreError !== null) {
                    $resultBox = ['success' => false, 'errors' => $restoreError];

                    return false;
                }

                // 2. Delete current items.
                $orderItemsTable->deleteAll(['order_id' => $order->id]);

                // 3. Patch order with new field values (exclude id/status/user_id/created).
                $patchable = [];
                foreach (
                    ['type', 'payment_method', 'shipping_cost', 'customer_id', 'delivery_id',
                        'customer_name', 'customer_phone', 'customer_address', 'notes'] as $f
                ) {
                    if (array_key_exists($f, $data)) {
                        $patchable[$f] = $data[$f];
                    }
                }
                $isDomicilio = ($patchable['type'] ?? $order->type) === OrderConstants::TYPE_DOMICILIO;
                if (!$isDomicilio) {
                    $patchable['shipping_cost'] = '0.00';
                    $patchable['delivery_id'] = null;
                    $patchable['customer_address'] = null;
                } elseif (isset($patchable['shipping_cost'])) {
                    $patchable['shipping_cost'] = number_format(
                        (float)$patchable['shipping_cost'],
                        OrderConstants::MONEY_DECIMALS,
                        '.',
                        '',
                    );
                }
                $ordersTable->patchEntity($order, $patchable, ['accessibleFields' => [
                    'type' => true, 'payment_method' => true, 'shipping_cost' => true,
                    'customer_id' => true, 'delivery_id' => true,
                    'customer_name' => true, 'customer_phone' => true, 'customer_address' => true,
                    'notes' => true,
                ]]);

                // 4. Build new items with DB-sourced snapshots.
                $items = [];
                $subtotalFloat = 0.0;
                foreach ((array)$data['items'] as $line) {
                    $pid = (int)($line['product_id'] ?? 0);
                    $prod = $products[$pid];
                    $qty = (float)($line['quantity'] ?? 0);
                    $price = (float)$prod->price;
                    $lineSubtotal = round($price * $qty, OrderConstants::MONEY_DECIMALS);
                    $subtotalFloat += $lineSubtotal;

                    $items[] = $orderItemsTable->newEntity([
                        'product_id' => $prod->id,
                        'product_name' => $prod->name,
                        'quantity' => number_format($qty, OrderConstants::QUANTITY_DECIMALS, '.', ''),
                        'price_at_sale' => number_format($price, OrderConstants::MONEY_DECIMALS, '.', ''),
                        'line_subtotal' => number_format($lineSubtotal, OrderConstants::MONEY_DECIMALS, '.', ''),
                        'notes' => isset($line['notes']) && trim((string)$line['notes']) !== ''
                            ? (string)$line['notes']
                            : null,
                    ]);
                }
                $order->subtotal = number_format($subtotalFloat, OrderConstants::MONEY_DECIMALS, '.', '');
                $order->total = number_format(
                    $subtotalFloat + (float)$order->shipping_cost,
                    OrderConstants::MONEY_DECIMALS,
                    '.',
                    '',
                );
                $order->order_items = $items;

                // 5. Payment-method transitions wire CxC lifecycle.
                $newPaymentMethod = (string)$order->payment_method;
                $newTotal = (string)$order->total;
                if (
                    $oldPaymentMethod !== OrderConstants::PAYMENT_CREDIT
                    && $newPaymentMethod === OrderConstants::PAYMENT_CREDIT
                ) {
                    $cxcResult = $this->receivables->createFromOrder($order, $userId);
                    if (empty($cxcResult['success'])) {
                        $resultBox = [
                            'success' => false,
                            'errors' => $cxcResult['errors']
                                ?? ['No se pudo crear la cuenta por cobrar.'],
                        ];

                        return false;
                    }
                } elseif (
                    $oldPaymentMethod === OrderConstants::PAYMENT_CREDIT
                    && $newPaymentMethod !== OrderConstants::PAYMENT_CREDIT
                ) {
                    $cxcResult = $this->receivables->deleteForOrder(
                        $order,
                        $userId,
                        'payment_method_changed',
                    );
                    if (empty($cxcResult['success'])) {
                        $resultBox = [
                            'success' => false,
                            'errors' => $cxcResult['errors']
                                ?? ['No se pudo eliminar la cuenta por cobrar.'],
                        ];

                        return false;
                    }
                } elseif (
                    $oldPaymentMethod === OrderConstants::PAYMENT_CREDIT
                    && $newTotal !== $oldTotal
                ) {
                    $cxcResult = $this->receivables->updateAmountForOrder($order, $userId);
                    if (empty($cxcResult['success'])) {
                        $resultBox = [
                            'success' => false,
                            'errors' => $cxcResult['errors']
                                ?? ['No se pudo actualizar la cuenta por cobrar.'],
                        ];

                        return false;
                    }
                }

                // 6. Save.
                if (!$ordersTable->save($order, ['associated' => ['OrderItems']])) {
                    $resultBox = [
                        'success' => false,
                        'errors' => $this->flattenErrors($order->getErrors()),
                    ];

                    return false;
                }

                // 7. Decrement new stock.
                $decErr = $this->decrementStockFor((array)$data['items'], (int)$order->id);
                if ($decErr !== null) {
                    $resultBox = ['success' => false, 'errors' => $decErr];

                    return false;
                }

                // 8. Audit.
                $this->history->logFieldChanges($order, $userId, $snapshot);
                $newItemsForLog = [];
                foreach ($order->order_items as $oi) {
                    $newItemsForLog[] = [
                        'product_id' => $oi->product_id,
                        'product_name' => $oi->product_name,
                        'quantity' => (string)$oi->quantity,
                    ];
                }
                $this->history->logItemsReplaced($order, $userId, $oldItems, $newItemsForLog);

                Log::info('Order updated: id={id}', [
                    'id' => $order->id, 'scope' => ['orders'],
                ]);

                $resultBox = ['success' => true, 'order' => $order];

                return true;
            });
        } catch (Throwable $e) {
            Log::error('OrderService::update threw: {msg}', [
                'msg' => $e->getMessage(), 'scope' => ['orders'],
            ]);

            return ['success' => false, 'errors' => ['No se pudo actualizar el pedido: ' . $e->getMessage()]];
        }

        return $resultBox;
    }

    // -------------------- cancel --------------------

    /**
     * @return array{success: bool, order?: \App\Model\Entity\Order, errors?: list<string>}
     */
    public function cancel(Order $order, int $userId, ?string $reason = null): array
    {
        if (!$order->isCancellable()) {
            return ['success' => false, 'errors' => [
                'El estado actual no admite cancelación.',
            ]];
        }

        $items = $this->loadItems($order);
        $resultBox = ['success' => false, 'errors' => ['Error desconocido.']];
        $connection = ConnectionManager::get('default');
        $ordersTable = $this->fetchTable('Orders');

        try {
            $connection->transactional(function () use (
                $order,
                $items,
                $userId,
                $reason,
                $ordersTable,
                &$resultBox,
            ): bool {
                $restoreErr = $this->restoreStockFor($items, (int)$order->id);
                if ($restoreErr !== null) {
                    $resultBox = ['success' => false, 'errors' => $restoreErr];

                    return false;
                }

                // Cancel CxC for credit orders.
                if ($order->isCredit()) {
                    $cxcResult = $this->receivables->deleteForOrder(
                        $order,
                        $userId,
                        'order_cancelled',
                    );
                    if (empty($cxcResult['success'])) {
                        $resultBox = [
                            'success' => false,
                            'errors' => $cxcResult['errors']
                                ?? ['No se pudo eliminar la cuenta por cobrar.'],
                        ];

                        return false;
                    }
                }

                $order->status = OrderConstants::STATUS_CANCELLED;
                $order->cancelled_at = new DateTime();
                $order->cancelled_by = $userId > 0 ? $userId : null;

                if (!$ordersTable->save($order)) {
                    $resultBox = [
                        'success' => false,
                        'errors' => $this->flattenErrors($order->getErrors()),
                    ];

                    return false;
                }

                $this->history->logCancelled($order, $userId, (string)($reason ?? ''));

                Log::info('Order cancelled: id={id}', [
                    'id' => $order->id, 'scope' => ['orders'],
                ]);

                $resultBox = ['success' => true, 'order' => $order];

                return true;
            });
        } catch (Throwable $e) {
            Log::error('OrderService::cancel threw: {msg}', [
                'msg' => $e->getMessage(), 'scope' => ['orders'],
            ]);

            return ['success' => false, 'errors' => ['No se pudo cancelar el pedido: ' . $e->getMessage()]];
        }

        return $resultBox;
    }

    // -------------------- reactivate --------------------

    /**
     * @return array{success: bool, order?: \App\Model\Entity\Order, errors?: list<string>}
     */
    public function reactivate(Order $order, int $userId): array
    {
        if (!$order->isCancelled()) {
            return ['success' => false, 'errors' => [
                'Solo se pueden reactivar pedidos cancelados.',
            ]];
        }

        $items = $this->loadItems($order);
        $resultBox = ['success' => false, 'errors' => ['Error desconocido.']];
        $connection = ConnectionManager::get('default');
        $ordersTable = $this->fetchTable('Orders');

        try {
            $connection->transactional(function () use (
                $order,
                $items,
                $userId,
                $ordersTable,
                &$resultBox,
            ): bool {
                $decErr = $this->decrementStockForItems($items, (int)$order->id, 'Reactivación');
                if ($decErr !== null) {
                    $resultBox = ['success' => false, 'errors' => $decErr];

                    return false;
                }

                if ($order->isCredit()) {
                    $cxcResult = $this->receivables->findOrCreateForOrder($order, $userId);
                    if (empty($cxcResult['success'])) {
                        $resultBox = [
                            'success' => false,
                            'errors' => $cxcResult['errors']
                                ?? ['No se pudo recrear la cuenta por cobrar.'],
                        ];

                        return false;
                    }
                }

                $order->status = OrderConstants::STATUS_RECEIVED;
                $order->cancelled_at = null;
                $order->cancelled_by = null;

                if (!$ordersTable->save($order)) {
                    $resultBox = [
                        'success' => false,
                        'errors' => $this->flattenErrors($order->getErrors()),
                    ];

                    return false;
                }

                $this->history->logReactivated($order, $userId);

                Log::info('Order reactivated: id={id}', [
                    'id' => $order->id, 'scope' => ['orders'],
                ]);

                $resultBox = ['success' => true, 'order' => $order];

                return true;
            });
        } catch (Throwable $e) {
            Log::error('OrderService::reactivate threw: {msg}', [
                'msg' => $e->getMessage(), 'scope' => ['orders'],
            ]);

            return ['success' => false, 'errors' => ['No se pudo reactivar el pedido: ' . $e->getMessage()]];
        }

        return $resultBox;
    }

    // -------------------- delete --------------------

    /**
     * @return array{success: bool, errors?: list<string>}
     */
    public function delete(Order $order, int $userId): array
    {
        // TODO Phase 5: check receivables->hasPayments(order->id) and block.

        $items = $this->loadItems($order);
        $resultBox = ['success' => false, 'errors' => ['Error desconocido.']];
        $connection = ConnectionManager::get('default');
        $ordersTable = $this->fetchTable('Orders');

        try {
            $connection->transactional(function () use (
                $order,
                $items,
                $userId,
                $ordersTable,
                &$resultBox,
            ): bool {
                // Restore stock if the order is still active (cancelled already restored).
                if (!$order->isCancelled()) {
                    $restoreErr = $this->restoreStockFor($items, (int)$order->id);
                    if ($restoreErr !== null) {
                        $resultBox = ['success' => false, 'errors' => $restoreErr];

                        return false;
                    }
                }

                if ($order->isCredit()) {
                    $cxcResult = $this->receivables->deleteForOrder(
                        $order,
                        $userId,
                        'order_deleted',
                    );
                    if (empty($cxcResult['success'])) {
                        $resultBox = [
                            'success' => false,
                            'errors' => $cxcResult['errors']
                                ?? ['No se pudo eliminar la cuenta por cobrar.'],
                        ];

                        return false;
                    }
                }

                // Persist the deletion log BEFORE removing the row, so the
                // log captures order_id while it is still valid (FK will
                // null it after delete, but order_id_snapshot preserves it).
                $this->history->logDeleted($order, $userId);

                if (!$ordersTable->delete($order)) {
                    $resultBox = ['success' => false, 'errors' => ['No se pudo eliminar el pedido.']];

                    return false;
                }

                Log::warning('Order deleted: id={id}', [
                    'id' => $order->id, 'scope' => ['orders'],
                ]);

                $resultBox = ['success' => true];

                return true;
            });
        } catch (Throwable $e) {
            Log::error('OrderService::delete threw: {msg}', [
                'msg' => $e->getMessage(), 'scope' => ['orders'],
            ]);

            return ['success' => false, 'errors' => ['No se pudo eliminar el pedido: ' . $e->getMessage()]];
        }

        return $resultBox;
    }

    // -------------------- Internals --------------------

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function validateCreateInput(array $data): array
    {
        $errors = [];

        $type = (string)($data['type'] ?? '');
        if (!in_array($type, OrderConstants::TYPES, true)) {
            $errors[] = 'Tipo de pedido inválido.';
        }

        $method = (string)($data['payment_method'] ?? '');
        if (!in_array($method, OrderConstants::PAYMENT_METHODS, true)) {
            $errors[] = 'Método de pago inválido.';
        }

        $items = $data['items'] ?? null;
        if (!is_array($items) || $items === []) {
            $errors[] = 'Agregá al menos un producto.';
        } elseif (count($items) > OrderConstants::MAX_ITEMS_PER_ORDER) {
            $errors[] = sprintf(
                'No se pueden agregar más de %d líneas por pedido.',
                OrderConstants::MAX_ITEMS_PER_ORDER,
            );
        } else {
            foreach ($items as $i => $line) {
                $pid = (int)($line['product_id'] ?? 0);
                $qty = $line['quantity'] ?? null;
                if ($pid <= 0) {
                    $errors[] = sprintf('Línea %d: producto inválido.', $i + 1);
                }
                if ($qty === null || $qty === '' || !is_numeric($qty) || (float)$qty <= 0) {
                    $errors[] = sprintf('Línea %d: la cantidad debe ser mayor a 0.', $i + 1);
                }
            }
        }

        if ($type === OrderConstants::TYPE_DOMICILIO) {
            if ((int)($data['delivery_id'] ?? 0) <= 0) {
                $errors[] = 'Asigná un repartidor para pedidos a domicilio.';
            }
            if (trim((string)($data['customer_address'] ?? '')) === '') {
                $errors[] = 'La dirección es obligatoria para domicilio.';
            }
            $ship = $data['shipping_cost'] ?? null;
            if ($ship !== null && $ship !== '' && (!is_numeric($ship) || (float)$ship < 0)) {
                $errors[] = 'El costo de envío no puede ser negativo.';
            }
        }

        if ($method === OrderConstants::PAYMENT_CREDIT) {
            if (trim((string)($data['customer_phone'] ?? '')) === '') {
                $errors[] = 'El teléfono del cliente es obligatorio para pedidos a crédito.';
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function validateUpdateInput(array $data): array
    {
        return $this->validateCreateInput($data);
    }

    /**
     * Restore stock for the given items (signed positive adjustment).
     * Used on cancel/update/delete-of-active. Returns error list or null on success.
     *
     * @param list<array{product_id: int|null, quantity: string|float, product_name?: string}> $items
     * @return list<string>|null
     */
    private function restoreStockFor(array $items, int $orderId): ?array
    {
        $ingredientsTable = $this->fetchTable('Ingredients');
        foreach ($items as $item) {
            $pid = (int)($item['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $units = (int)((float)($item['quantity'] ?? 0));
            $plan = $this->recipes->buildDecrementPlan($pid, $units);
            if ($plan === []) {
                continue;
            }
            foreach ($plan as $step) {
                $ing = $ingredientsTable->find()
                    ->where(['Ingredients.id' => (int)$step['ingredient_id']])
                    ->first();
                if ($ing === null) {
                    continue; // ingredient was deleted; nothing to restore.
                }
                $result = $this->ingredients->adjustStock(
                    $ing,
                    '+' . $step['quantity'],
                    sprintf('Restauración pedido #%d', $orderId),
                );
                if (empty($result['success'])) {
                    return $result['errors'] ?? ['No se pudo restaurar stock.'];
                }
            }
        }

        return null;
    }

    /**
     * Decrement stock for the items in the request data (during create/update).
     *
     * @param list<array<string, mixed>> $items
     * @return list<string>|null
     */
    private function decrementStockFor(array $items, int $orderId): ?array
    {
        $ingredientsTable = $this->fetchTable('Ingredients');
        foreach ($items as $line) {
            $pid = (int)($line['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $units = (int)((float)($line['quantity'] ?? 0));
            $plan = $this->recipes->buildDecrementPlan($pid, $units);
            if ($plan === []) {
                Log::info('Product without recipe sold: id={pid}', [
                    'pid' => $pid, 'scope' => ['orders'],
                ]);

                continue;
            }
            foreach ($plan as $step) {
                $ing = $ingredientsTable->find()
                    ->where(['Ingredients.id' => (int)$step['ingredient_id']])
                    ->first();
                if ($ing === null) {
                    return [sprintf('Ingrediente #%d no encontrado.', (int)$step['ingredient_id'])];
                }
                $result = $this->ingredients->adjustStock(
                    $ing,
                    '-' . $step['quantity'],
                    sprintf('Pedido #%d', $orderId),
                );
                if (empty($result['success'])) {
                    return $result['errors'] ?? ['Stock insuficiente.'];
                }
            }
        }

        return null;
    }

    /**
     * Variant for reactivate path where items come from OrderItem entities.
     *
     * @param list<array{product_id: int|null, quantity: string|float, product_name?: string}> $items
     * @return list<string>|null
     */
    private function decrementStockForItems(array $items, int $orderId, string $reasonPrefix): ?array
    {
        $ingredientsTable = $this->fetchTable('Ingredients');
        foreach ($items as $item) {
            $pid = (int)($item['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $units = (int)((float)($item['quantity'] ?? 0));
            $plan = $this->recipes->buildDecrementPlan($pid, $units);
            if ($plan === []) {
                continue;
            }
            foreach ($plan as $step) {
                $ing = $ingredientsTable->find()
                    ->where(['Ingredients.id' => (int)$step['ingredient_id']])
                    ->first();
                if ($ing === null) {
                    continue;
                }
                $result = $this->ingredients->adjustStock(
                    $ing,
                    '-' . $step['quantity'],
                    sprintf('%s pedido #%d', $reasonPrefix, $orderId),
                );
                if (empty($result['success'])) {
                    $name = (string)($ing->name ?? '?');
                    $msg = sprintf(
                        'No se puede reactivar: stock insuficiente para %s. Registrá un ajuste primero.',
                        $name,
                    );

                    return [$msg];
                }
            }
        }

        return null;
    }

    /**
     * Returns a list of item arrays suitable for stock restore/decrement.
     * If the order's items are not hydrated, they are loaded eagerly.
     *
     * @return list<array{product_id: int|null, quantity: string, product_name: string}>
     */
    private function loadItems(Order $order): array
    {
        $items = $order->order_items ?? null;
        if (!is_array($items) || $items === []) {
            $rows = $this->fetchTable('OrderItems')->find()
                ->where(['OrderItems.order_id' => $order->id])
                ->all()
                ->toList();
            $items = $rows;
        }
        $out = [];
        foreach ($items as $oi) {
            $out[] = [
                'product_id' => $oi->product_id,
                'quantity' => (string)$oi->quantity,
                'product_name' => (string)$oi->product_name,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $errors
     * @return list<string>
     */
    private function flattenErrors(array $errors): array
    {
        $flat = [];
        array_walk_recursive($errors, function ($message) use (&$flat): void {
            if (is_string($message) && $message !== '') {
                $flat[] = $message;
            }
        });

        return $flat !== [] ? $flat : ['Datos inválidos.'];
    }
}
