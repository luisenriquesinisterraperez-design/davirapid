<?php
/**
 * @var \App\View\AppView $this
 * @var \Cake\ORM\ResultSet|\App\Model\Entity\Product[] $products
 * @var array{q:string,status:string,sort:string,direction:string} $filters
 */
$this->assign('title', 'Productos');
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Productos</h1>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg"></i> Nuevo producto',
        ['action' => 'add'],
        ['escape' => false, 'class' => 'btn btn-primary']
    ) ?>
</div>

<form method="get" class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <i class="bi bi-search text-muted"></i>
            <input type="text" name="q" class="form-control form-control-sm" style="max-width: 320px;"
                   value="<?= h($filters['q']) ?>" placeholder="Buscar por nombre o código">
            <select name="status" class="form-select form-select-sm" style="max-width: 180px;">
                <?php foreach (['all' => 'Todos', 'active' => 'Activos', 'inactive' => 'Inactivos'] as $val => $label): ?>
                    <option value="<?= h($val) ?>" <?= $filters['status'] === $val ? 'selected' : '' ?>>
                        <?= h($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-secondary">Filtrar</button>
            <?php if ($filters['q'] !== '' || $filters['status'] !== 'all'): ?>
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
                    <th style="width:80px;"></th>
                    <th><?= $this->Paginator->sort('code', 'Código') ?></th>
                    <th><?= $this->Paginator->sort('name', 'Nombre') ?></th>
                    <th class="text-end"><?= $this->Paginator->sort('price', 'Precio') ?></th>
                    <th class="text-center" style="width:140px;">Disponible</th>
                    <th class="text-end" style="width:140px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $product): ?>
                    <tr<?= $product->is_active ? '' : ' class="text-muted" style="opacity:.7;"' ?>>
                        <td>
                            <div class="border rounded overflow-hidden d-flex align-items-center justify-content-center"
                                 style="width:64px; height:64px; background:#f8f9fa;">
                                <img src="<?= h($product->getImageUrl()) ?>" alt="" style="max-width:100%; max-height:100%; object-fit:cover;">
                            </div>
                        </td>
                        <td><?= $product->code ? h($product->code) : '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <?= $this->Html->link(h($product->name), ['action' => 'edit', $product->id]) ?>
                            <?php if (!$product->is_active): ?>
                                <span class="badge badge-soft-secondary ms-1">Inactivo</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= h($product->getFormattedPrice()) ?></td>
                        <td class="text-center">
                            <?= $this->Form->postLink(
                                $product->is_active
                                    ? '<i class="bi bi-toggle-on text-success fs-4"></i>'
                                    : '<i class="bi bi-toggle-off text-muted fs-4"></i>',
                                ['action' => 'toggleActive', $product->id],
                                [
                                    'escape' => false,
                                    'class' => 'btn btn-sm btn-link p-0',
                                    'title' => $product->is_active ? 'Desactivar' : 'Activar',
                                ]
                            ) ?>
                        </td>
                        <td class="text-end">
                            <?= $this->Html->link(
                                '<i class="bi bi-pencil"></i>',
                                ['action' => 'edit', $product->id],
                                ['escape' => false, 'class' => 'btn btn-icon btn-light', 'title' => 'Editar']
                            ) ?>
                            <?= $this->Form->postLink(
                                '<i class="bi bi-trash"></i>',
                                ['action' => 'delete', $product->id],
                                [
                                    'escape' => false,
                                    'class' => 'btn btn-icon btn-light text-danger',
                                    'title' => 'Eliminar',
                                    'confirm' => sprintf('¿Eliminar el producto "%s"?', $product->name),
                                ]
                            ) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($products->toArray()) === 0): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">
                            <?php if ($filters['q'] !== '' || $filters['status'] !== 'all'): ?>
                                Sin resultados para los filtros aplicados.
                            <?php else: ?>
                                Aún no hay productos.
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
