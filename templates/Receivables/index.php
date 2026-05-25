<?php
use App\Constants\ReceivableConstants;

/**
 * @var \App\View\AppView $this
 * @var \Cake\Datasource\Paging\PaginatedInterface|iterable<\App\Model\Entity\Receivable> $receivables
 * @var array{status:string,customer_id:int,from:string,to:string,q:string} $filters
 * @var array{total_pending:float,paid_this_month:float,customers_with_debt:int} $kpis
 * @var array<int, string> $customers
 * @var array<string, string> $statusLabels
 * @var array<string, array<string, bool>> $userPermissions
 */
$this->assign('title', 'Cuentas por Cobrar');
$rows = is_array($receivables) ? $receivables : iterator_to_array($receivables);
$hasActiveFilters = $filters['status'] !== ReceivableConstants::STATUS_PENDIENTE
    || $filters['customer_id'] > 0
    || $filters['from'] !== ''
    || $filters['to'] !== ''
    || $filters['q'] !== '';
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Cuentas por Cobrar</h1>
    <?php if (!empty($userPermissions['receivables']['create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg"></i> Nueva cuenta',
            ['action' => 'add'],
            ['escape' => false, 'class' => 'btn btn-primary'],
        ) ?>
    <?php endif; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-4">
        <div class="card stat-card border-start border-3 border-danger">
            <div class="card-body py-3">
                <small class="text-muted">Total pendiente</small>
                <div class="fs-4 fw-semibold">
                    $<?= h(number_format($kpis['total_pending'], 0, ',', '.')) ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card stat-card">
            <div class="card-body py-3">
                <small class="text-muted">Pagado este mes</small>
                <div class="fs-4 fw-semibold">
                    $<?= h(number_format($kpis['paid_this_month'], 0, ',', '.')) ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card stat-card">
            <div class="card-body py-3">
                <small class="text-muted">Clientes con deuda</small>
                <div class="fs-4 fw-semibold"><?= (int)$kpis['customers_with_debt'] ?></div>
            </div>
        </div>
    </div>
</div>

<form method="get" class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <select name="status" class="form-select form-select-sm" style="max-width: 160px;">
                <option value="<?= h(ReceivableConstants::STATUS_PENDIENTE) ?>"
                    <?= $filters['status'] === ReceivableConstants::STATUS_PENDIENTE ? 'selected' : '' ?>>
                    Pendientes
                </option>
                <option value="<?= h(ReceivableConstants::STATUS_PAGADO) ?>"
                    <?= $filters['status'] === ReceivableConstants::STATUS_PAGADO ? 'selected' : '' ?>>
                    Pagadas
                </option>
                <option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>Todas</option>
            </select>
            <select name="customer_id" class="form-select form-select-sm" style="max-width: 240px;">
                <option value="0">Todos los clientes</option>
                <?php foreach ($customers as $id => $name): ?>
                    <option value="<?= (int)$id ?>" <?= $filters['customer_id'] === (int)$id ? 'selected' : '' ?>>
                        <?= h($name) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label class="form-label mb-0 ms-2" for="from">Desde</label>
            <input type="date" id="from" name="from" class="form-control form-control-sm"
                   style="max-width: 160px;" value="<?= h($filters['from']) ?>">
            <label class="form-label mb-0" for="to">Hasta</label>
            <input type="date" id="to" name="to" class="form-control form-control-sm"
                   style="max-width: 160px;" value="<?= h($filters['to']) ?>">
            <input type="text" name="q" class="form-control form-control-sm"
                   style="max-width: 200px;" placeholder="Buscar..."
                   value="<?= h($filters['q']) ?>">
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
                    <th style="width:60px;">#</th>
                    <th style="width:110px;"><?= $this->Paginator->sort('created', 'Fecha') ?></th>
                    <th>Cliente</th>
                    <th>Descripción</th>
                    <th class="text-end" style="width:120px;"><?= $this->Paginator->sort('total_amount', 'Total') ?></th>
                    <th class="text-end" style="width:140px;">Abonado</th>
                    <th class="text-end" style="width:120px;">Saldo</th>
                    <th class="text-center" style="width:110px;"><?= $this->Paginator->sort('status', 'Estado') ?></th>
                    <th class="text-end" style="width:120px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $rec): ?>
                    <tr>
                        <td>
                            <?= (int)$rec->id ?>
                            <?php if ($rec->order_id !== null): ?>
                                <i class="bi bi-receipt text-muted ms-1"
                                   title="Pedido #<?= (int)$rec->order_id ?>"></i>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $rec->created !== null
                                ? h($rec->created->i18nFormat('dd/MM/yyyy'))
                                : '—' ?>
                        </td>
                        <td>
                            <?php if ($rec->customer !== null): ?>
                                <?= $this->Html->link(
                                    h($rec->customer->name),
                                    ['controller' => 'Customers', 'action' => 'view', $rec->customer_id],
                                ) ?>
                                <?php if (!empty($rec->customer->phone)): ?>
                                    <small class="text-muted d-block"><?= h($rec->customer->phone) ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">Cliente eliminado</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($rec->description) ?></td>
                        <td class="text-end">
                            $<?= h(number_format((float)$rec->total_amount, 0, ',', '.')) ?>
                        </td>
                        <td class="text-end">
                            $<?= h(number_format((float)$rec->paid_amount, 0, ',', '.')) ?>
                            <?php if ($rec->getProgressPercent() > 0 && $rec->getProgressPercent() < 100): ?>
                                <div class="progress mt-1" style="height: 4px;">
                                    <div class="progress-bar bg-success"
                                         style="width: <?= $rec->getProgressPercent() ?>%;"></div>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <strong class="<?= $rec->getBalance() > 0 ? 'text-danger' : 'text-success' ?>">
                                $<?= h(number_format($rec->getBalance(), 0, ',', '.')) ?>
                            </strong>
                        </td>
                        <td class="text-center">
                            <?php if ($rec->isPaid()): ?>
                                <span class="badge badge-soft-success">Pagado</span>
                            <?php else: ?>
                                <span class="badge badge-soft-warning">Pendiente</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?= $this->Html->link(
                                '<i class="bi bi-eye"></i>',
                                ['action' => 'view', $rec->id],
                                ['escape' => false, 'class' => 'btn btn-icon btn-light', 'title' => 'Ver'],
                            ) ?>
                            <?php if (
                                $rec->isPending()
                                && !empty($userPermissions['receivables']['edit'])
                            ): ?>
                                <?= $this->Form->postLink(
                                    '<i class="bi bi-check2-circle"></i>',
                                    ['action' => 'markPaid', $rec->id],
                                    [
                                        'escape' => false,
                                        'class' => 'btn btn-icon btn-light text-success',
                                        'title' => 'Marcar como pagada',
                                        'confirm' => '¿Marcar como pagada manualmente? '
                                            . 'Esta acción no registra un abono real.',
                                    ],
                                ) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($rows) === 0): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-5">
                            <?php if ($hasActiveFilters): ?>
                                Sin resultados para los filtros aplicados.
                            <?php else: ?>
                                No hay cuentas pendientes.
                                <?php if (!empty($userPermissions['receivables']['create'])): ?>
                                    <?= $this->Html->link(
                                        'Registrar deuda',
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
