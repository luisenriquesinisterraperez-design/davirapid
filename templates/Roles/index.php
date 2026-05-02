<?php
/**
 * @var \App\View\AppView $this
 * @var \Cake\ORM\ResultSet|\App\Model\Entity\Role[] $roles
 * @var array<string, string> $moduleCatalog
 */
$this->assign('title', 'Roles');
$totalModules = count($moduleCatalog);
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Roles</h1>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg"></i> Nuevo rol',
        ['action' => 'add'],
        ['escape' => false, 'class' => 'btn btn-primary']
    ) ?>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Permisos</th>
                    <th>Usuarios</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roles as $role): ?>
                    <tr>
                        <td>
                            <?= $this->Html->link(h($role->name), ['action' => 'view', $role->id]) ?>
                            <?php if ($role->isAdministrator()): ?>
                                <span class="badge badge-soft-primary ms-2">
                                    <i class="bi bi-shield-fill-check"></i> Administrador
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $configured = 0;
                            foreach ($role->permissions ?? [] as $p) {
                                if ($p->can_view || $p->can_create || $p->can_edit || $p->can_delete) {
                                    $configured++;
                                }
                            }
                            $configured = $role->isAdministrator() ? $totalModules : $configured;
                            ?>
                            <span class="badge badge-soft-secondary">
                                <?= $configured ?> de <?= $totalModules ?> módulos
                            </span>
                        </td>
                        <td>
                            <span class="text-muted"><?= count($role->users ?? []) ?></span>
                        </td>
                        <td class="text-end">
                            <?= $this->Html->link(
                                '<i class="bi bi-eye"></i>',
                                ['action' => 'view', $role->id],
                                ['escape' => false, 'class' => 'btn btn-icon btn-light', 'title' => 'Ver']
                            ) ?>
                            <?php if ($role->isAdministrator()): ?>
                                <button class="btn btn-icon btn-light" disabled title="El rol Administrador no se edita">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-icon btn-light" disabled title="El rol Administrador no se elimina">
                                    <i class="bi bi-trash"></i>
                                </button>
                            <?php else: ?>
                                <?= $this->Html->link(
                                    '<i class="bi bi-pencil"></i>',
                                    ['action' => 'edit', $role->id],
                                    ['escape' => false, 'class' => 'btn btn-icon btn-light', 'title' => 'Editar']
                                ) ?>
                                <?= $this->Form->postLink(
                                    '<i class="bi bi-trash"></i>',
                                    ['action' => 'delete', $role->id],
                                    [
                                        'escape' => false,
                                        'class' => 'btn btn-icon btn-light text-danger',
                                        'title' => 'Eliminar',
                                        'confirm' => sprintf('¿Eliminar el rol "%s"?', $role->name),
                                    ]
                                ) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($roles->toArray()) === 0): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">No hay roles cargados.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->element('pagination') ?>
