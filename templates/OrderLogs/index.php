<?php
use App\Constants\OrderLogConstants;

/**
 * @var \App\View\AppView $this
 * @var \Cake\Datasource\Paging\PaginatedInterface|iterable<\App\Model\Entity\OrderLog> $logs
 * @var array<string, mixed> $filters
 * @var array<int, string> $usersList
 * @var array<string, string> $kinds
 */
$this->assign('title', 'Auditoría');
$rows = is_array($logs) ? $logs : iterator_to_array($logs);
$hasActiveFilters = (int)($filters['order_id'] ?? 0) > 0
    || (int)($filters['user_id'] ?? 0) > 0
    || ($filters['kind'] ?? 'all') !== 'all'
    || ($filters['from'] ?? '') !== ''
    || ($filters['to'] ?? '') !== '';
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Auditoría</h1>
</div>

<form method="get" class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <input type="number" name="order_id" class="form-control form-control-sm"
                   placeholder="# Pedido" style="max-width: 120px;"
                   value="<?= (int)($filters['order_id'] ?? 0) ?: '' ?>">

            <select name="user_id" class="form-select form-select-sm" style="max-width: 200px;">
                <option value="0">Autor: todos</option>
                <?php foreach ($usersList as $id => $name): ?>
                    <option value="<?= (int)$id ?>" <?= (int)($filters['user_id'] ?? 0) === (int)$id ? 'selected' : '' ?>>
                        <?= h($name) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="kind" class="form-select form-select-sm" style="max-width: 200px;">
                <option value="all" <?= ($filters['kind'] ?? 'all') === 'all' ? 'selected' : '' ?>>Tipo: todos</option>
                <?php foreach ($kinds as $key => $label): ?>
                    <option value="<?= h($key) ?>" <?= ($filters['kind'] ?? '') === $key ? 'selected' : '' ?>>
                        <?= h($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="date" name="from" class="form-control form-control-sm"
                   style="max-width: 150px;" value="<?= h((string)($filters['from'] ?? '')) ?>">
            <input type="date" name="to" class="form-control form-control-sm"
                   style="max-width: 150px;" value="<?= h((string)($filters['to'] ?? '')) ?>">

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
                    <th style="width:160px;">Fecha</th>
                    <th style="width:100px;">Pedido</th>
                    <th style="width:160px;">Autor</th>
                    <th style="width:180px;">Tipo</th>
                    <th>Descripción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $log): ?>
                    <tr>
                        <td><?= h($log->getFormattedDate()) ?></td>
                        <td>
                            <?php if ($log->isOrphan()): ?>
                                <span class="text-muted">#<?= (int)$log->order_id_snapshot ?> (eliminado)</span>
                            <?php else: ?>
                                <?= $this->Html->link(
                                    '#' . (int)$log->order_id_snapshot,
                                    ['controller' => 'Orders', 'action' => 'view', $log->order_id_snapshot],
                                    ['class' => 'font-monospace'],
                                ) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= h($log->user_name_snapshot) ?></td>
                        <td>
                            <i class="bi <?= h($log->getIcon()) ?>"></i>
                            <span class="ms-1"><?= h($log->getKindLabel()) ?></span>
                        </td>
                        <td><?= h($log->description) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($rows) === 0): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-5">
                            <?php if ($hasActiveFilters): ?>
                                Sin registros para los filtros aplicados.
                            <?php else: ?>
                                Aún no hay actividad registrada.
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->element('pagination') ?>
