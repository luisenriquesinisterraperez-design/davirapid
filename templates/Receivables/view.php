<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Receivable $receivable
 * @var array<int, \App\Model\Entity\Receivable> $otherDebts
 * @var array<string, array<string, bool>> $userPermissions
 */
$this->assign('title', 'CxC #' . $receivable->id);
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">
        Cuenta por Cobrar #<?= (int)$receivable->id ?>
        <?php if ($receivable->isPaid()) : ?>
            <span class="badge badge-soft-success ms-2">Pagada</span>
        <?php else : ?>
            <span class="badge badge-soft-warning ms-2">Pendiente</span>
        <?php endif; ?>
    </h1>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left"></i> Volver',
        ['action' => 'index'],
        ['escape' => false, 'class' => 'btn btn-light'],
    ) ?>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-8">
        <div class="card mb-3">
            <div class="card-header"><strong>Cliente</strong></div>
            <div class="card-body">
                <?php if ($receivable->customer !== null) : ?>
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h5 class="mb-1"><?= h($receivable->customer->name) ?></h5>
                            <?php if (!empty($receivable->customer->phone)) : ?>
                                <div class="text-muted">
                                    <i class="bi bi-telephone"></i>
                                    <?= h($receivable->customer->phone) ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($receivable->customer->address)) : ?>
                                <div class="text-muted">
                                    <i class="bi bi-geo-alt"></i>
                                    <?= h($receivable->customer->address) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?= $this->Html->link(
                            'Ver cliente',
                            ['controller' => 'Customers', 'action' => 'view', $receivable->customer_id],
                            ['class' => 'btn btn-sm btn-light'],
                        ) ?>
                    </div>
                <?php else : ?>
                    <span class="text-muted">Cliente eliminado.</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <strong>
                    <?= $receivable->order_id !== null ? 'Pedido vinculado' : 'Deuda manual' ?>
                </strong>
            </div>
            <div class="card-body">
                <?php if ($receivable->order_id !== null && $receivable->order !== null) : ?>
                    <div class="d-flex justify-content-between">
                        <div>
                            <strong>Pedido #<?= (int)$receivable->order_id ?></strong>
                            <div class="text-muted">
                                Total $<?= h(number_format(
                                    (float)$receivable->order->total,
                                    2,
                                    ',',
                                    '.',
                                )) ?>
                            </div>
                        </div>
                        <?= $this->Html->link(
                            'Ver pedido',
                            ['controller' => 'Orders', 'action' => 'view', $receivable->order_id],
                            ['class' => 'btn btn-sm btn-light'],
                        ) ?>
                    </div>
                <?php elseif ($receivable->order_id !== null) : ?>
                    <span class="text-muted">
                        Pedido #<?= (int)$receivable->order_id ?> ya no existe (referencia perdida).
                    </span>
                <?php else : ?>
                    <p class="mb-0"><?= h($receivable->description) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Abonos</strong>
                <?php if ($receivable->isPending() && !empty($userPermissions['account_payments']['create'])) : ?>
                    <?= $this->Html->link(
                        '<i class="bi bi-plus-lg"></i> Registrar abono',
                        [
                            'controller' => 'AccountPayments', 'action' => 'add',
                            '?' => ['receivable_id' => $receivable->id],
                        ],
                        ['escape' => false, 'class' => 'btn btn-sm btn-primary'],
                    ) ?>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php $abonos = $receivable->account_payments ?? []; ?>
                <?php if (empty($abonos)) : ?>
                    <p class="text-muted mb-0">Aún no hay abonos registrados.</p>
                <?php else : ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($abonos as $abono) : ?>
                            <li class="d-flex align-items-center py-2 border-bottom">
                                <span class="me-2 text-muted">●</span>
                                <span class="me-3 text-muted small" style="min-width: 110px;">
                                    <?= $abono->created !== null
                                        ? h($abono->created->i18nFormat('dd/MM HH:mm'))
                                        : '—' ?>
                                </span>
                                <strong class="me-3" style="min-width: 110px;">
                                    <?= h($abono->getFormattedAmount()) ?>
                                </strong>
                                <span class="badge badge-soft-info me-3">
                                    <?= h($abono->getMethodLabel()) ?>
                                </span>
                                <span class="text-muted small me-auto">
                                    por <?= h($abono->creator?->name ?? '—') ?>
                                </span>
                                <?php if (!empty($userPermissions['account_payments']['delete'])) : ?>
                                    <?= $this->Form->postLink(
                                        '<i class="bi bi-x-lg"></i>',
                                        [
                                            'controller' => 'AccountPayments',
                                            'action' => 'delete', $abono->id,
                                        ],
                                        [
                                            'escape' => false,
                                            'class' => 'btn btn-icon btn-light text-danger',
                                            'title' => 'Eliminar abono',
                                            'confirm' => sprintf(
                                                '¿Eliminar este abono de %s? '
                                                . 'Se recalculará el saldo de la cuenta.',
                                                $abono->getFormattedAmount(),
                                            ),
                                        ],
                                    ) ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($otherDebts)) : ?>
            <div class="card mt-3">
                <div class="card-header">
                    <strong>Otras deudas pendientes del cliente</strong>
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($otherDebts as $other) : ?>
                        <?= $this->Html->link(
                            sprintf(
                                '#%d — %s — $%s',
                                (int)$other->id,
                                h($other->description),
                                number_format($other->getBalance(), 2, ',', '.'),
                            ),
                            ['action' => 'view', $other->id],
                            ['class' => 'list-group-item list-group-item-action', 'escape' => false],
                        ) ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-12 col-lg-4">
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Total adeudado</span>
                    <strong>$<?= h(number_format((float)$receivable->total_amount, 2, ',', '.')) ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">
                        Pagado (<?= $receivable->getProgressPercent() ?>%)
                    </span>
                    <strong>$<?= h(number_format((float)$receivable->paid_amount, 2, ',', '.')) ?></strong>
                </div>
                <hr>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-muted">Saldo</span>
                    <span class="fs-4 fw-bold <?= $receivable->getBalance() > 0 ? 'text-danger' : 'text-success' ?>">
                        $<?= h(number_format($receivable->getBalance(), 2, ',', '.')) ?>
                    </span>
                </div>
                <div class="progress mt-2" style="height: 6px;">
                    <div class="progress-bar bg-success"
                         style="width: <?= $receivable->getProgressPercent() ?>%;"></div>
                </div>
            </div>
            <div class="card-footer bg-white">
                <div class="d-grid gap-2">
                    <?php if ($receivable->isPending() && !empty($userPermissions['account_payments']['create'])) : ?>
                        <?= $this->Html->link(
                            'Registrar abono',
                            [
                                'controller' => 'AccountPayments', 'action' => 'add',
                                '?' => ['receivable_id' => $receivable->id],
                            ],
                            ['class' => 'btn btn-primary'],
                        ) ?>
                    <?php endif; ?>
                    <?php if ($receivable->isPending() && !empty($userPermissions['receivables']['edit'])) : ?>
                        <?= $this->Form->postLink(
                            'Marcar como pagada',
                            ['action' => 'markPaid', $receivable->id],
                            [
                                'class' => 'btn btn-secondary',
                                'confirm' => '¿Marcar esta cuenta como pagada manualmente? '
                                    . 'Esta acción no registra un abono.',
                            ],
                        ) ?>
                    <?php endif; ?>
                    <?php if (!$receivable->hasPayments() && !empty($userPermissions['receivables']['delete'])) : ?>
                        <?= $this->Form->postLink(
                            'Eliminar CxC',
                            ['action' => 'delete', $receivable->id],
                            [
                                'class' => 'btn btn-danger',
                                'confirm' => '¿Eliminar esta cuenta por cobrar? '
                                    . 'Esta acción no se puede deshacer.',
                            ],
                        ) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <small class="text-muted d-block">
                    Creada el
                    <?= $receivable->created !== null
                        ? h($receivable->created->i18nFormat('dd/MM/yyyy HH:mm'))
                        : '—' ?>
                </small>
                <?php if ($receivable->creator !== null) : ?>
                    <small class="text-muted d-block">
                        Por <?= h($receivable->creator->name) ?>
                    </small>
                <?php endif; ?>
                <?php if ($receivable->modified !== null) : ?>
                    <small class="text-muted d-block">
                        Última actualización
                        <?= h($receivable->modified->i18nFormat('dd/MM/yyyy HH:mm')) ?>
                    </small>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
