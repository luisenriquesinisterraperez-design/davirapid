<?php
use App\Constants\OrderConstants;

/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Order $order
 * @var array<int, \App\Model\Entity\Product> $productsList
 * @var array<int, \App\Model\Entity\Delivery> $deliveriesList
 */
$this->assign('title', 'Nuevo pedido');
$currentType = (string)($order->type ?? OrderConstants::TYPE_LOCAL);
$currentMethod = (string)($order->payment_method ?? OrderConstants::PAYMENT_CASH);
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Nuevo pedido</h1>
</div>

<?= $this->Form->create($order, ['url' => ['action' => 'add'], 'id' => 'order-form']) ?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header bg-white"><strong>Cliente</strong></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label" for="customer_phone">Teléfono</label>
                        <input type="text" name="customer_phone" id="customer_phone"
                               class="form-control"
                               maxlength="30"
                               value="<?= h((string)($order->customer_phone ?? '')) ?>">
                    </div>
                    <div class="col-md-7">
                        <label class="form-label" for="customer_name">Nombre</label>
                        <input type="text" name="customer_name" id="customer_name"
                               class="form-control"
                               maxlength="150"
                               value="<?= h((string)($order->customer_name ?? '')) ?>">
                    </div>
                    <div class="col-12" id="address-block">
                        <label class="form-label" for="customer_address">Dirección</label>
                        <input type="text" name="customer_address" id="customer_address"
                               class="form-control"
                               maxlength="255"
                               value="<?= h((string)($order->customer_address ?? '')) ?>">
                        <small class="form-text text-muted">Requerido si el pedido es a domicilio.</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header bg-white"><strong>Tipo de pedido</strong></div>
            <div class="card-body">
                <div class="btn-group dr-toggle" role="group">
                    <input type="radio" class="btn-check" name="type" id="type-local"
                           value="<?= h(OrderConstants::TYPE_LOCAL) ?>"
                           <?= $currentType === OrderConstants::TYPE_LOCAL ? 'checked' : '' ?>>
                    <label class="btn btn-outline-secondary" for="type-local">
                        <i class="bi bi-shop"></i> Local
                    </label>
                    <input type="radio" class="btn-check" name="type" id="type-domicilio"
                           value="<?= h(OrderConstants::TYPE_DOMICILIO) ?>"
                           <?= $currentType === OrderConstants::TYPE_DOMICILIO ? 'checked' : '' ?>>
                    <label class="btn btn-outline-secondary" for="type-domicilio">
                        <i class="bi bi-bicycle"></i> Domicilio
                    </label>
                </div>

                <div id="domicilio-block" class="mt-3" style="<?= $currentType === OrderConstants::TYPE_DOMICILIO ? '' : 'display:none;' ?>">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label" for="delivery_id">Repartidor</label>
                            <select name="delivery_id" id="delivery_id" class="form-select">
                                <option value="">Seleccionar repartidor...</option>
                                <?php foreach ($deliveriesList as $d): ?>
                                    <option value="<?= (int)$d->id ?>"
                                        <?= (int)($order->delivery_id ?? 0) === (int)$d->id ? 'selected' : '' ?>>
                                        <?= h(trim(($d->first_name ?? '') . ' ' . ($d->last_name ?? ''))) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="shipping_cost">Costo de envío</label>
                            <input type="number" name="shipping_cost" id="shipping_cost"
                                   class="form-control"
                                   step="100" min="0"
                                   value="<?= h((string)($order->shipping_cost ?? '0')) ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <strong>Productos</strong>
                <button type="button" class="btn btn-sm btn-secondary" id="add-line-btn">
                    <i class="bi bi-plus-lg"></i> Agregar línea
                </button>
            </div>
            <div class="card-body" id="order-lines">
                <div class="order-line row g-2 align-items-end mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Producto</label>
                        <select name="items[0][product_id]" class="form-select line-product">
                            <option value="">Seleccionar...</option>
                            <?php foreach ($productsList as $p): ?>
                                <option value="<?= (int)$p->id ?>" data-price="<?= h((string)$p->price) ?>">
                                    <?= h($p->name) ?> — $<?= h(number_format((float)$p->price, 0, ',', '.')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Cantidad</label>
                        <input type="number" name="items[0][quantity]" class="form-control line-qty"
                               step="1" min="1" value="1">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Subtotal</label>
                        <div class="form-control-plaintext line-subtotal">$0</div>
                    </div>
                    <div class="col-md-1 text-end">
                        <button type="button" class="btn btn-icon btn-light text-danger remove-line">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <div class="col-12">
                        <input type="text" name="items[0][notes]" class="form-control form-control-sm"
                               placeholder="Notas opcionales por línea (sin cebolla, extra picante…)">
                    </div>
                </div>
            </div>
        </div>

        <template id="order-line-tpl">
            <div class="order-line row g-2 align-items-end mb-3">
                <div class="col-md-6">
                    <label class="form-label">Producto</label>
                    <select name="items[__IDX__][product_id]" class="form-select line-product">
                        <option value="">Seleccionar...</option>
                        <?php foreach ($productsList as $p): ?>
                            <option value="<?= (int)$p->id ?>" data-price="<?= h((string)$p->price) ?>">
                                <?= h($p->name) ?> — $<?= h(number_format((float)$p->price, 0, ',', '.')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Cantidad</label>
                    <input type="number" name="items[__IDX__][quantity]" class="form-control line-qty"
                           step="1" min="1" value="1">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Subtotal</label>
                    <div class="form-control-plaintext line-subtotal">$0</div>
                </div>
                <div class="col-md-1 text-end">
                    <button type="button" class="btn btn-icon btn-light text-danger remove-line">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <div class="col-12">
                    <input type="text" name="items[__IDX__][notes]" class="form-control form-control-sm"
                           placeholder="Notas opcionales por línea">
                </div>
            </div>
        </template>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header bg-white"><strong>Método de pago</strong></div>
            <div class="card-body">
                <?php foreach (OrderConstants::PAYMENT_LABELS as $key => $label): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="payment_method"
                               id="pm-<?= h($key) ?>" value="<?= h($key) ?>"
                               <?= $currentMethod === $key ? 'checked' : '' ?>>
                        <label class="form-check-label" for="pm-<?= h($key) ?>">
                            <?= h($label) ?>
                        </label>
                    </div>
                <?php endforeach; ?>
                <div id="credit-warning" class="alert alert-warning mt-3 mb-0"
                     style="<?= $currentMethod === OrderConstants::PAYMENT_CREDIT ? '' : 'display:none;' ?>">
                    <i class="bi bi-exclamation-triangle"></i>
                    Si elegís Crédito se generará una Cuenta por Cobrar al cliente.
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header bg-white"><strong>Resumen</strong></div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-1">
                    <span>Subtotal</span>
                    <strong id="resumen-subtotal">$0</strong>
                </div>
                <div class="d-flex justify-content-between mb-1">
                    <span>Envío</span>
                    <strong id="resumen-shipping">$0</strong>
                </div>
                <hr>
                <div class="d-flex justify-content-between fs-5">
                    <span>Total</span>
                    <strong id="resumen-total">$0</strong>
                </div>

                <div class="mt-3">
                    <label class="form-label" for="notes">Notas del pedido</label>
                    <textarea name="notes" id="notes" class="form-control" rows="3"><?= h((string)($order->notes ?? '')) ?></textarea>
                </div>
            </div>
            <div class="card-footer bg-white d-flex gap-2 justify-content-end">
                <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
                <button type="submit" class="btn btn-primary">Guardar pedido</button>
            </div>
        </div>
    </div>
</div>
<?= $this->Form->end() ?>

<script>
(function () {
    var lines = document.getElementById('order-lines');
    var tpl = document.getElementById('order-line-tpl');
    var addBtn = document.getElementById('add-line-btn');
    var nextIdx = 1;

    function fmtMoney(n) {
        return '$' + (Math.round(n)).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function recompute() {
        var subtotal = 0;
        lines.querySelectorAll('.order-line').forEach(function (row) {
            var sel = row.querySelector('.line-product');
            var qtyEl = row.querySelector('.line-qty');
            var subEl = row.querySelector('.line-subtotal');
            var opt = sel.options[sel.selectedIndex];
            var price = parseFloat(opt && opt.dataset.price ? opt.dataset.price : '0') || 0;
            var qty = parseFloat(qtyEl.value || '0') || 0;
            var line = Math.round(price * qty * 100) / 100;
            subEl.textContent = fmtMoney(line);
            subtotal += line;
        });
        var ship = parseFloat(document.getElementById('shipping_cost') ? (document.getElementById('shipping_cost').value || '0') : '0') || 0;
        document.getElementById('resumen-subtotal').textContent = fmtMoney(subtotal);
        document.getElementById('resumen-shipping').textContent = fmtMoney(ship);
        document.getElementById('resumen-total').textContent = fmtMoney(subtotal + ship);
    }

    function addLine() {
        var html = tpl.innerHTML.replace(/__IDX__/g, String(nextIdx++));
        var temp = document.createElement('div');
        temp.innerHTML = html.trim();
        var node = temp.firstChild;
        lines.appendChild(node);
        wireRow(node);
        recompute();
    }

    function removeLine(row) {
        if (lines.querySelectorAll('.order-line').length <= 1) {
            return;
        }
        row.remove();
        recompute();
    }

    function wireRow(row) {
        row.querySelector('.line-product').addEventListener('change', recompute);
        row.querySelector('.line-qty').addEventListener('input', recompute);
        row.querySelector('.remove-line').addEventListener('click', function () {
            removeLine(row);
        });
    }

    lines.querySelectorAll('.order-line').forEach(wireRow);
    if (addBtn) addBtn.addEventListener('click', addLine);

    var shippingEl = document.getElementById('shipping_cost');
    if (shippingEl) shippingEl.addEventListener('input', recompute);

    document.querySelectorAll('input[name="type"]').forEach(function (r) {
        r.addEventListener('change', function () {
            var dom = (r.value === 'domicilio' && r.checked);
            document.getElementById('domicilio-block').style.display = dom ? '' : 'none';
        });
    });

    document.querySelectorAll('input[name="payment_method"]').forEach(function (r) {
        r.addEventListener('change', function () {
            document.getElementById('credit-warning').style.display =
                (r.value === 'credito' && r.checked) ? '' : 'none';
        });
    });

    recompute();
})();
</script>
