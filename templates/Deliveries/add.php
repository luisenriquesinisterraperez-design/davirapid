<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Delivery $delivery
 */
$this->assign('title', 'Nuevo repartidor');
$isEdit = false;
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Nuevo repartidor</h1>
</div>

<?= $this->element('Deliveries/_form', compact('delivery', 'isEdit')) ?>
