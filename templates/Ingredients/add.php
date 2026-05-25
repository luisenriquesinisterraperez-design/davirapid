<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Ingredient $ingredient
 */
$this->assign('title', 'Nuevo ingrediente');
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Nuevo ingrediente</h1>
</div>

<?= $this->element('Ingredients/_form', ['ingredient' => $ingredient, 'submitLabel' => 'Crear ingrediente']) ?>
