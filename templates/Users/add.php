<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 * @var \Cake\ORM\ResultSet|\App\Model\Entity\Role[] $roles
 * @var bool $isEditingAdministrator
 * @var array<int, string> $deliveriesList
 */
$this->assign('title', 'Nuevo usuario');
$rolesList = [];
foreach ($roles as $r) {
    $rolesList[$r->id] = $r->name;
}
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Nuevo usuario</h1>
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
                    'placeholder' => 'pedro.garcia',
                    'help' => 'Letras, números, punto, guion bajo o guion. Sin espacios.',
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
                    'autocomplete' => 'new-password',
                    'help' => 'Mínimo 8 caracteres.',
                ]) ?>
            </div>
            <div class="col-md-6">
                <?= $this->Form->control('role_id', [
                    'label' => 'Rol',
                    'class' => 'form-select',
                    'type' => 'select',
                    'options' => $rolesList,
                    'empty' => 'Seleccionar rol…',
                ]) ?>
            </div>
            <div class="col-md-6">
                <?= $this->Form->control('delivery_id', [
                    'label' => 'Repartidor vinculado (opcional)',
                    'class' => 'form-select',
                    'type' => 'select',
                    'options' => $deliveriesList,
                    'empty' => '— Ninguno —',
                    'required' => false,
                ]) ?>
            </div>
            <div class="col-12">
                <div class="form-check">
                    <input type="hidden" name="active" value="0">
                    <input type="checkbox" name="active" id="active" class="form-check-input"
                           value="1" <?= $user->active === false ? '' : 'checked' ?>>
                    <label class="form-check-label" for="active">Usuario activo</label>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="d-flex gap-2">
    <?= $this->Form->button('<i class="bi bi-check-lg"></i> Crear usuario', ['escapeTitle' => false, 'class' => 'btn btn-primary']) ?>
    <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
</div>
<?= $this->Form->end() ?>
