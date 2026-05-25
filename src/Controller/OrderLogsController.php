<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\OrderLogConstants;
use App\Model\Table\OrderLogsTable;
use App\Model\Table\UsersTable;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\ORM\Query\SelectQuery;

/**
 * OrderLogs (Auditoría) Controller — read-only audit trail.
 *
 * The module is admin-only; AuthorizationService::isAllowed hardcodes a
 * bypass-to-false for non-admins. Even if the placeholder permission row is
 * toggled in /roles/edit, the structural guard still blocks access.
 */
class OrderLogsController extends AppController
{
    /**
     * @var array<string, mixed>
     */
    public array $paginate = [
        'limit' => 25,
        'maxLimit' => 50,
        'order' => [
            'OrderLogs.created' => 'DESC',
            'OrderLogs.id' => 'DESC',
        ],
    ];

    private OrderLogsTable $OrderLogs;
    private UsersTable $Users;

    /**
     * Wires the OrderLogs and Users tables.
     */
    public function initialize(): void
    {
        parent::initialize();
        /** @var \App\Model\Table\OrderLogsTable $logs */
        $logs = $this->fetchTable('OrderLogs');
        $this->OrderLogs = $logs;
        /** @var \App\Model\Table\UsersTable $users */
        $users = $this->fetchTable('Users');
        $this->Users = $users;
    }

    /**
     * All actions of this controller require 'view' on the 'audit' module.
     */
    protected function _actionToPermission(string $action): string
    {
        return 'view';
    }

    /**
     * @param int|null $orderId If provided via path /audit/order/{id}, filter by that order.
     */
    public function index(?int $orderId = null): void
    {
        $filters = $this->_currentFilters();
        if ($orderId !== null && $orderId > 0) {
            $filters['order_id'] = $orderId;
        }

        $query = $this->_buildIndexQuery($filters);
        $logs = $this->paginate($query);

        $usersList = $this->Users->find('list', keyField: 'id', valueField: 'name')->toArray();
        $kinds = OrderLogConstants::KIND_LABELS;

        $this->set(compact('logs', 'filters', 'usersList', 'kinds'));
        $this->set('breadcrumbs', [['label' => 'Auditoría']]);
    }

    /**
     * @return \Cake\Http\Response|null
     */
    public function view(int $id)
    {
        try {
            /** @var \App\Model\Entity\OrderLog $log */
            $log = $this->OrderLogs->get($id, ['contain' => ['Users']]);
        } catch (RecordNotFoundException) {
            $this->Flash->error('Registro de auditoría no encontrado.');

            return $this->redirect(['action' => 'index']);
        }

        $this->set('log', $log);
        $this->set('breadcrumbs', [
            ['label' => 'Auditoría', 'url' => ['action' => 'index']],
            ['label' => '#' . $log->id],
        ]);

        return null;
    }

    // -------------------- Helpers --------------------

    /**
     * @return array{order_id: int, user_id: int, kind: string, from: string, to: string}
     */
    protected function _currentFilters(): array
    {
        $kind = (string)$this->request->getQuery('kind', 'all');
        $kindAllowed = array_merge(['all'], OrderLogConstants::KINDS);

        return [
            'order_id' => (int)$this->request->getQuery('order_id', 0),
            'user_id' => (int)$this->request->getQuery('user_id', 0),
            'kind' => in_array($kind, $kindAllowed, true) ? $kind : 'all',
            'from' => trim((string)$this->request->getQuery('from', '')),
            'to' => trim((string)$this->request->getQuery('to', '')),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    protected function _buildIndexQuery(array $filters): SelectQuery
    {
        $query = $this->OrderLogs->find()->contain(['Users']);

        if (!empty($filters['order_id'])) {
            $query->where(['OrderLogs.order_id_snapshot' => (int)$filters['order_id']]);
        }
        if (!empty($filters['user_id'])) {
            $query->where(['OrderLogs.user_id' => (int)$filters['user_id']]);
        }
        if (($filters['kind'] ?? 'all') !== 'all') {
            $query->where(['OrderLogs.kind' => $filters['kind']]);
        }
        if (!empty($filters['from'])) {
            $query->where(['OrderLogs.created >=' => $filters['from'] . ' 00:00:00']);
        }
        if (!empty($filters['to'])) {
            $query->where(['OrderLogs.created <=' => $filters['to'] . ' 23:59:59']);
        }

        return $query;
    }
}
