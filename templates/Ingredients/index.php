<?php
use App\Constants\IngredientConstants;

/**
 * @var \App\View\AppView $this
 * @var \Cake\ORM\ResultSet|\App\Model\Entity\Ingredient[] $ingredients
 * @var array{q:string,unit:string,low_stock:string,sort:string,direction:string} $filters
 * @var array<string, array<string, bool>> $userPermissions
 */
$this->assign('title', 'Ingredientes');
$hasActiveFilters = $filters['q'] !== '' || $filters['unit'] !== 'all' || $filters['low_stock'] === '1';
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Ingredientes</h1>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg"></i> Nuevo ingrediente',
        ['action' => 'add'],
        ['escape' => false, 'class' => 'btn btn-primary']
    ) ?>
</div>

<form method="get" class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <i class="bi bi-search text-muted"></i>
            <input type="text" name="q" class="form-control form-control-sm" style="max-width: 320px;"
                   value="<?= h($filters['q']) ?>" placeholder="Buscar por nombre">
            <select name="unit" class="form-select form-select-sm" style="max-width: 200px;">
                <option value="all" <?= $filters['unit'] === 'all' ? 'selected' : '' ?>>Todas las unidades</option>
                <?php foreach (IngredientConstants::UNIT_LABELS as $val => $label): ?>
                    <option value="<?= h($val) ?>" <?= $filters['unit'] === $val ? 'selected' : '' ?>>
                        <?= h($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="form-check ms-2">
                <input type="hidden" name="low_stock" value="0">
                <input type="checkbox" name="low_stock" id="low_stock" class="form-check-input"
                       value="1" <?= $filters['low_stock'] === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="low_stock">Solo bajo stock</label>
            </div>
            <button type="submit" class="btn btn-sm btn-secondary">Filtrar</button>
            <?php if ($hasActiveFilters): ?>
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
                    <th><?= $this->Paginator->sort('name', 'Nombre') ?></th>
                    <th style="width:120px;">Unidad</th>
                    <th class="text-end" style="width:160px;"><?= $this->Paginator->sort('stock_quantity', 'Stock actual') ?></th>
                    <th class="text-end" style="width:160px;"><?= $this->Paginator->sort('unit_cost', 'Costo unitario') ?></th>
                    <th class="text-center" style="width:140px;">Estado</th>
                    <th class="text-end" style="width:140px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ingredients as $ingredient): ?>
                    <tr>
                        <td>
                            <?= $this->Html->link(h($ingredient->name), ['action' => 'edit', $ingredient->id]) ?>
                        </td>
                        <td>
                            <?= h(IngredientConstants::UNIT_LABELS[$ingredient->unit] ?? $ingredient->unit) ?>
                        </td>
                        <td class="text-end">
                            <?php if ($ingredient->isLowStock()): ?>
                                <span class="text-danger fw-semibold"><?= h($ingredient->getFormattedStock()) ?></span>
                            <?php else: ?>
                                <?= h($ingredient->getFormattedStock()) ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= h($ingredient->getFormattedUnitCost()) ?></td>
                        <td class="text-center">
                            <?php if ($ingredient->isOutOfStock()): ?>
                                <span class="badge badge-soft-danger">Sin stock</span>
                            <?php elseif ($ingredient->isLowStock()): ?>
                                <span class="badge badge-soft-danger">Bajo stock</span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?= $this->Html->link(
                                '<i class="bi bi-pencil"></i>',
                                ['action' => 'edit', $ingredient->id],
                                ['escape' => false, 'class' => 'btn btn-icon btn-light', 'title' => 'Editar']
                            ) ?>
                            <?php if (!empty($userPermissions['ingredients']['delete'])): ?>
                                <?= $this->Form->postLink(
                                    '<i class="bi bi-trash"></i>',
                                    ['action' => 'delete', $ingredient->id],
                                    [
                                        'escape' => false,
                                        'class' => 'btn btn-icon btn-light text-danger',
                                        'title' => 'Eliminar',
                                        'confirm' => sprintf('¿Eliminar el ingrediente "%s"?', $ingredient->name),
                                    ]
                                ) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($ingredients->toArray()) === 0): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">
                            <?php if ($hasActiveFilters): ?>
                                Sin resultados para los filtros aplicados.
                            <?php else: ?>
                                Aún no hay ingredientes.
                                <?= $this->Html->link('Crear el primero', ['action' => 'add'], ['class' => 'ms-1']) ?>.
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->element('pagination') ?>
