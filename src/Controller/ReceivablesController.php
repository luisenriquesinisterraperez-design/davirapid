<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\ReceivableConstants;
use App\Model\Table\ReceivablesTable;
use App\Service\ReceivableService;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\ORM\Query\SelectQuery;
use DateTimeImmutable;

/**
 * Receivables (Cuentas por Cobrar) — Finanzas module.
 *
 * Lists, creates manually, marks-paid and deletes CxC. Automatic CxC
 * creation lives in OrderService (re-wired in Phase 5).
 */
class ReceivablesController extends AppController
{
    /**
     * @var array<string, mixed>
     */
    public array $paginate = [
        'limit' => 15,
        'maxLimit' => 15,
        'order' => [
            "CASE WHEN Receivables.status = 'pendiente' THEN 0 ELSE 1 END" => 'ASC',
            'Receivables.created' => 'DESC',
            'Receivables.id' => 'DESC',
        ],
        'sortableFields' => ['created', 'total_amount', 'status'],
    ];

    private ReceivableService $service;
    private ReceivablesTable $Receivables;

    /**
     * Wire the service and explicitly load the table.
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->service = new ReceivableService();
        /** @var \App\Model\Table\ReceivablesTable $table */
        $table = $this->fetchTable('Receivables');
        $this->Receivables = $table;
    }

    /**
     * Custom action `markPaid` requires the `edit` permission.
     */
    protected function _actionToPermission(string $action): string
    {
        return match ($action) {
            'markPaid' => 'edit',
            default => parent::_actionToPermission($action),
        };
    }

    /**
     * Paginated index with KPI strip and filters.
     */
    public function index(): void
    {
        $filters = $this->_currentFilters();
        $query = $this->_buildIndexQuery($filters);
        $receivables = $this->paginate($query);
        $kpis = $this->_loadKpis();
        $customers = $this->fetchTable('Customers')
            ->find('list', ['keyField' => 'id', 'valueField' => 'name'])
            ->orderBy(['Customers.name' => 'ASC'])
            ->toArray();

        $this->set(compact('receivables', 'filters', 'kpis', 'customers'));
        $this->set('statusLabels', ReceivableConstants::STATUS_LABELS);
        $this->set('breadcrumbs', [['label' => 'Cuentas por Cobrar']]);
    }

    /**
     * View a single CxC with linked customer, order, and creator.
     */
    public function view(int $id): void
    {
        try {
            /** @var \App\Model\Entity\Receivable $receivable */
            $receivable = $this->Receivables->get($id, contain: [
                'Customers',
                'Orders',
                'Creator',
                'AccountPayments' => function ($q) {
                    return $q->contain(['Creator'])
                        ->orderBy(['AccountPayments.created' => 'DESC']);
                },
            ]);
        } catch (RecordNotFoundException $e) {
            throw $e;
        }

        // Other open debts of the same customer (excluding this one).
        $otherDebts = $this->Receivables->find()
            ->where([
                'Receivables.customer_id' => $receivable->customer_id,
                'Receivables.id !=' => $receivable->id,
                'Receivables.status' => ReceivableConstants::STATUS_PENDIENTE,
            ])
            ->orderBy(['Receivables.created' => 'DESC'])
            ->limit(5)
            ->all()
            ->toArray();

        $this->set(compact('receivable', 'otherDebts'));
        $this->set('breadcrumbs', [
            ['label' => 'Cuentas por Cobrar', 'url' => ['action' => 'index']],
            ['label' => '#' . $receivable->id],
        ]);
    }

    /**
     * Manual creation.
     *
     * @return \Cake\Http\Response|null
     */
    public function add()
    {
        /** @var \App\Model\Entity\Receivable $receivable */
        $receivable = $this->Receivables->newEmptyEntity();
        $preselectId = (int)$this->request->getQuery('customer_id', 0);
        if ($preselectId > 0) {
            $receivable->customer_id = $preselectId;
        }

        if ($this->request->is(['post', 'put'])) {
            $identity = $this->Authentication->getIdentity();
            $userId = $identity !== null ? (int)$identity->get('id') : 0;
            $data = (array)$this->request->getData();
            $result = $this->service->createManual($data, $userId);
            if (!empty($result['success'])) {
                $this->Flash->success('Cuenta por cobrar creada correctamente.');

                return $this->redirect(['action' => 'index']);
            }
            foreach ($result['errors'] ?? ['No se pudo crear la cuenta.'] as $msg) {
                $this->Flash->error($msg);
            }
            $receivable = $this->Receivables->patchEntity($receivable, $data);
        }

        $customers = $this->fetchTable('Customers')
            ->find('list', ['keyField' => 'id', 'valueField' => 'name'])
            ->where(['Customers.is_active' => true])
            ->orderBy(['Customers.name' => 'ASC'])
            ->toArray();

        $this->set(compact('receivable', 'customers'));
        $this->set('breadcrumbs', [
            ['label' => 'Cuentas por Cobrar', 'url' => ['action' => 'index']],
            ['label' => 'Nueva cuenta'],
        ]);

        return null;
    }

    /**
     * Marks a CxC as paid manually (without registering a real payment).
     *
     * @return \Cake\Http\Response|null
     */
    public function markPaid(int $id)
    {
        $this->request->allowMethod(['post']);

        try {
            /** @var \App\Model\Entity\Receivable $receivable */
            $receivable = $this->Receivables->get($id);
        } catch (RecordNotFoundException) {
            $this->Flash->error('La cuenta ya no existe.');

            return $this->redirect(['action' => 'index']);
        }

        $identity = $this->Authentication->getIdentity();
        $userId = $identity !== null ? (int)$identity->get('id') : 0;
        $result = $this->service->markAsPaid($receivable, $userId);
        if (!empty($result['success'])) {
            $this->Flash->success('La cuenta fue marcada como pagada.');
        } else {
            $this->Flash->error($result['errors'][0] ?? 'No se pudo marcar la cuenta.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Deletes a CxC (blocks if payments registered).
     *
     * @return \Cake\Http\Response|null
     */
    public function delete(int $id)
    {
        $this->request->allowMethod(['post', 'delete']);

        try {
            /** @var \App\Model\Entity\Receivable $receivable */
            $receivable = $this->Receivables->get($id);
        } catch (RecordNotFoundException) {
            $this->Flash->error('La cuenta ya no existe.');

            return $this->redirect(['action' => 'index']);
        }

        $identity = $this->Authentication->getIdentity();
        $userId = $identity !== null ? (int)$identity->get('id') : 0;
        $result = $this->service->delete($receivable, $userId);
        if (!empty($result['success'])) {
            $this->Flash->success('Cuenta eliminada.');
        } else {
            $this->Flash->error($result['errors'][0] ?? 'No se pudo eliminar la cuenta.');
        }

        return $this->redirect(['action' => 'index']);
    }

    // -------------------------------------------------------------------
    // Helpers.
    // -------------------------------------------------------------------

    /**
     * @return array{status: string, customer_id: int, from: string, to: string, q: string}
     */
    protected function _currentFilters(): array
    {
        $rawStatus = (string)$this->request->getQuery('status', ReceivableConstants::STATUS_PENDIENTE);
        $allowedStatuses = array_merge(['all'], ReceivableConstants::STATUSES);
        $status = in_array($rawStatus, $allowedStatuses, true)
            ? $rawStatus
            : ReceivableConstants::STATUS_PENDIENTE;

        $rawFrom = trim((string)$this->request->getQuery('from', ''));
        $rawTo = trim((string)$this->request->getQuery('to', ''));
        if ($rawFrom !== '' && $rawTo !== '' && strcmp($rawTo, $rawFrom) < 0) {
            $this->Flash->warning('El rango de fechas estaba invertido; se reordenó automáticamente.');
            [$rawFrom, $rawTo] = [$rawTo, $rawFrom];
        }

        return [
            'status' => $status,
            'customer_id' => (int)$this->request->getQuery('customer_id', 0),
            'from' => $rawFrom,
            'to' => $rawTo,
            'q' => trim((string)$this->request->getQuery('q', '')),
        ];
    }

    /**
     * @param array{status: string, customer_id: int, from: string, to: string, q: string} $filters
     */
    protected function _buildIndexQuery(array $filters): SelectQuery
    {
        $query = $this->Receivables->find()
            ->contain(['Customers', 'Orders', 'Creator']);

        if ($filters['status'] !== 'all') {
            $query->where(['Receivables.status' => $filters['status']]);
        }
        if ($filters['customer_id'] > 0) {
            $query->where(['Receivables.customer_id' => $filters['customer_id']]);
        }
        if ($filters['from'] !== '') {
            $query->where(['Receivables.created >=' => $filters['from'] . ' 00:00:00']);
        }
        if ($filters['to'] !== '') {
            $query->where(['Receivables.created <=' => $filters['to'] . ' 23:59:59']);
        }
        if ($filters['q'] !== '') {
            $like = '%' . $filters['q'] . '%';
            $query->where([
                'OR' => [
                    'Receivables.description LIKE' => $like,
                    'Customers.name LIKE' => $like,
                    'Customers.phone LIKE' => $like,
                ],
            ]);
        }

        return $query;
    }

    /**
     * KPI strip (global figures, independent of current filters).
     *
     * @return array{total_pending: float, paid_this_month: float, customers_with_debt: int}
     */
    protected function _loadKpis(): array
    {
        $table = $this->Receivables;

        $pendingRow = $table->find()
            ->select(['s' => $table->find()->func()
                ->sum('Receivables.total_amount - Receivables.paid_amount')])
            ->where(['Receivables.status' => ReceivableConstants::STATUS_PENDIENTE])
            ->first();
        $totalPending = (float)($pendingRow?->s ?? 0);

        $now = new DateTimeImmutable();
        $monthStart = $now->format('Y-m-01 00:00:00');
        $paidRow = $table->find()
            ->select(['s' => $table->find()->func()->sum('Receivables.paid_amount')])
            ->where([
                'Receivables.status' => ReceivableConstants::STATUS_PAGADO,
                'Receivables.modified >=' => $monthStart,
            ])
            ->first();
        $paidThisMonth = (float)($paidRow?->s ?? 0);

        $customersWithDebt = $table->find()
            ->select(['Receivables.customer_id'])
            ->where(['Receivables.status' => ReceivableConstants::STATUS_PENDIENTE])
            ->distinct(['Receivables.customer_id'])
            ->all()
            ->count();

        return [
            'total_pending' => $totalPending,
            'paid_this_month' => $paidThisMonth,
            'customers_with_debt' => $customersWithDebt,
        ];
    }
}
