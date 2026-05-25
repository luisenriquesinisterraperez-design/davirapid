<?php
use App\Constants\IngredientConstants;

/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Ingredient $ingredient
 * @var string $submitLabel
 */
?>
<?= $this->Form->create($ingredient) ?>
<div class="card mb-4">
    <div class="card-body">
        <div class="row g-4">
            <div class="col-md-6">
                <h6 class="text-muted text-uppercase small mb-3">Identidad</h6>
                <div class="mb-3">
                    <?= $this->Form->control('name', [
                        'label' => 'Nombre',
                        'class' => 'form-control',
                        'maxlength' => IngredientConstants::NAME_MAX_LENGTH,
                        'autofocus' => $ingredient->isNew(),
                        'help' => 'Debe ser único.',
                    ]) ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="unit">Unidad</label>
                    <?= $this->Form->select(
                        'unit',
                        IngredientConstants::UNIT_LABELS,
                        [
                            'empty' => 'Seleccionar...',
                            'class' => 'form-select',
                            'id' => 'unit',
                            'value' => $ingredient->unit,
                        ]
                    ) ?>
                    <?php if (!$ingredient->isNew()): ?>
                        <div class="form-text text-warning">
                            Cambiar la unidad no convierte el stock actual.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-md-6">
                <h6 class="text-muted text-uppercase small mb-3">Inventario y costo</h6>
                <div class="mb-3">
                    <label class="form-label" for="stock_quantity">Stock actual</label>
                    <div class="input-group">
                        <input type="number" name="stock_quantity" id="stock_quantity"
                               class="form-control"
                               min="0" step="0.001"
                               value="<?= h($ingredient->stock_quantity ?? '') ?>" required>
                        <?php if (!empty($ingredient->unit)): ?>
                            <span class="input-group-text"><?= h($ingredient->unit) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="form-text">Hasta 3 decimales para `gr` y `ml`.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="unit_cost">Costo unitario</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" name="unit_cost" id="unit_cost"
                               class="form-control"
                               min="0" step="0.01"
                               value="<?= h($ingredient->unit_cost ?? '') ?>" required>
                    </div>
                    <div class="form-text">Costo por unidad indicada arriba.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="d-flex gap-2">
    <?= $this->Form->button(
        '<i class="bi bi-check-lg"></i> ' . h($submitLabel),
        ['escapeTitle' => false, 'class' => 'btn btn-primary']
    ) ?>
    <?php if ($ingredient->isNew()): ?>
        <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
    <?php else: ?>
        <?= $this->Html->link('Cancelar', ['action' => 'view', $ingredient->id], ['class' => 'btn btn-light']) ?>
    <?php endif; ?>
</div>
<?= $this->Form->end() ?>
