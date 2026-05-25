<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AccountPaymentConstants;
use App\Constants\OrderConstants;
use App\Constants\ReceivableConstants;
use App\Model\Entity\AccountPayment;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Throwable;

/**
 * AccountPaymentService — append-only orchestration of abonos against CxC.
 *
 * Append-only by design: no `update` method exists. Mistakes are corrected
 * by deleting and re-creating. Every mutation acquires a FOR UPDATE lock
 * on the parent `receivables` row to serialize concurrent abonos and
 * guarantee `paid_amount` / `status` consistency.
 *
 * Floating-point money: Decimal(12,2) values fit in PHP floats without
 * precision loss. All comparisons use AccountPaymentConstants::EPSILON.
 *
 * SQLite caveat: `epilog('FOR UPDATE')` is silently ignored by SQLite,
 * so the lock is effective only on MySQL/Postgres. Tests still pass.
 */
final class AccountPaymentService
{
    use LocatorAwareTrait;

    private ReceivableService $receivables;

    /**
     * @param \App\Service\ReceivableService|null $receivables Reserved for
     *     future cross-service orchestration; today the service self-contains
     *     the recompute logic to avoid nested transactions.
     */
    public function __construct(?ReceivableService $receivables = null)
    {
        $this->receivables = $receivables ?? new ReceivableService();
    }

    /**
     * Registers a new abono against a Receivable and atomically updates
     * the parent CxC's `paid_amount` and `status`.
     *
     * @param array<string, mixed> $data
     * @return array{success: bool, payment?: \App\Model\Entity\AccountPayment, receivable?: \App\Model\Entity\Receivable, errors?: array<int, string>}
     */
    public function create(array $data, int $userId): array
    {
        $errors = $this->validateInput($data);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $receivableId = (int)$data['receivable_id'];
        $amount = (float)$data['amount'];
        $paymentMethod = (string)$data['payment_method'];
        $notes = isset($data['notes']) && trim((string)$data['notes']) !== ''
            ? (string)$data['notes']
            : null;

        $receivablesTable = $this->fetchTable('Receivables');
        $accountPaymentsTable = $this->fetchTable('AccountPayments');

        // Fast pre-check (no lock) for UX. Lock-aware recheck happens inside the tx.
        /** @var \App\Model\Entity\Receivable|null $rec */
        $rec = $receivablesTable->find()
            ->where(['Receivables.id' => $receivableId])
            ->first();
        if ($rec === null) {
            return ['success' => false, 'errors' => ['La cuenta no existe.']];
        }
        if ($rec->isPaid()) {
            return [
                'success' => false,
                'errors' => ['La cuenta ya está pagada. No se admiten más abonos.'],
            ];
        }

        $resultBox = ['success' => false, 'errors' => ['Error desconocido.']];
        $connection = ConnectionManager::get('default');

        try {
            $connection->transactional(function () use (
                $receivablesTable,
                $accountPaymentsTable,
                $receivableId,
                $amount,
                $paymentMethod,
                $notes,
                $userId,
                &$resultBox,
            ): bool {
                // 1. Pessimistic lock on the receivable row.
                /** @var \App\Model\Entity\Receivable|null $locked */
                $locked = $receivablesTable->find()
                    ->where(['Receivables.id' => $receivableId])
                    ->epilog('FOR UPDATE')
                    ->first();

                if ($locked === null) {
                    $resultBox = [
                        'success' => false,
                        'errors' => ['La cuenta no existe.'],
                    ];

                    return false;
                }

                // 2. Idempotency under lock: another tx might have completed it.
                if ($locked->isPaid()) {
                    $resultBox = [
                        'success' => false,
                        'errors' => ['La cuenta ya está pagada. No se admiten más abonos.'],
                    ];

                    return false;
                }

                // 3. Overpayment guard.
                $currentPaid = (float)$locked->paid_amount;
                $total = (float)$locked->total_amount;
                if ($currentPaid + $amount > $total + AccountPaymentConstants::EPSILON) {
                    $remaining = round($total - $currentPaid, 2);
                    $resultBox = [
                        'success' => false,
                        'errors' => [sprintf(
                            'El monto excede el saldo de $%s.',
                            number_format(max(0.0, $remaining), 2, ',', '.'),
                        )],
                    ];

                    return false;
                }

                // 4. Persist the abono.
                /** @var \App\Model\Entity\AccountPayment $payment */
                $payment = $accountPaymentsTable->newEntity([
                    'receivable_id' => $receivableId,
                    'amount' => number_format($amount, 2, '.', ''),
                    'payment_method' => $paymentMethod,
                    'notes' => $notes,
                    'created_by' => $userId > 0 ? $userId : null,
                ]);

                if (!$accountPaymentsTable->save($payment)) {
                    $resultBox = [
                        'success' => false,
                        'errors' => $this->flattenErrors($payment->getErrors()),
                    ];

                    return false;
                }

                // 5. Recompute paid_amount from SUM (includes the just-inserted row
                //    since we're inside the same connection/transaction).
                $newPaid = $this->sumPaymentsForReceivable($receivableId);

                $oldStatus = $locked->status;
                $locked->paid_amount = number_format($newPaid, 2, '.', '');
                $locked->status = $newPaid + AccountPaymentConstants::EPSILON >= $total
                    ? ReceivableConstants::STATUS_PAGADO
                    : ReceivableConstants::STATUS_PENDIENTE;

                if (!$receivablesTable->save($locked)) {
                    $resultBox = [
                        'success' => false,
                        'errors' => $this->flattenErrors($locked->getErrors()),
                    ];

                    return false;
                }

                Log::info(
                    'Abono created: id={id} rec={r} amount={a} method={m} user={u}',
                    [
                        'id' => $payment->id, 'r' => $receivableId,
                        'a' => $payment->amount, 'm' => $paymentMethod, 'u' => $userId,
                        'scope' => ['account_payments'],
                    ],
                );

                if (
                    $oldStatus !== $locked->status
                    && $locked->status === ReceivableConstants::STATUS_PAGADO
                ) {
                    Log::info('CxC fully paid via abono: rec={r} total={t}', [
                        'r' => $receivableId, 't' => $locked->total_amount,
                        'scope' => ['receivables'],
                    ]);
                }

                $resultBox = [
                    'success' => true,
                    'payment' => $payment,
                    'receivable' => $locked,
                ];

                return true;
            });
        } catch (Throwable $e) {
            Log::error('AccountPaymentService::create threw: {msg}', [
                'msg' => $e->getMessage(), 'scope' => ['account_payments'],
            ]);

            return [
                'success' => false,
                'errors' => ['No se pudo registrar el abono: ' . $e->getMessage()],
            ];
        }

        return $resultBox;
    }

    /**
     * Deletes an abono, recomputes the parent CxC and demotes its status
     * back to `pendiente` when the deletion drops paid_amount below total.
     *
     * @return array{success: bool, receivable?: \App\Model\Entity\Receivable, errors?: array<int, string>}
     */
    public function delete(AccountPayment $payment, int $userId): array
    {
        $receivableId = (int)$payment->receivable_id;
        $paymentId = (int)$payment->id;
        $paymentAmount = (string)$payment->amount;

        $receivablesTable = $this->fetchTable('Receivables');
        $accountPaymentsTable = $this->fetchTable('AccountPayments');

        $resultBox = ['success' => false, 'errors' => ['Error desconocido.']];
        $connection = ConnectionManager::get('default');

        try {
            $connection->transactional(function () use (
                $receivablesTable,
                $accountPaymentsTable,
                $payment,
                $receivableId,
                $paymentId,
                $paymentAmount,
                $userId,
                &$resultBox,
            ): bool {
                // 1. Lock the parent CxC (may have been CASCADE-deleted already).
                /** @var \App\Model\Entity\Receivable|null $locked */
                $locked = $receivablesTable->find()
                    ->where(['Receivables.id' => $receivableId])
                    ->epilog('FOR UPDATE')
                    ->first();

                if ($locked === null) {
                    // Parent gone — nothing to recompute. Delete is a no-op.
                    $resultBox = ['success' => true];

                    return true;
                }

                // 2. Delete the abono row.
                if (!$accountPaymentsTable->delete($payment)) {
                    $resultBox = [
                        'success' => false,
                        'errors' => ['No se pudo eliminar el abono.'],
                    ];

                    return false;
                }

                // 3. Recompute the parent CxC.
                $newPaid = $this->sumPaymentsForReceivable($receivableId);
                $total = (float)$locked->total_amount;
                $oldStatus = $locked->status;

                $locked->paid_amount = number_format($newPaid, 2, '.', '');
                $locked->status = $newPaid + AccountPaymentConstants::EPSILON >= $total
                    ? ReceivableConstants::STATUS_PAGADO
                    : ReceivableConstants::STATUS_PENDIENTE;

                if (!$receivablesTable->save($locked)) {
                    $resultBox = [
                        'success' => false,
                        'errors' => $this->flattenErrors($locked->getErrors()),
                    ];

                    return false;
                }

                if (
                    $oldStatus === ReceivableConstants::STATUS_PAGADO
                    && $locked->status === ReceivableConstants::STATUS_PENDIENTE
                ) {
                    Log::warning(
                        'CxC demoted from pagado: rec={r} new_paid={p} total={t}',
                        [
                            'r' => $receivableId, 'p' => $locked->paid_amount,
                            't' => $locked->total_amount, 'scope' => ['receivables'],
                        ],
                    );
                }

                Log::warning(
                    'Abono deleted: id={p} rec={r} amount={a} user={u}',
                    [
                        'p' => $paymentId, 'r' => $receivableId,
                        'a' => $paymentAmount, 'u' => $userId,
                        'scope' => ['account_payments'],
                    ],
                );

                $resultBox = ['success' => true, 'receivable' => $locked];

                return true;
            });
        } catch (Throwable $e) {
            Log::error('AccountPaymentService::delete threw: {msg}', [
                'msg' => $e->getMessage(), 'scope' => ['account_payments'],
            ]);

            return [
                'success' => false,
                'errors' => ['No se pudo eliminar el abono: ' . $e->getMessage()],
            ];
        }

        return $resultBox;
    }

    /**
     * Computes the total paid amount for a receivable from its abonos.
     * Runs within the caller's transaction/lock context.
     */
    private function sumPaymentsForReceivable(int $receivableId): float
    {
        $accountPaymentsTable = $this->fetchTable('AccountPayments');
        $sumRow = $accountPaymentsTable->find()
            ->select(['s' => $accountPaymentsTable->find()->func()
                ->sum('AccountPayments.amount')])
            ->where(['AccountPayments.receivable_id' => $receivableId])
            ->first();

        return (float)($sumRow?->s ?? 0);
    }

    /**
     * Defensive pre-DB validation of the raw input array.
     *
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    private function validateInput(array $data): array
    {
        $errors = [];

        if ((int)($data['receivable_id'] ?? 0) <= 0) {
            $errors[] = 'La cuenta es requerida.';
        }

        $rawAmount = $data['amount'] ?? null;
        if (
            $rawAmount === null || $rawAmount === ''
            || !is_numeric($rawAmount) || (float)$rawAmount <= 0
        ) {
            $errors[] = 'El monto debe ser mayor a 0.';
        }

        $method = (string)($data['payment_method'] ?? '');
        if ($method === '') {
            $errors[] = 'El método de pago es requerido.';
        } elseif ($method === OrderConstants::PAYMENT_CREDIT) {
            // Explicit, friendly message before the generic inList kicks in.
            $errors[] = 'No se puede abonar con método Crédito.';
        } elseif (!in_array($method, AccountPaymentConstants::PAYMENT_METHODS, true)) {
            $errors[] = 'Método de pago inválido.';
        }

        $notes = (string)($data['notes'] ?? '');
        if (mb_strlen($notes) > AccountPaymentConstants::NOTES_MAX_LENGTH) {
            $errors[] = 'Las observaciones son demasiado extensas.';
        }

        return $errors;
    }

    /**
     * Flattens the nested error tree from save() into a flat list of strings.
     *
     * @param array<string, mixed> $errors
     * @return array<int, string>
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
