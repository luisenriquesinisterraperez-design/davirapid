<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Role $role
 * @var array<string, array<string, bool>> $matrix
 * @var array<string, string> $moduleCatalog
 */
$this->assign('title', $role->name);
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">
        <?= h($role->name) ?>
        <?php if ($role->isAdministrator()): ?>
            <span class="badge badge-soft-primary ms-2">
                <i class="bi bi-shield-fill-check"></i> Administrador
            </span>
        <?php endif; ?>
    </h1>
    <div class="d-flex gap-2">
        <?php if (!$role->isAdministrator()): ?>
            <?= $this->Html->link(
                '<i class="bi bi-pencil"></i> Editar',
                ['action' => 'edit', $role->id],
                ['escape' => false, 'class' => 'btn btn-primary']
            ) ?>
        <?php endif; ?>
        <?= $this->Html->link('Volver', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h2 class="h6 text-muted text-uppercase mb-3">Permisos por módulo</h2>
        <table class="table dr-permission-matrix mb-0">
            <thead>
                <tr>
                    <th>Módulo</th>
                    <th>Ver</th>
                    <th>Crear</th>
                    <th>Editar</th>
                    <th>Eliminar</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($moduleCatalog as $moduleKey => $moduleLabel): ?>
                    <?php
                    $isAdmin = $role->isAdministrator();
                    $row = $matrix[$moduleKey] ?? ['can_view' => false, 'can_create' => false, 'can_edit' => false, 'can_delete' => false];
                    ?>
                    <tr>
                        <td class="dr-module-name"><?= h($moduleLabel) ?></td>
                        <?php foreach (['can_view', 'can_create', 'can_edit', 'can_delete'] as $field): ?>
                            <td class="dr-perm-cell">
                                <?php if ($isAdmin || !empty($row[$field])): ?>
                                    <i class="bi bi-check-lg text-success"></i>
                                <?php else: ?>
                                    <i class="bi bi-dash text-muted"></i>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
