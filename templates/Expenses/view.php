<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Expense $expense
 * @var array<string, array<string, bool>> $userPermissions
 */
$this->assign('title', 'Gasto #' . $expense->id);
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Gasto #<?= (int)$expense->id ?></h1>
    <div class="d-flex gap-2">
        <?php if (!empty($userPermissions['expenses']['edit'])) : ?>
            <?= $this->Html->link(
                '<i class="bi bi-pencil"></i> Editar',
                ['action' => 'edit', $expense->id],
                ['escape' => false, 'class' => 'btn btn-secondary'],
            ) ?>
        <?php endif; ?>
        <?php if (!empty($userPermissions['expenses']['delete'])) : ?>
            <?= $this->Form->postLink(
                '<i class="bi bi-trash"></i> Eliminar',
                ['action' => 'delete', $expense->id],
                [
                    'escape' => false,
                    'class' => 'btn btn-danger',
                    'confirm' => '¿Eliminar el gasto de ' . $expense->getFormattedAmount() . '?',
                ],
            ) ?>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-4 col-md-3 text-muted">Descripción</dt>
                    <dd class="col-8 col-md-9"><?= h($expense->description) ?></dd>

                    <dt class="col-4 col-md-3 text-muted">Monto</dt>
                    <dd class="col-8 col-md-9 fs-4 text-danger fw-semibold">
                        <?= h($expense->getFormattedAmount()) ?>
                    </dd>

                    <dt class="col-4 col-md-3 text-muted">Fecha</dt>
                    <dd class="col-8 col-md-9">
                        <?= h($expense->getFormattedDate()) ?>
                        <?php if ($expense->isFuture()) : ?>
                            <span class="badge badge-soft-info ms-1">Futuro</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-4 col-md-3 text-muted">Autor</dt>
                    <dd class="col-8 col-md-9">
                        <?= $expense->creator !== null
                            ? h($expense->creator->name ?? $expense->creator->username ?? '—')
                            : '<span class="text-muted">Usuario eliminado</span>' ?>
                    </dd>

                    <dt class="col-4 col-md-3 text-muted">Registrado</dt>
                    <dd class="col-8 col-md-9">
                        <?= $expense->created !== null
                            ? h($expense->created->i18nFormat('dd/MM/yyyy HH:mm'))
                            : '—' ?>
                    </dd>

                    <?php if (
                        $expense->modified !== null
                        && $expense->created !== null
                        && $expense->modified->getTimestamp() !== $expense->created->getTimestamp()
) : ?>
                        <dt class="col-4 col-md-3 text-muted">Última edición</dt>
                        <dd class="col-8 col-md-9">
                            <?= h($expense->modified->i18nFormat('dd/MM/yyyy HH:mm')) ?>
                        </dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>
</div>
