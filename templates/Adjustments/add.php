<?php
use App\Constants\InventoryAdjustmentConstants;

/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\InventoryAdjustment $adjustment
 * @var array<int, string> $ingredients
 * @var array<int, \App\Model\Entity\Ingredient> $ingredientsMeta
 * @var list<string> $reasonSuggestions
 */
$this->assign('title', 'Nuevo ajuste');
$selectedId = (int)($adjustment->ingredient_id ?? 0);
$selectedMeta = $selectedId > 0 ? ($ingredientsMeta[$selectedId] ?? null) : null;
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Nuevo ajuste</h1>
</div>

<?= $this->Form->create($adjustment, ['url' => ['action' => 'add']]) ?>
<div class="card">
    <div class="card-body">
        <div class="row g-4">
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label" for="ingredient_id">Ingrediente</label>
                    <select name="ingredient_id" id="ingredient_id" class="form-select" required>
                        <option value="">Seleccionar ingrediente...</option>
                        <?php foreach ($ingredients as $id => $label):
                            $meta = $ingredientsMeta[$id] ?? null;
                            $unit = $meta !== null ? (string)$meta->unit : '';
                            $stock = $meta !== null ? (string)$meta->stock_quantity : '';
                            ?>
                            <option value="<?= (int)$id ?>"
                                data-unit="<?= h($unit) ?>"
                                data-stock="<?= h($stock) ?>"
                                <?= $selectedId === (int)$id ? 'selected' : '' ?>>
                                <?= h($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($selectedMeta !== null): ?>
                    <div class="card stat-card mb-3">
                        <div class="card-body py-2">
                            <small class="text-muted d-block">Stock actual</small>
                            <strong>
                                <?= h(number_format((float)$selectedMeta->stock_quantity, 3, '.', '')) ?>
                                <?= h((string)$selectedMeta->unit) ?>
                            </strong>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label d-block">Tipo</label>
                    <div class="btn-group dr-toggle" role="group" aria-label="Tipo de ajuste">
                        <input type="radio" class="btn-check" name="type" id="type-entrada"
                               value="<?= h(InventoryAdjustmentConstants::TYPE_ENTRY) ?>"
                               <?= ($adjustment->type ?? '') === InventoryAdjustmentConstants::TYPE_ENTRY ? 'checked' : '' ?>
                               required>
                        <label class="btn btn-outline-success" for="type-entrada">
                            <i class="bi bi-arrow-down-circle"></i> Entrada
                        </label>

                        <input type="radio" class="btn-check" name="type" id="type-baja"
                               value="<?= h(InventoryAdjustmentConstants::TYPE_BAJA) ?>"
                               <?= ($adjustment->type ?? '') === InventoryAdjustmentConstants::TYPE_BAJA ? 'checked' : '' ?>>
                        <label class="btn btn-outline-warning" for="type-baja">
                            <i class="bi bi-arrow-up-circle"></i> Baja
                        </label>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="quantity">Cantidad</label>
                    <input type="number" name="quantity" id="quantity" class="form-control"
                           step="0.001" min="0.001" required
                           value="<?= h((string)($adjustment->quantity ?? '')) ?>">
                </div>
            </div>

            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label" for="reason">Motivo</label>
                    <input type="text" name="reason" id="reason" class="form-control"
                           maxlength="120" required list="reason-suggestions"
                           value="<?= h((string)($adjustment->reason ?? '')) ?>">
                    <datalist id="reason-suggestions">
                        <?php foreach ($reasonSuggestions as $suggestion): ?>
                            <option value="<?= h($suggestion) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <small class="form-text text-muted">Texto libre — las sugerencias son guía.</small>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="notes">Notas</label>
                    <textarea name="notes" id="notes" class="form-control" rows="4"><?= h((string)($adjustment->notes ?? '')) ?></textarea>
                    <small class="form-text text-muted">Detalle opcional para contexto futuro.</small>
                </div>
            </div>
        </div>
    </div>
    <div class="card-footer bg-white d-flex gap-2 justify-content-end">
        <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
        <button type="submit" class="btn btn-primary">Registrar ajuste</button>
    </div>
</div>
<?= $this->Form->end() ?>
