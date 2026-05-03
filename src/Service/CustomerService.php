<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Customer;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;

final class CustomerService
{
    use LocatorAwareTrait;

    /**
     * @return array{success: bool, customer?: Customer, errors?: array<string>}
     */
    public function create(array $data): array
    {
        $table = $this->fetchTable('Customers');
        $customer = $table->newEmptyEntity();
        $customer = $table->patchEntity($customer, $data);

        if (!$table->save($customer)) {
            return [
                'success' => false,
                'errors' => $this->flattenErrors($customer->getErrors()),
                'customer' => $customer,
            ];
        }

        Log::info('Customer created: id={id} phone={phone}', [
            'id' => $customer->id,
            'phone' => $customer->phone,
            'scope' => ['customers'],
        ]);

        return ['success' => true, 'customer' => $customer];
    }

    /**
     * @return array{success: bool, customer?: Customer, errors?: array<string>}
     */
    public function update(Customer $customer, array $data): array
    {
        $table = $this->fetchTable('Customers');
        $patched = $table->patchEntity($customer, $data);

        if (!$table->save($patched)) {
            return [
                'success' => false,
                'errors' => $this->flattenErrors($patched->getErrors()),
                'customer' => $patched,
            ];
        }

        return ['success' => true, 'customer' => $patched];
    }

    /**
     * @return array{success: bool, errors?: array<string>}
     */
    public function delete(Customer $customer): array
    {
        $deps = $this->countDependencies($customer);
        $msgs = [];
        if ($deps['orders'] > 0) {
            $msgs[] = "tiene {$deps['orders']} pedido(s)";
        }
        if ($deps['receivables'] > 0) {
            $msgs[] = "tiene {$deps['receivables']} cuenta(s) por cobrar";
        }
        if (!empty($msgs)) {
            return [
                'success' => false,
                'errors' => [
                    'No se puede eliminar el cliente: ' . implode(' y ', $msgs)
                        . '. Desactivalo en su lugar.',
                ],
            ];
        }

        $table = $this->fetchTable('Customers');
        if (!$table->delete($customer)) {
            return ['success' => false, 'errors' => ['No se pudo eliminar el cliente.']];
        }

        Log::info('Customer deleted: id={id} phone={phone}', [
            'id' => $customer->id,
            'phone' => $customer->phone,
            'scope' => ['customers'],
        ]);

        return ['success' => true];
    }

    /**
     * @return array{success: bool, customer?: Customer, errors?: array<string>}
     */
    public function toggleActive(Customer $customer): array
    {
        $customer->is_active = !$customer->is_active;
        $table = $this->fetchTable('Customers');
        if (!$table->save($customer)) {
            return [
                'success' => false,
                'errors' => $this->flattenErrors($customer->getErrors()),
                'customer' => $customer,
            ];
        }
        return ['success' => true, 'customer' => $customer];
    }

    /**
     * @param array{phone: string, name?: string, address?: ?string} $data
     */
    public function findOrCreateByPhone(array $data): Customer
    {
        $phone = (string)($data['phone'] ?? '');
        if ($phone === '') {
            throw new \InvalidArgumentException('phone is required');
        }

        $table = $this->fetchTable('Customers');
        $existing = $table->find('byPhone', ['phone' => $phone])->first();
        if ($existing instanceof Customer) {
            return $existing;
        }

        $customer = $table->newEntity([
            'name' => (string)($data['name'] ?? ''),
            'phone' => $phone,
            'address' => $data['address'] ?? null,
            'is_active' => true,
        ]);

        if (!$table->save($customer)) {
            throw new \RuntimeException(
                'Could not auto-create customer for phone ' . $phone
                    . ': ' . json_encode($customer->getErrors(), JSON_UNESCAPED_UNICODE)
            );
        }

        Log::info('Customer auto-created via findOrCreateByPhone: id={id} phone={phone}', [
            'id' => $customer->id,
            'phone' => $customer->phone,
            'scope' => ['customers'],
        ]);

        return $customer;
    }

    /**
     * @return array{orders: int, receivables: int}
     */
    private function countDependencies(Customer $customer): array
    {
        $connection = ConnectionManager::get('default');
        $existing = $connection->getSchemaCollection()->listTables();

        $orders = 0;
        if (in_array('orders', $existing, true)) {
            try {
                $orders = (int)$connection
                    ->execute('SELECT COUNT(*) AS c FROM orders WHERE customer_id = :id', ['id' => $customer->id])
                    ->fetch('assoc')['c'];
            } catch (\Throwable) {
                $orders = 0;
            }
        }

        $receivables = 0;
        if (in_array('accounts_receivable', $existing, true)) {
            try {
                $receivables = (int)$connection
                    ->execute('SELECT COUNT(*) AS c FROM accounts_receivable WHERE customer_id = :id', ['id' => $customer->id])
                    ->fetch('assoc')['c'];
            } catch (\Throwable) {
                $receivables = 0;
            }
        }

        return ['orders' => $orders, 'receivables' => $receivables];
    }

    /**
     * @return array<string>
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
