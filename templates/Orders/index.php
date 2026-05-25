<?php
use App\Constants\OrderConstants;

/**
 * @var \App\View\AppView $this
 * @var \Cake\Datasource\Paging\PaginatedInterface|iterable<\App\Model\Entity\Order> $orders
 * @var array<string, mixed> $filters
 * @var array<string, int|string> $kpis
 * @var array<int, string> $deliveries
 * @var bool $isRepartidor
 * @var array<string, array<string, bool>> $userPermissions
 */
$this->assign('title', 'Pedidos');

$rows = is_array($orders) ? $orders : iterator_to_array($orders);
$hasActiveFilters = ($filters['q'] ?? '') !== ''
    || ($filters['status'] ?? 'visible') !== 'visible'
    || ($filters['type'] ?? 'all') !== 'all'
    || ($filters['payment_method'] ?? 'all') !== 'all'
    || (int)($filters['delivery_id'] ?? 0) > 0
    || ($filters['from'] ?? '') !== ''
    || ($filters['to'] ?? '') !== '';
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Pedidos</h1>
    <?php if (!empty($userPermissions['orders']['create']) && !$isRepartidor): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg"></i> Nuevo pedido',
            ['action' => 'add'],
            ['escape' => false, 'class' => 'btn btn-primary'],
        ) ?>
    <?php endif; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card stat-card">
            <div class="card-body py-3">
                <small class="text-muted">Pedidos hoy</small>
                <div class="fs-4 fw-semibold"><?= (int)($kpis['orders_today'] ?? 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card">
            <div class="card-body py-3">
                <small class="text-muted">Ventas hoy</small>
                <div class="fs-4 fw-semibold">
                    $<?= h(number_format((float)($kpis['sales_today'] ?? 0), 0, ',', '.')) ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card">
            <div class="card-body py-3">
                <small class="text-muted">En preparación</small>
                <div class="fs-4 fw-semibold"><?= (int)($kpis['preparing'] ?? 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card">
            <div class="card-body py-3">
                <small class="text-muted">En camino</small>
                <div class="fs-4 fw-semibold"><?= (int)($kpis['on_route'] ?? 0) ?></div>
            </div>
        </div>
    </div>
</div>

<form method="get" class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <input type="text" name="q" class="form-control form-control-sm"
                   placeholder="Buscar #ID, cliente o teléfono"
                   style="max-width: 280px;" value="<?= h((string)($filters['q'] ?? '')) ?>">

            <select name="status" class="form-select form-select-sm" style="max-width: 160px;">
                <option value="visible" <?= ($filters['status'] ?? '') === 'visible' ? 'selected' : '' ?>>Visibles</option>
                <option value="all" <?= ($filters['status'] ?? '') === 'all' ? 'selected' : '' ?>>Todos</option>
                <?php foreach (OrderConstants::STATUS_LABELS as $key => $label): ?>
                    <option value="<?= h($key) ?>" <?= ($filters['status'] ?? '') === $key ? 'selected' : '' ?>>
                        <?= h($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="type" class="form-select form-select-sm" style="max-width: 140px;">
                <option value="all" <?= ($filters['type'] ?? '') === 'all' ? 'selected' : '' ?>>Tipo: todos</option>
                <?php foreach (OrderConstants::TYPE_LABELS as $key => $label): ?>
                    <option value="<?= h($key) ?>" <?= ($filters['type'] ?? '') === $key ? 'selected' : '' ?>>
                        <?= h($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="payment_method" class="form-select form-select-sm" style="max-width: 170px;">
                <option value="all" <?= ($filters['payment_method'] ?? '') === 'all' ? 'selected' : '' ?>>Pago: todos</option>
                <?php foreach (OrderConstants::PAYMENT_LABELS as $key => $label): ?>
                    <option value="<?= h($key) ?>" <?= ($filters['payment_method'] ?? '') === $key ? 'selected' : '' ?>>
                        <?= h($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if (!$isRepartidor): ?>
                <select name="delivery_id" class="form-select form-select-sm" style="max-width: 200px;">
                    <option value="0">Repartidor: todos</option>
                    <?php foreach ($deliveries as $id => $name): ?>
                        <option value="<?= (int)$id ?>" <?= (int)($filters['delivery_id'] ?? 0) === (int)$id ? 'selected' : '' ?>>
                            <?= h($name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

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
                    <th style="width:70px;"><?= $this->Paginator->sort('id', '#') ?></th>
                    <th style="width:140px;"><?= $this->Paginator->sort('created', 'Fecha') ?></th>
                    <th>Cliente</th>
                    <th class="text-center" style="width:100px;">Tipo</th>
                    <th>Productos</th>
                    <th class="text-end" style="width:110px;"><?= $this->Paginator->sort('total', 'Total') ?></th>
                    <th style="width:130px;">Pago</th>
                    <?php if (!$isRepartidor): ?>
                        <th style="width:140px;">Repartidor</th>
                    <?php endif; ?>
                    <th class="text-center" style="width:130px;"><?= $this->Paginator->sort('status', 'Estado') ?></th>
                    <th class="text-end" style="width:140px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $o): ?>
                    <tr>
                        <td>
                            <?= $this->Html->link('#' . (int)$o->id, ['action' => 'view', $o->id], ['class' => 'font-monospace']) ?>
                        </td>
                        <td>
                            <?= $o->created !== null
                                ? h($o->created->i18nFormat('dd/MM HH:mm'))
                                : '—' ?>
                        </td>
                        <td>
                            <?= h($o->getCustomerName()) ?>
                            <?php if ($o->getCustomerPhone() !== ''): ?>
                                <small class="text-muted d-block"><?= h($o->getCustomerPhone()) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($o->isDomicilio()): ?>
                                <span class="badge badge-soft-primary">Domicilio</span>
                            <?php else: ?>
                                <span class="badge badge-soft-info">Local</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($o->getItemsSummary()) ?></td>
                        <td class="text-end">
                            $<?= h(number_format((float)$o->total, 0, ',', '.')) ?>
                        </td>
                        <td>
                            <?= h(OrderConstants::PAYMENT_LABELS[$o->payment_method] ?? $o->payment_method) ?>
                        </td>
                        <?php if (!$isRepartidor): ?>
                            <td>
                                <?= $o->delivery !== null
                                    ? h(trim(($o->delivery->first_name ?? '') . ' ' . ($o->delivery->last_name ?? '')))
                                    : '—' ?>
                            </td>
                        <?php endif; ?>
                        <td class="text-center">
                            <?= $this->element('order_status_badge', ['order' => $o]) ?>
                        </td>
                        <td class="text-end">
                            <?= $this->Html->link(
                                '<i class="bi bi-eye"></i>',
                                ['action' => 'view', $o->id],
                                ['escape' => false, 'class' => 'btn btn-icon btn-light', 'title' => 'Ver'],
                            ) ?>
                            <?= $this->Html->link(
                                '<i class="bi bi-printer"></i>',
                                ['action' => 'ticket', $o->id],
                                [
                                    'escape' => false,
                                    'class' => 'btn btn-icon btn-light',
                                    'title' => 'Imprimir ticket',
                                    'target' => '_blank',
                                ],
                            ) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (count($rows) === 0): ?>
                    <tr>
                        <td colspan="<?= $isRepartidor ? 9 : 10 ?>" class="text-center text-muted py-5">
                            <?php if ($hasActiveFilters): ?>
                                Sin pedidos para los filtros aplicados.
                            <?php else: ?>
                                No hay pedidos visibles.
                                <?php if (!empty($userPermissions['orders']['create']) && !$isRepartidor): ?>
                                    <?= $this->Html->link(
                                        '¿Querés crear el primero?',
                                        ['action' => 'add'],
                                        ['class' => 'ms-1'],
                                    ) ?>
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
