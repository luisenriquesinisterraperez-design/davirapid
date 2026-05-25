<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\OrderConstants;
use Cake\ORM\Query\SelectQuery;

/**
 * OrderFilterService — encapsulates the WHERE-clause logic for the orders
 * listing. Decoupled from the controller so that other consumers (dashboards,
 * exports, reports) can reuse it.
 *
 * Repartidor scoping is NOT applied here — it lives in AppController as a
 * cross-cutting concern that runs BEFORE this filter.
 */
final class OrderFilterService
{
    /**
     * @param array<string, mixed> $filters
     */
    public function apply(SelectQuery $query, array $filters): SelectQuery
    {
        // Status
        $status = (string)($filters['status'] ?? 'visible');
        if ($status === 'visible') {
            $query->where(['Orders.status !=' => OrderConstants::STATUS_CANCELLED]);
        } elseif ($status === 'all') {
            // No filter.
        } elseif (in_array($status, OrderConstants::STATUSES, true)) {
            $query->where(['Orders.status' => $status]);
        }

        // Type
        $type = (string)($filters['type'] ?? 'all');
        if (in_array($type, OrderConstants::TYPES, true)) {
            $query->where(['Orders.type' => $type]);
        }

        // Payment method
        $method = (string)($filters['payment_method'] ?? 'all');
        if (in_array($method, OrderConstants::PAYMENT_METHODS, true)) {
            $query->where(['Orders.payment_method' => $method]);
        }

        // Repartidor (admin pick from dropdown).
        if (!empty($filters['delivery_id'])) {
            $query->where(['Orders.delivery_id' => (int)$filters['delivery_id']]);
        }

        // Customer (LIKE on snapshot fields).
        if (!empty($filters['customer'])) {
            $needle = '%' . $filters['customer'] . '%';
            $query->where([
                'OR' => [
                    'Orders.customer_name LIKE' => $needle,
                    'Orders.customer_phone LIKE' => $needle,
                ],
            ]);
        }

        // Date range (inclusive).
        if (!empty($filters['from'])) {
            $query->where(['Orders.created >=' => $filters['from'] . ' 00:00:00']);
        }
        if (!empty($filters['to'])) {
            $query->where(['Orders.created <=' => $filters['to'] . ' 23:59:59']);
        }

        // Generic search (q): numeric → exact id match; otherwise LIKE.
        if (!empty($filters['q'])) {
            $q = trim((string)$filters['q']);
            if (ctype_digit($q)) {
                $query->where(['Orders.id' => (int)$q]);
            } else {
                $needle = '%' . $q . '%';
                $query->where([
                    'OR' => [
                        'Orders.customer_name LIKE' => $needle,
                        'Orders.customer_phone LIKE' => $needle,
                    ],
                ]);
            }
        }

        return $query;
    }
}
