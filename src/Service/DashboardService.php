<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\IngredientConstants;
use App\Constants\OrderConstants;
use Cake\I18n\Date;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Aggregates business metrics for the operator dashboard.
 *
 * Every metric excludes cancelled orders (spec §19.5).
 * Credit sales are NOT income — income = non-credit sales + abonos (spec §15).
 */
final class DashboardService
{
    use LocatorAwareTrait;

    /**
     * Returns the full breakdown for the general (non-repartidor) dashboard.
     *
     * @return array<string, mixed>
     */
    public function buildGeneral(string $from, string $to): array
    {
        $fromDt = $from . ' 00:00:00';
        $toDt = $to . ' 23:59:59';

        return [
            'today' => $this->buildTodayKpis(),
            'period' => $this->buildPeriodKpis($fromDt, $toDt),
            'by_method' => $this->incomeByMethod($fromDt, $toDt),
            'sales_by_day' => $this->salesByDay($fromDt, $toDt),
            'top_products' => $this->topProducts($fromDt, $toDt, 5),
            'delivery_ranking' => $this->deliveryRanking($fromDt, $toDt),
            'local_vs_domicilio' => $this->localVsDomicilio($fromDt, $toDt),
            'low_stock' => $this->lowStock(),
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * Personal view for users linked to a delivery (repartidor).
     *
     * @return array<string, mixed>
     */
    public function buildForRepartidor(int $deliveryId, string $from, string $to): array
    {
        $orders = $this->fetchTable('Orders');
        $fromDt = $from . ' 00:00:00';
        $toDt = $to . ' 23:59:59';
        $today = (new Date())->format('Y-m-d');

        $delivered = $orders->find()
            ->where([
                'Orders.delivery_id' => $deliveryId,
                'Orders.status' => OrderConstants::STATUS_DELIVERED,
                'Orders.created >=' => $fromDt,
                'Orders.created <=' => $toDt,
            ]);

        $deliveredCount = $delivered->count();
        $earningsRow = $orders->find()
            ->select(['s' => $orders->find()->func()->sum('Orders.shipping_cost')])
            ->where([
                'Orders.delivery_id' => $deliveryId,
                'Orders.status' => OrderConstants::STATUS_DELIVERED,
                'Orders.created >=' => $fromDt,
                'Orders.created <=' => $toDt,
            ])
            ->first();
        $earnings = (float)($earningsRow?->s ?? 0);

        $pendingToday = $orders->find()
            ->where([
                'Orders.delivery_id' => $deliveryId,
                'Orders.status NOT IN' => [
                    OrderConstants::STATUS_DELIVERED,
                    OrderConstants::STATUS_CANCELLED,
                ],
                'Orders.created >=' => $today . ' 00:00:00',
                'Orders.created <=' => $today . ' 23:59:59',
            ])
            ->count();

        return [
            'delivered' => $deliveredCount,
            'earnings' => $earnings,
            'pending_today' => $pendingToday,
            'from' => $from,
            'to' => $to,
        ];
    }

    // -------------------------------------------------------------------
    // General dashboard sections
    // -------------------------------------------------------------------

    /**
     * @return array{orders: int, revenue: float, in_prep: int, on_route: int}
     */
    private function buildTodayKpis(): array
    {
        $orders = $this->fetchTable('Orders');
        $today = (new Date())->format('Y-m-d');
        $start = $today . ' 00:00:00';
        $end = $today . ' 23:59:59';

        $ordersCount = $orders->find()
            ->where([
                'Orders.status !=' => OrderConstants::STATUS_CANCELLED,
                'Orders.created >=' => $start,
                'Orders.created <=' => $end,
            ])
            ->count();

        $revenueRow = $orders->find()
            ->select(['s' => $orders->find()->func()->sum('Orders.total')])
            ->where([
                'Orders.payment_method !=' => OrderConstants::PAYMENT_CREDIT,
                'Orders.status !=' => OrderConstants::STATUS_CANCELLED,
                'Orders.created >=' => $start,
                'Orders.created <=' => $end,
            ])
            ->first();
        $revenue = (float)($revenueRow?->s ?? 0);

        $inPrep = $orders->find()
            ->where(['Orders.status' => OrderConstants::STATUS_PREPARING])
            ->count();
        $onRoute = $orders->find()
            ->where(['Orders.status' => OrderConstants::STATUS_ON_ROUTE])
            ->count();

        return [
            'orders' => $ordersCount,
            'revenue' => $revenue,
            'in_prep' => $inPrep,
            'on_route' => $onRoute,
        ];
    }

    /**
     * @return array{income: float, cogs: float, shipping: float, expenses: float, profit: float, order_count: int}
     */
    private function buildPeriodKpis(string $fromDt, string $toDt): array
    {
        $orders = $this->fetchTable('Orders');
        $payments = $this->fetchTable('AccountPayments');
        $expenses = $this->fetchTable('Expenses');
        $items = $this->fetchTable('OrderItems');

        // Income = non-credit sales (period) + abonos (period).
        $salesRow = $orders->find()
            ->select(['s' => $orders->find()->func()->sum('Orders.total')])
            ->where([
                'Orders.payment_method !=' => OrderConstants::PAYMENT_CREDIT,
                'Orders.status !=' => OrderConstants::STATUS_CANCELLED,
                'Orders.created >=' => $fromDt,
                'Orders.created <=' => $toDt,
            ])
            ->first();
        $sales = (float)($salesRow?->s ?? 0);

        $paymentsRow = $payments->find()
            ->select(['s' => $payments->find()->func()->sum('AccountPayments.amount')])
            ->where([
                'AccountPayments.created >=' => $fromDt,
                'AccountPayments.created <=' => $toDt,
            ])
            ->first();
        $abonos = (float)($paymentsRow?->s ?? 0);

        $income = round($sales + $abonos, 2);

        // Shipping total (period, non-cancelled).
        $shippingRow = $orders->find()
            ->select(['s' => $orders->find()->func()->sum('Orders.shipping_cost')])
            ->where([
                'Orders.status !=' => OrderConstants::STATUS_CANCELLED,
                'Orders.created >=' => $fromDt,
                'Orders.created <=' => $toDt,
            ])
            ->first();
        $shipping = (float)($shippingRow?->s ?? 0);

        // Expenses (period, by expense_date).
        $expensesRow = $expenses->find()
            ->select(['s' => $expenses->find()->func()->sum('Expenses.amount')])
            ->where([
                'Expenses.expense_date >=' => substr($fromDt, 0, 10),
                'Expenses.expense_date <=' => substr($toDt, 0, 10),
            ])
            ->first();
        $expensesTotal = (float)($expensesRow?->s ?? 0);

        // COGS = sum across sold items of (recipe ingredient cost × qty × line.quantity).
        // For simplicity & performance, compute via subquery joining order_items → product_ingredients → ingredients.
        $connection = $orders->getConnection();
        $cogsResult = $connection->execute(
            'SELECT COALESCE(SUM(oi.quantity * pi.quantity * ing.unit_cost), 0) AS s
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             JOIN product_ingredients pi ON pi.product_id = oi.product_id
             JOIN ingredients ing ON ing.id = pi.ingredient_id
             WHERE o.status != :st AND o.created BETWEEN :f AND :t',
            ['st' => OrderConstants::STATUS_CANCELLED, 'f' => $fromDt, 't' => $toDt],
        )->fetch('assoc');
        $cogs = (float)($cogsResult['s'] ?? 0);

        $profit = round($income - $cogs - $shipping - $expensesTotal, 2);

        $orderCount = $orders->find()
            ->where([
                'Orders.status !=' => OrderConstants::STATUS_CANCELLED,
                'Orders.created >=' => $fromDt,
                'Orders.created <=' => $toDt,
            ])
            ->count();
        unset($items);

        return [
            'income' => $income,
            'cogs' => round($cogs, 2),
            'shipping' => $shipping,
            'expenses' => $expensesTotal,
            'profit' => $profit,
            'order_count' => $orderCount,
        ];
    }

    /**
     * @return array<string, float>
     */
    private function incomeByMethod(string $fromDt, string $toDt): array
    {
        $orders = $this->fetchTable('Orders');
        $payments = $this->fetchTable('AccountPayments');

        $byMethod = [];
        foreach (OrderConstants::PAYMENT_METHODS as $m) {
            $byMethod[$m] = 0.0;
        }

        $rows = $orders->find()
            ->select([
                'method' => 'Orders.payment_method',
                'total' => $orders->find()->func()->sum('Orders.total'),
            ])
            ->where([
                'Orders.payment_method !=' => OrderConstants::PAYMENT_CREDIT,
                'Orders.status !=' => OrderConstants::STATUS_CANCELLED,
                'Orders.created >=' => $fromDt,
                'Orders.created <=' => $toDt,
            ])
            ->groupBy(['Orders.payment_method'])
            ->all();
        foreach ($rows as $r) {
            $byMethod[(string)$r->method] = (float)$r->total;
        }

        // Abonos add their own per-method totals.
        $payRows = $payments->find()
            ->select([
                'method' => 'AccountPayments.payment_method',
                'total' => $payments->find()->func()->sum('AccountPayments.amount'),
            ])
            ->where([
                'AccountPayments.created >=' => $fromDt,
                'AccountPayments.created <=' => $toDt,
            ])
            ->groupBy(['AccountPayments.payment_method'])
            ->all();
        foreach ($payRows as $r) {
            $key = (string)$r->method;
            if (!array_key_exists($key, $byMethod)) {
                $byMethod[$key] = 0.0;
            }
            $byMethod[$key] += (float)$r->total;
        }

        unset($byMethod[OrderConstants::PAYMENT_CREDIT]);

        return $byMethod;
    }

    /**
     * @return list<array{day: string, total: float}>
     */
    private function salesByDay(string $fromDt, string $toDt): array
    {
        $orders = $this->fetchTable('Orders');
        $rows = $orders->find()
            ->select([
                'day' => $orders->find()->func()->date_format(['Orders.created' => 'identifier', "'%Y-%m-%d'" => 'literal']),
                'total' => $orders->find()->func()->sum('Orders.total'),
            ])
            ->where([
                'Orders.payment_method !=' => OrderConstants::PAYMENT_CREDIT,
                'Orders.status !=' => OrderConstants::STATUS_CANCELLED,
                'Orders.created >=' => $fromDt,
                'Orders.created <=' => $toDt,
            ])
            ->groupBy(['day'])
            ->orderBy(['day' => 'ASC'])
            ->all();

        $out = [];
        foreach ($rows as $r) {
            $out[] = ['day' => (string)$r->day, 'total' => (float)$r->total];
        }

        return $out;
    }

    /**
     * @return list<array{name: string, qty: float, revenue: float}>
     */
    private function topProducts(string $fromDt, string $toDt, int $limit): array
    {
        $items = $this->fetchTable('OrderItems');
        $rows = $items->find()
            ->select([
                'name' => 'OrderItems.product_name_snapshot',
                'qty' => $items->find()->func()->sum('OrderItems.quantity'),
                'revenue' => $items->find()->func()->sum('OrderItems.line_subtotal'),
            ])
            ->innerJoinWith('Orders')
            ->where([
                'Orders.status !=' => OrderConstants::STATUS_CANCELLED,
                'Orders.created >=' => $fromDt,
                'Orders.created <=' => $toDt,
            ])
            ->groupBy(['OrderItems.product_name_snapshot'])
            ->orderBy(['qty' => 'DESC'])
            ->limit($limit)
            ->all();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'name' => (string)$r->name,
                'qty' => (float)$r->qty,
                'revenue' => (float)$r->revenue,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{name: string, deliveries: int, earnings: float}>
     */
    private function deliveryRanking(string $fromDt, string $toDt): array
    {
        $orders = $this->fetchTable('Orders');
        $rows = $orders->find()
            ->select([
                'name' => 'Deliveries.name',
                'deliveries' => $orders->find()->func()->count('Orders.id'),
                'earnings' => $orders->find()->func()->sum('Orders.shipping_cost'),
            ])
            ->contain(['Deliveries'])
            ->innerJoinWith('Deliveries')
            ->where([
                'Orders.status' => OrderConstants::STATUS_DELIVERED,
                'Orders.type' => OrderConstants::TYPE_DOMICILIO,
                'Orders.created >=' => $fromDt,
                'Orders.created <=' => $toDt,
            ])
            ->groupBy(['Deliveries.id', 'Deliveries.name'])
            ->orderBy(['deliveries' => 'DESC'])
            ->limit(10)
            ->all();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'name' => (string)$r->name,
                'deliveries' => (int)$r->deliveries,
                'earnings' => (float)$r->earnings,
            ];
        }

        return $out;
    }

    /**
     * @return array{local: int, domicilio: int, local_revenue: float, domicilio_revenue: float}
     */
    private function localVsDomicilio(string $fromDt, string $toDt): array
    {
        $orders = $this->fetchTable('Orders');
        $rows = $orders->find()
            ->select([
                'type' => 'Orders.type',
                'cnt' => $orders->find()->func()->count('Orders.id'),
                'total' => $orders->find()->func()->sum('Orders.total'),
            ])
            ->where([
                'Orders.payment_method !=' => OrderConstants::PAYMENT_CREDIT,
                'Orders.status !=' => OrderConstants::STATUS_CANCELLED,
                'Orders.created >=' => $fromDt,
                'Orders.created <=' => $toDt,
            ])
            ->groupBy(['Orders.type'])
            ->all();

        $out = [
            'local' => 0, 'domicilio' => 0,
            'local_revenue' => 0.0, 'domicilio_revenue' => 0.0,
        ];
        foreach ($rows as $r) {
            $type = (string)$r->type;
            if ($type === OrderConstants::TYPE_LOCAL) {
                $out['local'] = (int)$r->cnt;
                $out['local_revenue'] = (float)$r->total;
            } elseif ($type === OrderConstants::TYPE_DOMICILIO) {
                $out['domicilio'] = (int)$r->cnt;
                $out['domicilio_revenue'] = (float)$r->total;
            }
        }

        return $out;
    }

    /**
     * @return list<array{id:int, name:string, stock:float, unit:string}>
     */
    private function lowStock(): array
    {
        $ingredients = $this->fetchTable('Ingredients');
        $rows = $ingredients->find()
            ->where([
                'Ingredients.stock_quantity <=' => IngredientConstants::LOW_STOCK_THRESHOLD,
            ])
            ->orderBy(['Ingredients.stock_quantity' => 'ASC'])
            ->limit(10)
            ->all();
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int)$r->id,
                'name' => (string)$r->name,
                'stock' => (float)$r->stock_quantity,
                'unit' => (string)$r->unit,
            ];
        }

        return $out;
    }
}
