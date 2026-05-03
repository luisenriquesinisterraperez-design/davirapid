<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Delivery $delivery
 * @var bool $isEdit
 */
?>
<?= $this->Form->create($delivery) ?>
<div class="card mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <?= $this->Form->control('first_name', [
                    'label' => 'Nombre',
                    'class' => 'form-control',
                    'autofocus' => true,
                    'maxlength' => 60,
                ]) ?>
            </div>
            <div class="col-md-6">
                <?= $this->Form->control('last_name', [
                    'label' => 'Apellido',
                    'class' => 'form-control',
                    'maxlength' => 60,
                ]) ?>
            </div>
            <div class="col-md-6">
                <?= $this->Form->control('phone', [
                    'label' => 'Teléfono',
                    'class' => 'form-control',
                    'maxlength' => 20,
                    'help' => 'Solo dígitos, espacios, "+", "-" y paréntesis.',
                ]) ?>
            </div>
            <?php if ($isEdit): ?>
                <div class="col-12">
                    <div class="form-check">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" id="is_active" class="form-check-input"
                               value="1" <?= $delivery->is_active === false ? '' : 'checked' ?>>
                        <label class="form-check-label" for="is_active">Repartidor activo</label>
                    </div>
                </div>
            <?php else: ?>
                <input type="hidden" name="is_active" value="1">
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="d-flex gap-2">
    <?= $this->Form->button(
        '<i class="bi bi-check-lg"></i> ' . ($isEdit ? 'Guardar cambios' : 'Crear repartidor'),
        ['escapeTitle' => false, 'class' => 'btn btn-primary']
    ) ?>
    <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
</div>
<?= $this->Form->end() ?>
