<?php
/**
 * @var \App\View\AppView $this
 */
if (!$this->Paginator->total() || $this->Paginator->total() <= $this->Paginator->limit()) {
    return;
}
?>
<nav aria-label="Paginación" class="mt-3">
    <ul class="pagination justify-content-end mb-0">
        <?= $this->Paginator->prev('« Anterior', ['class' => 'page-item', 'tag' => 'li', 'disabledTag' => 'a', 'disabledClass' => 'page-item disabled']) ?>
        <?= $this->Paginator->numbers(['class' => 'page-item', 'tag' => 'li', 'currentClass' => 'page-item active', 'currentTag' => 'a']) ?>
        <?= $this->Paginator->next('Siguiente »', ['class' => 'page-item', 'tag' => 'li', 'disabledTag' => 'a', 'disabledClass' => 'page-item disabled']) ?>
    </ul>
</nav>
