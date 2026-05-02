<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Product $product
 */
$this->assign('title', $product->name);
?>
<div class="dr-page-header">
    <h1 class="dr-page-title"><?= h($product->name) ?></h1>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-pencil"></i> Editar',
            ['action' => 'edit', $product->id],
            ['escape' => false, 'class' => 'btn btn-secondary']
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left"></i> Volver',
            ['action' => 'index'],
            ['escape' => false, 'class' => 'btn btn-light']
        ) ?>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="row g-4">
            <div class="col-md-4">
                <div class="border rounded overflow-hidden d-flex align-items-center justify-content-center"
                     style="aspect-ratio:1/1; background:#f8f9fa;">
                    <img src="<?= h($product->getImageUrl()) ?>" alt="" style="max-width:100%; max-height:100%; object-fit:contain;">
                </div>
            </div>
            <div class="col-md-8">
                <dl class="row mb-0">
                    <dt class="col-sm-3">Código</dt>
                    <dd class="col-sm-9"><?= $product->code ? h($product->code) : '<span class="text-muted">Sin código</span>' ?></dd>

                    <dt class="col-sm-3">Precio</dt>
                    <dd class="col-sm-9"><?= h($product->getFormattedPrice()) ?></dd>

                    <dt class="col-sm-3">Estado</dt>
                    <dd class="col-sm-9">
                        <?php if ($product->is_active): ?>
                            <span class="badge badge-soft-success">Disponible</span>
                        <?php else: ?>
                            <span class="badge badge-soft-secondary">No disponible</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-3">Descripción</dt>
                    <dd class="col-sm-9"><?= $product->description ? nl2br(h($product->description)) : '<span class="text-muted">—</span>' ?></dd>

                    <dt class="col-sm-3">Creado</dt>
                    <dd class="col-sm-9"><?= $product->created ? h($product->created->i18nFormat('dd/MM/yyyy HH:mm')) : '—' ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>
