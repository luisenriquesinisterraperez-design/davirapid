<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\DashboardService;
use Cake\I18n\Date;

/**
 * Dashboard — real-time business metrics view.
 *
 * Users linked to a delivery (repartidor) automatically get the
 * personal view regardless of RBAC, per spec §19.1.
 */
class DashboardController extends AppController
{
    private DashboardService $service;

    public function initialize(): void
    {
        parent::initialize();
        $this->service = new DashboardService();
    }

    public function index(): void
    {
        $filters = $this->_currentFilters();

        $identity = $this->Authentication->getIdentity();
        $userArray = $identity !== null ? $this->_identityToArray($identity) : [];
        $deliveryId = (int)($userArray['delivery_id'] ?? 0);

        if ($deliveryId > 0) {
            $data = $this->service->buildForRepartidor($deliveryId, $filters['from'], $filters['to']);
            $this->set('isRepartidorView', true);
            $this->set('data', $data);
        } else {
            $data = $this->service->buildGeneral($filters['from'], $filters['to']);
            $this->set('isRepartidorView', false);
            $this->set('data', $data);
        }

        $this->set('filters', $filters);
        $this->set('breadcrumbs', [['label' => 'Dashboard']]);
    }

    /**
     * @return array{from: string, to: string}
     */
    protected function _currentFilters(): array
    {
        $today = (new Date())->format('Y-m-d');
        $defaultFrom = (new Date())->modify('-30 days')->format('Y-m-d');

        $from = trim((string)$this->request->getQuery('from', $defaultFrom));
        $to = trim((string)$this->request->getQuery('to', $today));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = $defaultFrom;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = $today;
        }
        if (strcmp($to, $from) < 0) {
            [$from, $to] = [$to, $from];
        }

        return ['from' => $from, 'to' => $to];
    }
}
