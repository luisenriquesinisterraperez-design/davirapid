<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\OrderLog $log
 */
$this->assign('title', 'Auditoría #' . (int)$log->id);
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Registro de auditoría #<?= (int)$log->id ?></h1>
</div>

<div class="card">
    <div class="card-body">
        <div class="mb-2 fs-3 text-muted">
            <i class="bi <?= h($log->getIcon()) ?>"></i>
            <?= h($log->getKindLabel()) ?>
        </div>
        <div class="mb-3"><?= h($log->description) ?></div>
        <div>
            <strong>Pedido:</strong>
            <?php if ($log->isOrphan()): ?>
                <span class="text-muted">#<?= (int)$log->order_id_snapshot ?> (eliminado)</span>
            <?php else: ?>
                <?= $this->Html->link(
                    '#' . (int)$log->order_id_snapshot,
                    ['controller' => 'Orders', 'action' => 'view', $log->order_id_snapshot],
                ) ?>
            <?php endif; ?>
        </div>
        <div><strong>Autor:</strong> <?= h($log->user_name_snapshot) ?></div>
        <div><strong>Fecha:</strong> <?= h($log->getFormattedDate()) ?></div>
    </div>
    <div class="card-footer bg-white">
        <?= $this->Html->link('Volver', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
    </div>
</div>
