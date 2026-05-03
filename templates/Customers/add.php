<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Customer $customer
 */
$this->assign('title', 'Nuevo cliente');
$isEdit = false;
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Nuevo cliente</h1>
</div>
<?= $this->element('Customers/_form', ['customer' => $customer, 'isEdit' => $isEdit]) ?>
