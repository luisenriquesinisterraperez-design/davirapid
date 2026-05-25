<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\DailyClosing $closing
 * @var array{sales_total:float,payments_total:float,expenses_total:float,expected:float} $preview
 */
$this->assign('title', 'Nuevo cierre');
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Nuevo cierre diario</h1>
</div>

<?= $this->Form->create($closing, ['type' => 'post', 'id' => 'closing-form']) ?>

<div class="row g-3">
    <div class="col-12 col-lg-5">
        <div class="card">
            <div class="card-body">
                <h2 class="h6 text-uppercase text-muted mb-3">Datos del cierre</h2>

                <div class="mb-3">
                    <label class="form-label" for="closing_date">Fecha</label>
                    <input type="date" class="form-control" id="closing_date" name="closing_date"
                           required
                           value="<?= h($closing->closing_date?->format('Y-m-d') ?? date('Y-m-d')) ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label" for="initial_balance">Base inicial</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" step="0.01" min="0" class="form-control"
                               id="initial_balance" name="initial_balance"
                               value="<?= h($closing->initial_balance ?? '0') ?>">
                    </div>
                    <small class="text-muted">Caja con la que arrancó el día.</small>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="actual_amount">Monto contado físicamente</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" step="0.01" min="0" class="form-control"
                               id="actual_amount" name="actual_amount" required
                               value="<?= h($closing->actual_amount ?? '') ?>">
                    </div>
                    <small class="text-muted">Lo que hay en caja al momento del cierre.</small>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="notes">Observaciones (opcional)</label>
                    <textarea class="form-control" id="notes" name="notes" rows="3"
                              maxlength="2000"><?= h($closing->notes ?? '') ?></textarea>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
                    <button type="submit" class="btn btn-primary">Guardar cierre</button>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-body">
                <h2 class="h6 text-uppercase text-muted mb-3">Cálculo automático</h2>

                <dl class="row mb-0" id="preview-rows">
                    <dt class="col-7 text-muted">Base inicial</dt>
                    <dd class="col-5 text-end" id="pv-initial">
                        $<?= h(number_format((float)($closing->initial_balance ?? 0), 0, ',', '.')) ?>
                    </dd>

                    <dt class="col-7 text-muted">
                        + Ventas del día (no crédito)
                    </dt>
                    <dd class="col-5 text-end text-success" id="pv-sales">
                        $<?= h(number_format($preview['sales_total'], 0, ',', '.')) ?>
                    </dd>

                    <dt class="col-7 text-muted">+ Abonos recibidos hoy</dt>
                    <dd class="col-5 text-end text-success" id="pv-payments">
                        $<?= h(number_format($preview['payments_total'], 0, ',', '.')) ?>
                    </dd>

                    <dt class="col-7 text-muted">− Gastos del día</dt>
                    <dd class="col-5 text-end text-danger" id="pv-expenses">
                        $<?= h(number_format($preview['expenses_total'], 0, ',', '.')) ?>
                    </dd>
                </dl>

                <hr class="my-3">

                <dl class="row mb-0">
                    <dt class="col-7 fw-semibold">Monto esperado</dt>
                    <dd class="col-5 text-end fs-4 fw-semibold" id="pv-expected">
                        $<?= h(number_format($preview['expected'], 0, ',', '.')) ?>
                    </dd>
                </dl>

                <div class="alert alert-info mt-3 mb-0 small">
                    <i class="bi bi-info-circle"></i>
                    El cálculo se actualiza al cambiar la fecha o la base inicial.
                </div>
            </div>
        </div>
    </div>
</div>

<?= $this->Form->end() ?>

<script>
(function () {
    const dateInput = document.getElementById('closing_date');
    const initialInput = document.getElementById('initial_balance');
    const previewUrl = '<?= $this->Url->build(['action' => 'preview']) ?>';

    function fmt(n) {
        return '$' + Math.round(n).toLocaleString('es-CO');
    }

    let timer = null;
    function refresh() {
        clearTimeout(timer);
        timer = setTimeout(async function () {
            const date = dateInput.value;
            const initial = parseFloat(initialInput.value || '0') || 0;
            const url = previewUrl + '?date=' + encodeURIComponent(date)
                + '&initial_balance=' + encodeURIComponent(initial);
            try {
                const r = await fetch(url, {credentials: 'same-origin'});
                if (!r.ok) return;
                const d = await r.json();
                document.getElementById('pv-initial').textContent = fmt(initial);
                document.getElementById('pv-sales').textContent = fmt(d.sales_total);
                document.getElementById('pv-payments').textContent = fmt(d.payments_total);
                document.getElementById('pv-expenses').textContent = fmt(d.expenses_total);
                document.getElementById('pv-expected').textContent = fmt(d.expected);
            } catch (e) {
                // Silently ignore preview errors; the server will compute on save.
            }
        }, 250);
    }

    dateInput.addEventListener('change', refresh);
    initialInput.addEventListener('input', refresh);
})();
</script>
