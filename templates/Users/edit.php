<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 * @var \Cake\ORM\ResultSet|\App\Model\Entity\Role[] $roles
 * @var bool $isEditingAdministrator
 * @var array<int, string> $deliveriesList
 */
$this->assign('title', 'Editar usuario');
$rolesList = [];
foreach ($roles as $r) {
    $rolesList[$r->id] = $r->name;
}
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Editar usuario: <?= h($user->username) ?></h1>
</div>

<?= $this->Form->create($user) ?>
<div class="card mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <?= $this->Form->control('username', [
                    'label' => 'Usuario',
                    'class' => 'form-control',
                    'autofocus' => true,
                    'maxlength' => 60,
                ]) ?>
            </div>
            <div class="col-md-6">
                <?= $this->Form->control('name', [
                    'label' => 'Nombre completo',
                    'class' => 'form-control',
                    'maxlength' => 120,
                ]) ?>
            </div>
            <div class="col-md-6">
                <?= $this->Form->control('password', [
                    'label' => 'Contraseña',
                    'class' => 'form-control',
                    'type' => 'password',
                    'value' => '',
                    'autocomplete' => 'new-password',
                    'help' => 'Dejá en blanco para no cambiar. Mínimo 8 caracteres si la cambiás.',
                    'required' => false,
                ]) ?>
            </div>
            <div class="col-md-6">
                <?php if ($isEditingAdministrator): ?>
                    <label class="form-label">Rol</label>
                    <input type="text" class="form-control" disabled
                           value="<?= h($user->role?->name ?? 'Administrador') ?>">
                    <input type="hidden" name="role_id" value="<?= h($user->role_id) ?>">
                    <small class="form-text text-muted">El rol del Administrador no puede cambiarse.</small>
                <?php else: ?>
                    <?= $this->Form->control('role_id', [
                        'label' => 'Rol',
                        'class' => 'form-select',
                        'type' => 'select',
                        'options' => $rolesList,
                        'empty' => 'Seleccionar rol…',
                    ]) ?>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <?= $this->Form->control('delivery_id', [
                    'label' => 'Repartidor vinculado (opcional)',
                    'class' => 'form-select',
                    'type' => 'select',
                    'options' => $deliveriesList,
                    'empty' => '— Ninguno —',
                    'required' => false,
                    'default' => $user->delivery_id,
                ]) ?>
            </div>
            <div class="col-12">
                <?php if ($isEditingAdministrator): ?>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" disabled checked>
                        <label class="form-check-label">Usuario activo</label>
                        <input type="hidden" name="active" value="1">
                    </div>
                    <small class="text-muted">El Administrador no se puede desactivar.</small>
                <?php else: ?>
                    <div class="form-check">
                        <input type="hidden" name="active" value="0">
                        <input type="checkbox" name="active" id="active" class="form-check-input"
                               value="1" <?= $user->active === false ? '' : 'checked' ?>>
                        <label class="form-check-label" for="active">Usuario activo</label>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="d-flex gap-2">
    <?= $this->Form->button('<i class="bi bi-check-lg"></i> Guardar cambios', ['escapeTitle' => false, 'class' => 'btn btn-primary']) ?>
    <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
</div>
<?= $this->Form->end() ?>
