<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\ExpenseConstants;
use App\Model\Table\ExpensesTable;
use App\Service\ExpenseService;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\I18n\Date;
use Cake\ORM\Query\SelectQuery;

/**
 * Expenses (Gastos) — Finanzas module. General business outflows.
 */
class ExpensesController extends AppController
{
    /**
     * @var array<string, mixed>
     */
    public array $paginate = [
        'limit' => 15,
        'maxLimit' => 15,
        'order' => [
            'Expenses.expense_date' => 'DESC',
            'Expenses.id' => 'DESC',
        ],
        'sortableFields' => ['expense_date', 'amount'],
    ];

    private ExpenseService $service;
    private ExpensesTable $Expenses;

    public function initialize(): void
    {
        parent::initialize();
        $this->service = new ExpenseService();
        /** @var \App\Model\Table\ExpensesTable $table */
        $table = $this->fetchTable('Expenses');
        $this->Expenses = $table;
    }

    public function index(): void
    {
        $filters = $this->_currentFilters();
        $query = $this->_buildIndexQuery($filters);
        $expenses = $this->paginate($query);
        $kpis = $this->_loadKpis();

        $this->set(compact('expenses', 'filters', 'kpis'));
        $this->set('descriptionSuggestions', ExpenseConstants::DESCRIPTION_SUGGESTIONS);
        $this->set('breadcrumbs', [['label' => 'Gastos']]);
    }

    public function view(int $id): void
    {
        try {
            /** @var \App\Model\Entity\Expense $expense */
            $expense = $this->Expenses->get($id, contain: ['Creator']);
        } catch (RecordNotFoundException $e) {
            throw $e;
        }

        $this->set('expense', $expense);
        $this->set('breadcrumbs', [
            ['label' => 'Gastos', 'url' => ['action' => 'index']],
            ['label' => '#' . $expense->id],
        ]);
    }

    /**
     * @return \Cake\Http\Response|null
     */
    public function add()
    {
        /** @var \App\Model\Entity\Expense $expense */
        $expense = $this->Expenses->newEmptyEntity();
        $expense->expense_date = new Date();

        if ($this->request->is(['post', 'put'])) {
            $identity = $this->Authentication->getIdentity();
            $userId = $identity !== null ? (int)$identity->get('id') : 0;
            $data = (array)$this->request->getData();
            $result = $this->service->create($data, $userId);
            if (!empty($result['success'])) {
                $this->Flash->success('Gasto registrado correctamente.');

                return $this->redirect(['action' => 'index']);
            }
            foreach ($result['errors'] ?? ['No se pudo registrar el gasto.'] as $msg) {
                $this->Flash->error($msg);
            }
            $expense = $result['expense'] ?? $this->Expenses->patchEntity($expense, $data);
        }

        $this->set('expense', $expense);
        $this->set('descriptionSuggestions', ExpenseConstants::DESCRIPTION_SUGGESTIONS);
        $this->set('breadcrumbs', [
            ['label' => 'Gastos', 'url' => ['action' => 'index']],
            ['label' => 'Nuevo gasto'],
        ]);

        return null;
    }

    /**
     * @return \Cake\Http\Response|null
     */
    public function edit(int $id)
    {
        try {
            /** @var \App\Model\Entity\Expense $expense */
            $expense = $this->Expenses->get($id);
        } catch (RecordNotFoundException $e) {
            throw $e;
        }

        if ($this->request->is(['post', 'put', 'patch'])) {
            $data = (array)$this->request->getData();
            $result = $this->service->update($expense, $data);
            if (!empty($result['success'])) {
                $this->Flash->success('Gasto actualizado.');

                return $this->redirect(['action' => 'view', $id]);
            }
            foreach ($result['errors'] ?? ['No se pudo actualizar el gasto.'] as $msg) {
                $this->Flash->error($msg);
            }
            $expense = $result['expense'] ?? $expense;
        }

        $this->set('expense', $expense);
        $this->set('descriptionSuggestions', ExpenseConstants::DESCRIPTION_SUGGESTIONS);
        $this->set('breadcrumbs', [
            ['label' => 'Gastos', 'url' => ['action' => 'index']],
            ['label' => '#' . $expense->id, 'url' => ['action' => 'view', $id]],
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
            /** @var \App\Model\Entity\Expense $expense */
            $expense = $this->Expenses->get($id);
        } catch (RecordNotFoundException) {
            $this->Flash->error('El gasto ya no existe.');

            return $this->redirect(['action' => 'index']);
        }

        $result = $this->service->delete($expense);
        if (!empty($result['success'])) {
            $this->Flash->success('Gasto eliminado.');
        } else {
            $this->Flash->error($result['errors'][0] ?? 'No se pudo eliminar el gasto.');
        }

        return $this->redirect(['action' => 'index']);
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    /**
     * @return array{q: string, from: string, to: string, sort: string, direction: string}
     */
    protected function _currentFilters(): array
    {
        $allowedSort = ['expense_date', 'amount'];
        $allowedDir = ['asc', 'desc'];

        $rawFrom = trim((string)$this->request->getQuery('from', ''));
        $rawTo = trim((string)$this->request->getQuery('to', ''));
        if ($rawFrom !== '' && $rawTo !== '' && strcmp($rawTo, $rawFrom) < 0) {
            $this->Flash->warning('El rango de fechas estaba invertido; se reordenó automáticamente.');
            [$rawFrom, $rawTo] = [$rawTo, $rawFrom];
        }

        $sort = (string)$this->request->getQuery('sort', 'expense_date');
        $direction = strtolower((string)$this->request->getQuery('direction', 'desc'));

        return [
            'q' => trim((string)$this->request->getQuery('q', '')),
            'from' => $rawFrom,
            'to' => $rawTo,
            'sort' => in_array($sort, $allowedSort, true) ? $sort : 'expense_date',
            'direction' => in_array($direction, $allowedDir, true) ? $direction : 'desc',
        ];
    }

    /**
     * @param array{q: string, from: string, to: string, sort: string, direction: string} $filters
     */
    protected function _buildIndexQuery(array $filters): SelectQuery
    {
        $query = $this->Expenses->find()->contain(['Creator']);

        if ($filters['q'] !== '') {
            $query->where([
                'Expenses.description LIKE' => '%' . $filters['q'] . '%',
            ]);
        }
        if ($filters['from'] !== '') {
            $query->where(['Expenses.expense_date >=' => $filters['from']]);
        }
        if ($filters['to'] !== '') {
            $query->where(['Expenses.expense_date <=' => $filters['to']]);
        }

        return $query;
    }

    /**
     * @return array{today: float, month: float, ytd: float}
     */
    protected function _loadKpis(): array
    {
        $today = (new Date())->format('Y-m-d');
        $monthStart = (new Date())->modify('first day of this month')->format('Y-m-d');
        $yearStart = (new Date())->modify('first day of january ' . date('Y'))->format('Y-m-d');

        $f = $this->Expenses->find();
        $sumExpr = $f->func()->sum('Expenses.amount');

        $todayRow = $this->Expenses->find()
            ->select(['s' => $sumExpr])
            ->where(['Expenses.expense_date' => $today])
            ->first();
        $monthRow = $this->Expenses->find()
            ->select(['s' => $sumExpr])
            ->where(['Expenses.expense_date >=' => $monthStart])
            ->first();
        $ytdRow = $this->Expenses->find()
            ->select(['s' => $sumExpr])
            ->where(['Expenses.expense_date >=' => $yearStart])
            ->first();

        return [
            'today' => (float)($todayRow?->s ?? 0),
            'month' => (float)($monthRow?->s ?? 0),
            'ytd' => (float)($ytdRow?->s ?? 0),
        ];
    }
}
