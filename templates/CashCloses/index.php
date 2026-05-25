<?php
/**
 * @var \App\View\AppView $this
 * @var \Cake\Datasource\Paging\PaginatedInterface|iterable<\App\Model\Entity\DailyClosing> $closings
 * @var array{from:string,to:string} $filters
 * @var array<string, array<string, bool>> $userPermissions
 */
$this->assign('title', 'Cierre Diario');
$rows = is_array($closings) ? $closings : iterator_to_array($closings);
$hasFilters = $filters['from'] !== '' || $filters['to'] !== '';
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Cierre Diario de Caja</h1>
    <?php if (!empty($userPermissions['cash_closes']['create'])) : ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg"></i> Nuevo cierre',
            ['action' => 'add'],
            ['escape' => false, 'class' => 'btn btn-primary'],
        ) ?>
    <?php endif; ?>
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
            <button type="submit" class="btn btn-sm btn-secondary">Filtrar</button>
            <?php if ($hasFilters) : ?>
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
                    <th style="width:120px;"><?= $this->Paginator->sort('closing_date', 'Fecha') ?></th>
                    <th class="text-end" style="width:140px;">Base</th>
                    <th class="text-end" style="width:160px;">
                        <?= $this->Paginator->sort('expected_amount', 'Esperado') ?>
                    </th>
                    <th class="text-end" style="width:160px;">
                        <?= $this->Paginator->sort('actual_amount', 'Real') ?>
                    </th>
                    <th class="text-end" style="width:160px;">
                        <?= $this->Paginator->sort('difference', 'Diferencia') ?>
                    </th>
                    <th style="width:160px;">Autor</th>
                    <th class="text-end" style="width:90px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $closing) : ?>
                    <tr>
                        <td><?= h($closing->getFormattedDate()) ?></td>
                        <td class="text-end">
                            $<?= h(number_format((float)$closing->initial_balance, 0, ',', '.')) ?>
                        </td>
                        <td class="text-end">
                            $<?= h(number_format((float)$closing->expected_amount, 0, ',', '.')) ?>
                        </td>
                        <td class="text-end">
                            $<?= h(number_format((float)$closing->actual_amount, 0, ',', '.')) ?>
                        </td>
                        <td class="text-end">
                            <?php if ($closing->isBalanced()) : ?>
                                <span class="badge badge-soft-success">Cuadrado</span>
                            <?php elseif ($closing->isSurplus()) : ?>
                                <span class="text-info fw-semibold">
                                    <?= h($closing->getFormattedDifference()) ?>
                                </span>
                            <?php else : ?>
                                <span class="text-danger fw-semibold">
                                    <?= h($closing->getFormattedDifference()) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $closing->creator !== null
                                ? h($closing->creator->name ?? $closing->creator->username ?? '—')
                                : '<span class="text-muted">Usuario eliminado</span>' ?>
                        </td>
                        <td class="text-end">
                            <?= $this->Html->link(
                                '<i class="bi bi-eye"></i>',
                                ['action' => 'view', $closing->id],
                                ['escape' => false, 'class' => 'btn btn-icon btn-light', 'title' => 'Ver'],
                            ) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($rows) === 0) : ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <?php if ($hasFilters) : ?>
                                Sin cierres para los filtros aplicados.
                            <?php else : ?>
                                Aún no hay cierres diarios.
                                <?php if (!empty($userPermissions['cash_closes']['create'])) : ?>
                                    <?= $this->Html->link(
                                        'Hacer el primer cierre',
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
