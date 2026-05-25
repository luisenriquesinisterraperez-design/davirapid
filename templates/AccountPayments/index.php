<?php
use App\Constants\AccountPaymentConstants;
use App\Constants\OrderConstants;

/**
 * @var \App\View\AppView $this
 * @var \Cake\Datasource\Paging\PaginatedInterface|iterable<\App\Model\Entity\AccountPayment> $payments
 * @var array{from:string,to:string,q:string,payment_method:string,customer_id:int} $filters
 * @var array{today_amount:float,month_amount:float,today_count:int} $kpis
 * @var array<int, string> $customers
 * @var array<string, string> $paymentMethods
 * @var array<string, array<string, bool>> $userPermissions
 */
$this->assign('title', 'Abonos');
$rows = is_array($payments) ? $payments : iterator_to_array($payments);
$hasActiveFilters = $filters['from'] !== ''
    || $filters['to'] !== ''
    || $filters['q'] !== ''
    || $filters['payment_method'] !== ''
    || $filters['customer_id'] > 0;
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Abonos</h1>
    <?php if (!empty($userPermissions['account_payments']['create'])) : ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg"></i> Registrar abono',
            ['action' => 'add'],
            ['escape' => false, 'class' => 'btn btn-primary'],
        ) ?>
    <?php endif; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-4">
        <div class="card stat-card border-start border-3 border-danger">
            <div class="card-body py-3">
                <small class="text-muted">Abonos hoy</small>
                <div class="fs-4 fw-semibold">
                    $<?= h(number_format($kpis['today_amount'], 0, ',', '.')) ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card stat-card">
            <div class="card-body py-3">
                <small class="text-muted">Total mes</small>
                <div class="fs-4 fw-semibold">
                    $<?= h(number_format($kpis['month_amount'], 0, ',', '.')) ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card stat-card">
            <div class="card-body py-3">
                <small class="text-muted"># transacciones hoy</small>
                <div class="fs-4 fw-semibold"><?= (int)$kpis['today_count'] ?></div>
            </div>
        </div>
    </div>
</div>

<form method="get" class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <label class="form-label mb-0" for="from">Desde</label>
            <input type="date" id="from" name="from" class="form-control form-control-sm"
                   style="max-width: 160px;" value="<?= h($filters['from']) ?>">
            <label class="form-label mb-0" for="to">Hasta</label>
            <input type="date" id="to" name="to" class="form-control form-control-sm"
                   style="max-width: 160px;" value="<?= h($filters['to']) ?>">

            <select name="customer_id" class="form-select form-select-sm" style="max-width: 220px;">
                <option value="0">Todos los clientes</option>
                <?php foreach ($customers as $id => $name) : ?>
                    <option value="<?= (int)$id ?>"
                        <?= $filters['customer_id'] === (int)$id ? 'selected' : '' ?>>
                        <?= h($name) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="payment_method" class="form-select form-select-sm"
                    style="max-width: 180px;">
                <option value="">Todos los métodos</option>
                <?php foreach (AccountPaymentConstants::PAYMENT_METHODS as $method) : ?>
                    <option value="<?= h($method) ?>"
                        <?= $filters['payment_method'] === $method ? 'selected' : '' ?>>
                        <?= h($paymentMethods[$method] ?? ucfirst($method)) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="text" name="q" class="form-control form-control-sm"
                   style="max-width: 220px;"
                   placeholder="Buscar cliente/descripción..."
                   value="<?= h($filters['q']) ?>">
            <button type="submit" class="btn btn-sm btn-secondary">Filtrar</button>
            <?php if ($hasActiveFilters) : ?>
                <?= $this->Html->link(
                    'Limpiar',
                    ['action' => 'index'],
                    ['class' => 'btn btn-sm btn-light'],
                ) ?>
            <?php endif; ?>
        </div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th style="width:70px;">#</th>
                    <th style="width:140px;"><?= $this->Paginator->sort('created', 'Fecha') ?></th>
                    <th>Cliente</th>
                    <th style="width:80px;">CxC</th>
                    <th>Descripción</th>
                    <th class="text-end" style="width:120px;">
                        <?= $this->Paginator->sort('amount', 'Monto') ?>
                    </th>
                    <th class="text-center" style="width:130px;">
                        <?= $this->Paginator->sort('payment_method', 'Método') ?>
                    </th>
                    <th style="width:140px;">Autor</th>
                    <?php if (!empty($userPermissions['account_payments']['delete'])) : ?>
                        <th class="text-end" style="width:80px;">Acciones</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $payment) : ?>
                    <?php
                    $rec = $payment->receivable ?? null;
                    $customer = $rec?->customer ?? null;
                    $isCash = $payment->payment_method === OrderConstants::PAYMENT_CASH;
                    $badgeClass = $isCash ? 'badge-soft-success' : 'badge-soft-info';
                    ?>
                    <tr>
                        <td><?= (int)$payment->id ?></td>
                        <td>
                            <?= $payment->created !== null
                                ? h($payment->created->i18nFormat('dd/MM HH:mm'))
                                : '—' ?>
                        </td>
                        <td>
                            <?php if ($customer !== null) : ?>
                                <?= $this->Html->link(
                                    h($customer->name),
                                    [
                                        'controller' => 'Customers', 'action' => 'view',
                                        $customer->id,
                                    ],
                                ) ?>
                                <?php if (!empty($customer->phone)) : ?>
                                    <small class="text-muted d-block">
                                        <?= h($customer->phone) ?>
                                    </small>
                                <?php endif; ?>
                            <?php else : ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($rec !== null) : ?>
                                <?= $this->Html->link(
                                    '#' . (int)$rec->id,
                                    ['controller' => 'Receivables', 'action' => 'view', $rec->id],
                                ) ?>
                            <?php else : ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= h($rec?->description ?? '—') ?>
                        </td>
                        <td class="text-end">
                            <strong>
                                $<?= h(number_format((float)$payment->amount, 2, ',', '.')) ?>
                            </strong>
                        </td>
                        <td class="text-center">
                            <span class="badge <?= h($badgeClass) ?>">
                                <?= h($payment->getMethodLabel()) ?>
                            </span>
                        </td>
                        <td>
                            <?= h($payment->creator?->name ?? '—') ?>
                        </td>
                        <?php if (!empty($userPermissions['account_payments']['delete'])) : ?>
                            <td class="text-end">
                                <?= $this->Form->postLink(
                                    '<i class="bi bi-trash"></i>',
                                    ['action' => 'delete', $payment->id],
                                    [
                                        'escape' => false,
                                        'class' => 'btn btn-icon btn-light text-danger',
                                        'title' => 'Eliminar abono',
                                        'confirm' => sprintf(
                                            '¿Eliminar este abono de %s? '
                                            . 'Se recalculará el saldo de la cuenta.',
                                            $payment->getFormattedAmount(),
                                        ),
                                    ],
                                ) ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($rows) === 0) : ?>
                    <tr>
                        <td colspan="<?= !empty($userPermissions['account_payments']['delete']) ? 9 : 8 ?>"
                            class="text-center text-muted py-5">
                            <?php if ($hasActiveFilters) : ?>
                                Sin resultados para los filtros aplicados.
                            <?php else : ?>
                                No hay abonos registrados.
                                <?php if (!empty($userPermissions['account_payments']['create'])) : ?>
                                    <?= $this->Html->link(
                                        'Registrar abono',
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
