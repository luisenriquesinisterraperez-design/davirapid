<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 */
$this->assign('title', $user->username);
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">
        <?= h($user->username) ?>
        <?php if ($user->isAdministrator()): ?>
            <span class="badge badge-soft-primary ms-2">Administrador</span>
        <?php elseif (!$user->active): ?>
            <span class="badge badge-soft-secondary ms-2">Inactivo</span>
        <?php elseif ($user->isLocked()): ?>
            <span class="badge badge-soft-warning ms-2"><i class="bi bi-lock-fill"></i> Bloqueado</span>
        <?php else: ?>
            <span class="badge badge-soft-success ms-2">Activo</span>
        <?php endif; ?>
    </h1>
    <div class="d-flex gap-2">
        <?php if (!$user->isAdministrator()): ?>
            <?php if ($user->isLocked()): ?>
                <?= $this->Form->postLink(
                    '<i class="bi bi-unlock"></i> Desbloquear',
                    ['action' => 'unlock', $user->id],
                    [
                        'escape' => false,
                        'class' => 'btn btn-warning',
                        'confirm' => sprintf('¿Desbloquear la cuenta de %s?', $user->username),
                    ]
                ) ?>
            <?php endif; ?>
        <?php endif; ?>
        <?= $this->Html->link(
            '<i class="bi bi-pencil"></i> Editar',
            ['action' => 'edit', $user->id],
            ['escape' => false, 'class' => 'btn btn-primary']
        ) ?>
        <?= $this->Html->link('Volver', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3 text-muted">Nombre</dt>
            <dd class="col-sm-9"><?= h($user->name) ?></dd>

            <dt class="col-sm-3 text-muted">Rol</dt>
            <dd class="col-sm-9"><?= h($user->role?->name ?? '—') ?></dd>

            <dt class="col-sm-3 text-muted">Repartidor</dt>
            <dd class="col-sm-9">
                <?php if (!empty($user->delivery)): ?>
                    <?= $this->Html->link(
                        h(trim(($user->delivery->last_name ?? '') . ', ' . ($user->delivery->first_name ?? ''))),
                        ['controller' => 'Deliveries', 'action' => 'view', $user->delivery->id]
                    ) ?>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </dd>

            <dt class="col-sm-3 text-muted">Última conexión</dt>
            <dd class="col-sm-9">
                <?= $user->last_login_at ? h($user->last_login_at->i18nFormat('dd/MM/yyyy HH:mm')) : '—' ?>
            </dd>

            <dt class="col-sm-3 text-muted">Intentos fallidos</dt>
            <dd class="col-sm-9"><?= (int)$user->failed_login_count ?> de <?= \App\Service\LoginThrottleService::MAX_ATTEMPTS ?></dd>

            <?php if ($user->isLocked()): ?>
                <dt class="col-sm-3 text-muted">Bloqueado hasta</dt>
                <dd class="col-sm-9 text-warning">
                    <?= h($user->locked_until->i18nFormat('dd/MM/yyyy HH:mm')) ?>
                </dd>
            <?php endif; ?>

            <dt class="col-sm-3 text-muted">Creado</dt>
            <dd class="col-sm-9"><?= $user->created ? h($user->created->i18nFormat('dd/MM/yyyy HH:mm')) : '—' ?></dd>
        </dl>
    </div>
</div>
