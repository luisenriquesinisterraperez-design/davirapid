<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Expense $expense
 * @var list<string> $descriptionSuggestions
 */
$this->assign('title', 'Nuevo gasto');
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Nuevo gasto</h1>
</div>

<div class="row">
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-body">
                <?= $this->Form->create($expense, ['type' => 'post']) ?>

                <div class="mb-3">
                    <label class="form-label" for="description">Descripción</label>
                    <input type="text" class="form-control" id="description" name="description"
                           list="description-suggestions" maxlength="255" required
                           value="<?= h($expense->description ?? '') ?>"
                           placeholder="Ej. Compra de carne">
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
                                   value="<?= h($expense->amount ?? '') ?>">
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="expense_date">Fecha del gasto</label>
                        <input type="date" class="form-control" id="expense_date" name="expense_date"
                               required
                               value="<?= h($expense->expense_date?->format('Y-m-d') ?? date('Y-m-d')) ?>">
                    </div>
                </div>

                <?php if ($expense->isFuture()) : ?>
                    <div class="alert alert-info mt-3 mb-0">
                        Estás registrando un gasto con fecha futura.
                    </div>
                <?php endif; ?>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>

                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>
