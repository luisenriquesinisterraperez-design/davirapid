<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Product $product
 */
$this->assign('title', 'Nuevo producto');
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Nuevo producto</h1>
</div>

<?= $this->element('Products/_form', ['product' => $product, 'submitLabel' => 'Crear producto']) ?>
