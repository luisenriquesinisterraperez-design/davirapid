<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\DailyClosing $closing
 */
$this->assign('title', 'Editar cierre');
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Editar cierre del <?= h($closing->getFormattedDate()) ?></h1>
</div>

<div class="row">
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-body">
                <?= $this->Form->create($closing, ['type' => 'put']) ?>

                <div class="alert alert-info">
                    Solo el monto contado y las observaciones son editables.
                    Los totales del día quedaron capturados como snapshot al cierre.
                </div>

                <div class="mb-3">
                    <label class="form-label">Esperado (snapshot)</label>
                    <div class="form-control-plaintext fs-5 fw-semibold">
                        $<?= h(number_format((float)$closing->expected_amount, 2, ',', '.')) ?>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="actual_amount">Monto contado</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" step="0.01" min="0" class="form-control"
                               id="actual_amount" name="actual_amount" required
                               value="<?= h($closing->actual_amount) ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="notes">Observaciones</label>
                    <textarea class="form-control" id="notes" name="notes" rows="4"
                              maxlength="2000"><?= h($closing->notes ?? '') ?></textarea>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <?= $this->Html->link(
                        'Cancelar',
                        ['action' => 'view', $closing->id],
                        ['class' => 'btn btn-light'],
                    ) ?>
                    <button type="submit" class="btn btn-primary">Guardar cambios</button>
                </div>

                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>
