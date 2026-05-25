<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\AccountPaymentConstants;
use App\Constants\ReceivableConstants;
use App\Model\Entity\Receivable;
use App\Model\Table\AccountPaymentsTable;
use App\Service\AccountPaymentService;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\I18n\DateTime;
use Cake\ORM\Query\SelectQuery;

/**
 * AccountPayments (Abonos) — Finanzas module.
 *
 * Lists, creates and deletes abonos. There is no `edit` action:
 * AccountPayments are append-only (mistakes are corrected by deleting
 * and re-creating). There is no `view` action either: the per-CxC
 * timeline lives in ReceivablesController::view, and the global recent
 * list lives in index.
 */
class AccountPaymentsController extends AppController
{
    /**
     * @var array<string, mixed>
     */
    public array $paginate = [
        'limit' => 15,
        'maxLimit' => 15,
        'order' => [
            'AccountPayments.created' => 'DESC',
            'AccountPayments.id' => 'DESC',
        ],
        'sortableFields' => ['created', 'amount', 'payment_method'],
    ];

    private AccountPaymentService $service;
    private AccountPaymentsTable $AccountPayments;

    /**
     * Wire the service and explicitly load the table (PSR naming).
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->service = new AccountPaymentService();
        /** @var \App\Model\Table\AccountPaymentsTable $table */
        $table = $this->fetchTable('AccountPayments');
        $this->AccountPayments = $table;
    }

    /**
     * Paginated index with KPI strip + multi-field filters.
     */
    public function index(): void
    {
        $filters = $this->_currentFilters();
        $query = $this->_buildIndexQuery($filters);
        $payments = $this->paginate($query);
        $kpis = $this->_loadKpis();
        $customers = $this->fetchTable('Customers')
            ->find('list', ['keyField' => 'id', 'valueField' => 'name'])
            ->orderBy(['Customers.name' => 'ASC'])
            ->toArray();

        $this->set(compact('payments', 'filters', 'kpis', 'customers'));
        $this->set('paymentMethods', AccountPaymentConstants::PAYMENT_LABELS);
        $this->set('breadcrumbs', [['label' => 'Abonos']]);
    }

    /**
     * Form to register a new abono. Accepts ?receivable_id=X for preselection.
     *
     * @return \Cake\Http\Response|null
     */
    public function add()
    {
        /** @var \App\Model\Entity\AccountPayment $payment */
        $payment = $this->AccountPayments->newEmptyEntity();
        $preselectId = (int)$this->request->getQuery('receivable_id', 0);
        $hint = null;

        if ($preselectId > 0) {
            $payment->receivable_id = $preselectId;
            /** @var \App\Model\Entity\Receivable|null $preselected */
            $preselected = $this->fetchTable('Receivables')->find()
                ->where(['Receivables.id' => $preselectId])
                ->contain(['Customers'])
                ->first();
            if ($preselected instanceof Receivable && $preselected->isPending()) {
                $hint = [
                    'receivable' => $preselected,
                    'balance' => $preselected->getBalance(),
                ];
            }
        }

        if ($this->request->is(['post', 'put'])) {
            $identity = $this->Authentication->getIdentity();
            $userId = $identity !== null ? (int)$identity->get('id') : 0;
            $data = (array)$this->request->getData();
            $result = $this->service->create($data, $userId);
            if (!empty($result['success'])) {
                /** @var \App\Model\Entity\Receivable|null $rec */
                $rec = $result['receivable'] ?? null;
                $balance = $rec !== null ? $rec->getBalance() : 0.0;
                $message = $balance <= AccountPaymentConstants::EPSILON
                    ? 'Abono registrado. La cuenta ha sido marcada como pagada.'
                    : sprintf(
                        'Abono registrado. Saldo restante: $%s.',
                        number_format($balance, 2, ',', '.'),
                    );
                $this->Flash->success($message);

                $redirectId = $rec !== null ? (int)$rec->id : $preselectId;
                if ($redirectId > 0) {
                    return $this->redirect([
                        'controller' => 'Receivables', 'action' => 'view', $redirectId,
                    ]);
                }

                return $this->redirect(['action' => 'index']);
            }
            foreach ($result['errors'] ?? ['No se pudo registrar el abono.'] as $msg) {
                $this->Flash->error($msg);
            }
            $payment = $this->AccountPayments->patchEntity($payment, $data);
        }

        $receivablesList = $this->fetchTable('Receivables')->find()
            ->where(['Receivables.status' => ReceivableConstants::STATUS_PENDIENTE])
            ->contain(['Customers'])
            ->orderBy(['Receivables.created' => 'DESC'])
            ->all()
            ->toArray();

        $this->set(compact('payment', 'receivablesList', 'hint'));
        $this->set('paymentMethods', AccountPaymentConstants::PAYMENT_LABELS);
        $this->set('breadcrumbs', [
            ['label' => 'Abonos', 'url' => ['action' => 'index']],
            ['label' => 'Nuevo abono'],
        ]);

        return null;
    }

    /**
     * Deletes an abono and recomputes the parent CxC.
     *
     * @return \Cake\Http\Response|null
     */
    public function delete(int $id)
    {
        $this->request->allowMethod(['post', 'delete']);

        try {
            /** @var \App\Model\Entity\AccountPayment $payment */
            $payment = $this->AccountPayments->get($id);
        } catch (RecordNotFoundException) {
            $this->Flash->error('El abono ya no existe.');

            return $this->redirect(['action' => 'index']);
        }

        $receivableId = (int)$payment->receivable_id;
        $identity = $this->Authentication->getIdentity();
        $userId = $identity !== null ? (int)$identity->get('id') : 0;
        $result = $this->service->delete($payment, $userId);

        if (!empty($result['success'])) {
            $this->Flash->success('Abono eliminado y saldo recalculado.');
        } else {
            $this->Flash->error($result['errors'][0] ?? 'No se pudo eliminar el abono.');
        }

        $referer = $this->request->referer(true);
        if ($referer !== '/' && $referer !== null && $referer !== '') {
            return $this->redirect($referer);
        }

        if ($receivableId > 0) {
            return $this->redirect([
                'controller' => 'Receivables', 'action' => 'view', $receivableId,
            ]);
        }

        return $this->redirect(['action' => 'index']);
    }

    // -------------------------------------------------------------------
    // Helpers.
    // -------------------------------------------------------------------

    /**
     * @return array{from: string, to: string, q: string, payment_method: string, customer_id: int}
     */
    protected function _currentFilters(): array
    {
        $rawFrom = trim((string)$this->request->getQuery('from', ''));
        $rawTo = trim((string)$this->request->getQuery('to', ''));
        if ($rawFrom !== '' && $rawTo !== '' && strcmp($rawTo, $rawFrom) < 0) {
            $this->Flash->warning('El rango de fechas estaba invertido; se reordenó automáticamente.');
            [$rawFrom, $rawTo] = [$rawTo, $rawFrom];
        }

        $method = (string)$this->request->getQuery('payment_method', '');
        $allowed = array_merge([''], AccountPaymentConstants::PAYMENT_METHODS);
        if (!in_array($method, $allowed, true)) {
            $method = '';
        }

        return [
            'from' => $rawFrom,
            'to' => $rawTo,
            'q' => trim((string)$this->request->getQuery('q', '')),
            'payment_method' => $method,
            'customer_id' => (int)$this->request->getQuery('customer_id', 0),
        ];
    }

    /**
     * @param array{from: string, to: string, q: string, payment_method: string, customer_id: int} $filters
     */
    protected function _buildIndexQuery(array $filters): SelectQuery
    {
        $query = $this->AccountPayments->find()
            ->contain(['Receivables' => ['Customers'], 'Creator']);

        if ($filters['from'] !== '') {
            $query->where(['AccountPayments.created >=' => $filters['from'] . ' 00:00:00']);
        }
        if ($filters['to'] !== '') {
            $query->where(['AccountPayments.created <=' => $filters['to'] . ' 23:59:59']);
        }
        if ($filters['payment_method'] !== '') {
            $query->where(['AccountPayments.payment_method' => $filters['payment_method']]);
        }
        if ($filters['customer_id'] > 0) {
            $query->where(['Receivables.customer_id' => $filters['customer_id']]);
        }
        if ($filters['q'] !== '') {
            $like = '%' . $filters['q'] . '%';
            $query->where([
                'OR' => [
                    'Customers.name LIKE' => $like,
                    'Customers.phone LIKE' => $like,
                    'Receivables.description LIKE' => $like,
                ],
            ]);
        }

        return $query;
    }

    /**
     * KPI strip: today amount, current-month amount, today transaction count.
     *
     * @return array{today_amount: float, month_amount: float, today_count: int}
     */
    protected function _loadKpis(): array
    {
        $table = $this->AccountPayments;
        $now = new DateTime();
        $todayStart = $now->format('Y-m-d') . ' 00:00:00';
        $todayEnd = $now->format('Y-m-d') . ' 23:59:59';
        $monthStart = $now->format('Y-m-01') . ' 00:00:00';

        $todayRow = $table->find()
            ->select(['s' => $table->find()->func()->sum('AccountPayments.amount')])
            ->where([
                'AccountPayments.created >=' => $todayStart,
                'AccountPayments.created <=' => $todayEnd,
            ])
            ->first();
        $todayAmount = (float)($todayRow?->s ?? 0);

        $monthRow = $table->find()
            ->select(['s' => $table->find()->func()->sum('AccountPayments.amount')])
            ->where(['AccountPayments.created >=' => $monthStart])
            ->first();
        $monthAmount = (float)($monthRow?->s ?? 0);

        $todayCount = $table->find()
            ->where([
                'AccountPayments.created >=' => $todayStart,
                'AccountPayments.created <=' => $todayEnd,
            ])
            ->count();

        return [
            'today_amount' => $todayAmount,
            'month_amount' => $monthAmount,
            'today_count' => $todayCount,
        ];
    }
}
