<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\ReceivableConstants;
use App\Model\Entity\Order;
use App\Model\Entity\Receivable;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Throwable;

/**
 * ReceivableService — orchestrates Cuentas por Cobrar (CxC) lifecycle.
 *
 * Two kinds of CxC exist:
 *  - Automatic: created by OrderService when a credit-payment order is
 *    persisted. Idempotent via UNIQUE(order_id).
 *  - Manual: created via /receivables/add to record arbitrary debt.
 *
 * Methods named `*ForOrder` are called from inside OrderService's own
 * transaction (they do NOT open their own); the rest open transactions.
 *
 * Floating-point money: Decimal(12,2) values fit in PHP floats without
 * precision loss. All comparisons use a 0.005 epsilon.
 */
final class ReceivableService
{
    use LocatorAwareTrait;

    /**
     * No external dependencies today. AccountPaymentService (module 6)
     * will eventually call into this service, not the other way around.
     */
    public function __construct()
    {
    }

    // ------------------------------------------------------------------
    // Creation from an Order (called inside OrderService's transaction).
    // ------------------------------------------------------------------

    /**
     * Idempotent creation from an order. Returns the existing CxC if one
     * already references the same order_id (UNIQUE constraint).
     *
     * Does NOT open its own transaction — the caller (OrderService) is
     * already inside one.
     *
     * @return array{success: bool, receivable?: \App\Model\Entity\Receivable|null, errors?: list<string>, idempotent?: string}
     */
    public function createFromOrder(Order $order, int $userId): array
    {
        // Defensive: only for credit orders.
        if (!$order->isCredit()) {
            return ['success' => true, 'receivable' => null];
        }

        if ((float)$order->total <= 0.0) {
            return [
                'success' => false,
                'errors' => ['El total del pedido debe ser mayor a 0 para crear la cuenta por cobrar.'],
            ];
        }

        if ((int)($order->customer_id ?? 0) <= 0) {
            return [
                'success' => false,
                'errors' => ['El pedido a crédito requiere un cliente asociado.'],
            ];
        }

        $receivables = $this->fetchTable('Receivables');

        // 1. Idempotency: pre-existing CxC for this order.
        $existing = $receivables->find()
            ->where(['Receivables.order_id' => $order->id])
            ->first();
        if ($existing instanceof Receivable) {
            return ['success' => true, 'receivable' => $existing, 'idempotent' => 'reused'];
        }

        // 2. Build and persist.
        $description = sprintf(
            ReceivableConstants::AUTO_DESCRIPTION_TEMPLATE,
            (int)$order->id,
            (string)($order->customer?->name ?? $order->customer_name ?? 'Cliente'),
        );

        /** @var \App\Model\Entity\Receivable $rec */
        $rec = $receivables->newEntity([
            'customer_id' => (int)$order->customer_id,
            'order_id' => (int)$order->id,
            'total_amount' => number_format((float)$order->total, 2, '.', ''),
            'paid_amount' => '0.00',
            'description' => $description,
            'status' => ReceivableConstants::STATUS_PENDIENTE,
            'created_by' => $userId > 0 ? $userId : null,
        ]);

        if (!$receivables->save($rec)) {
            // 3. Race condition: UNIQUE collision after our lookup → re-fetch winner.
            $winner = $receivables->find()
                ->where(['Receivables.order_id' => $order->id])
                ->first();
            if ($winner instanceof Receivable) {
                Log::info('CxC createFromOrder lost race, returning existing: order={o}', [
                    'o' => $order->id, 'scope' => ['receivables'],
                ]);

                return ['success' => true, 'receivable' => $winner, 'idempotent' => 'race_recovered'];
            }

            return [
                'success' => false,
                'errors' => $this->flattenErrors($rec->getErrors()),
            ];
        }

        Log::info('CxC created from order: id={id} order={o} amount={a}', [
            'id' => $rec->id, 'o' => $order->id, 'a' => $rec->total_amount,
            'scope' => ['receivables'],
        ]);

        return ['success' => true, 'receivable' => $rec];
    }

    /**
     * Returns the receivable for an order, creating one if absent (idempotent).
     * Same transaction discipline as createFromOrder.
     *
     * @return array{success: bool, receivable?: \App\Model\Entity\Receivable|null, errors?: list<string>, idempotent?: string}
     */
    public function findOrCreateForOrder(Order $order, int $userId): array
    {
        return $this->createFromOrder($order, $userId);
    }

    /**
     * Adjusts the total_amount of an existing CxC after the originating
     * order's total changed (only used by OrderService::update). NO own
     * transaction. Falls back to createFromOrder if no CxC exists yet.
     *
     * @return array{success: bool, receivable?: \App\Model\Entity\Receivable|null, errors?: list<string>}
     */
    public function updateAmountForOrder(Order $order, int $userId): array
    {
        $receivables = $this->fetchTable('Receivables');
        $rec = $receivables->find()
            ->where(['Receivables.order_id' => $order->id])
            ->first();

        if (!$rec instanceof Receivable) {
            return $this->createFromOrder($order, $userId);
        }

        $newTotal = (float)$order->total;
        $paid = (float)$rec->paid_amount;
        if ($paid > $newTotal + 0.005) {
            return [
                'success' => false,
                'errors' => [sprintf(
                    'El nuevo total ($%s) es menor que lo ya abonado ($%s). '
                        . 'Anule los abonos primero o mantenga el total.',
                    number_format($newTotal, 2, '.', ''),
                    number_format($paid, 2, '.', ''),
                )],
            ];
        }

        $rec->total_amount = number_format($newTotal, 2, '.', '');
        $rec->status = $paid + 0.005 >= $newTotal
            ? ReceivableConstants::STATUS_PAGADO
            : ReceivableConstants::STATUS_PENDIENTE;

        if (!$receivables->save($rec)) {
            return [
                'success' => false,
                'errors' => $this->flattenErrors($rec->getErrors()),
            ];
        }

        Log::info('CxC total updated from order: id={id} order={o} new_total={t}', [
            'id' => $rec->id, 'o' => $order->id, 't' => $rec->total_amount,
            'scope' => ['receivables'],
        ]);

        return ['success' => true, 'receivable' => $rec];
    }

    /**
     * Removes the CxC linked to an order (cancel/delete/payment-method-change).
     * Idempotent: returns success when no CxC exists. NO own transaction.
     *
     * If the CxC has registered payments, a warning is logged with the voided
     * amount (the spec's "cancelar pedido anula crédito" rule overrides the
     * partial-payment record — the audit trail lives in logs).
     *
     * @return array{success: bool, errors?: list<string>}
     */
    public function deleteForOrder(Order $order, int $userId, string $reason): array
    {
        $receivables = $this->fetchTable('Receivables');
        /** @var \App\Model\Entity\Receivable|null $rec */
        $rec = $receivables->find()
            ->where(['Receivables.order_id' => $order->id])
            ->first();

        if (!$rec instanceof Receivable) {
            return ['success' => true];
        }

        if ($rec->hasPayments()) {
            Log::warning(
                'CxC voided with payments: cxc={cxc} order={o} paid={p} reason={r} user={u}',
                [
                    'cxc' => $rec->id, 'o' => $order->id,
                    'p' => $rec->paid_amount, 'r' => $reason, 'u' => $userId,
                    'scope' => ['receivables'],
                ],
            );
        }

        if (!$receivables->delete($rec)) {
            return [
                'success' => false,
                'errors' => ['No se pudo eliminar la cuenta por cobrar.'],
            ];
        }

        Log::warning('CxC auto-deleted: id={id} order={o} reason={r}', [
            'id' => $rec->id, 'o' => $order->id, 'r' => $reason,
            'scope' => ['receivables'],
        ]);

        return ['success' => true];
    }

    // ------------------------------------------------------------------
    // Standalone operations (open their own transactions).
    // ------------------------------------------------------------------

    /**
     * Manual creation (without an order) via /receivables/add.
     *
     * @param array<string, mixed> $data
     * @return array{success: bool, receivable?: \App\Model\Entity\Receivable, errors?: list<string>}
     */
    public function createManual(array $data, int $userId): array
    {
        $errors = $this->validateManualInput($data);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $customers = $this->fetchTable('Customers');
        $customerId = (int)$data['customer_id'];
        $customer = $customers->find()->where(['Customers.id' => $customerId])->first();
        if ($customer === null) {
            return ['success' => false, 'errors' => ['El cliente no existe.']];
        }

        $receivables = $this->fetchTable('Receivables');
        $resultBox = ['success' => false, 'errors' => ['Error desconocido.']];
        $connection = ConnectionManager::get('default');

        try {
            $connection->transactional(function () use (
                $receivables,
                $data,
                $customerId,
                $userId,
                &$resultBox,
            ): bool {
                /** @var \App\Model\Entity\Receivable $rec */
                $rec = $receivables->newEntity([
                    'customer_id' => $customerId,
                    'order_id' => null,
                    'total_amount' => number_format((float)$data['total_amount'], 2, '.', ''),
                    'paid_amount' => '0.00',
                    'description' => trim((string)$data['description']),
                    'status' => ReceivableConstants::STATUS_PENDIENTE,
                    'created_by' => $userId > 0 ? $userId : null,
                ]);

                if (!$receivables->save($rec)) {
                    $resultBox = [
                        'success' => false,
                        'errors' => $this->flattenErrors($rec->getErrors()),
                    ];

                    return false;
                }

                Log::info('CxC manual created: id={id} customer={c} amount={a}', [
                    'id' => $rec->id, 'c' => $customerId, 'a' => $rec->total_amount,
                    'scope' => ['receivables'],
                ]);

                $resultBox = ['success' => true, 'receivable' => $rec];

                return true;
            });
        } catch (Throwable $e) {
            Log::error('ReceivableService::createManual threw: {msg}', [
                'msg' => $e->getMessage(), 'scope' => ['receivables'],
            ]);

            return [
                'success' => false,
                'errors' => ['No se pudo crear la cuenta por cobrar: ' . $e->getMessage()],
            ];
        }

        return $resultBox;
    }

    /**
     * Marks a CxC as paid manually (without registering a real payment).
     * Sets paid_amount = total_amount to keep the invariant
     * "status=pagado ⇒ balance=0". Idempotent.
     *
     * Use only for non-monetary settlement (debt forgiveness, write-off).
     * Real partial payments must go through AccountPaymentService::create —
     * abonos take precedence over this flag, and `recomputeStatus()` will
     * overwrite `paid_amount` with the real SUM of abonos, potentially
     * reverting this manual override.
     *
     * @return array{success: bool, receivable?: \App\Model\Entity\Receivable, errors?: list<string>}
     */
    public function markAsPaid(Receivable $rec, int $userId): array
    {
        if ($rec->isPaid()) {
            return ['success' => true, 'receivable' => $rec];
        }

        $receivables = $this->fetchTable('Receivables');
        $resultBox = ['success' => false, 'errors' => ['Error desconocido.']];
        $connection = ConnectionManager::get('default');

        try {
            $connection->transactional(function () use (
                $receivables,
                $rec,
                $userId,
                &$resultBox,
            ): bool {
                $rec->status = ReceivableConstants::STATUS_PAGADO;
                $rec->paid_amount = number_format((float)$rec->total_amount, 2, '.', '');

                if (!$receivables->save($rec)) {
                    $resultBox = [
                        'success' => false,
                        'errors' => $this->flattenErrors($rec->getErrors()),
                    ];

                    return false;
                }

                Log::warning('CxC marked paid manually: id={id} user={u} amount={a}', [
                    'id' => $rec->id, 'u' => $userId, 'a' => $rec->total_amount,
                    'scope' => ['receivables'],
                ]);

                $resultBox = ['success' => true, 'receivable' => $rec];

                return true;
            });
        } catch (Throwable $e) {
            Log::error('ReceivableService::markAsPaid threw: {msg}', [
                'msg' => $e->getMessage(), 'scope' => ['receivables'],
            ]);

            return [
                'success' => false,
                'errors' => ['No se pudo marcar como pagada: ' . $e->getMessage()],
            ];
        }

        return $resultBox;
    }

    /**
     * Deletes a CxC. Blocks if any payment is registered.
     *
     * @return array{success: bool, errors?: list<string>}
     */
    public function delete(Receivable $rec, int $userId): array
    {
        if ($rec->hasPayments()) {
            return [
                'success' => false,
                'errors' => [
                    'No se puede eliminar: la cuenta tiene abonos registrados. '
                        . 'Anule los abonos primero.',
                ],
            ];
        }

        $receivables = $this->fetchTable('Receivables');
        $resultBox = ['success' => false, 'errors' => ['Error desconocido.']];
        $connection = ConnectionManager::get('default');

        try {
            $connection->transactional(function () use (
                $receivables,
                $rec,
                $userId,
                &$resultBox,
            ): bool {
                if (!$receivables->delete($rec)) {
                    $resultBox = [
                        'success' => false,
                        'errors' => ['No se pudo eliminar la cuenta por cobrar.'],
                    ];

                    return false;
                }

                Log::warning('CxC deleted: id={id} user={u} amount={a}', [
                    'id' => $rec->id, 'u' => $userId, 'a' => $rec->total_amount,
                    'scope' => ['receivables'],
                ]);

                $resultBox = ['success' => true];

                return true;
            });
        } catch (Throwable $e) {
            Log::error('ReceivableService::delete threw: {msg}', [
                'msg' => $e->getMessage(), 'scope' => ['receivables'],
            ]);

            return [
                'success' => false,
                'errors' => ['No se pudo eliminar la cuenta: ' . $e->getMessage()],
            ];
        }

        return $resultBox;
    }

    /**
     * Recomputes paid_amount and status from registered abonos.
     *
     * Acquires a pessimistic FOR UPDATE lock on the receivable row, sums
     * `account_payments.amount` for that receivable, writes the result to
     * `paid_amount`, and flips `status` accordingly using an EPSILON of
     * 0.005 for float comparisons.
     *
     * Logs a status flip when it occurs (info-level for both promotions
     * to `pagado` and demotions to `pendiente`).
     *
     * SQLite caveat: `epilog('FOR UPDATE')` is silently ignored by SQLite,
     * so the lock is effective only on MySQL/Postgres.
     *
     * @return array{success: bool, receivable?: \App\Model\Entity\Receivable, errors?: list<string>}
     */
    public function recomputeStatus(Receivable $rec): array
    {
        $receivables = $this->fetchTable('Receivables');
        $accountPayments = $this->fetchTable('AccountPayments');
        $resultBox = ['success' => false, 'errors' => ['Error desconocido.']];
        $connection = ConnectionManager::get('default');

        try {
            $connection->transactional(function () use (
                $receivables,
                $accountPayments,
                $rec,
                &$resultBox,
            ): bool {
                // Pessimistic lock on the receivable row.
                $receivables->find()
                    ->where(['Receivables.id' => $rec->id])
                    ->epilog('FOR UPDATE')
                    ->first();

                $sumRow = $accountPayments->find()
                    ->select(['s' => $accountPayments->find()->func()
                        ->sum('AccountPayments.amount')])
                    ->where(['AccountPayments.receivable_id' => $rec->id])
                    ->first();
                $newPaid = (float)($sumRow?->s ?? 0);
                $total = (float)$rec->total_amount;
                $oldStatus = $rec->status;

                $rec->paid_amount = number_format($newPaid, 2, '.', '');
                $rec->status = $newPaid + 0.005 >= $total
                    ? ReceivableConstants::STATUS_PAGADO
                    : ReceivableConstants::STATUS_PENDIENTE;

                if (!$receivables->save($rec)) {
                    $resultBox = [
                        'success' => false,
                        'errors' => $this->flattenErrors($rec->getErrors()),
                    ];

                    return false;
                }

                if ($oldStatus !== $rec->status) {
                    Log::info(
                        'CxC status flipped via recompute: id={id} from={f} to={t} paid={p}',
                        [
                            'id' => $rec->id, 'f' => $oldStatus, 't' => $rec->status,
                            'p' => $rec->paid_amount, 'scope' => ['receivables'],
                        ],
                    );
                }

                $resultBox = ['success' => true, 'receivable' => $rec];

                return true;
            });
        } catch (Throwable $e) {
            return [
                'success' => false,
                'errors' => ['No se pudo recalcular el estado: ' . $e->getMessage()],
            ];
        }

        return $resultBox;
    }

    // ------------------------------------------------------------------
    // Internals.
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function validateManualInput(array $data): array
    {
        $errors = [];

        if ((int)($data['customer_id'] ?? 0) <= 0) {
            $errors[] = 'El cliente es requerido.';
        }

        $total = $data['total_amount'] ?? null;
        if ($total === null || $total === '' || !is_numeric($total) || (float)$total <= 0) {
            $errors[] = 'El total debe ser mayor a 0.';
        }

        $desc = trim((string)($data['description'] ?? ''));
        if ($desc === '') {
            $errors[] = 'La descripción es requerida.';
        } elseif (mb_strlen($desc) > ReceivableConstants::DESCRIPTION_MAX_LENGTH) {
            $errors[] = 'La descripción no puede exceder 255 caracteres.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $errors
     * @return list<string>
     */
    private function flattenErrors(array $errors): array
    {
        $flat = [];
        array_walk_recursive($errors, function ($message) use (&$flat): void {
            if (is_string($message) && $message !== '') {
                $flat[] = $message;
            }
        });

        return $flat !== [] ? $flat : ['Datos inválidos.'];
    }
}
