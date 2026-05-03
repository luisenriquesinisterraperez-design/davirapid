<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Customer $customer
 * @var bool $isEdit
 */
?>
<?= $this->Form->create($customer, ['type' => 'post']) ?>
<div class="card">
    <div class="card-body">
        <div class="mb-3">
            <label class="form-label" for="customer-name">Nombre <span class="text-danger">*</span></label>
            <?= $this->Form->control('name', [
                'type' => 'text',
                'label' => false,
                'class' => 'form-control' . ($customer->getError('name') ? ' is-invalid' : ''),
                'id' => 'customer-name',
                'maxlength' => 150,
                'required' => true,
            ]) ?>
            <?php if ($customer->getError('name')): ?>
                <div class="invalid-feedback d-block"><?= h(implode(' ', $customer->getError('name'))) ?></div>
            <?php endif; ?>
        </div>

        <div class="mb-3">
            <label class="form-label" for="customer-phone">Teléfono <span class="text-danger">*</span></label>
            <?= $this->Form->control('phone', [
                'type' => 'text',
                'label' => false,
                'class' => 'form-control' . ($customer->getError('phone') ? ' is-invalid' : ''),
                'id' => 'customer-phone',
                'maxlength' => 30,
                'required' => true,
            ]) ?>
            <div class="form-text">Único. Se usará para identificar al cliente al cobrar a crédito.</div>
            <?php if ($customer->getError('phone')): ?>
                <div class="invalid-feedback d-block"><?= h(implode(' ', $customer->getError('phone'))) ?></div>
            <?php endif; ?>
        </div>

        <div class="mb-3">
            <label class="form-label" for="customer-address">Dirección</label>
            <?= $this->Form->control('address', [
                'type' => 'textarea',
                'label' => false,
                'rows' => 2,
                'class' => 'form-control' . ($customer->getError('address') ? ' is-invalid' : ''),
                'id' => 'customer-address',
                'maxlength' => 255,
            ]) ?>
            <?php if ($customer->getError('address')): ?>
                <div class="invalid-feedback d-block"><?= h(implode(' ', $customer->getError('address'))) ?></div>
            <?php endif; ?>
        </div>

        <?php if ($isEdit): ?>
            <div class="form-check mb-3">
                <?= $this->Form->checkbox('is_active', [
                    'class' => 'form-check-input',
                    'id' => 'customer-active',
                    'checked' => (bool)$customer->is_active,
                ]) ?>
                <label class="form-check-label" for="customer-active">Cliente activo</label>
            </div>
        <?php else: ?>
            <?= $this->Form->hidden('is_active', ['value' => '1']) ?>
        <?php endif; ?>
    </div>
    <div class="card-footer d-flex justify-content-between">
        <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
        <button type="submit" class="btn btn-primary">Guardar</button>
    </div>
</div>
<?= $this->Form->end() ?>
