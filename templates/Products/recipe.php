<?php
use App\Constants\IngredientConstants;

/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Product $product
 * @var list<\App\Model\Entity\ProductIngredient> $lines
 * @var float $cost
 * @var array<int, string> $availableIngredients
 * @var array<int, \App\Model\Entity\Ingredient> $ingredientsMeta
 * @var array<string, array<string, bool>> $userPermissions
 */
$this->assign('title', 'Receta — ' . $product->name);

$price = (float)$product->price;
$margin = $price - $cost;
$marginRatio = $price > 0 ? $margin / $price : 0.0;
$marginClass = 'badge-soft-success';
if (count($lines) === 0) {
    $marginClass = 'badge-soft-default';
} elseif ($marginRatio < 0.2) {
    $marginClass = 'badge-soft-danger';
} elseif ($marginRatio < 0.5) {
    $marginClass = 'badge-soft-warning';
}
?>
<div class="dr-page-header">
    <div>
        <h1 class="dr-page-title">
            Receta — <?= h($product->name) ?>
            <?php if (!$product->is_active) : ?>
                <span class="badge badge-soft-warning ms-2">Inactivo</span>
            <?php endif; ?>
        </h1>
        <?php if (!empty($product->code)) : ?>
            <code class="text-muted"><?= h($product->code) ?></code>
        <?php endif; ?>
    </div>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left"></i> Volver al producto',
        ['action' => 'view', $product->id],
        ['escape' => false, 'class' => 'btn btn-secondary'],
    ) ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="text-muted text-uppercase small mb-1">Precio de venta</div>
                <div class="fs-3 fw-semibold"><?= h($product->getFormattedPrice()) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="text-muted text-uppercase small mb-1">Costo total de receta</div>
                <?php if (count($lines) === 0) : ?>
                    <div class="fs-3 fw-semibold">$0</div>
                    <span class="badge badge-soft-info mt-1">Sin receta</span>
                <?php else : ?>
                    <div class="fs-3 fw-semibold">$<?= h(number_format($cost, 0, ',', '.')) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="text-muted text-uppercase small mb-1">Margen estimado</div>
                <?php if (count($lines) === 0) : ?>
                    <div class="fs-3 fw-semibold text-muted">—</div>
                <?php else : ?>
                    <div class="fs-3 fw-semibold">
                        <span class="badge <?= h($marginClass) ?>">
                            $<?= h(number_format($margin, 0, ',', '.')) ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (count($lines) === 0) : ?>
    <div class="card mb-4">
        <div class="card-body text-center py-5">
            <i class="bi bi-journal-text fs-1 text-muted mb-3 d-block"></i>
            <h5>Este producto no tiene receta.</h5>
            <p class="text-muted mb-0">
                Agregá ingredientes para que el inventario se descuente automáticamente al vender este producto.
            </p>
        </div>
    </div>
<?php else : ?>
    <div class="card mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0">Ingredientes de la receta</h5>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Ingrediente</th>
                        <th style="width:120px;">Unidad</th>
                        <th class="text-end" style="width:260px;">Cantidad por unidad</th>
                        <th class="text-end" style="width:140px;">Costo unitario</th>
                        <th class="text-end" style="width:140px;">Costo de línea</th>
                        <th class="text-center" style="width:90px;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lines as $line) : ?>
                        <tr>
                            <td>
                                <?= $this->Html->link(
                                    h($line->ingredient->name),
                                    ['controller' => 'Ingredients', 'action' => 'view', $line->ingredient->id],
                                ) ?>
                            </td>
                            <td>
                                <?php $unit = $line->ingredient->unit; ?>
                                <?= h(IngredientConstants::UNIT_LABELS[$unit] ?? $unit) ?>
                            </td>
                            <td class="text-end">
                                <?= $this->Form->create(null, [
                                    'url' => [
                                        'action' => 'updateRecipeLine',
                                        $product->id,
                                        $line->id,
                                    ],
                                    'class' => 'd-flex justify-content-end gap-2',
                                ]) ?>
                                    <div class="input-group input-group-sm" style="max-width:200px;">
                                        <input type="number" name="quantity"
                                               class="form-control text-end"
                                               step="0.001" min="0.001"
                                               value="<?= h($line->quantity) ?>" required>
                                        <span class="input-group-text"><?= h($line->ingredient->unit) ?></span>
                                    </div>
                                    <?= $this->Form->button('Actualizar', [
                                        'class' => 'btn btn-light btn-sm',
                                    ]) ?>
                                <?= $this->Form->end() ?>
                            </td>
                            <td class="text-end"><?= h($line->ingredient->getFormattedUnitCost()) ?></td>
                            <td class="text-end fw-semibold"><?= h($line->getFormattedLineCost()) ?></td>
                            <td class="text-center">
                                <?php if (!empty($userPermissions['recipes']['delete'])) : ?>
                                    <?= $this->Form->postLink(
                                        '<i class="bi bi-trash"></i>',
                                        ['action' => 'removeRecipeLine', $product->id, $line->id],
                                        [
                                            'escape' => false,
                                            'class' => 'btn btn-icon btn-light text-danger',
                                            'title' => 'Eliminar',
                                            'confirm' => '¿Eliminar este ingrediente de la receta?',
                                        ],
                                    ) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-light">
                        <th colspan="4" class="text-end">Total</th>
                        <th class="text-end">$<?= h(number_format($cost, 0, ',', '.')) ?></th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($userPermissions['recipes']['create'])) : ?>
    <?= $this->element('Recipes/_add_line_form', [
        'product' => $product,
        'availableIngredients' => $availableIngredients,
        'ingredientsMeta' => $ingredientsMeta,
    ]) ?>
<?php endif; ?>
