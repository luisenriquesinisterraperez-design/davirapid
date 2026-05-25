<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\OrderConstants;
use App\Model\Entity\Order;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * OrderPipelineService — pure state-machine for order status transitions.
 *
 * Does NOT handle stock effects. Cancel/reactivate live in OrderService
 * because they have inventory side-effects.
 */
final class OrderPipelineService
{
    use LocatorAwareTrait;

    /**
     * Allowed transitions ignoring type-dependent constraints.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        OrderConstants::STATUS_RECEIVED => [
            OrderConstants::STATUS_PREPARING,
            OrderConstants::STATUS_CANCELLED,
        ],
        OrderConstants::STATUS_PREPARING => [
            OrderConstants::STATUS_ON_ROUTE,
            OrderConstants::STATUS_DELIVERED,
            OrderConstants::STATUS_CANCELLED,
        ],
        OrderConstants::STATUS_ON_ROUTE => [
            OrderConstants::STATUS_DELIVERED,
            OrderConstants::STATUS_CANCELLED,
        ],
        OrderConstants::STATUS_DELIVERED => [],
        OrderConstants::STATUS_CANCELLED => [
            OrderConstants::STATUS_RECEIVED,
        ],
    ];

    private OrderHistoryService $history;

    /**
     * @param \App\Service\OrderHistoryService|null $history Injected for testing.
     */
    public function __construct(?OrderHistoryService $history = null)
    {
        $this->history = $history ?? new OrderHistoryService();
    }

    /**
     * Whether the order can transition to $newStatus.
     */
    public function canTransition(Order $order, string $newStatus): bool
    {
        $from = (string)$order->status;
        if (!isset(self::TRANSITIONS[$from])) {
            return false;
        }
        if (!in_array($newStatus, self::TRANSITIONS[$from], true)) {
            return false;
        }
        if ($newStatus === OrderConstants::STATUS_ON_ROUTE && !$order->isDomicilio()) {
            return false;
        }
        if (
            $from === OrderConstants::STATUS_PREPARING
            && $newStatus === OrderConstants::STATUS_DELIVERED
            && $order->isDomicilio()
        ) {
            return false;
        }

        return true;
    }

    /**
     * @return array{success: bool, order?: \App\Model\Entity\Order, errors?: list<string>}
     */
    public function advance(Order $order, string $newStatus, int $userId): array
    {
        // Cancellation and reactivation have inventory side-effects — must go through OrderService.
        if (
            $newStatus === OrderConstants::STATUS_CANCELLED
            || ($newStatus === OrderConstants::STATUS_RECEIVED
                && $order->status === OrderConstants::STATUS_CANCELLED)
        ) {
            return ['success' => false, 'errors' => [
                'Usar OrderService::cancel() o reactivate() para esta transición.',
            ]];
        }

        if (!$this->canTransition($order, $newStatus)) {
            return ['success' => false, 'errors' => [sprintf(
                'No se puede pasar de "%s" a "%s"%s.',
                OrderConstants::STATUS_LABELS[$order->status] ?? (string)$order->status,
                OrderConstants::STATUS_LABELS[$newStatus] ?? $newStatus,
                $newStatus === OrderConstants::STATUS_ON_ROUTE ? ' (solo aplica a domicilio)' : '',
            )]];
        }

        $previousStatus = (string)$order->status;
        $order->status = $newStatus;

        if ($newStatus === OrderConstants::STATUS_DELIVERED) {
            $order->delivered_at = new DateTime();
        }

        $ordersTable = $this->fetchTable('Orders');
        if (!$ordersTable->save($order)) {
            return ['success' => false, 'errors' => $this->flattenErrors($order->getErrors())];
        }

        $this->history->logStateChanged($order, $userId, $previousStatus, $newStatus);

        Log::info('Order state changed: id={id} from={from} to={to}', [
            'id' => $order->id,
            'from' => $previousStatus,
            'to' => $newStatus,
            'scope' => ['orders'],
        ]);

        return ['success' => true, 'order' => $order];
    }

    /**
     * @return list<string>
     */
    public function nextValidStates(Order $order): array
    {
        return array_values(array_filter(
            self::TRANSITIONS[$order->status] ?? [],
            fn(string $status): bool => $this->canTransition($order, $status),
        ));
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
