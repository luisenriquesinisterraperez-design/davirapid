<?php
use App\Constants\AccountPaymentConstants;
use App\Constants\OrderConstants;

/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\AccountPayment $payment
 * @var array<int, \App\Model\Entity\Receivable> $receivablesList
 * @var array{receivable: \App\Model\Entity\Receivable, balance: float}|null $hint
 * @var array<string, string> $paymentMethods
 */
$this->assign('title', 'Registrar abono');
$defaultMethod = (string)($payment->payment_method ?? OrderConstants::PAYMENT_CASH);
$maxAmount = $hint !== null ? (float)$hint['balance'] : null;
?>
<div class="dr-page-header">
    <h1 class="dr-page-title">Registrar abono</h1>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left"></i> Volver',
        $hint !== null
            ? ['controller' => 'Receivables', 'action' => 'view', $hint['receivable']->id]
            : ['action' => 'index'],
        ['escape' => false, 'class' => 'btn btn-light'],
    ) ?>
</div>

<div class="row">
    <div class="col-12 col-lg-7">
        <?= $this->Form->create($payment, ['url' => ['action' => 'add']]) ?>
        <div class="card">
            <div class="card-body">
                <?php if ($hint !== null) : ?>
                    <div class="alert alert-info">
                        <div class="d-flex justify-content-between">
                            <div>
                                <strong>
                                    <?= h($hint['receivable']->customer?->name ?? 'Cliente') ?>
                                </strong>
                                — <?= h($hint['receivable']->description) ?>
                                <div class="text-muted small">
                                    CxC #<?= (int)$hint['receivable']->id ?>
                                </div>
                            </div>
                            <div class="text-end">
                                <small class="text-muted d-block">Saldo actual</small>
                                <strong class="fs-5">
                                    $<?= h(number_format($hint['balance'], 2, ',', '.')) ?>
                                </strong>
                            </div>
                        </div>
                    </div>
                    <?= $this->Form->hidden('receivable_id', [
                        'value' => $hint['receivable']->id,
                    ]) ?>
                <?php else : ?>
                    <div class="mb-3">
                        <label for="receivable_id" class="form-label">
                            Cuenta por Cobrar
                        </label>
                        <select name="receivable_id" id="receivable_id"
                                class="form-select" required>
                            <option value="">Seleccioná una cuenta pendiente...</option>
                            <?php foreach ($receivablesList as $rec) : ?>
                                <?php
                                $label = sprintf(
                                    '%s — %s — Saldo $%s',
                                    $rec->customer?->name ?? 'Cliente',
                                    $rec->description,
                                    number_format($rec->getBalance(), 2, ',', '.'),
                                );
                                $selected = ((int)$payment->receivable_id) === (int)$rec->id;
                                ?>
                                <option value="<?= (int)$rec->id ?>"
                                    <?= $selected ? 'selected' : '' ?>>
                                    <?= h($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($receivablesList)) : ?>
                            <small class="text-muted">
                                No hay cuentas pendientes.
                            </small>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="mb-3">
                    <label for="amount" class="form-label">Monto</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" name="amount" id="amount"
                               class="form-control"
                               step="0.01" min="0.01"
                               <?= $maxAmount !== null
                                   ? 'max="' . h(number_format($maxAmount, 2, '.', '')) . '"'
                                   : '' ?>
                               value="<?= h((string)($payment->amount ?? '')) ?>"
                               required>
                        <?php if ($maxAmount !== null) : ?>
                            <button type="button" class="btn btn-outline-secondary"
                                    onclick="document.getElementById('amount').value='<?= h(number_format($maxAmount, 2, '.', '')) ?>'">
                                Pagar todo
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label d-block">Método de pago</label>
                    <div class="d-flex gap-3 flex-wrap">
                        <?php foreach (AccountPaymentConstants::PAYMENT_METHODS as $method) : ?>
                            <div class="form-check">
                                <input class="form-check-input" type="radio"
                                       name="payment_method"
                                       id="method_<?= h($method) ?>"
                                       value="<?= h($method) ?>"
                                       <?= $defaultMethod === $method ? 'checked' : '' ?>>
                                <label class="form-check-label"
                                       for="method_<?= h($method) ?>">
                                    <?= h($paymentMethods[$method] ?? ucfirst($method)) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="notes" class="form-label">Observaciones</label>
                    <textarea name="notes" id="notes" class="form-control"
                              rows="3"
                              placeholder="Opcional. Ej: pagó con billete grande, dejó vuelto en cuenta."><?= h((string)($payment->notes ?? '')) ?></textarea>
                </div>
            </div>
            <div class="card-footer bg-white d-flex gap-2 justify-content-end">
                <?= $this->Html->link(
                    'Cancelar',
                    $hint !== null
                        ? ['controller' => 'Receivables', 'action' => 'view',
                           $hint['receivable']->id]
                        : ['action' => 'index'],
                    ['class' => 'btn btn-light'],
                ) ?>
                <button type="submit" class="btn btn-primary">
                    Registrar abono
                </button>
            </div>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>
