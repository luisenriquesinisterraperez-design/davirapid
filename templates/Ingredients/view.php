<?php
use App\Constants\IngredientConstants;

/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Ingredient $ingredient
 */
$this->assign('title', $ingredient->name);
?>
<div class="dr-page-header">
    <h1 class="dr-page-title"><?= h($ingredient->name) ?></h1>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-pencil"></i> Editar',
            ['action' => 'edit', $ingredient->id],
            ['escape' => false, 'class' => 'btn btn-secondary']
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left"></i> Volver',
            ['action' => 'index'],
            ['escape' => false, 'class' => 'btn btn-light']
        ) ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">Nombre</dt>
            <dd class="col-sm-9"><?= h($ingredient->name) ?></dd>

            <dt class="col-sm-3">Unidad</dt>
            <dd class="col-sm-9"><?= h(IngredientConstants::UNIT_LABELS[$ingredient->unit] ?? $ingredient->unit) ?></dd>

            <dt class="col-sm-3">Stock actual</dt>
            <dd class="col-sm-9">
                <?php if ($ingredient->isOutOfStock()): ?>
                    <span class="text-danger fw-semibold"><?= h($ingredient->getFormattedStock()) ?></span>
                    <span class="badge badge-soft-danger ms-2">Sin stock</span>
                <?php elseif ($ingredient->isLowStock()): ?>
                    <span class="text-danger fw-semibold"><?= h($ingredient->getFormattedStock()) ?></span>
                    <span class="badge badge-soft-danger ms-2">Bajo stock</span>
                <?php else: ?>
                    <?= h($ingredient->getFormattedStock()) ?>
                <?php endif; ?>
            </dd>

            <dt class="col-sm-3">Costo unitario</dt>
            <dd class="col-sm-9"><?= h($ingredient->getFormattedUnitCost()) ?></dd>

            <dt class="col-sm-3">Creado</dt>
            <dd class="col-sm-9"><?= $ingredient->created ? h($ingredient->created->i18nFormat('dd/MM/yyyy HH:mm')) : '—' ?></dd>

            <dt class="col-sm-3">Modificado</dt>
            <dd class="col-sm-9"><?= $ingredient->modified ? h($ingredient->modified->i18nFormat('dd/MM/yyyy HH:mm')) : '—' ?></dd>
        </dl>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header bg-white">
        <h5 class="mb-0">Usos en recetas</h5>
    </div>
    <div class="card-body">
        <div class="text-muted">Las recetas se mostrarán cuando el módulo esté disponible.</div>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white">
        <h5 class="mb-0">Últimos ajustes</h5>
    </div>
    <div class="card-body">
        <div class="text-muted">Los ajustes de inventario se mostrarán cuando el módulo esté disponible.</div>
    </div>
</div>
