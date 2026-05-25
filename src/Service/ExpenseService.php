<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Expense;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;

final class ExpenseService
{
    use LocatorAwareTrait;

    /**
     * @param array<string, mixed> $data
     * @return array{success: bool, expense?: \App\Model\Entity\Expense, errors?: array<int, string>}
     */
    public function create(array $data, int $userId): array
    {
        $table = $this->fetchTable('Expenses');
        $this->normalizeDescription($data);
        $data['created_by'] = $userId > 0 ? $userId : null;

        /** @var \App\Model\Entity\Expense $expense */
        $expense = $table->newEmptyEntity();
        $expense = $table->patchEntity($expense, $data, ['accessibleFields' => [
            'description' => true,
            'amount' => true,
            'expense_date' => true,
            'created_by' => true,
        ]]);

        if (!$table->save($expense)) {
            return [
                'success' => false,
                'errors' => $this->flattenErrors($expense->getErrors()),
                'expense' => $expense,
            ];
        }

        Log::info('Expense created: id={id} amount={amount} date={date} by={user}', [
            'id' => $expense->id,
            'amount' => $expense->amount,
            'date' => (string)$expense->expense_date,
            'user' => $userId,
            'scope' => ['expenses'],
        ]);

        return ['success' => true, 'expense' => $expense];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{success: bool, expense?: \App\Model\Entity\Expense, errors?: array<int, string>}
     */
    public function update(Expense $expense, array $data): array
    {
        $table = $this->fetchTable('Expenses');
        $this->normalizeDescription($data);

        // created_by is NOT reassignable on update (preserve original author).
        unset($data['created_by']);
        $patched = $table->patchEntity($expense, $data);

        if (!$table->save($patched)) {
            return [
                'success' => false,
                'errors' => $this->flattenErrors($patched->getErrors()),
                'expense' => $patched,
            ];
        }

        Log::info('Expense updated: id={id}', [
            'id' => $patched->id,
            'scope' => ['expenses'],
        ]);

        return ['success' => true, 'expense' => $patched];
    }

    /**
     * @return array{success: bool, errors?: array<int, string>}
     */
    public function delete(Expense $expense): array
    {
        $id = $expense->id;
        $amount = $expense->amount;
        $date = (string)$expense->expense_date;

        $table = $this->fetchTable('Expenses');
        if (!$table->delete($expense)) {
            return ['success' => false, 'errors' => ['No se pudo eliminar el gasto.']];
        }

        Log::warning('Expense deleted: id={id} amount={amount} date={date}', [
            'id' => $id,
            'amount' => $amount,
            'date' => $date,
            'scope' => ['expenses'],
        ]);

        return ['success' => true];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function normalizeDescription(array &$data): void
    {
        if (!array_key_exists('description', $data)) {
            return;
        }
        $raw = $data['description'];
        if (!is_string($raw)) {
            return;
        }
        $collapsed = preg_replace('/\s+/', ' ', trim($raw));
        $data['description'] = $collapsed ?? '';
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
