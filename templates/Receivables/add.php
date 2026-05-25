<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Receivable $receivable
 * @var array<int, string> $customers
 */
$this->assign('title', 'Nueva cuenta por cobrar');
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Nueva cuenta por cobrar</h1>
</div>

<?= $this->Form->create($receivable, ['url' => ['action' => 'add']]) ?>
<div class="card">
    <div class="card-body">
        <div class="mb-3">
            <label class="form-label" for="customer_id">Cliente</label>
            <select name="customer_id" id="customer_id" class="form-select" required>
                <option value="">Seleccionar cliente...</option>
                <?php foreach ($customers as $id => $name): ?>
                    <option value="<?= (int)$id ?>"
                        <?= (int)($receivable->customer_id ?? 0) === (int)$id ? 'selected' : '' ?>>
                        <?= h($name) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small class="form-text text-muted">
                Solo aparecen clientes activos. <?= $this->Html->link(
                    'Crear nuevo cliente',
                    ['controller' => 'Customers', 'action' => 'add'],
                ) ?>.
            </small>
        </div>

        <div class="mb-3">
            <label class="form-label" for="total_amount">Monto</label>
            <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" name="total_amount" id="total_amount"
                       class="form-control" step="0.01" min="0.01" required
                       value="<?= h((string)($receivable->total_amount ?? '')) ?>">
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label" for="description">Descripción</label>
            <textarea name="description" id="description" class="form-control"
                      rows="3" maxlength="255" required
                      placeholder="ej. 'Mercado del 12/05', 'Préstamo personal'"><?= h((string)($receivable->description ?? '')) ?></textarea>
            <small class="form-text text-muted">Máx. 255 caracteres.</small>
        </div>
    </div>
    <div class="card-footer bg-white d-flex gap-2 justify-content-end">
        <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-light']) ?>
        <button type="submit" class="btn btn-primary">Registrar deuda</button>
    </div>
</div>
<?= $this->Form->end() ?>
