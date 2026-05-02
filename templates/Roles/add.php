<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Role $role
 * @var array<string, array<string, bool>> $matrix
 * @var array<string, string> $moduleCatalog
 */
$this->assign('title', 'Nuevo rol');
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Nuevo rol</h1>
</div>

<?= $this->Form->create($role) ?>
<div class="card mb-4">
    <div class="card-body">
        <div class="mb-3">
            <?= $this->Form->control('name', [
                'label' => 'Nombre del rol',
                'class' => 'form-control',
                'autofocus' => true,
                'maxlength' => 60,
                'placeholder' => 'Ej. Cajero, Encargado de turno',
            ]) ?>
        </div>
    </div>
</div>

<div class="card mb-4">
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
                    $row = $matrix[$moduleKey] ?? ['can_view' => false, 'can_create' => false, 'can_edit' => false, 'can_delete' => false];
                    $isRolesModule = $moduleKey === 'roles';
                    ?>
                    <tr<?= $isRolesModule ? ' title="Solo el Administrador puede gestionar Roles" data-bs-toggle="tooltip"' : '' ?>>
                        <td class="dr-module-name"><?= h($moduleLabel) ?></td>
                        <?php foreach (['can_view', 'can_create', 'can_edit', 'can_delete'] as $field): ?>
                            <td class="dr-perm-cell">
                                <input type="hidden" name="permissions[<?= h($moduleKey) ?>][<?= h($field) ?>]" value="0">
                                <input type="checkbox"
                                       class="form-check-input dr-perm-checkbox"
                                       name="permissions[<?= h($moduleKey) ?>][<?= h($field) ?>]"
                                       value="1"
                                       data-module="<?= h($moduleKey) ?>"
                                       data-field="<?= h($field) ?>"
                                       <?= !empty($row[$field]) ? 'checked' : '' ?>
                                       <?= $isRolesModule ? 'disabled' : '' ?>>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="d-flex gap-2">
    <?= $this->Form->button('<i class="bi bi-check-lg"></i> Guardar', ['escapeTitle' => false, 'class' => 'btn btn-primary']) ?>
    <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
</div>
<?= $this->Form->end() ?>

<?php $this->start('script'); ?>
<script>
(function () {
    document.querySelectorAll('.dr-perm-checkbox').forEach(function (cb) {
        cb.addEventListener('change', function () {
            if (cb.disabled) return;
            const module = cb.dataset.module;
            const field = cb.dataset.field;
            if (cb.checked && field !== 'can_view') {
                const view = document.querySelector('.dr-perm-checkbox[data-module="' + module + '"][data-field="can_view"]');
                if (view) view.checked = true;
            }
            if (!cb.checked && field === 'can_view') {
                document.querySelectorAll('.dr-perm-checkbox[data-module="' + module + '"]').forEach(function (other) {
                    other.checked = false;
                });
            }
        });
    });
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        new bootstrap.Tooltip(el);
    });
})();
</script>
<?php $this->end(); ?>
