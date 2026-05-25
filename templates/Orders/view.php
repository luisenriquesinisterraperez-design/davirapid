<?php
use App\Constants\OrderConstants;

/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Order $order
 * @var array<int, \App\Model\Entity\OrderLog> $logs
 * @var list<string> $nextStates
 * @var array<string, array<string, bool>> $userPermissions
 * @var bool $isAdministrator
 */
$this->assign('title', 'Pedido #' . (int)$order->id);
?>
<div class="dr-page-header">
    <div>
        <h1 class="dr-page-title">
            Pedido #<?= (int)$order->id ?>
            <?= $this->element('order_status_badge', ['order' => $order]) ?>
        </h1>
        <small class="text-muted">
            <?= $order->created !== null ? h($order->created->i18nFormat('dd/MM/yyyy HH:mm')) : '—' ?>
            · por <?= h($order->user->name ?? 'Usuario eliminado') ?>
        </small>
    </div>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-printer"></i> Imprimir',
            ['action' => 'ticket', $order->id],
            ['escape' => false, 'class' => 'btn btn-secondary', 'target' => '_blank'],
        ) ?>
    </div>
</div>

<?php if (!empty($nextStates) || $order->isCancellable() || $order->isCancelled()): ?>
    <div class="card mb-3">
        <div class="card-body d-flex gap-2 flex-wrap">
            <?php foreach ($nextStates as $to): ?>
                <?php
                $label = OrderConstants::STATUS_LABELS[$to] ?? $to;
                if ($to === OrderConstants::STATUS_CANCELLED) {
                    continue; // cancel se maneja con su botón propio
                }
                ?>
                <?= $this->Form->postLink(
                    '<i class="bi bi-arrow-right"></i> Avanzar a "' . h($label) . '"',
                    ['action' => 'advance', $order->id],
                    [
                        'escape' => false,
                        'class' => 'btn btn-primary',
                        'data' => ['to_status' => $to],
                    ],
                ) ?>
            <?php endforeach; ?>

            <?php if ($order->isCancellable() && !empty($userPermissions['orders']['edit'])): ?>
                <?= $this->Form->postLink(
                    '<i class="bi bi-x-octagon"></i> Cancelar pedido',
                    ['action' => 'cancel', $order->id],
                    [
                        'escape' => false,
                        'class' => 'btn btn-danger',
                        'confirm' => '¿Cancelar este pedido? Se restaurará el stock de los insumos.',
                    ],
                ) ?>
            <?php endif; ?>

            <?php if ($order->isCancelled() && !empty($userPermissions['orders']['edit'])): ?>
                <?= $this->Form->postLink(
                    '<i class="bi bi-arrow-clockwise"></i> Reactivar',
                    ['action' => 'reactivate', $order->id],
                    [
                        'escape' => false,
                        'class' => 'btn btn-secondary',
                        'confirm' => '¿Reactivar? Se volverá a descontar el stock de los insumos.',
                    ],
                ) ?>
            <?php endif; ?>

            <?php if ($order->isEditable() && !empty($userPermissions['orders']['edit'])): ?>
                <?= $this->Html->link(
                    '<i class="bi bi-pencil"></i> Editar',
                    ['action' => 'edit', $order->id],
                    ['escape' => false, 'class' => 'btn btn-light'],
                ) ?>
            <?php endif; ?>

            <?php if (!empty($userPermissions['orders']['delete'])): ?>
                <?= $this->Form->postLink(
                    '<i class="bi bi-trash"></i> Eliminar',
                    ['action' => 'delete', $order->id],
                    [
                        'escape' => false,
                        'class' => 'btn btn-light text-danger ms-auto',
                        'confirm' => '¿Eliminar permanentemente este pedido?',
                    ],
                ) ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header bg-white"><strong>Cliente</strong></div>
            <div class="card-body">
                <div><strong><?= h($order->getCustomerName()) ?></strong></div>
                <?php if ($order->getCustomerPhone() !== ''): ?>
                    <div class="text-muted"><?= h($order->getCustomerPhone()) ?></div>
                <?php endif; ?>
                <?php if ($order->customer_id !== null && $order->customer !== null): ?>
                    <div class="mt-2">
                        <?= $this->Html->link(
                            'Ver ficha del cliente →',
                            ['controller' => 'Customers', 'action' => 'view', $order->customer_id],
                        ) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header bg-white"><strong>Entrega</strong></div>
            <div class="card-body">
                <div>Tipo: <strong><?= h(OrderConstants::TYPE_LABELS[$order->type] ?? $order->type) ?></strong></div>
                <?php if ($order->isDomicilio()): ?>
                    <?php if (!empty($order->customer_address)): ?>
                        <div>Dirección: <?= h($order->customer_address) ?></div>
                    <?php endif; ?>
                    <?php if ($order->delivery !== null): ?>
                        <div>Repartidor: <?= h(trim(($order->delivery->first_name ?? '') . ' ' . ($order->delivery->last_name ?? ''))) ?></div>
                    <?php else: ?>
                        <div class="text-muted">Repartidor: —</div>
                    <?php endif; ?>
                    <div>Costo de envío: $<?= h(number_format((float)$order->shipping_cost, 0, ',', '.')) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-white"><strong>Productos</strong></div>
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th class="text-center" style="width:80px;">Cant.</th>
                    <th class="text-end" style="width:120px;">Precio</th>
                    <th class="text-end" style="width:120px;">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ((array)$order->order_items as $item): ?>
                    <tr>
                        <td>
                            <?= h($item->product_name) ?>
                            <?php if (!empty($item->notes)): ?>
                                <small class="text-muted d-block"><?= h($item->notes) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?= h($item->getFormattedQuantity()) ?></td>
                        <td class="text-end">$<?= h(number_format((float)$item->price_at_sale, 0, ',', '.')) ?></td>
                        <td class="text-end">$<?= h(number_format((float)$item->line_subtotal, 0, ',', '.')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="3" class="text-end">Subtotal</th>
                    <th class="text-end">$<?= h(number_format((float)$order->subtotal, 0, ',', '.')) ?></th>
                </tr>
                <?php if ($order->isDomicilio() && (float)$order->shipping_cost > 0): ?>
                    <tr>
                        <th colspan="3" class="text-end">Envío</th>
                        <th class="text-end">$<?= h(number_format((float)$order->shipping_cost, 0, ',', '.')) ?></th>
                    </tr>
                <?php endif; ?>
                <tr>
                    <th colspan="3" class="text-end fs-5">Total</th>
                    <th class="text-end fs-5">$<?= h(number_format((float)$order->total, 0, ',', '.')) ?></th>
                </tr>
            </tfoot>
        </table>
    </div>
    <div class="card-footer bg-white">
        <div>Pago: <strong><?= h(OrderConstants::PAYMENT_LABELS[$order->payment_method] ?? $order->payment_method) ?></strong></div>
        <?php if (!empty($order->notes)): ?>
            <div class="mt-2"><em>Notas:</em> <?= nl2br(h($order->notes)) ?></div>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong>Historial reciente</strong>
        <?php if (!empty($isAdministrator)): ?>
            <?= $this->Html->link(
                'Ver todo →',
                ['controller' => 'OrderLogs', 'action' => 'index', $order->id],
                ['class' => 'btn btn-sm btn-light'],
            ) ?>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($logs === []): ?>
            <div class="text-muted">Sin movimientos registrados aún.</div>
        <?php else: ?>
            <ul class="list-unstyled mb-0">
                <?php foreach ($logs as $log): ?>
                    <li class="mb-2 d-flex align-items-start gap-2">
                        <i class="bi <?= h($log->getIcon()) ?> text-muted mt-1"></i>
                        <div>
                            <div><?= h($log->description) ?></div>
                            <small class="text-muted">
                                <?= h($log->user_name_snapshot) ?> · <?= h($log->getFormattedDate()) ?>
                            </small>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
