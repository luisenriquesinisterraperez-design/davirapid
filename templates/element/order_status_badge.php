<?php
use App\Constants\OrderConstants;

/**
 * Render the order lifecycle chip with the dedicated `status-*` family
 * from DESIGN.md (NOT generic badge-*).
 *
 * Accepts either an `$order` entity or a raw `$status` string.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Order|null $order
 * @var string|null $status
 */
$rawStatus = isset($order) ? (string)$order->status : (string)($status ?? '');
$class = OrderConstants::STATUS_CSS_CLASS[$rawStatus] ?? 'status-pending';
$label = OrderConstants::STATUS_LABELS[$rawStatus] ?? $rawStatus;
?>
<span class="<?= h($class) ?>"><?= h($label) ?></span>
