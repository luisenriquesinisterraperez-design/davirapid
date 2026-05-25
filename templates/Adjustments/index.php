<?php
use App\Constants\InventoryAdjustmentConstants;

/**
 * @var \App\View\AppView $this
 * @var \Cake\Datasource\Paging\PaginatedInterface|iterable<\App\Model\Entity\InventoryAdjustment> $adjustments
 * @var array{ingredient_id:int,type:string,from:string,to:string,sort:string,direction:string} $filters
 * @var array<int, string> $ingredients
 * @var array<string, array<string, bool>> $userPermissions
 */
$this->assign('title', 'Ajustes de Inventario');

$hasActiveFilters = $filters['ingredient_id'] > 0
    || $filters['type'] !== 'all'
    || $filters['from'] !== ''
    || $filters['to'] !== '';

$rows = is_array($adjustments) ? $adjustments : iterator_to_array($adjustments);
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Ajustes de Inventario</h1>
    <?php if (!empty($userPermissions['adjustments']['create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg"></i> Nuevo ajuste',
            ['action' => 'add'],
            ['escape' => false, 'class' => 'btn btn-primary'],
        ) ?>
    <?php endif; ?>
</div>

<form method="get" class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <select name="ingredient_id" class="form-select form-select-sm" style="max-width: 240px;">
                <option value="0">Todos los ingredientes</option>
                <?php foreach ($ingredients as $id => $label): ?>
                    <option value="<?= (int)$id ?>" <?= $filters['ingredient_id'] === (int)$id ? 'selected' : '' ?>>
                        <?= h($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="type" class="form-select form-select-sm" style="max-width: 140px;">
                <option value="all" <?= $filters['type'] === 'all' ? 'selected' : '' ?>>Todos</option>
                <option value="<?= h(InventoryAdjustmentConstants::TYPE_ENTRY) ?>"
                    <?= $filters['type'] === InventoryAdjustmentConstants::TYPE_ENTRY ? 'selected' : '' ?>>
                    Entradas
                </option>
                <option value="<?= h(InventoryAdjustmentConstants::TYPE_BAJA) ?>"
                    <?= $filters['type'] === InventoryAdjustmentConstants::TYPE_BAJA ? 'selected' : '' ?>>
                    Bajas
                </option>
            </select>
            <label class="form-label mb-0 ms-2" for="from">Desde</label>
            <input type="date" id="from" name="from" class="form-control form-control-sm"
                   style="max-width: 160px;" value="<?= h($filters['from']) ?>">
            <label class="form-label mb-0" for="to">Hasta</label>
            <input type="date" id="to" name="to" class="form-control form-control-sm"
                   style="max-width: 160px;" value="<?= h($filters['to']) ?>">
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
                    <th style="width:160px;"><?= $this->Paginator->sort('created', 'Fecha') ?></th>
                    <th>Ingrediente</th>
                    <th class="text-center" style="width:110px;"><?= $this->Paginator->sort('type', 'Tipo') ?></th>
                    <th class="text-end" style="width:140px;">Cantidad</th>
                    <th style="width:220px;">Motivo</th>
                    <th style="width:160px;">Autor</th>
                    <th class="text-end" style="width:80px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $adj): ?>
                    <tr>
                        <td>
                            <?= $adj->created !== null
                                ? h($adj->created->i18nFormat('dd/MM/yyyy HH:mm'))
                                : '—' ?>
                        </td>
                        <td>
                            <?php if ($adj->ingredient !== null): ?>
                                <?= $this->Html->link(
                                    h($adj->ingredient->name),
                                    ['controller' => 'Ingredients', 'action' => 'view', $adj->ingredient_id],
                                ) ?>
                            <?php else: ?>
                                <span class="text-muted">Ingrediente eliminado</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($adj->isEntry()): ?>
                                <span class="badge badge-soft-success">Entrada</span>
                            <?php else: ?>
                                <span class="badge badge-soft-warning">Baja</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($adj->isEntry()): ?>
                                <span class="text-success fw-semibold"><?= h($adj->getFormattedQuantity()) ?></span>
                            <?php else: ?>
                                <span class="text-warning fw-semibold"><?= h($adj->getFormattedQuantity()) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= h($adj->reason) ?>
                            <?php if (!empty($adj->notes)): ?>
                                <i class="bi bi-chat-square-text text-muted ms-1"
                                   title="<?= h($adj->notes) ?>"></i>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($adj->user !== null): ?>
                                <?= h($adj->user->name) ?>
                            <?php else: ?>
                                <span class="text-muted">Usuario eliminado</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if (!empty($userPermissions['adjustments']['delete'])): ?>
                                <?= $this->Form->postLink(
                                    '<i class="bi bi-trash"></i>',
                                    ['action' => 'delete', $adj->id],
                                    [
                                        'escape' => false,
                                        'class' => 'btn btn-icon btn-light text-danger',
                                        'title' => 'Eliminar',
                                        'confirm' => sprintf(
                                            '¿Eliminar este ajuste? Se revertirá el movimiento sobre %s.',
                                            $adj->ingredient->name ?? 'el ingrediente',
                                        ),
                                    ],
                                ) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($rows) === 0): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <?php if ($hasActiveFilters): ?>
                                Sin ajustes para los filtros aplicados.
                            <?php else: ?>
                                Aún no hay ajustes registrados.
                                <?php if (!empty($userPermissions['adjustments']['create'])): ?>
                                    <?= $this->Html->link(
                                        'Registrar el primero',
                                        ['action' => 'add'],
                                        ['class' => 'ms-1'],
                                    ) ?>.
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->element('pagination') ?>
