<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\DailyClosingConstants;
use App\Constants\OrderConstants;
use App\Model\Entity\DailyClosing;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;

final class DailyClosingService
{
    use LocatorAwareTrait;

    /**
     * Computes the expected breakdown for a given closing date.
     *
     * @return array{sales_total: float, payments_total: float, expenses_total: float, expected: float}
     */
    public function computeExpected(string $closingDate, float $initialBalance = 0.0): array
    {
        $orders = $this->fetchTable('Orders');
        $payments = $this->fetchTable('AccountPayments');
        $expenses = $this->fetchTable('Expenses');

        $dayStart = $closingDate . ' 00:00:00';
        $dayEnd = $closingDate . ' 23:59:59';

        // Non-credit sales of the day (excluding cancelled).
        $salesRow = $orders->find()
            ->select(['s' => $orders->find()->func()->sum('Orders.total')])
            ->where([
                'Orders.payment_method !=' => OrderConstants::PAYMENT_CREDIT,
                'Orders.status !=' => OrderConstants::STATUS_CANCELLED,
                'Orders.created >=' => $dayStart,
                'Orders.created <=' => $dayEnd,
            ])
            ->first();
        $salesTotal = (float)($salesRow?->s ?? 0);

        // Account payments registered today (income from prior credit sales).
        $paymentsRow = $payments->find()
            ->select(['s' => $payments->find()->func()->sum('AccountPayments.amount')])
            ->where([
                'AccountPayments.created >=' => $dayStart,
                'AccountPayments.created <=' => $dayEnd,
            ])
            ->first();
        $paymentsTotal = (float)($paymentsRow?->s ?? 0);

        // Expenses of the day (by expense_date, which is the business day).
        $expensesRow = $expenses->find()
            ->select(['s' => $expenses->find()->func()->sum('Expenses.amount')])
            ->where(['Expenses.expense_date' => $closingDate])
            ->first();
        $expensesTotal = (float)($expensesRow?->s ?? 0);

        $expected = round(
            $initialBalance + $salesTotal + $paymentsTotal - $expensesTotal,
            DailyClosingConstants::MONEY_DECIMALS,
        );

        return [
            'sales_total' => round($salesTotal, DailyClosingConstants::MONEY_DECIMALS),
            'payments_total' => round($paymentsTotal, DailyClosingConstants::MONEY_DECIMALS),
            'expenses_total' => round($expensesTotal, DailyClosingConstants::MONEY_DECIMALS),
            'expected' => $expected,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{success: bool, closing?: \App\Model\Entity\DailyClosing, errors?: array<int, string>}
     */
    public function create(array $data, int $userId): array
    {
        $table = $this->fetchTable('DailyClosings');
        $closingDate = (string)($data['closing_date'] ?? '');
        $initialBalance = (float)($data['initial_balance'] ?? 0);
        $actualAmount = (float)($data['actual_amount'] ?? 0);

        if ($closingDate === '') {
            return ['success' => false, 'errors' => ['La fecha de cierre es requerida.']];
        }

        $connection = ConnectionManager::get('default');
        $box = ['success' => false, 'errors' => ['Error desconocido.']];

        $connection->transactional(function () use (
            $table,
            $closingDate,
            $initialBalance,
            $actualAmount,
            $data,
            $userId,
            &$box,
        ): bool {
            $breakdown = $this->computeExpected($closingDate, $initialBalance);
            $difference = round(
                $actualAmount - $breakdown['expected'],
                DailyClosingConstants::MONEY_DECIMALS,
            );

            /** @var \App\Model\Entity\DailyClosing $closing */
            $closing = $table->newEmptyEntity();
            $closing = $table->patchEntity($closing, [
                'closing_date' => $closingDate,
                'initial_balance' => $initialBalance,
                'sales_total' => $breakdown['sales_total'],
                'payments_total' => $breakdown['payments_total'],
                'expenses_total' => $breakdown['expenses_total'],
                'expected_amount' => $breakdown['expected'],
                'actual_amount' => $actualAmount,
                'difference' => $difference,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId > 0 ? $userId : null,
            ]);

            if (!$table->save($closing)) {
                $box = [
                    'success' => false,
                    'errors' => $this->flattenErrors($closing->getErrors()),
                    'closing' => $closing,
                ];

                return false;
            }

            Log::info('Daily closing committed: date={date} expected={e} actual={a} diff={d}', [
                'date' => $closingDate,
                'e' => $breakdown['expected'],
                'a' => $actualAmount,
                'd' => $difference,
                'scope' => ['cash_closes'],
            ]);

            $box = ['success' => true, 'closing' => $closing];

            return true;
        });

        return $box;
    }

    /**
     * Update notes and actual_amount on an existing closing.
     * Expected/sales/payments/expenses snapshots are NEVER recomputed —
     * those captured the day-of state for audit integrity.
     *
     * @param array<string, mixed> $data
     * @return array{success: bool, closing?: \App\Model\Entity\DailyClosing, errors?: array<int, string>}
     */
    public function update(DailyClosing $closing, array $data): array
    {
        $table = $this->fetchTable('DailyClosings');

        $allowed = ['actual_amount', 'notes'];
        $patch = array_intersect_key($data, array_flip($allowed));

        if (array_key_exists('actual_amount', $patch)) {
            $actual = (float)$patch['actual_amount'];
            $patch['difference'] = round(
                $actual - (float)$closing->expected_amount,
                DailyClosingConstants::MONEY_DECIMALS,
            );
        }

        $patched = $table->patchEntity($closing, $patch);
        if (!$table->save($patched)) {
            return [
                'success' => false,
                'errors' => $this->flattenErrors($patched->getErrors()),
                'closing' => $patched,
            ];
        }

        Log::info('Daily closing updated: id={id}', [
            'id' => $patched->id,
            'scope' => ['cash_closes'],
        ]);

        return ['success' => true, 'closing' => $patched];
    }

    /**
     * @return array{success: bool, errors?: array<int, string>}
     */
    public function delete(DailyClosing $closing): array
    {
        $id = $closing->id;
        $date = (string)$closing->closing_date;

        $table = $this->fetchTable('DailyClosings');
        if (!$table->delete($closing)) {
            return ['success' => false, 'errors' => ['No se pudo eliminar el cierre.']];
        }

        Log::warning('Daily closing deleted: id={id} date={date}', [
            'id' => $id,
            'date' => $date,
            'scope' => ['cash_closes'],
        ]);

        return ['success' => true];
    }

    /**
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

        return $flat ?: ['Datos inválidos.'];
    }
}
