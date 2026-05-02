<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Product $product
 */
$this->assign('title', 'Editar producto');
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Editar: <?= h($product->name) ?></h1>
</div>

<?= $this->element('Products/_form', ['product' => $product, 'submitLabel' => 'Guardar cambios']) ?>
