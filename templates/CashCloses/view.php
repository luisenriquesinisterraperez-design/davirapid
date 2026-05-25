<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\DailyClosing $closing
 * @var array<string, array<string, bool>> $userPermissions
 */
$this->assign('title', 'Cierre ' . $closing->getFormattedDate());

$diffClass = $closing->isBalanced()
    ? 'text-success'
    : ($closing->isSurplus() ? 'text-info' : 'text-danger');
$diffLabel = $closing->isBalanced()
    ? 'Cuadre exacto'
    : ($closing->isSurplus() ? 'Sobrante' : 'Faltante');
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Cierre del <?= h($closing->getFormattedDate()) ?></h1>
    <div class="d-flex gap-2">
        <?php if (!empty($userPermissions['cash_closes']['edit'])) : ?>
            <?= $this->Html->link(
                '<i class="bi bi-pencil"></i> Editar',
                ['action' => 'edit', $closing->id],
                ['escape' => false, 'class' => 'btn btn-secondary'],
            ) ?>
        <?php endif; ?>
        <?php if (!empty($userPermissions['cash_closes']['delete'])) : ?>
            <?= $this->Form->postLink(
                '<i class="bi bi-trash"></i> Eliminar',
                ['action' => 'delete', $closing->id],
                [
                    'escape' => false,
                    'class' => 'btn btn-danger',
                    'confirm' => '¿Eliminar este cierre? Acción irreversible.',
                ],
            ) ?>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <small class="text-muted">Esperado</small>
                <div class="fs-3 fw-semibold">
                    $<?= h(number_format((float)$closing->expected_amount, 2, ',', '.')) ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <small class="text-muted">Real (contado)</small>
                <div class="fs-3 fw-semibold">
                    $<?= h(number_format((float)$closing->actual_amount, 2, ',', '.')) ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <small class="text-muted"><?= h($diffLabel) ?></small>
                <div class="fs-3 fw-semibold <?= $diffClass ?>">
                    <?= h($closing->getFormattedDifference()) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-body">
        <h2 class="h6 text-uppercase text-muted mb-3">Desglose del día</h2>
        <dl class="row mb-0">
            <dt class="col-6 col-md-4 text-muted">Base inicial</dt>
            <dd class="col-6 col-md-8">
                $<?= h(number_format((float)$closing->initial_balance, 2, ',', '.')) ?>
            </dd>

            <dt class="col-6 col-md-4 text-muted">+ Ventas no crédito</dt>
            <dd class="col-6 col-md-8 text-success">
                $<?= h(number_format((float)$closing->sales_total, 2, ',', '.')) ?>
            </dd>

            <dt class="col-6 col-md-4 text-muted">+ Abonos recibidos</dt>
            <dd class="col-6 col-md-8 text-success">
                $<?= h(number_format((float)$closing->payments_total, 2, ',', '.')) ?>
            </dd>

            <dt class="col-6 col-md-4 text-muted">− Gastos</dt>
            <dd class="col-6 col-md-8 text-danger">
                $<?= h(number_format((float)$closing->expenses_total, 2, ',', '.')) ?>
            </dd>

            <dt class="col-6 col-md-4 fw-semibold mt-3">Esperado neto</dt>
            <dd class="col-6 col-md-8 fw-semibold mt-3">
                $<?= h(number_format((float)$closing->expected_amount, 2, ',', '.')) ?>
            </dd>
        </dl>

        <?php if (!empty($closing->notes)) : ?>
            <hr>
            <h2 class="h6 text-uppercase text-muted mt-3">Observaciones</h2>
            <p class="mb-0"><?= nl2br(h($closing->notes)) ?></p>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-3">
    <div class="card-body">
        <small class="text-muted">
            Registrado por
            <?= $closing->creator !== null
                ? h($closing->creator->name ?? $closing->creator->username ?? '—')
                : 'usuario eliminado' ?>
            el <?= h($closing->created?->i18nFormat('dd/MM/yyyy HH:mm')) ?>.
            <?php if (
                $closing->modified !== null
                && $closing->created !== null
                && $closing->modified->getTimestamp() !== $closing->created->getTimestamp()
) : ?>
                Última edición: <?= h($closing->modified->i18nFormat('dd/MM/yyyy HH:mm')) ?>.
            <?php endif; ?>
        </small>
    </div>
</div>
