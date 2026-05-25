<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Product $product
 * @var array<int, string> $availableIngredients
 * @var array<int, \App\Model\Entity\Ingredient> $ingredientsMeta
 */
?>
<div class="card mb-4">
    <div class="card-header bg-white">
        <h5 class="mb-0">Agregar ingrediente</h5>
    </div>
    <div class="card-body">
        <?php if ($availableIngredients === []) : ?>
            <p class="text-muted mb-0">
                Todos los ingredientes disponibles ya están en la receta. Para
                cambiar una cantidad, editá la fila correspondiente arriba.
            </p>
        <?php else : ?>
            <?= $this->Form->create(null, [
                'url' => ['action' => 'addRecipeLine', $product->id],
            ]) ?>
                <div class="row g-3 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label" for="recipe-ingredient-id">Ingrediente</label>
                        <select name="ingredient_id" id="recipe-ingredient-id"
                                class="form-select" required>
                            <option value="">Seleccionar ingrediente...</option>
                            <?php foreach ($availableIngredients as $id => $label) : ?>
                                <?php
                                $meta = $ingredientsMeta[$id] ?? null;
                                $unit = $meta?->unit ?? '';
                                $unitCost = $meta?->unit_cost ?? '';
                                ?>
                                <option value="<?= h((string)$id) ?>"
                                        data-unit="<?= h((string)$unit) ?>"
                                        data-cost="<?= h((string)$unitCost) ?>">
                                    <?= h($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="recipe-quantity">Cantidad</label>
                        <div class="input-group">
                            <input type="number" name="quantity" id="recipe-quantity"
                                   class="form-control"
                                   step="0.001" min="0.001" required>
                            <span class="input-group-text" id="recipe-unit-suffix">unidad</span>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <?= $this->Form->button('Agregar', [
                            'class' => 'btn btn-primary w-100',
                        ]) ?>
                    </div>
                </div>

                <div class="form-check mt-3">
                    <input type="hidden" name="update_ingredient_cost" value="0">
                    <input type="checkbox" name="update_ingredient_cost"
                           id="recipe-update-cost"
                           class="form-check-input" value="1">
                    <label class="form-check-label" for="recipe-update-cost">
                        Actualizar costo unitario del ingrediente al guardar
                    </label>
                </div>
                <div id="recipe-new-cost-wrap" class="mt-2" style="display:none; max-width:280px;">
                    <label class="form-label" for="recipe-new-unit-cost">Nuevo costo unitario</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" name="new_unit_cost" id="recipe-new-unit-cost"
                               class="form-control" step="0.01" min="0">
                    </div>
                    <div class="form-text">
                        Este nuevo costo aplicará a todas las recetas que usan este ingrediente,
                        no solo a esta línea.
                    </div>
                </div>
            <?= $this->Form->end() ?>

            <script>
                (function () {
                    var sel = document.getElementById('recipe-ingredient-id');
                    var unitSuffix = document.getElementById('recipe-unit-suffix');
                    var checkbox = document.getElementById('recipe-update-cost');
                    var newCostWrap = document.getElementById('recipe-new-cost-wrap');
                    var newCostInput = document.getElementById('recipe-new-unit-cost');

                    function updateFromSelection() {
                        var opt = sel.options[sel.selectedIndex];
                        if (!opt || !opt.value) {
                            unitSuffix.textContent = 'unidad';
                            newCostInput.value = '';
                            newCostInput.placeholder = '';
                            return;
                        }
                        var unit = opt.getAttribute('data-unit') || 'unidad';
                        var cost = opt.getAttribute('data-cost') || '';
                        unitSuffix.textContent = unit;
                        newCostInput.placeholder = cost;
                        if (checkbox.checked) {
                            newCostInput.value = cost;
                        }
                    }

                    function toggleNewCost() {
                        if (checkbox.checked) {
                            newCostWrap.style.display = '';
                            var opt = sel.options[sel.selectedIndex];
                            if (opt && opt.value && !newCostInput.value) {
                                newCostInput.value = opt.getAttribute('data-cost') || '';
                            }
                        } else {
                            newCostWrap.style.display = 'none';
                        }
                    }

                    sel.addEventListener('change', updateFromSelection);
                    checkbox.addEventListener('change', toggleNewCost);
                })();
            </script>
        <?php endif; ?>
    </div>
</div>
