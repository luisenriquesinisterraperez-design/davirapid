<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Customer $customer
 */
$this->assign('title', 'Editar cliente');
$isEdit = true;
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Editar cliente</h1>
</div>
<?= $this->element('Customers/_form', ['customer' => $customer, 'isEdit' => $isEdit]) ?>
