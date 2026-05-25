<?php
/**
 * @var \App\View\AppView $this
 * @var bool $isRepartidorView
 * @var array<string, mixed> $data
 * @var array{from:string,to:string} $filters
 */

use App\Constants\OrderConstants;

$this->assign('title', 'Dashboard');

function dr_money(float $value): string
{
    return '$' . number_format($value, 0, ',', '.');
}
?>

<div class="dr-page-header">
    <h1 class="dr-page-title">
        <?= $isRepartidorView ? 'Mi resumen' : 'Dashboard' ?>
    </h1>
    <form method="get" class="d-flex gap-2 align-items-center">
        <label class="form-label mb-0" for="from">Desde</label>
        <input type="date" id="from" name="from" class="form-control form-control-sm"
               style="max-width: 160px;" value="<?= h($filters['from']) ?>">
        <label class="form-label mb-0" for="to">Hasta</label>
        <input type="date" id="to" name="to" class="form-control form-control-sm"
               style="max-width: 160px;" value="<?= h($filters['to']) ?>">
        <button type="submit" class="btn btn-sm btn-primary">Aplicar</button>
    </form>
</div>

<?php if ($isRepartidorView) : ?>
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-4">
            <div class="card stat-card">
                <div class="card-body">
                    <small class="text-muted">Entregas en el período</small>
                    <div class="fs-2 fw-semibold"><?= (int)$data['delivered'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card stat-card">
                <div class="card-body">
                    <small class="text-muted">Ganancia del período</small>
                    <div class="fs-2 fw-semibold text-success">
                        <?= h(dr_money((float)$data['earnings'])) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card stat-card border-start border-3 border-warning">
                <div class="card-body">
                    <small class="text-muted">Pendientes hoy</small>
                    <div class="fs-2 fw-semibold"><?= (int)$data['pending_today'] ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="d-flex">
        <?= $this->Html->link(
            '<i class="bi bi-bag"></i> Ver mis entregas',
            ['controller' => 'Orders', 'action' => 'index'],
            ['escape' => false, 'class' => 'btn btn-primary'],
        ) ?>
    </div>
<?php else : ?>
    <?php $today = $data['today']; ?>
    <h2 class="h6 text-uppercase text-muted mt-2 mb-2">Hoy</h2>
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-3">
            <div class="card stat-card">
                <div class="card-body">
                    <small class="text-muted">Pedidos</small>
                    <div class="fs-3 fw-semibold"><?= (int)$today['orders'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card stat-card">
                <div class="card-body">
                    <small class="text-muted">Ventas no crédito</small>
                    <div class="fs-3 fw-semibold text-success">
                        <?= h(dr_money((float)$today['revenue'])) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card stat-card border-start border-3 border-warning">
                <div class="card-body">
                    <small class="text-muted">Preparando</small>
                    <div class="fs-3 fw-semibold"><?= (int)$today['in_prep'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card stat-card border-start border-3 border-info">
                <div class="card-body">
                    <small class="text-muted">En camino</small>
                    <div class="fs-3 fw-semibold"><?= (int)$today['on_route'] ?></div>
                </div>
            </div>
        </div>
    </div>

    <?php $p = $data['period']; ?>
    <h2 class="h6 text-uppercase text-muted mt-3 mb-2">
        Período: <?= h($data['from']) ?> a <?= h($data['to']) ?>
    </h2>
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-3">
            <div class="card stat-card">
                <div class="card-body">
                    <small class="text-muted">Ingresos reales</small>
                    <div class="fs-3 fw-semibold text-success">
                        <?= h(dr_money((float)$p['income'])) ?>
                    </div>
                    <small class="text-muted d-block">Ventas no crédito + abonos</small>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card stat-card">
                <div class="card-body">
                    <small class="text-muted">Costo insumos</small>
                    <div class="fs-3 fw-semibold">
                        <?= h(dr_money((float)$p['cogs'])) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card stat-card">
                <div class="card-body">
                    <small class="text-muted">Gastos</small>
                    <div class="fs-3 fw-semibold text-danger">
                        <?= h(dr_money((float)$p['expenses'])) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card stat-card">
                <div class="card-body">
                    <small class="text-muted">Utilidad neta</small>
                    <div class="fs-3 fw-semibold <?= $p['profit'] >= 0 ? 'text-success' : 'text-danger' ?>">
                        <?= h(dr_money((float)$p['profit'])) ?>
                    </div>
                    <small class="text-muted d-block">Ingresos − insumos − envíos − gastos</small>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-7">
            <div class="card">
                <div class="card-body">
                    <h2 class="h6 text-uppercase text-muted mb-3">Ventas por día</h2>
                    <?php if (count($data['sales_by_day']) === 0) : ?>
                        <p class="text-muted mb-0">Sin datos en el período.</p>
                    <?php else : ?>
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($data['sales_by_day'] as $row) : ?>
                                    <tr>
                                        <td><?= h($row['day']) ?></td>
                                        <td class="text-end text-success">
                                            <?= h(dr_money((float)$row['total'])) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-5">
            <div class="card">
                <div class="card-body">
                    <h2 class="h6 text-uppercase text-muted mb-3">Ingresos por método</h2>
                    <table class="table table-sm align-middle mb-0">
                        <tbody>
                            <?php foreach ($data['by_method'] as $method => $total) : ?>
                                <?php
                                $label = OrderConstants::PAYMENT_LABELS[$method] ?? ucfirst((string)$method);
                                ?>
                                <tr>
                                    <td><?= h($label) ?></td>
                                    <td class="text-end">
                                        <?= h(dr_money((float)$total)) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-body">
                    <h2 class="h6 text-uppercase text-muted mb-3">Top 5 productos</h2>
                    <?php if (count($data['top_products']) === 0) : ?>
                        <p class="text-muted mb-0">Sin ventas en el período.</p>
                    <?php else : ?>
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th class="text-end">Cant.</th>
                                    <th class="text-end">Ingresos</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($data['top_products'] as $row) : ?>
                                    <tr>
                                        <td><?= h($row['name']) ?></td>
                                        <td class="text-end"><?= (int)$row['qty'] ?></td>
                                        <td class="text-end"><?= h(dr_money((float)$row['revenue'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-body">
                    <h2 class="h6 text-uppercase text-muted mb-3">Ranking de repartidores</h2>
                    <?php if (count($data['delivery_ranking']) === 0) : ?>
                        <p class="text-muted mb-0">Sin entregas en el período.</p>
                    <?php else : ?>
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Repartidor</th>
                                    <th class="text-end">Entregas</th>
                                    <th class="text-end">Ganancia</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($data['delivery_ranking'] as $row) : ?>
                                    <tr>
                                        <td><?= h($row['name']) ?></td>
                                        <td class="text-end"><?= (int)$row['deliveries'] ?></td>
                                        <td class="text-end"><?= h(dr_money((float)$row['earnings'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-body">
                    <h2 class="h6 text-uppercase text-muted mb-3">Local vs Domicilio</h2>
                    <table class="table table-sm align-middle mb-0">
                        <tbody>
                            <tr>
                                <td>Local</td>
                                <td class="text-end"><?= (int)$data['local_vs_domicilio']['local'] ?> pedidos</td>
                                <td class="text-end"><?= h(dr_money(
                                    (float)$data['local_vs_domicilio']['local_revenue'],
                                )) ?></td>
                            </tr>
                            <tr>
                                <td>Domicilio</td>
                                <td class="text-end"><?= (int)$data['local_vs_domicilio']['domicilio'] ?> pedidos</td>
                                <td class="text-end"><?= h(dr_money(
                                    (float)$data['local_vs_domicilio']['domicilio_revenue'],
                                )) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card border-start border-3 border-warning">
                <div class="card-body">
                    <h2 class="h6 text-uppercase text-muted mb-3">Alertas de stock bajo</h2>
                    <?php if (count($data['low_stock']) === 0) : ?>
                        <p class="text-muted mb-0">Sin alertas. Todo el stock está saludable.</p>
                    <?php else : ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($data['low_stock'] as $ing) : ?>
                                <li class="d-flex justify-content-between border-bottom py-1">
                                    <?= $this->Html->link(
                                        h($ing['name']),
                                        ['controller' => 'Ingredients', 'action' => 'view', $ing['id']],
                                    ) ?>
                                    <span class="text-danger fw-semibold">
                                        <?= h(number_format((float)$ing['stock'], 2, ',', '.')) ?>
                                        <?= h($ing['unit']) ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
