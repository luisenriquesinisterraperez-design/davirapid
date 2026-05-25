<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InventoryAdjustmentConstants;
use App\Model\Entity\InventoryAdjustment;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * InventoryAdjustmentService — append-only orchestration of stock movements.
 *
 * No `update` method by design: a correction is recorded as a new adjustment
 * of opposite sign. Every mutation of `ingredients.stock_quantity` is delegated
 * to `IngredientService::adjustStock()` so the FOR UPDATE lock stays centralized.
 */
final class InventoryAdjustmentService
{
    use LocatorAwareTrait;

    private IngredientService $ingredients;

    /**
     * @param \App\Service\IngredientService|null $ingredients Injected for testing; defaults to a fresh instance.
     */
    public function __construct(?IngredientService $ingredients = null)
    {
        $this->ingredients = $ingredients ?? new IngredientService();
    }

    /**
     * Creates an inventory adjustment and moves the ingredient stock atomically.
     *
     * @param array<string, mixed> $data
     * @return array{success: bool, adjustment?: \App\Model\Entity\InventoryAdjustment, errors?: array<int, string>}
     */
    public function create(array $data, int $userId): array
    {
        $validation = $this->validateInput($data);
        if ($validation !== []) {
            return ['success' => false, 'errors' => $validation];
        }

        $ingredientId = (int)$data['ingredient_id'];
        $type = (string)$data['type'];
        $quantity = (string)$data['quantity'];
        $reason = trim((string)$data['reason']);
        $notes = isset($data['notes']) && trim((string)$data['notes']) !== ''
            ? (string)$data['notes']
            : null;

        $adjustmentsTable = $this->fetchTable('InventoryAdjustments');
        $ingredientsTable = $this->fetchTable('Ingredients');

        /** @var \App\Model\Entity\Ingredient|null $ingredient */
        $ingredient = $ingredientsTable->find()
            ->where(['Ingredients.id' => $ingredientId])
            ->first();

        if ($ingredient === null) {
            return ['success' => false, 'errors' => ['Ingrediente no encontrado.']];
        }

        $resultBox = ['success' => false, 'errors' => ['Error desconocido.']];
        $connection = ConnectionManager::get('default');

        $connection->transactional(function () use (
            $adjustmentsTable,
            $ingredient,
            $ingredientId,
            $type,
            $quantity,
            $reason,
            $notes,
            $userId,
            &$resultBox,
        ): bool {
            /** @var \App\Model\Entity\InventoryAdjustment $adjustment */
            $adjustment = $adjustmentsTable->newEntity([
                'ingredient_id' => $ingredientId,
                'type' => $type,
                'quantity' => $quantity,
                'reason' => $reason,
                'notes' => $notes,
                'user_id' => $userId > 0 ? $userId : null,
            ]);

            if (!$adjustmentsTable->save($adjustment)) {
                $resultBox = [
                    'success' => false,
                    'errors' => $this->flattenErrors($adjustment->getErrors()),
                    'adjustment' => $adjustment,
                ];

                return false;
            }

            // Hydrate the association so getSignedDelta() / getFormattedQuantity() work.
            $adjustment->ingredient = $ingredient;

            $stockResult = $this->ingredients->adjustStock(
                $ingredient,
                $adjustment->getSignedDelta(),
                sprintf('Ajuste #%d: %s', $adjustment->id, $adjustment->reason),
            );

            if (empty($stockResult['success'])) {
                $resultBox = [
                    'success' => false,
                    'errors' => $stockResult['errors'] ?? ['No se pudo mover el stock.'],
                ];

                return false;
            }

            Log::info('Inventory adjustment created: id={id} ingredient={ing} type={t} qty={q}', [
                'id' => $adjustment->id,
                'ing' => $ingredient->id,
                't' => $adjustment->type,
                'q' => $adjustment->quantity,
                'scope' => ['adjustments'],
            ]);

            $resultBox = ['success' => true, 'adjustment' => $adjustment];

            return true;
        });

        return $resultBox;
    }

    /**
     * Reverts the adjustment's stock impact and deletes the row atomically.
     * Fails when the reverse delta would push stock below zero.
     *
     * @return array{success: bool, errors?: array<int, string>}
     */
    public function delete(InventoryAdjustment $adjustment): array
    {
        $adjustmentsTable = $this->fetchTable('InventoryAdjustments');
        $ingredientsTable = $this->fetchTable('Ingredients');

        /** @var \App\Model\Entity\Ingredient|null $ingredient */
        $ingredient = $ingredientsTable->find()
            ->where(['Ingredients.id' => $adjustment->ingredient_id])
            ->first();

        if ($ingredient === null) {
            return ['success' => false, 'errors' => ['Ingrediente no encontrado.']];
        }

        // Hydrate so getReverseDelta()/getFormattedQuantity() have unit context.
        $adjustment->ingredient = $ingredient;

        $resultBox = ['success' => false, 'errors' => ['Error desconocido.']];
        $connection = ConnectionManager::get('default');

        $connection->transactional(function () use (
            $adjustmentsTable,
            $adjustment,
            $ingredient,
            &$resultBox,
        ): bool {
            $reverse = $adjustment->getReverseDelta();
            $stockResult = $this->ingredients->adjustStock(
                $ingredient,
                $reverse,
                sprintf('Reversión del ajuste #%d', $adjustment->id),
            );

            if (empty($stockResult['success'])) {
                $msg = sprintf(
                    'No se puede eliminar el ajuste: revertir bajaría el stock de %s.'
                    . ' Registrá un ajuste compensatorio primero.',
                    $ingredient->name,
                );
                $resultBox = ['success' => false, 'errors' => [$msg]];

                return false;
            }

            if (!$adjustmentsTable->delete($adjustment)) {
                $resultBox = ['success' => false, 'errors' => ['No se pudo eliminar el ajuste.']];

                return false;
            }

            Log::warning('Inventory adjustment reversed: id={id} ingredient={ing} reverse_delta={d}', [
                'id' => $adjustment->id,
                'ing' => $ingredient->id,
                'd' => $reverse,
                'scope' => ['adjustments'],
            ]);

            $resultBox = ['success' => true];

            return true;
        });

        return $resultBox;
    }

    /**
     * Defensive pre-DB validation of the raw input array. Returns a flat list
     * of error messages (empty when input is valid).
     *
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    private function validateInput(array $data): array
    {
        $errors = [];

        $ingredientId = (int)($data['ingredient_id'] ?? 0);
        if ($ingredientId <= 0) {
            $errors[] = 'El ingrediente es requerido.';
        }

        $type = (string)($data['type'] ?? '');
        if (!in_array($type, InventoryAdjustmentConstants::TYPES, true)) {
            $errors[] = 'Tipo inválido.';
        }

        $rawQty = $data['quantity'] ?? null;
        if ($rawQty === null || $rawQty === '' || !is_numeric($rawQty) || (float)$rawQty <= 0) {
            $errors[] = 'La cantidad debe ser mayor a 0.';
        }

        $reason = trim((string)($data['reason'] ?? ''));
        if ($reason === '') {
            $errors[] = 'El motivo es requerido.';
        } elseif (mb_strlen($reason) > InventoryAdjustmentConstants::REASON_MAX_LENGTH) {
            $errors[] = 'El motivo no puede exceder 120 caracteres.';
        }

        return $errors;
    }

    /**
     * Flattens the nested error tree from patchEntity() into a flat list of messages.
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
