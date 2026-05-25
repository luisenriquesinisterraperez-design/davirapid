<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\IngredientConstants;
use App\Model\Entity\Ingredient;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;

final class IngredientService
{
    use LocatorAwareTrait;

    /**
     * No dependencies in Phase 1. When Adjustments / Orders consume
     * adjustStock(), their services will inject this service themselves.
     */
    public function __construct()
    {
    }

    /**
     * @param array<string, mixed> $data
     * @return array{success: bool, ingredient?: \App\Model\Entity\Ingredient, errors?: array<int, string>}
     */
    public function create(array $data): array
    {
        $table = $this->fetchTable('Ingredients');
        $this->normalizeName($data);

        /** @var \App\Model\Entity\Ingredient $ingredient */
        $ingredient = $table->newEmptyEntity();
        $ingredient = $table->patchEntity($ingredient, $data);

        if (!$table->save($ingredient)) {
            return [
                'success' => false,
                'errors' => $this->flattenErrors($ingredient->getErrors()),
                'ingredient' => $ingredient,
            ];
        }

        Log::info('Ingredient created: id={id} name={name}', [
            'id' => $ingredient->id,
            'name' => $ingredient->name,
            'scope' => ['ingredients'],
        ]);

        return ['success' => true, 'ingredient' => $ingredient];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{success: bool, ingredient?: \App\Model\Entity\Ingredient, errors?: array<int, string>}
     */
    public function update(Ingredient $ingredient, array $data): array
    {
        $table = $this->fetchTable('Ingredients');
        $this->normalizeName($data);

        $patched = $table->patchEntity($ingredient, $data);

        if (!$table->save($patched)) {
            return [
                'success' => false,
                'errors' => $this->flattenErrors($patched->getErrors()),
                'ingredient' => $patched,
            ];
        }

        Log::info('Ingredient updated: id={id}', [
            'id' => $patched->id,
            'scope' => ['ingredients'],
        ]);

        return ['success' => true, 'ingredient' => $patched];
    }

    /**
     * @return array{success: bool, errors?: array<int, string>}
     */
    public function delete(Ingredient $ingredient): array
    {
        $id = $ingredient->id;
        $name = $ingredient->name;

        $table = $this->fetchTable('Ingredients');
        if (!$table->delete($ingredient)) {
            return ['success' => false, 'errors' => ['No se pudo eliminar el ingrediente.']];
        }

        Log::warning('Ingredient deleted: id={id} name={name}', [
            'id' => $id,
            'name' => $name,
            'scope' => ['ingredients'],
        ]);

        return ['success' => true];
    }

    /**
     * Atomic stock adjustment. Not called by any controller in Phase 1 —
     * defined as the contract for future InventoryAdjustmentService / OrderService.
     *
     * Uses a SELECT ... FOR UPDATE inside a transaction to avoid races when
     * multiple orders decrement the same ingredient.
     *
     * @param string $deltaSigned Signed decimal string (e.g. '+2.500', '-0.250').
     * @return array{success: bool, ingredient?: \App\Model\Entity\Ingredient, new_stock?: string, errors?: array<int, string>}
     */
    public function adjustStock(Ingredient $ingredient, string $deltaSigned, string $reason): array
    {
        $table = $this->fetchTable('Ingredients');
        $connection = ConnectionManager::get('default');

        $resultBox = ['success' => false, 'errors' => ['Error desconocido.']];

        $connection->transactional(function () use ($table, $ingredient, $deltaSigned, $reason, &$resultBox): bool {
            /** @var \App\Model\Entity\Ingredient|null $fresh */
            $fresh = $table->find()
                ->where(['Ingredients.id' => $ingredient->id])
                ->epilog('FOR UPDATE')
                ->first();

            if ($fresh === null) {
                $resultBox = ['success' => false, 'errors' => ['Ingrediente no encontrado.']];

                return false;
            }

            $current = (string)$fresh->stock_quantity;
            $decimals = IngredientConstants::STOCK_DECIMALS;
            $newFloat = round((float)$current + (float)$deltaSigned, $decimals);
            $new = number_format($newFloat, $decimals, '.', '');

            if ($newFloat < 0) {
                $resultBox = [
                    'success' => false,
                    'errors' => [sprintf(
                        'Stock insuficiente para %s (actual %s, requerido %s)',
                        $fresh->name,
                        $current,
                        $deltaSigned,
                    )],
                    'ingredient' => $fresh,
                    'new_stock' => $current,
                ];

                return false;
            }

            $fresh->stock_quantity = $new;
            if (!$table->save($fresh)) {
                $resultBox = [
                    'success' => false,
                    'errors' => $this->flattenErrors($fresh->getErrors()),
                    'ingredient' => $fresh,
                ];

                return false;
            }

            Log::info('Ingredient stock adjusted: id={id} delta={delta} reason={reason} new={new}', [
                'id' => $fresh->id,
                'delta' => $deltaSigned,
                'reason' => $reason,
                'new' => $new,
                'scope' => ['ingredients'],
            ]);

            $resultBox = ['success' => true, 'ingredient' => $fresh, 'new_stock' => $new];

            return true;
        });

        return $resultBox;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function normalizeName(array &$data): void
    {
        if (!array_key_exists('name', $data)) {
            return;
        }
        $raw = $data['name'];
        if (!is_string($raw)) {
            return;
        }
        $collapsed = preg_replace('/\s+/', ' ', trim($raw));
        $data['name'] = $collapsed ?? '';
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
