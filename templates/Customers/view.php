<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Customer $customer
 */
$this->assign('title', $customer->name);
?>
<div class="dr-page-header">
    <h1 class="dr-page-title"><?= h($customer->name) ?></h1>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-pencil"></i> Editar',
            ['action' => 'edit', $customer->id],
            ['escape' => false, 'class' => 'btn btn-secondary']
        ) ?>
        <?= $this->Form->postLink(
            '<i class="bi bi-trash"></i> Eliminar',
            ['action' => 'delete', $customer->id],
            [
                'escape' => false,
                'class' => 'btn btn-danger',
                'confirm' => '¿Eliminar el cliente "' . h($customer->name) . '"?',
            ]
        ) ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header">Datos del cliente</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted">Nombre</dt>
                    <dd class="col-sm-8"><?= h($customer->name) ?></dd>

                    <dt class="col-sm-4 text-muted">Teléfono</dt>
                    <dd class="col-sm-8 font-monospace"><?= h($customer->phone) ?></dd>

                    <dt class="col-sm-4 text-muted">Dirección</dt>
                    <dd class="col-sm-8">
                        <?= !empty($customer->address) ? h($customer->address) : '<span class="text-muted">—</span>' ?>
                    </dd>

                    <dt class="col-sm-4 text-muted">Estado</dt>
                    <dd class="col-sm-8">
                        <?php if ($customer->is_active): ?>
                            <span class="badge bg-success-subtle text-success-emphasis">Activo</span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary-emphasis">Inactivo</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-4 text-muted">Registrado</dt>
                    <dd class="col-sm-8"><?= $customer->created ? h($customer->created->i18nFormat('dd/MM/yyyy HH:mm')) : '—' ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-7">
        <div class="card mb-3">
            <div class="card-header">Pedidos del cliente</div>
            <div class="card-body text-muted">
                Disponible cuando se habilite el módulo de Pedidos.
            </div>
        </div>
        <div class="card">
            <div class="card-header">Cuenta por cobrar</div>
            <div class="card-body text-muted">
                Disponible cuando se habilite el módulo de Cuentas por Cobrar.
            </div>
        </div>
    </div>
</div>
