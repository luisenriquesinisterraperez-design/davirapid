<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\OrderConstants;
use App\Constants\OrderLogConstants;
use App\Model\Entity\Order;
use App\Model\Entity\OrderItem;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use DateTimeInterface;

/**
 * OrderHistoryService — append-only audit log for order mutations.
 *
 * Logging failures do NOT abort the order flow: they emit a Log::error and
 * return silently. The audit is best-effort by design (a transient DB issue
 * should not refuse a customer their pedido).
 */
final class OrderHistoryService
{
    use LocatorAwareTrait;

    /**
     * Persists a `created` log entry for the order.
     */
    public function logCreated(Order $order, int $userId, string $extra = ''): void
    {
        $base = sprintf('Pedido creado por %s.', $this->resolveUserName($userId));
        $description = $extra !== '' ? trim($base . ' ' . $extra) : $base;
        $this->persist($order, $userId, OrderLogConstants::KIND_CREATED, $description);
    }

    /**
     * Persists a `state_changed` log entry using STATUS_LABELS for from/to.
     */
    public function logStateChanged(Order $order, int $userId, string $from, string $to): void
    {
        $description = sprintf(
            "Estado: de '%s' a '%s'",
            OrderConstants::STATUS_LABELS[$from] ?? $from,
            OrderConstants::STATUS_LABELS[$to] ?? $to,
        );
        $this->persist($order, $userId, OrderLogConstants::KIND_STATE_CHANGED, $description);
    }

    /**
     * Persists a `field_changed` log if normalized values differ.
     */
    public function logFieldChange(Order $order, int $userId, string $field, mixed $oldVal, mixed $newVal): void
    {
        $normalizedOld = $this->normalize($oldVal);
        $normalizedNew = $this->normalize($newVal);
        if ($normalizedOld === $normalizedNew) {
            return;
        }
        $description = sprintf(
            "%s: de '%s' a '%s'",
            $field,
            $this->stringify($normalizedOld),
            $this->stringify($normalizedNew),
        );
        $this->persist($order, $userId, OrderLogConstants::KIND_FIELD_CHANGED, $description);
    }

    /**
     * @param array<string, mixed> $snapshot Map of field => old value at the start of the update.
     */
    public function logFieldChanges(Order $order, int $userId, array $snapshot): void
    {
        $fields = [
            'type',
            'payment_method',
            'shipping_cost',
            'customer_id',
            'delivery_id',
            'notes',
            'customer_name',
            'customer_phone',
            'customer_address',
        ];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $snapshot)) {
                continue;
            }
            $this->logFieldChange($order, $userId, $field, $snapshot[$field], $order->get($field));
        }
    }

    /**
     * Persists a single `item_added` entry.
     */
    public function logItemAdded(Order $order, int $userId, OrderItem $item): void
    {
        $qty = rtrim(rtrim(number_format((float)$item->quantity, 3, '.', ''), '0'), '.');
        $description = sprintf('Agregado: %s × %s', $qty !== '' ? $qty : '0', (string)$item->product_name);
        $this->persist($order, $userId, OrderLogConstants::KIND_ITEM_ADDED, $description);
    }

    /**
     * Persists a single `item_removed` entry.
     */
    public function logItemRemoved(Order $order, int $userId, OrderItem $item): void
    {
        $qty = rtrim(rtrim(number_format((float)$item->quantity, 3, '.', ''), '0'), '.');
        $description = sprintf('Removido: %s × %s', $qty !== '' ? $qty : '0', (string)$item->product_name);
        $this->persist($order, $userId, OrderLogConstants::KIND_ITEM_REMOVED, $description);
    }

    /**
     * @param list<\App\Model\Entity\OrderItem|array<string, mixed>> $oldItems
     * @param list<\App\Model\Entity\OrderItem|array<string, mixed>> $newItems
     */
    public function logItemsReplaced(Order $order, int $userId, array $oldItems, array $newItems): void
    {
        $description = $this->summarizeItemsDiff($oldItems, $newItems);
        if ($description === '') {
            return;
        }
        $this->persist($order, $userId, OrderLogConstants::KIND_ITEM_CHANGED, $description);
    }

    /**
     * Persists a `cancelled` log with optional reason.
     */
    public function logCancelled(Order $order, int $userId, string $reason = ''): void
    {
        $description = $reason !== ''
            ? sprintf('Pedido cancelado: %s', $reason)
            : 'Pedido cancelado';
        $this->persist($order, $userId, OrderLogConstants::KIND_CANCELLED, $description);
    }

    /**
     * Persists a `reactivated` log.
     */
    public function logReactivated(Order $order, int $userId): void
    {
        $this->persist($order, $userId, OrderLogConstants::KIND_REACTIVATED, 'Pedido reactivado');
    }

    /**
     * Persists a `deleted` log — MUST be invoked BEFORE the order's physical delete.
     */
    public function logDeleted(Order $order, int $userId): void
    {
        $this->persist($order, $userId, OrderLogConstants::KIND_DELETED, 'Pedido eliminado');
    }

    // -------------------- Internals --------------------

    /**
     * Normalises a value for diff comparison (DateTime → string, numeric → 2-dec string, '' → null).
     */
    private function normalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_bool($value)) {
            return $value;
        }
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return number_format((float)$value, 2, '.', '');
        }

        return $value;
    }

    /**
     * Converts any value into a human-readable representation for log text.
     */
    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '∅';
        }
        if (is_bool($value)) {
            return $value ? 'sí' : 'no';
        }
        if (is_scalar($value)) {
            return (string)$value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '?';
    }

    /**
     * @param list<\App\Model\Entity\OrderItem|array<string, mixed>> $oldItems
     * @param list<\App\Model\Entity\OrderItem|array<string, mixed>> $newItems
     */
    private function summarizeItemsDiff(array $oldItems, array $newItems): string
    {
        $oldIndex = $this->indexItems($oldItems);
        $newIndex = $this->indexItems($newItems);

        $added = [];
        $removed = [];
        $changed = [];

        foreach ($newIndex as $key => $info) {
            if (!isset($oldIndex[$key])) {
                $added[] = sprintf('%s × %s', $info['qty'], $info['name']);
            } elseif ($oldIndex[$key]['qty'] !== $info['qty']) {
                $changed[] = sprintf(
                    '%s × %s (era %s)',
                    $info['qty'],
                    $info['name'],
                    $oldIndex[$key]['qty'],
                );
            }
        }
        foreach ($oldIndex as $key => $info) {
            if (!isset($newIndex[$key])) {
                $removed[] = sprintf('%s × %s', $info['qty'], $info['name']);
            }
        }

        $parts = [];
        if ($added !== []) {
            $parts[] = 'agregados ' . implode(', ', $added);
        }
        if ($removed !== []) {
            $parts[] = 'removidos ' . implode(', ', $removed);
        }
        if ($changed !== []) {
            $parts[] = 'cambiados ' . implode(', ', $changed);
        }

        return $parts !== [] ? 'Productos: ' . implode(' | ', $parts) : '';
    }

    /**
     * @param list<\App\Model\Entity\OrderItem|array<string, mixed>> $items
     * @return array<string, array{qty: string, name: string}>
     */
    private function indexItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $pid = is_object($item) ? ($item->product_id ?? null) : ($item['product_id'] ?? null);
            $name = is_object($item) ? ($item->product_name ?? '?') : ($item['product_name'] ?? '?');
            $qtyRaw = is_object($item) ? ($item->quantity ?? 0) : ($item['quantity'] ?? 0);
            $key = ($pid === null ? 'null:' . (string)$name : 'id:' . (string)$pid);
            $qty = rtrim(rtrim(number_format((float)$qtyRaw, 3, '.', ''), '0'), '.');
            $out[$key] = ['qty' => $qty !== '' ? $qty : '0', 'name' => (string)$name];
        }

        return $out;
    }

    /**
     * Returns the display name for a user id, falling back to username or '—'.
     */
    private function resolveUserName(int $userId): string
    {
        if ($userId <= 0) {
            return '—';
        }
        $row = $this->fetchTable('Users')->find()
            ->where(['Users.id' => $userId])
            ->first();
        if ($row === null) {
            return '—';
        }
        $name = (string)($row->get('name') ?? '');

        return $name !== '' ? $name : (string)($row->get('username') ?? '—');
    }

    /**
     * Best-effort persist of one log entry — failures emit Log::error and return silently.
     */
    private function persist(Order $order, int $userId, string $kind, string $description): void
    {
        $userName = $this->resolveUserName($userId);
        $orderLogs = $this->fetchTable('OrderLogs');

        $log = $orderLogs->newEntity([
            'order_id' => $order->id,
            'order_id_snapshot' => (int)$order->id,
            'user_id' => $userId > 0 ? $userId : null,
            'user_name_snapshot' => $userName,
            'kind' => $kind,
            'description' => mb_substr($description, 0, 500),
        ]);

        if (!$orderLogs->save($log)) {
            Log::error('Failed to persist order log: {errors}', [
                'errors' => json_encode($log->getErrors(), JSON_UNESCAPED_UNICODE),
                'scope' => ['orders', 'audit'],
            ]);
        }
    }
}
