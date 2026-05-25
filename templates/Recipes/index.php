<?php
/**
 * @var \App\View\AppView $this
 * @var \Cake\ORM\ResultSet|array<\App\Model\Entity\Product> $products
 * @var array{q:string, has_recipe:string} $filters
 * @var array<string, array<string, bool>> $userPermissions
 */
$this->assign('title', 'Recetas');
$hasActiveFilters = $filters['q'] !== '' || $filters['has_recipe'] !== 'all';

/**
 * Suma de getLineCost() sobre las líneas hidratadas del producto. Evita una
 * segunda query por fila (mantenemos los datos del contain('ProductIngredients.Ingredients')).
 */
$recipeCostSum = function ($product): float {
    $total = 0.0;
    foreach ($product->product_ingredients ?? [] as $line) {
        $total += $line->getLineCost();
    }

    return $total;
};
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Recetas</h1>
</div>

<form method="get" class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <i class="bi bi-search text-muted"></i>
            <input type="text" name="q" class="form-control form-control-sm" style="max-width: 320px;"
                   value="<?= h($filters['q']) ?>" placeholder="Buscar por nombre o código">
            <select name="has_recipe" class="form-select form-select-sm" style="max-width: 200px;">
                <?php
                $hasRecipeOptions = ['all' => 'Todas', 'with' => 'Con receta', 'without' => 'Sin receta'];
                ?>
                <?php foreach ($hasRecipeOptions as $val => $label) : ?>
                    <option value="<?= h($val) ?>" <?= $filters['has_recipe'] === $val ? 'selected' : '' ?>>
                        <?= h($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-secondary">Filtrar</button>
            <?php if ($hasActiveFilters) : ?>
                <?= $this->Html->link('Limpiar', ['action' => 'index'], ['class' => 'btn btn-sm btn-light']) ?>
            <?php endif; ?>
        </div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th><?= $this->Paginator->sort('name', 'Producto') ?></th>
                    <th class="text-end" style="width:120px;"><?= $this->Paginator->sort('price', 'Precio') ?></th>
                    <th class="text-center" style="width:140px;">Ingredientes</th>
                    <th class="text-end" style="width:140px;">Costo de receta</th>
                    <th class="text-end" style="width:120px;">Margen</th>
                    <th class="text-end" style="width:160px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $product) : ?>
                    <?php
                    $lineCount = count($product->product_ingredients ?? []);
                    $cost = $recipeCostSum($product);
                    $price = (float)$product->price;
                    $margin = $price - $cost;
                    $marginRatio = $price > 0 ? $margin / $price : 0.0;
                    $marginClass = 'badge-soft-success';
                    if ($lineCount === 0) {
                        $marginClass = 'badge-soft-default';
                    } elseif ($marginRatio < 0.2) {
                        $marginClass = 'badge-soft-danger';
                    } elseif ($marginRatio < 0.5) {
                        $marginClass = 'badge-soft-warning';
                    }
                    ?>
                    <tr>
                        <td>
                            <?= $this->Html->link(
                                h($product->name),
                                ['controller' => 'Products', 'action' => 'view', $product->id],
                            ) ?>
                            <?php if (!$product->is_active) : ?>
                                <span class="badge badge-soft-secondary ms-1">Inactivo</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= h($product->getFormattedPrice()) ?></td>
                        <td class="text-center">
                            <?php if ($lineCount === 0) : ?>
                                <span class="badge badge-soft-info">Sin receta</span>
                            <?php else : ?>
                                <?= h($lineCount) ?>
                                <?= $lineCount === 1 ? 'ingrediente' : 'ingredientes' ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($lineCount === 0) : ?>
                                <span class="text-muted">—</span>
                            <?php else : ?>
                                $<?= h(number_format($cost, 0, ',', '.')) ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($lineCount === 0) : ?>
                                <span class="text-muted">—</span>
                            <?php else : ?>
                                <span class="badge <?= h($marginClass) ?>">
                                    $<?= h(number_format($margin, 0, ',', '.')) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?= $this->Html->link(
                                '<i class="bi bi-journal-text"></i> Editar receta',
                                ['controller' => 'Products', 'action' => 'recipe', $product->id],
                                ['escape' => false, 'class' => 'btn btn-sm btn-secondary'],
                            ) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($products->toArray()) === 0) : ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">
                            <?php if ($hasActiveFilters) : ?>
                                Sin resultados para los filtros aplicados.
                            <?php else : ?>
                                Aún no hay productos en el catálogo.
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->element('pagination') ?>
