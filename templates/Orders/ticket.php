<?php
use App\Constants\OrderConstants;

/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Order $order
 */
$this->assign('title', 'Ticket #' . (int)$order->id);

// TODO Fase 6: parametrizar logo/NIT/dirección/teléfono via tabla business_info o config/app.php.
$business = [
    'name' => 'DAVI RAPID',
    'address' => 'Calle 100 #50-10',
    'nit' => 'NIT: 900.123.456-7',
    'phone' => 'Tel: 555-1234',
];
?>
<div class="ticket-center ticket-section">
    <h1><?= h($business['name']) ?></h1>
    <div><?= h($business['address']) ?></div>
    <div><?= h($business['nit']) ?></div>
    <div><?= h($business['phone']) ?></div>
</div>
<div class="ticket-sep"></div>

<div class="ticket-row">
    <strong>PEDIDO #<?= (int)$order->id ?></strong>
    <span><?= $order->created !== null ? h($order->created->i18nFormat('dd/MM HH:mm')) : '' ?></span>
</div>
<div class="ticket-sep"></div>

<div class="ticket-section">
    <div>Cliente: <?= h($order->getCustomerName()) ?></div>
    <?php if ($order->getCustomerPhone() !== ''): ?>
        <div>Tel: <?= h($order->getCustomerPhone()) ?></div>
    <?php endif; ?>
    <?php if ($order->isDomicilio()): ?>
        <?php if (!empty($order->customer_address)): ?>
            <div>Dirección: <?= h($order->customer_address) ?></div>
        <?php endif; ?>
        <div>Tipo: DOMICILIO</div>
        <?php if ($order->delivery !== null): ?>
            <div>Repartidor: <?= h(trim(($order->delivery->first_name ?? '') . ' ' . ($order->delivery->last_name ?? ''))) ?></div>
        <?php endif; ?>
    <?php else: ?>
        <div>Tipo: LOCAL</div>
    <?php endif; ?>
</div>
<div class="ticket-sep"></div>

<div class="ticket-section">
    <?php foreach ((array)$order->order_items as $item): ?>
        <div class="ticket-row">
            <span><?= h($item->getFormattedQuantity()) ?> × <?= h($item->product_name) ?></span>
            <span><?= h(number_format((float)$item->line_subtotal, 0, ',', '.')) ?></span>
        </div>
        <?php if (!empty($item->notes)): ?>
            <div style="padding-left:8px;font-style:italic;">— <?= h($item->notes) ?></div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<div class="ticket-sep"></div>

<div class="ticket-row">
    <span>Subtotal:</span>
    <span><?= h(number_format((float)$order->subtotal, 0, ',', '.')) ?></span>
</div>
<?php if ($order->isDomicilio() && (float)$order->shipping_cost > 0): ?>
    <div class="ticket-row">
        <span>Envío:</span>
        <span><?= h(number_format((float)$order->shipping_cost, 0, ',', '.')) ?></span>
    </div>
<?php endif; ?>
<div class="ticket-row">
    <strong>TOTAL:</strong>
    <strong><?= h(number_format((float)$order->total, 0, ',', '.')) ?></strong>
</div>
<div class="ticket-sep"></div>

<div>Pago: <strong><?= h(OrderConstants::PAYMENT_LABELS[$order->payment_method] ?? $order->payment_method) ?></strong></div>

<?php if (!empty($order->notes)): ?>
    <div class="ticket-section">Notas: <?= h($order->notes) ?></div>
<?php endif; ?>

<div class="ticket-sep"></div>
<div class="ticket-center ticket-section">¡Gracias por su compra!</div>

<script>
(function () {
    var params = new URL(window.location).searchParams;
    if (params.get('autoprint') !== '0') {
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 300);
        });
    }
})();
</script>
