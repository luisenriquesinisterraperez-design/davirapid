<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Delivery $delivery
 */
$this->assign('title', $delivery->full_name);
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">
        <?= h($delivery->full_name) ?>
        <?php if ($delivery->is_active): ?>
            <span class="badge badge-soft-success ms-2">Activo</span>
        <?php else: ?>
            <span class="badge badge-soft-secondary ms-2">Inactivo</span>
        <?php endif; ?>
    </h1>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-pencil"></i> Editar',
            ['action' => 'edit', $delivery->id],
            ['escape' => false, 'class' => 'btn btn-secondary']
        ) ?>
        <?= $this->Form->postLink(
            $delivery->is_active
                ? '<i class="bi bi-slash-circle"></i> Desactivar'
                : '<i class="bi bi-check-circle"></i> Activar',
            ['action' => 'toggleActive', $delivery->id],
            [
                'escape' => false,
                'class' => 'btn btn-light',
                'confirm' => $delivery->is_active
                    ? '¿Desactivar al repartidor "' . h($delivery->full_name) . '"?'
                    : '¿Activar al repartidor "' . h($delivery->full_name) . '"?',
            ]
        ) ?>
        <?= $this->Html->link('Volver', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header">Datos del repartidor</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted">Nombre</dt>
                    <dd class="col-sm-8"><?= h($delivery->first_name) ?></dd>

                    <dt class="col-sm-4 text-muted">Apellido</dt>
                    <dd class="col-sm-8"><?= h($delivery->last_name) ?></dd>

                    <dt class="col-sm-4 text-muted">Teléfono</dt>
                    <dd class="col-sm-8 font-monospace"><?= h($delivery->phone) ?></dd>

                    <dt class="col-sm-4 text-muted">Estado</dt>
                    <dd class="col-sm-8">
                        <?php if ($delivery->is_active): ?>
                            <span class="badge bg-success-subtle text-success-emphasis">Activo</span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary-emphasis">Inactivo</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-4 text-muted">Registrado</dt>
                    <dd class="col-sm-8"><?= $delivery->created ? h($delivery->created->i18nFormat('dd/MM/yyyy HH:mm')) : '—' ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-7">
        <div class="card mb-3">
            <div class="card-header">Cuenta de sistema</div>
            <div class="card-body">
                <?php if (!empty($delivery->user)): ?>
                    <dl class="row mb-0">
                        <dt class="col-sm-4 text-muted">Usuario</dt>
                        <dd class="col-sm-8 font-monospace"><?= h($delivery->user->username) ?></dd>

                        <dt class="col-sm-4 text-muted">Nombre</dt>
                        <dd class="col-sm-8"><?= h($delivery->user->name) ?></dd>

                        <dt class="col-sm-4 text-muted">Rol</dt>
                        <dd class="col-sm-8"><?= h($delivery->user->role?->name ?? '—') ?></dd>
                    </dl>
                    <div class="mt-3">
                        <?= $this->Html->link(
                            '<i class="bi bi-pencil"></i> Editar usuario',
                            ['controller' => 'Users', 'action' => 'edit', $delivery->user->id],
                            ['escape' => false, 'class' => 'btn btn-sm btn-light']
                        ) ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-2">Sin cuenta de sistema asignada.</p>
                    <?= $this->Html->link(
                        '<i class="bi bi-person-plus"></i> Crear usuario',
                        ['controller' => 'Users', 'action' => 'add'],
                        ['escape' => false, 'class' => 'btn btn-sm btn-light']
                    ) ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="card">
            <div class="card-header">Pedidos asignados</div>
            <div class="card-body text-muted">
                Disponible cuando se habilite el módulo de Pedidos.
            </div>
        </div>
    </div>
</div>
