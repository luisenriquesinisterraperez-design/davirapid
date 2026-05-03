<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Delivery $delivery
 */
$this->assign('title', 'Editar repartidor');
$isEdit = true;
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Editar repartidor: <?= h($delivery->full_name) ?></h1>
</div>

<?= $this->element('Deliveries/_form', compact('delivery', 'isEdit')) ?>
