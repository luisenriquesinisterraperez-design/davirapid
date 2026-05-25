<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\OrderConstants;
use App\Service\OrderPipelineService;
use Cake\ORM\Entity;

/**
 * @property int $id
 * @property int|null $customer_id
 * @property int|null $delivery_id
 * @property int|null $user_id
 * @property string $type
 * @property string $status
 * @property string $payment_method
 * @property string|null $customer_name
 * @property string|null $customer_phone
 * @property string|null $customer_address
 * @property string $shipping_cost
 * @property string $subtotal
 * @property string $total
 * @property string|null $notes
 * @property \Cake\I18n\DateTime|null $delivered_at
 * @property \Cake\I18n\DateTime|null $cancelled_at
 * @property int|null $cancelled_by
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property \App\Model\Entity\Customer|null $customer
 * @property \App\Model\Entity\Delivery|null $delivery
 * @property \App\Model\Entity\User|null $user
 * @property \App\Model\Entity\User|null $cancelled_by_user
 * @property array<int, \App\Model\Entity\OrderItem> $order_items
 */
class Order extends Entity
{
    /**
     * @var array<string, bool>
     */
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
        'order_items' => true,
        'customer' => true,
        'delivery' => true,
        'user' => true,
        'cancelled_by_user' => true,
    ];

    /**
     * @var list<string>
     */
    protected array $_virtual = ['display_status', 'item_count', 'is_credit'];

    // -------------------- State predicates --------------------

    /**
     * Whether the order has been cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === OrderConstants::STATUS_CANCELLED;
    }

    /**
     * Whether the order has reached the terminal delivered state.
     */
    public function isDelivered(): bool
    {
        return $this->status === OrderConstants::STATUS_DELIVERED;
    }

    /**
     * Whether the order is a domicilio (delivery) order.
     */
    public function isDomicilio(): bool
    {
        return $this->type === OrderConstants::TYPE_DOMICILIO;
    }

    /**
     * Whether the order is a local (in-store) order.
     */
    public function isLocal(): bool
    {
        return $this->type === OrderConstants::TYPE_LOCAL;
    }

    /**
     * Whether the payment method is credit (fiado).
     */
    public function isCredit(): bool
    {
        return $this->payment_method === OrderConstants::PAYMENT_CREDIT;
    }

    /**
     * Whether the order is in a state that allows mutations of its fields/items.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, OrderConstants::EDITABLE_STATUSES, true);
    }

    /**
     * Whether the order can be cancelled from its current state.
     */
    public function isCancellable(): bool
    {
        return in_array($this->status, OrderConstants::CANCELLABLE_FROM, true);
    }

    // -------------------- Transitions --------------------

    /**
     * Whether the order may transition to $newStatus given the current state
     * and type. Consults the pipeline matrix without instantiating the service.
     */
    public function canTransitionTo(string $newStatus): bool
    {
        $allowed = OrderPipelineService::TRANSITIONS[$this->status] ?? [];
        if (!in_array($newStatus, $allowed, true)) {
            return false;
        }
        if ($newStatus === OrderConstants::STATUS_ON_ROUTE && !$this->isDomicilio()) {
            return false;
        }
        if (
            $this->status === OrderConstants::STATUS_PREPARING
            && $newStatus === OrderConstants::STATUS_DELIVERED
            && $this->isDomicilio()
        ) {
            return false;
        }

        return true;
    }

    // -------------------- Display helpers --------------------

    /**
     * Returns the human-readable label for the current status.
     */
    public function getDisplayStatus(): string
    {
        return OrderConstants::STATUS_LABELS[$this->status] ?? (string)$this->status;
    }

    /**
     * Returns the CSS class for the status chip (status-* family from DESIGN.md).
     */
    public function getStatusCssClass(): string
    {
        return OrderConstants::STATUS_CSS_CLASS[$this->status] ?? 'status-pending';
    }

    /**
     * Returns the customer name, preferring the order snapshot, falling back
     * to the related customer entity, and finally to a placeholder.
     */
    public function getCustomerName(): string
    {
        $snapshot = (string)($this->customer_name ?? '');
        if ($snapshot !== '') {
            return $snapshot;
        }
        $related = $this->customer?->name ?? '';
        if ((string)$related !== '') {
            return (string)$related;
        }

        return 'Sin nombre';
    }

    /**
     * Returns the customer phone, preferring snapshot then related entity.
     */
    public function getCustomerPhone(): string
    {
        $snapshot = (string)($this->customer_phone ?? '');
        if ($snapshot !== '') {
            return $snapshot;
        }

        return (string)($this->customer?->phone ?? '');
    }

    /**
     * Returns the count of order_items hydrated on the entity (0 if not loaded).
     */
    public function getItemCount(): int
    {
        return is_array($this->order_items) ? count($this->order_items) : 0;
    }

    /**
     * Returns a one-liner summary of the items.
     *
     * Examples: "2 × Hamburguesa", "2 × Hamburguesa (+1 más)".
     */
    public function getItemsSummary(int $maxNames = 1): string
    {
        $items = $this->order_items ?? [];
        if (!is_array($items) || $items === []) {
            return '—';
        }
        $first = $items[0];
        $qty = rtrim(rtrim(number_format((float)$first->quantity, 3, '.', ''), '0'), '.');
        $base = trim(($qty !== '' ? $qty : '0') . ' × ' . ($first->product_name ?? '?'));
        $rest = count($items) - $maxNames;

        return $rest > 0 ? sprintf('%s (+%d más)', $base, $rest) : $base;
    }

    // -------------------- Virtual accessors --------------------

    /**
     * Virtual property accessor for `display_status`.
     */
    protected function _getDisplayStatus(): string
    {
        return $this->getDisplayStatus();
    }

    /**
     * Virtual property accessor for `item_count`.
     */
    protected function _getItemCount(): int
    {
        return $this->getItemCount();
    }

    /**
     * Virtual property accessor for `is_credit`.
     */
    protected function _getIsCredit(): bool
    {
        return $this->isCredit();
    }
}
