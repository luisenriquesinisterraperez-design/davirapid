<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Delivery;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;

final class DeliveryService
{
    use LocatorAwareTrait;

    public function create(array $data): array
    {
        $table = $this->fetchTable('Deliveries');
        $delivery = $table->newEmptyEntity();
        $delivery = $table->patchEntity($delivery, $data);

        if (!$table->save($delivery)) {
            return [
                'success' => false,
                'errors' => $this->flattenErrors($delivery->getErrors()),
                'delivery' => $delivery,
            ];
        }

        Log::info('Delivery created: id={id} name={name}', [
            'id' => $delivery->id,
            'name' => $delivery->full_name,
            'scope' => ['deliveries'],
        ]);

        return ['success' => true, 'delivery' => $delivery];
    }

    public function update(Delivery $delivery, array $data): array
    {
        $table = $this->fetchTable('Deliveries');
        $patched = $table->patchEntity($delivery, $data);

        if (!$table->save($patched)) {
            return [
                'success' => false,
                'errors' => $this->flattenErrors($patched->getErrors()),
                'delivery' => $patched,
            ];
        }

        return ['success' => true, 'delivery' => $patched];
    }

    public function activate(Delivery $delivery): array
    {
        return $this->setActive($delivery, true);
    }

    public function deactivate(Delivery $delivery): array
    {
        return $this->setActive($delivery, false);
    }

    public function toggleActive(Delivery $delivery): array
    {
        return $this->setActive($delivery, !$delivery->isActive());
    }

    private function setActive(Delivery $delivery, bool $active): array
    {
        $table = $this->fetchTable('Deliveries');
        $delivery->is_active = $active;

        if (!$table->save($delivery)) {
            return [
                'success' => false,
                'errors' => $this->flattenErrors($delivery->getErrors()),
                'delivery' => $delivery,
            ];
        }

        Log::info('Delivery {state}: id={id}', [
            'state' => $active ? 'activated' : 'deactivated',
            'id' => $delivery->id,
            'scope' => ['deliveries'],
        ]);

        return ['success' => true, 'delivery' => $delivery];
    }

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
