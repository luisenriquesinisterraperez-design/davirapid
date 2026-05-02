<?php
/**
 * @var \App\View\AppView $this
 * @var \Cake\ORM\ResultSet|\App\Model\Entity\User[] $users
 * @var string $q
 */
$this->assign('title', 'Usuarios');
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Usuarios</h1>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg"></i> Nuevo usuario',
        ['action' => 'add'],
        ['escape' => false, 'class' => 'btn btn-primary']
    ) ?>
</div>

<form method="get" class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex gap-2 align-items-center">
            <i class="bi bi-search text-muted"></i>
            <input type="text" name="q" class="form-control form-control-sm"
                   value="<?= h($q) ?>" placeholder="Buscar por usuario o nombre">
            <?php if ($q !== ''): ?>
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
                    <th>Usuario</th>
                    <th>Nombre</th>
                    <th>Rol</th>
                    <th>Estado</th>
                    <th>Última conexión</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td>
                            <?= $this->Html->link(h($user->username), ['action' => 'view', $user->id]) ?>
                        </td>
                        <td><?= h($user->name) ?></td>
                        <td>
                            <?= h($user->role?->name ?? '—') ?>
                            <?php if ($user->isAdministrator()): ?>
                                <span class="badge badge-soft-primary ms-1">Admin</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$user->active): ?>
                                <span class="badge badge-soft-secondary">Inactivo</span>
                            <?php elseif ($user->isLocked()): ?>
                                <span class="badge badge-soft-warning">
                                    <i class="bi bi-lock-fill"></i> Bloqueado
                                </span>
                            <?php else: ?>
                                <span class="badge badge-soft-success">Activo</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $user->last_login_at ? h($user->last_login_at->i18nFormat('dd/MM HH:mm')) : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td class="text-end">
                            <?php if ($user->isAdministrator()): ?>
                                <span class="text-muted small">—</span>
                            <?php else: ?>
                                <?= $this->Html->link(
                                    '<i class="bi bi-pencil"></i>',
                                    ['action' => 'edit', $user->id],
                                    ['escape' => false, 'class' => 'btn btn-icon btn-light', 'title' => 'Editar']
                                ) ?>
                                <?php if ($user->isLocked()): ?>
                                    <?= $this->Form->postLink(
                                        '<i class="bi bi-unlock"></i>',
                                        ['action' => 'unlock', $user->id],
                                        [
                                            'escape' => false,
                                            'class' => 'btn btn-icon btn-light text-warning',
                                            'title' => 'Desbloquear cuenta',
                                            'confirm' => sprintf('¿Desbloquear la cuenta de %s?', $user->username),
                                        ]
                                    ) ?>
                                <?php endif; ?>
                                <?= $this->Form->postLink(
                                    '<i class="bi bi-trash"></i>',
                                    ['action' => 'delete', $user->id],
                                    [
                                        'escape' => false,
                                        'class' => 'btn btn-icon btn-light text-danger',
                                        'title' => 'Eliminar',
                                        'confirm' => sprintf('¿Eliminar al usuario %s?', $user->username),
                                    ]
                                ) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($users->toArray()) === 0): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            <?= $q !== '' ? 'Sin resultados para la búsqueda.' : 'No hay usuarios cargados.' ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->element('pagination') ?>
