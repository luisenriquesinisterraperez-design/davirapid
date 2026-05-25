<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\IngredientConstants;
use App\Constants\RecipeConstants;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * RecipeService — operaciones del dominio Receta (líneas product_ingredients).
 */
final class RecipeService
{
    use LocatorAwareTrait;

    private IngredientService $ingredients;

    /**
     * @param \App\Service\IngredientService|null $ingredients Reused for in-transaction cost updates.
     */
    public function __construct(?IngredientService $ingredients = null)
    {
        $this->ingredients = $ingredients ?? new IngredientService();
    }

    /**
     * Agrega o sobreescribe una línea de receta. Si update_ingredient_cost es
     * true, actualiza el costo unitario del ingrediente en la misma transacción.
     *
     * @param array<string, mixed> $data
     * @return array{success: bool, line?: \App\Model\Entity\ProductIngredient, errors?: array<int, string>}
     */
    public function addLine(array $data): array
    {
        $productId = (int)($data['product_id'] ?? 0);
        $ingredientId = (int)($data['ingredient_id'] ?? 0);
        $quantity = (string)($data['quantity'] ?? '');
        $updateCost = !empty($data['update_ingredient_cost']);
        $newCost = $data['new_unit_cost'] ?? null;

        // Pre-validar new_unit_cost cuando se solicita actualizar el costo.
        if ($updateCost) {
            if ($newCost === null || $newCost === '') {
                return [
                    'success' => false,
                    'errors' => ['Si vas a actualizar el costo, ingresá el nuevo valor.'],
                ];
            }
            if (!is_numeric($newCost)) {
                return [
                    'success' => false,
                    'errors' => ['El nuevo costo debe ser numérico.'],
                ];
            }
            if ((float)$newCost < 0) {
                return [
                    'success' => false,
                    'errors' => ['El nuevo costo no puede ser negativo.'],
                ];
            }
        }

        $piTable = $this->fetchTable('ProductIngredients');
        $ingredientsTable = $this->fetchTable('Ingredients');

        /** @var \App\Model\Entity\ProductIngredient|null $existing */
        $existing = $piTable->find()
            ->where([
                'ProductIngredients.product_id' => $productId,
                'ProductIngredients.ingredient_id' => $ingredientId,
            ])
            ->first();

        if ($existing !== null) {
            /** @var \App\Model\Entity\ProductIngredient $line */
            $line = $piTable->patchEntity($existing, ['quantity' => $quantity]);
        } else {
            /** @var \App\Model\Entity\ProductIngredient $line */
            $line = $piTable->newEntity([
                'product_id' => $productId,
                'ingredient_id' => $ingredientId,
                'quantity' => $quantity,
            ]);
        }

        $resultBox = ['success' => false, 'errors' => ['Error desconocido.']];
        $connection = ConnectionManager::get('default');

        $connection->transactional(
            function () use (
                $piTable,
                $ingredientsTable,
                $line,
                $ingredientId,
                $updateCost,
                $newCost,
                &$resultBox,
            ): bool {
                if ($updateCost) {
                    /** @var \App\Model\Entity\Ingredient|null $ingredient */
                    $ingredient = $ingredientsTable->find()
                        ->where(['Ingredients.id' => $ingredientId])
                        ->first();
                    if ($ingredient === null) {
                        $resultBox = [
                            'success' => false,
                            'errors' => ['Ingrediente no encontrado.'],
                        ];

                        return false;
                    }
                    $upd = $this->ingredients->update($ingredient, ['unit_cost' => (string)$newCost]);
                    if (!$upd['success']) {
                        $resultBox = [
                            'success' => false,
                            'errors' => $upd['errors'] ?? ['No se pudo actualizar el costo.'],
                        ];

                        return false;
                    }
                }

                if (!$piTable->save($line)) {
                    $resultBox = [
                        'success' => false,
                        'errors' => $this->flattenErrors($line->getErrors()),
                        'line' => $line,
                    ];

                    return false;
                }

                $resultBox = ['success' => true];

                return true;
            },
        );

        if (empty($resultBox['success'])) {
            return $resultBox;
        }

        /** @var \App\Model\Entity\ProductIngredient $hydrated */
        $hydrated = $piTable->get($line->id, ['contain' => ['Ingredients']]);

        Log::info('Recipe line saved: product={product_id} ingredient={ingredient_id} qty={qty} cost_updated={cu}', [
            'product_id' => $hydrated->product_id,
            'ingredient_id' => $hydrated->ingredient_id,
            'qty' => $hydrated->quantity,
            'cu' => $updateCost ? 'true' : 'false',
            'scope' => ['recipes'],
        ]);

        return ['success' => true, 'line' => $hydrated];
    }

    /**
     * Actualiza solo la cantidad de una línea existente.
     *
     * @return array{success: bool, line?: \App\Model\Entity\ProductIngredient, errors?: array<int, string>}
     */
    public function updateLine(int $lineId, string|float $quantity): array
    {
        $piTable = $this->fetchTable('ProductIngredients');

        try {
            /** @var \App\Model\Entity\ProductIngredient $line */
            $line = $piTable->get($lineId);
        } catch (RecordNotFoundException) {
            return ['success' => false, 'errors' => ['La línea de receta no existe.']];
        }

        /** @var \App\Model\Entity\ProductIngredient $patched */
        $patched = $piTable->patchEntity($line, ['quantity' => (string)$quantity]);

        if (!$piTable->save($patched)) {
            return [
                'success' => false,
                'errors' => $this->flattenErrors($patched->getErrors()),
                'line' => $patched,
            ];
        }

        Log::info('Recipe line updated: id={id} product={pid} ingredient={iid} qty={qty}', [
            'id' => $patched->id,
            'pid' => $patched->product_id,
            'iid' => $patched->ingredient_id,
            'qty' => $patched->quantity,
            'scope' => ['recipes'],
        ]);

        /** @var \App\Model\Entity\ProductIngredient $hydrated */
        $hydrated = $piTable->get($patched->id, ['contain' => ['Ingredients']]);

        return ['success' => true, 'line' => $hydrated];
    }

    /**
     * Borra una línea. No mueve stock (la receta es declarativa).
     *
     * @return array{success: bool, errors?: array<int, string>}
     */
    public function removeLine(int $lineId): array
    {
        $piTable = $this->fetchTable('ProductIngredients');

        try {
            /** @var \App\Model\Entity\ProductIngredient $line */
            $line = $piTable->get($lineId);
        } catch (RecordNotFoundException) {
            return ['success' => false, 'errors' => ['La línea de receta no existe.']];
        }

        $pid = $line->product_id;
        $iid = $line->ingredient_id;

        if (!$piTable->delete($line)) {
            return ['success' => false, 'errors' => ['No se pudo eliminar la línea de receta.']];
        }

        Log::warning('Recipe line removed: product={pid} ingredient={iid}', [
            'pid' => $pid,
            'iid' => $iid,
            'scope' => ['recipes'],
        ]);

        return ['success' => true];
    }

    /**
     * Devuelve la receta del producto con ingredientes hidratados.
     *
     * @return list<\App\Model\Entity\ProductIngredient>
     */
    public function getRecipeFor(int $productId): array
    {
        /** @var list<\App\Model\Entity\ProductIngredient> $lines */
        $lines = $this->fetchTable('ProductIngredients')
            ->find('forProduct', product_id: $productId)
            ->toList();

        return $lines;
    }

    /**
     * Costo total de la receta = suma de costos por línea.
     */
    public function calculateRecipeCost(int $productId): float
    {
        $total = 0.0;
        foreach ($this->getRecipeFor($productId) as $line) {
            $total += $line->getLineCost();
        }

        return round($total, IngredientConstants::COST_DECIMALS);
    }

    /**
     * ¿El producto tiene al menos una línea de receta?
     */
    public function hasRecipe(int $productId): bool
    {
        return $this->fetchTable('ProductIngredients')
            ->exists(['product_id' => $productId]);
    }

    /**
     * Plan de descuento de inventario para vender N unidades del producto.
     * Contrato consumido por el futuro OrderService.
     *
     * @return list<array{ingredient_id: int, quantity: string}>
     */
    public function buildDecrementPlan(int $productId, int $unitsSold): array
    {
        if ($unitsSold <= 0) {
            return [];
        }
        $lines = $this->getRecipeFor($productId);
        if ($lines === []) {
            return [];
        }

        $plan = [];
        foreach ($lines as $line) {
            $qty = round((float)$line->quantity * $unitsSold, RecipeConstants::QUANTITY_DECIMALS);
            $plan[] = [
                'ingredient_id' => (int)$line->ingredient_id,
                'quantity' => number_format($qty, RecipeConstants::QUANTITY_DECIMALS, '.', ''),
            ];
        }

        return $plan;
    }

    /**
     * Aplana el árbol de errores de patchEntity en una lista plana de mensajes.
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
