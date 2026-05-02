<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Product $product
 * @var string $submitLabel
 */
?>
<?= $this->Form->create($product, ['type' => 'file']) ?>
<div class="card mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Imagen actual</label>
                <div class="border rounded d-flex align-items-center justify-content-center"
                     style="aspect-ratio: 1/1; background: #f8f9fa; overflow: hidden;">
                    <img src="<?= h($product->getImageUrl()) ?>"
                         alt="Imagen del producto"
                         style="max-width: 100%; max-height: 100%; object-fit: contain;">
                </div>
            </div>
            <div class="col-md-9">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label" for="image">Imagen</label>
                        <input type="file" name="image" id="image" class="form-control"
                               accept="image/jpeg,image/png,image/webp">
                        <div class="form-text">JPG, PNG o WebP. Hasta 10 MB. Se redimensiona a 800×800.</div>
                    </div>
                    <div class="col-md-4">
                        <?= $this->Form->control('code', [
                            'label' => 'Código',
                            'class' => 'form-control',
                            'maxlength' => 20,
                            'placeholder' => 'Ej. H2',
                            'help' => 'Atajo opcional para la pantalla de pedidos.',
                            'value' => $product->code,
                        ]) ?>
                    </div>
                    <div class="col-md-8">
                        <?= $this->Form->control('name', [
                            'label' => 'Nombre',
                            'class' => 'form-control',
                            'maxlength' => 120,
                            'autofocus' => $product->isNew(),
                        ]) ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="price">Precio</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" name="price" id="price" class="form-control"
                                   min="1" step="1"
                                   value="<?= h($product->price ?? '') ?>" required>
                        </div>
                        <div class="form-text">Pesos colombianos, enteros.</div>
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <div class="form-check">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" id="is_active" class="form-check-input"
                                   value="1" <?= ($product->isNew() || $product->is_active !== false) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">Disponible para la venta</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <?= $this->Form->control('description', [
                            'label' => 'Descripción',
                            'type' => 'textarea',
                            'rows' => 3,
                            'class' => 'form-control',
                        ]) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="d-flex gap-2">
    <?= $this->Form->button(
        '<i class="bi bi-check-lg"></i> ' . h($submitLabel),
        ['escapeTitle' => false, 'class' => 'btn btn-primary']
    ) ?>
    <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
</div>
<?= $this->Form->end() ?>
