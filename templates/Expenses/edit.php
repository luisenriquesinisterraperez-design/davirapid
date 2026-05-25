<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Expense $expense
 * @var list<string> $descriptionSuggestions
 */
$this->assign('title', 'Editar gasto');
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Editar gasto #<?= (int)$expense->id ?></h1>
</div>

<div class="row">
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-body">
                <?= $this->Form->create($expense, ['type' => 'put']) ?>

                <div class="mb-3">
                    <label class="form-label" for="description">Descripción</label>
                    <input type="text" class="form-control" id="description" name="description"
                           list="description-suggestions" maxlength="255" required
                           value="<?= h($expense->description) ?>">
                    <datalist id="description-suggestions">
                        <?php foreach ($descriptionSuggestions as $sug) : ?>
                            <option value="<?= h($sug) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="amount">Monto</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" step="0.01" min="0.01" class="form-control"
                                   id="amount" name="amount" required
                                   value="<?= h($expense->amount) ?>">
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="expense_date">Fecha del gasto</label>
                        <input type="date" class="form-control" id="expense_date" name="expense_date"
                               required
                               value="<?= h($expense->expense_date?->format('Y-m-d')) ?>">
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <?= $this->Html->link(
                        'Cancelar',
                        ['action' => 'view', $expense->id],
                        ['class' => 'btn btn-light'],
                    ) ?>
                    <button type="submit" class="btn btn-primary">Guardar cambios</button>
                </div>

                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>
