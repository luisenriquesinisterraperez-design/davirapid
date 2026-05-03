<?php
/**
 * @var \App\View\AppView $this
 * @var \Cake\ORM\ResultSet|\App\Model\Entity\Customer[] $customers
 * @var array{q:string,status:string,sort:string,direction:string} $filters
 */
$this->assign('title', 'Clientes');
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Clientes</h1>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg"></i> Nuevo cliente',
        ['action' => 'add'],
        ['escape' => false, 'class' => 'btn btn-primary']
    ) ?>
</div>

<form method="get" class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <i class="bi bi-search text-muted"></i>
            <input type="text" name="q" class="form-control form-control-sm" style="max-width: 320px;"
                   value="<?= h($filters['q']) ?>" placeholder="Buscar por nombre o teléfono">
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
                    <th><?= $this->Paginator->sort('name', 'Nombre') ?></th>
                    <th><?= $this->Paginator->sort('phone', 'Teléfono') ?></th>
                    <th>Dirección</th>
                    <th class="text-center" style="width:140px;">Activo</th>
                    <th class="text-end" style="width:160px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $customer): ?>
                    <tr<?= $customer->is_active ? '' : ' class="text-muted" style="opacity:.7;"' ?>>
                        <td><?= h($customer->name) ?></td>
                        <td class="font-monospace"><?= h($customer->phone) ?></td>
                        <td>
                            <?php if (!empty($customer->address)): ?>
                                <span title="<?= h($customer->address) ?>" class="d-inline-block text-truncate" style="max-width: 280px;">
                                    <?= h($customer->address) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?= $this->Form->postLink(
                                $customer->is_active ? 'Sí' : 'No',
                                ['action' => 'toggleActive', $customer->id],
                                [
                                    'class' => 'btn btn-sm ' . ($customer->is_active ? 'btn-success' : 'btn-light'),
                                    'title' => 'Cambiar estado',
                                ]
                            ) ?>
                        </td>
                        <td class="text-end">
                            <?= $this->Html->link(
                                '<i class="bi bi-eye"></i>',
                                ['action' => 'view', $customer->id],
                                ['escape' => false, 'class' => 'btn btn-sm btn-light', 'title' => 'Ver']
                            ) ?>
                            <?= $this->Html->link(
                                '<i class="bi bi-pencil"></i>',
                                ['action' => 'edit', $customer->id],
                                ['escape' => false, 'class' => 'btn btn-sm btn-light', 'title' => 'Editar']
                            ) ?>
                            <?= $this->Form->postLink(
                                '<i class="bi bi-trash"></i>',
                                ['action' => 'delete', $customer->id],
                                [
                                    'escape' => false,
                                    'class' => 'btn btn-sm btn-light text-danger',
                                    'title' => 'Eliminar',
                                    'confirm' => '¿Eliminar el cliente "' . h($customer->name) . '"?',
                                ]
                            ) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($customers) === 0): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No hay clientes para mostrar.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->element('pagination') ?>
