<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Table\DailyClosingsTable;
use App\Service\DailyClosingService;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\I18n\Date;
use Cake\ORM\Query\SelectQuery;

/**
 * Cash closes (Cierre Diario) — Finanzas module.
 */
class CashClosesController extends AppController
{
    /**
     * @var array<string, mixed>
     */
    public array $paginate = [
        'limit' => 15,
        'maxLimit' => 15,
        'order' => [
            'DailyClosings.closing_date' => 'DESC',
            'DailyClosings.id' => 'DESC',
        ],
        'sortableFields' => ['closing_date', 'expected_amount', 'actual_amount', 'difference'],
    ];

    private DailyClosingService $service;
    private DailyClosingsTable $DailyClosings;

    public function initialize(): void
    {
        parent::initialize();
        $this->service = new DailyClosingService();
        /** @var \App\Model\Table\DailyClosingsTable $table */
        $table = $this->fetchTable('DailyClosings');
        $this->DailyClosings = $table;
    }

    /**
     * `preview` and `index` need view perm; `add` needs create.
     */
    protected function _actionToPermission(string $action): string
    {
        return match ($action) {
            'preview' => 'view',
            default => parent::_actionToPermission($action),
        };
    }

    public function index(): void
    {
        $filters = $this->_currentFilters();
        $query = $this->_buildIndexQuery($filters);
        $closings = $this->paginate($query);

        $this->set(compact('closings', 'filters'));
        $this->set('breadcrumbs', [['label' => 'Cierre Diario']]);
    }

    public function view(int $id): void
    {
        try {
            /** @var \App\Model\Entity\DailyClosing $closing */
            $closing = $this->DailyClosings->get($id, contain: ['Creator']);
        } catch (RecordNotFoundException $e) {
            throw $e;
        }

        $this->set('closing', $closing);
        $this->set('breadcrumbs', [
            ['label' => 'Cierre Diario', 'url' => ['action' => 'index']],
            ['label' => $closing->getFormattedDate()],
        ]);
    }

    /**
     * AJAX endpoint: returns the computed expected breakdown for a date.
     *
     * @return \Cake\Http\Response
     */
    public function preview()
    {
        $date = (string)$this->request->getQuery('date', (new Date())->format('Y-m-d'));
        $initial = (float)$this->request->getQuery('initial_balance', '0');
        $breakdown = $this->service->computeExpected($date, $initial);

        return $this->response
            ->withType('application/json')
            ->withStringBody((string)json_encode($breakdown));
    }

    /**
     * @return \Cake\Http\Response|null
     */
    public function add()
    {
        /** @var \App\Model\Entity\DailyClosing $closing */
        $closing = $this->DailyClosings->newEmptyEntity();
        $today = (new Date())->format('Y-m-d');
        $closing->closing_date = new Date($today);

        if ($this->request->is(['post', 'put'])) {
            $identity = $this->Authentication->getIdentity();
            $userId = $identity !== null ? (int)$identity->get('id') : 0;
            $data = (array)$this->request->getData();
            $result = $this->service->create($data, $userId);
            if (!empty($result['success'])) {
                $this->Flash->success('Cierre diario guardado correctamente.');

                return $this->redirect(['action' => 'view', $result['closing']->id]);
            }
            foreach ($result['errors'] ?? ['No se pudo guardar el cierre.'] as $msg) {
                $this->Flash->error($msg);
            }
            $closing = $result['closing'] ?? $this->DailyClosings->patchEntity($closing, $data);
        }

        // Pre-compute breakdown for the default date so the form opens populated.
        $previewDate = (string)($closing->closing_date instanceof Date
            ? $closing->closing_date->format('Y-m-d')
            : $today);
        $preview = $this->service->computeExpected(
            $previewDate,
            (float)($closing->initial_balance ?? 0),
        );

        $this->set(compact('closing', 'preview'));
        $this->set('breadcrumbs', [
            ['label' => 'Cierre Diario', 'url' => ['action' => 'index']],
            ['label' => 'Nuevo cierre'],
        ]);

        return null;
    }

    /**
     * @return \Cake\Http\Response|null
     */
    public function edit(int $id)
    {
        try {
            /** @var \App\Model\Entity\DailyClosing $closing */
            $closing = $this->DailyClosings->get($id);
        } catch (RecordNotFoundException $e) {
            throw $e;
        }

        if ($this->request->is(['post', 'put', 'patch'])) {
            $data = (array)$this->request->getData();
            $result = $this->service->update($closing, $data);
            if (!empty($result['success'])) {
                $this->Flash->success('Cierre actualizado.');

                return $this->redirect(['action' => 'view', $id]);
            }
            foreach ($result['errors'] ?? ['No se pudo actualizar el cierre.'] as $msg) {
                $this->Flash->error($msg);
            }
            $closing = $result['closing'] ?? $closing;
        }

        $this->set('closing', $closing);
        $this->set('breadcrumbs', [
            ['label' => 'Cierre Diario', 'url' => ['action' => 'index']],
            ['label' => $closing->getFormattedDate(), 'url' => ['action' => 'view', $id]],
            ['label' => 'Editar'],
        ]);

        return null;
    }

    /**
     * @return \Cake\Http\Response|null
     */
    public function delete(int $id)
    {
        $this->request->allowMethod(['post', 'delete']);

        try {
            /** @var \App\Model\Entity\DailyClosing $closing */
            $closing = $this->DailyClosings->get($id);
        } catch (RecordNotFoundException) {
            $this->Flash->error('El cierre ya no existe.');

            return $this->redirect(['action' => 'index']);
        }

        $result = $this->service->delete($closing);
        if (!empty($result['success'])) {
            $this->Flash->success('Cierre eliminado.');
        } else {
            $this->Flash->error($result['errors'][0] ?? 'No se pudo eliminar el cierre.');
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * @return array{from: string, to: string}
     */
    protected function _currentFilters(): array
    {
        $rawFrom = trim((string)$this->request->getQuery('from', ''));
        $rawTo = trim((string)$this->request->getQuery('to', ''));
        if ($rawFrom !== '' && $rawTo !== '' && strcmp($rawTo, $rawFrom) < 0) {
            $this->Flash->warning('El rango de fechas estaba invertido; se reordenó automáticamente.');
            [$rawFrom, $rawTo] = [$rawTo, $rawFrom];
        }

        return ['from' => $rawFrom, 'to' => $rawTo];
    }

    /**
     * @param array{from: string, to: string} $filters
     */
    protected function _buildIndexQuery(array $filters): SelectQuery
    {
        $query = $this->DailyClosings->find()->contain(['Creator']);
        if ($filters['from'] !== '') {
            $query->where(['DailyClosings.closing_date >=' => $filters['from']]);
        }
        if ($filters['to'] !== '') {
            $query->where(['DailyClosings.closing_date <=' => $filters['to']]);
        }

        return $query;
    }
}
