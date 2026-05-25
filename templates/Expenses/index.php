<?php
/**
 * @var \App\View\AppView $this
 * @var \Cake\Datasource\Paging\PaginatedInterface|iterable<\App\Model\Entity\Expense> $expenses
 * @var array{q:string,from:string,to:string,sort:string,direction:string} $filters
 * @var array{today:float,month:float,ytd:float} $kpis
 * @var array<string, array<string, bool>> $userPermissions
 */
$this->assign('title', 'Gastos');
$rows = is_array($expenses) ? $expenses : iterator_to_array($expenses);
$hasActiveFilters = $filters['q'] !== '' || $filters['from'] !== '' || $filters['to'] !== '';
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Gastos</h1>
    <?php if (!empty($userPermissions['expenses']['create'])) : ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg"></i> Nuevo gasto',
            ['action' => 'add'],
            ['escape' => false, 'class' => 'btn btn-primary'],
        ) ?>
    <?php endif; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-4">
        <div class="card stat-card border-start border-3 border-danger">
            <div class="card-body py-3">
                <small class="text-muted">Gastos hoy</small>
                <div class="fs-4 fw-semibold text-danger">
                    $<?= h(number_format($kpis['today'], 0, ',', '.')) ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card stat-card">
            <div class="card-body py-3">
                <small class="text-muted">Total mes</small>
                <div class="fs-4 fw-semibold">
                    $<?= h(number_format($kpis['month'], 0, ',', '.')) ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card stat-card">
            <div class="card-body py-3">
                <small class="text-muted">Acumulado año</small>
                <div class="fs-4 fw-semibold">
                    $<?= h(number_format($kpis['ytd'], 0, ',', '.')) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<form method="get" class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <input type="text" name="q" class="form-control form-control-sm"
                   style="max-width: 240px;" placeholder="Buscar descripción..."
                   value="<?= h($filters['q']) ?>">
            <label class="form-label mb-0 ms-2" for="from">Desde</label>
            <input type="date" id="from" name="from" class="form-control form-control-sm"
                   style="max-width: 160px;" value="<?= h($filters['from']) ?>">
            <label class="form-label mb-0" for="to">Hasta</label>
            <input type="date" id="to" name="to" class="form-control form-control-sm"
                   style="max-width: 160px;" value="<?= h($filters['to']) ?>">
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
                    <th style="width:130px;"><?= $this->Paginator->sort('expense_date', 'Fecha') ?></th>
                    <th>Descripción</th>
                    <th class="text-end" style="width:140px;"><?= $this->Paginator->sort('amount', 'Monto') ?></th>
                    <th style="width:180px;">Autor</th>
                    <th class="text-end" style="width:110px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $expense) : ?>
                    <tr>
                        <td>
                            <?= h($expense->getFormattedDate()) ?>
                            <?php if ($expense->isFuture()) : ?>
                                <span class="badge badge-soft-info ms-1" title="Fecha futura">Futuro</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($expense->description) ?></td>
                        <td class="text-end text-danger fw-semibold">
                            <?= h($expense->getFormattedAmount()) ?>
                        </td>
                        <td>
                            <?= $expense->creator !== null
                                ? h($expense->creator->name ?? $expense->creator->username ?? '—')
                                : '<span class="text-muted">Usuario eliminado</span>' ?>
                        </td>
                        <td class="text-end">
                            <?= $this->Html->link(
                                '<i class="bi bi-eye"></i>',
                                ['action' => 'view', $expense->id],
                                ['escape' => false, 'class' => 'btn btn-icon btn-light', 'title' => 'Ver'],
                            ) ?>
                            <?php if (!empty($userPermissions['expenses']['edit'])) : ?>
                                <?= $this->Html->link(
                                    '<i class="bi bi-pencil"></i>',
                                    ['action' => 'edit', $expense->id],
                                    ['escape' => false, 'class' => 'btn btn-icon btn-light', 'title' => 'Editar'],
                                ) ?>
                            <?php endif; ?>
                            <?php if (!empty($userPermissions['expenses']['delete'])) : ?>
                                <?= $this->Form->postLink(
                                    '<i class="bi bi-trash"></i>',
                                    ['action' => 'delete', $expense->id],
                                    [
                                        'escape' => false,
                                        'class' => 'btn btn-icon btn-light text-danger',
                                        'title' => 'Eliminar',
                                        'confirm' => '¿Eliminar el gasto de '
                                            . $expense->getFormattedAmount() . '? '
                                            . 'Esta acción puede afectar cierres ya emitidos.',
                                    ],
                                ) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($rows) === 0) : ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-5">
                            <?php if ($hasActiveFilters) : ?>
                                Sin gastos para los filtros aplicados.
                            <?php else : ?>
                                Aún no hay gastos registrados.
                                <?php if (!empty($userPermissions['expenses']['create'])) : ?>
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
