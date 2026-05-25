<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Ingredient $ingredient
 * @var array<string, array<string, bool>> $userPermissions
 */
$this->assign('title', 'Editar ingrediente');
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Editar: <?= h($ingredient->name) ?></h1>
    <?php if (!empty($userPermissions['ingredients']['delete'])): ?>
        <?= $this->Form->postLink(
            '<i class="bi bi-trash"></i> Eliminar',
            ['action' => 'delete', $ingredient->id],
            [
                'escape' => false,
                'class' => 'btn btn-danger',
                'confirm' => sprintf('¿Eliminar el ingrediente "%s"? Esta acción no se puede deshacer.', $ingredient->name),
            ]
        ) ?>
    <?php endif; ?>
</div>

<?= $this->element('Ingredients/_form', ['ingredient' => $ingredient, 'submitLabel' => 'Guardar cambios']) ?>
