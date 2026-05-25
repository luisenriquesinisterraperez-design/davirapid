<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\InventoryAdjustmentConstants;
use App\Model\Table\IngredientsTable;
use App\Model\Table\InventoryAdjustmentsTable;
use App\Service\InventoryAdjustmentService;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\ORM\Query\SelectQuery;

/**
 * Adjustments (Ajustes de Inventario) — append-only ledger of stock movements.
 *
 * Controller name `Adjustments` does NOT inflect to the real table
 * `inventory_adjustments`, so the table is loaded explicitly via
 * `$this->fetchTable('InventoryAdjustments')` and the templates live in
 * `templates/Adjustments/` (matching the controller name).
 */
class AdjustmentsController extends AppController
{
    /**
     * @var array<string, mixed>
     */
    public array $paginate = [
        'limit' => 15,
        'maxLimit' => 15,
        'order' => [
            'InventoryAdjustments.created' => 'DESC',
            'InventoryAdjustments.id' => 'DESC',
        ],
        'sortableFields' => ['created', 'type'],
    ];

    private InventoryAdjustmentService $adjustmentService;
    private InventoryAdjustmentsTable $InventoryAdjustments;
    private IngredientsTable $Ingredients;

    /**
     * Wires services and explicitly loads tables whose names do not match the controller.
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->adjustmentService = new InventoryAdjustmentService();
        /** @var \App\Model\Table\InventoryAdjustmentsTable $ia */
        $ia = $this->fetchTable('InventoryAdjustments');
        $this->InventoryAdjustments = $ia;
        /** @var \App\Model\Table\IngredientsTable $ing */
        $ing = $this->fetchTable('Ingredients');
        $this->Ingredients = $ing;
    }

    /**
     * Paginated chronological listing with ingredient/type/date filters.
     */
    public function index(): void
    {
        $filters = $this->_currentFilters();
        $query = $this->_buildIndexQuery($filters);
        $adjustments = $this->paginate($query);
        $ingredients = $this->Ingredients->find('nameList')->toArray();

        $this->set(compact('adjustments', 'filters', 'ingredients'));
        $this->set('breadcrumbs', [['label' => 'Ajustes de Inventario']]);
    }

    /**
     * Form to register a new adjustment.
     *
     * @return \Cake\Http\Response|null
     */
    public function add()
    {
        /** @var \App\Model\Entity\InventoryAdjustment $adjustment */
        $adjustment = $this->InventoryAdjustments->newEmptyEntity();
        $preselectId = (int)$this->request->getQuery('ingredient_id', 0);
        if ($preselectId > 0) {
            $adjustment->ingredient_id = $preselectId;
        }

        if ($this->request->is(['post', 'put'])) {
            $identity = $this->Authentication->getIdentity();
            $userId = $identity !== null ? (int)$identity->get('id') : 0;
            $data = (array)$this->request->getData();
            $result = $this->adjustmentService->create($data, $userId);
            if ($result['success']) {
                $this->Flash->success('Ajuste registrado correctamente.');

                return $this->redirect(['action' => 'index']);
            }
            foreach ($result['errors'] ?? ['No se pudo registrar el ajuste.'] as $msg) {
                $this->Flash->error($msg);
            }
            $adjustment = $result['adjustment']
                ?? $this->InventoryAdjustments->patchEntity($adjustment, $data);
        }

        $ingredients = $this->Ingredients->find('nameList')->toArray();
        $ingredientsMeta = $this->Ingredients->find()
            ->select(['Ingredients.id', 'Ingredients.unit', 'Ingredients.stock_quantity'])
            ->all()
            ->indexBy('id')
            ->toArray();

        $this->set(compact('adjustment', 'ingredients', 'ingredientsMeta'));
        $this->set('reasonSuggestions', InventoryAdjustmentConstants::REASON_SUGGESTIONS);
        $this->set('breadcrumbs', [
            ['label' => 'Ajustes de Inventario', 'url' => ['action' => 'index']],
            ['label' => 'Nuevo ajuste'],
        ]);

        return null;
    }

    /**
     * Reverses an adjustment's stock impact and deletes the row.
     *
     * @return \Cake\Http\Response|null
     */
    public function delete(int $id)
    {
        $this->request->allowMethod(['post', 'delete']);

        try {
            /** @var \App\Model\Entity\InventoryAdjustment $adjustment */
            $adjustment = $this->InventoryAdjustments->get($id, contain: ['Ingredients']);
        } catch (RecordNotFoundException) {
            $this->Flash->error('El ajuste ya no existe.');

            return $this->redirect(['action' => 'index']);
        }

        $result = $this->adjustmentService->delete($adjustment);
        if ($result['success']) {
            $this->Flash->success('Ajuste revertido y eliminado.');
        } else {
            $this->Flash->error($result['errors'][0] ?? 'No se pudo eliminar el ajuste.');
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * @return array{ingredient_id: int, type: string, from: string, to: string, sort: string, direction: string}
     */
    protected function _currentFilters(): array
    {
        $allowedType = [
            'all',
            InventoryAdjustmentConstants::TYPE_ENTRY,
            InventoryAdjustmentConstants::TYPE_BAJA,
        ];
        $allowedSort = ['created', 'type'];
        $allowedDir = ['asc', 'desc'];

        $rawFrom = trim((string)$this->request->getQuery('from', ''));
        $rawTo = trim((string)$this->request->getQuery('to', ''));

        if ($rawFrom !== '' && $rawTo !== '' && strcmp($rawTo, $rawFrom) < 0) {
            $this->Flash->warning('El rango de fechas estaba invertido; se reordenó automáticamente.');
            [$rawFrom, $rawTo] = [$rawTo, $rawFrom];
        }

        $type = (string)$this->request->getQuery('type', 'all');
        $sort = (string)$this->request->getQuery('sort', 'created');
        $direction = strtolower((string)$this->request->getQuery('direction', 'desc'));

        return [
            'ingredient_id' => (int)$this->request->getQuery('ingredient_id', 0),
            'type' => in_array($type, $allowedType, true) ? $type : 'all',
            'from' => $rawFrom,
            'to' => $rawTo,
            'sort' => in_array($sort, $allowedSort, true) ? $sort : 'created',
            'direction' => in_array($direction, $allowedDir, true) ? $direction : 'desc',
        ];
    }

    /**
     * @param array{ingredient_id: int, type: string, from: string, to: string, sort: string, direction: string} $filters
     */
    protected function _buildIndexQuery(array $filters): SelectQuery
    {
        $query = $this->InventoryAdjustments->find()
            ->contain(['Ingredients', 'Users']);

        if ($filters['ingredient_id'] > 0) {
            $query->where(['InventoryAdjustments.ingredient_id' => $filters['ingredient_id']]);
        }
        if ($filters['type'] !== 'all') {
            $query->where(['InventoryAdjustments.type' => $filters['type']]);
        }
        if ($filters['from'] !== '') {
            $query->where(['InventoryAdjustments.created >=' => $filters['from'] . ' 00:00:00']);
        }
        if ($filters['to'] !== '') {
            $query->where(['InventoryAdjustments.created <=' => $filters['to'] . ' 23:59:59']);
        }

        return $query;
    }
}
