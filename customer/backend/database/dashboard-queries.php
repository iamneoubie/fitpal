<?php
/**
 * FitPal Customer Dashboard Queries
 *
 * Pure data-access layer for the customer dashboard.
 *
 * ---------------------------------------------------------------------
 * REVENUE RECOGNITION
 * ---------------------------------------------------------------------
 * No gross revenue is recognised until an order reaches 'delivered'.
 * Cancelled, refunded, and failed orders contribute zero to every
 * spend aggregate below. The `transaction` table still records every
 * money movement, so an audit can reconcile a refund or a
 * failed-delivery loss.
 *
 * ---------------------------------------------------------------------
 * DESIGN NOTES
 * ---------------------------------------------------------------------
 * - `orders` no longer stores subtotal / delivery_charge /
 *   total_amount. Every total here is derived from queue_item rows
 *   (queue_quantity × COALESCE(final_price, unit_price)) plus the fee
 *   schedule in shared/backend/database/fee-queries.php — same
 *   pattern as getOrderTotals() in the shared order-transaction
 *   layer.
 *
 * - The old version fired 5 separate queries inside
 *   getDashboardStats(). This version merges them into 2 aggregates:
 *       1. order_counts  (total + active)
 *       2. spend_summary (7d, 30d, all-time-delivered + counts)
 *   Wallet balance is a 3rd query but it hits a different table with
 *   a primary-key lookup, so it cannot be folded in without a JOIN
 *   that would scan orders unnecessarily.
 *
 * - Single source of truth for "what counts as spend":
 *       order_status NOT IN ('cancelled','refunded','failed')
 *
 * @package FitPal
 * @version 3.0 — Requires the shared fee schedule from
 *                shared/backend/database/fee-queries.php. The
 *                previous revision required the customer-scoped copy
 *                at customer/backend/database/fee-queries.php, which
 *                has been removed. The two functions this file
 *                calls — calculateOrderFees() and the FITPAL_*
 *                constants — are declared in exactly the same shape
 *                at the shared path, so no call site in this file
 *                changes.
 *
 *                (2.0: consolidated aggregates; nicer chart scale
 *                support.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/backend/database/fee-queries.php';

/**
 * One round-trip for order counts and wallet balance.
 *
 * @return array{total_orders:int, active_orders:int, wallet_balance:float}
 */
function getDashboardCounts(PDO $db, int $customerId): array
{
    $stmt = $db->prepare(
        "SELECT
            COUNT(*) AS total_orders,
            SUM(CASE WHEN order_status IN ('pending','preparing','rider_pending','picking_up','delivering')
                     THEN 1 ELSE 0 END) AS active_orders
         FROM orders
         WHERE customer_id = :cid"
    );
    $stmt->execute([':cid' => $customerId]);
    $orders = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmt = $db->prepare(
        "SELECT fa.balance
           FROM customer_profile cp
           JOIN financial_account fa
             ON cp.financial_account_id = fa.financial_account_id
          WHERE cp.customer_id = :cid
          LIMIT 1"
    );
    $stmt->execute([':cid' => $customerId]);
    $balance = (float)($stmt->fetchColumn() ?: 0);

    return [
        'total_orders'   => (int)($orders['total_orders']  ?? 0),
        'active_orders'  => (int)($orders['active_orders'] ?? 0),
        'wallet_balance' => $balance,
    ];
}

/**
 * One round-trip for 7-day, 30-day, and delivered-order aggregates.
 *
 * Uses a CASE-driven SUM so a single scan of queue_item produces all
 * three windows. Delivered-order count is separate because it uses a
 * different status filter.
 *
 * @return array{
 *   weekly_spend:float, weekly_order_count:int,
 *   monthly_spend:float, monthly_order_count:int,
 *   delivered_total:float, delivered_count:int,
 *   average_order_value:float
 * }
 */
function getDashboardSpendSummary(PDO $db, int $customerId): array
{
    $stmt = $db->prepare(
        "SELECT
            COALESCE(SUM(CASE
                WHEN o.order_status NOT IN ('cancelled','refunded','failed')
                 AND o.order_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                THEN qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
                ELSE 0 END), 0) AS weekly_spend,

            COUNT(DISTINCT CASE
                WHEN o.order_status NOT IN ('cancelled','refunded','failed')
                 AND o.order_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                THEN o.order_id END) AS weekly_order_count,

            COALESCE(SUM(CASE
                WHEN o.order_status NOT IN ('cancelled','refunded','failed')
                 AND o.order_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                THEN qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
                ELSE 0 END), 0) AS monthly_spend,

            COUNT(DISTINCT CASE
                WHEN o.order_status NOT IN ('cancelled','refunded','failed')
                 AND o.order_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                THEN o.order_id END) AS monthly_order_count,

            COALESCE(SUM(CASE
                WHEN o.order_status = 'delivered'
                THEN qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
                ELSE 0 END), 0) AS delivered_total,

            COUNT(DISTINCT CASE
                WHEN o.order_status = 'delivered'
                THEN o.order_id END) AS delivered_count
         FROM orders o
         JOIN queue_item qi ON qi.order_id = o.order_id
         WHERE o.customer_id = :cid"
    );
    $stmt->execute([':cid' => $customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $deliveredCount = (int)($row['delivered_count'] ?? 0);
    $deliveredTotal = (float)($row['delivered_total'] ?? 0);

    return [
        'weekly_spend'        => (float)($row['weekly_spend']  ?? 0),
        'weekly_order_count'  => (int)  ($row['weekly_order_count'] ?? 0),
        'monthly_spend'       => (float)($row['monthly_spend'] ?? 0),
        'monthly_order_count' => (int)  ($row['monthly_order_count'] ?? 0),
        'delivered_total'     => $deliveredTotal,
        'delivered_count'     => $deliveredCount,
        'average_order_value' => $deliveredCount > 0
            ? round($deliveredTotal / $deliveredCount, 2)
            : 0.0,
    ];
}

/**
 * Seven-day spend series (oldest → newest).
 * Days with no orders are included with amount = 0.
 *
 * @return array<int, array{date:string, label:string, short:string, amount:float}>
 */
function getWeeklySpendingSeries(PDO $db, int $customerId, int $days = 7): array
{
    $stmt = $db->prepare(
        "SELECT
            DATE(o.order_date) AS day,
            COALESCE(SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)), 0) AS amount
         FROM orders o
         JOIN queue_item qi ON qi.order_id = o.order_id
         WHERE o.customer_id = :cid
           AND o.order_status NOT IN ('cancelled','refunded','failed')
           AND o.order_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
         GROUP BY DATE(o.order_date)
         ORDER BY day ASC"
    );
    $stmt->bindValue(':cid', $customerId, PDO::PARAM_INT);
    $stmt->bindValue(':days', $days - 1, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byDay = [];
    foreach ($rows as $r) {
        $byDay[$r['day']] = (float)$r['amount'];
    }

    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $ts   = strtotime("-{$i} days");
        $date = date('Y-m-d', $ts);
        $series[] = [
            'date'   => $date,
            'label'  => date('l', $ts),
            'short'  => date('D', $ts),
            'amount' => $byDay[$date] ?? 0.0,
        ];
    }
    return $series;
}

/**
 * Recent orders with fees-derived totals attached.
 *
 * @return array<int, array<string, mixed>>
 */
function getRecentOrdersWithTotals(PDO $db, int $customerId, int $limit = 3): array
{
    $stmt = $db->prepare(
        "SELECT
            o.order_id,
            o.order_status,
            o.order_date,
            o.payment_method,
            COALESCE(SUM(qi.queue_quantity), 0) AS item_count,
            COUNT(DISTINCT qi.branch_id) AS branch_count,
            COALESCE(SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)), 0) AS subtotal
         FROM orders o
         LEFT JOIN queue_item qi ON qi.order_id = o.order_id
         WHERE o.customer_id = :cid
         GROUP BY o.order_id
         ORDER BY o.order_date DESC
         LIMIT :lim"
    );
    $stmt->bindValue(':cid', $customerId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($orders as &$order) {
        $subtotal    = (float)$order['subtotal'];
        $branchCount = max(1, (int)$order['branch_count']);
        $fees        = calculateOrderFees($branchCount, $subtotal);

        $order['subtotal']     = $subtotal;
        $order['delivery_fee'] = $fees['delivery_fee'];
        $order['service_fee']  = $fees['service_fee'];
        $order['vat']          = $fees['vat'];
        $order['total_amount'] = round(
            $subtotal + $fees['delivery_fee'] + $fees['service_fee'] + $fees['vat'],
            2
        );
    }
    unset($order);

    return $orders;
}

/**
 * Compact profile snapshot used by the sidebar card.
 *
 * @return array<string, mixed>
 */
function getDashboardProfileSnapshot(PDO $db, int $customerId): array
{
    $stmt = $db->prepare(
        "SELECT
            c.first_name,
            c.last_name,
            c.email,
            c.username,
            c.contact_number,
            cp.dietary_preferences,
            cp.allergies,
            cp.fitness_goal,
            cp.height_cm,
            cp.weight_kg
         FROM customer c
         LEFT JOIN customer_profile cp ON c.customer_id = cp.customer_id
         WHERE c.customer_id = :cid
         LIMIT 1"
    );
    $stmt->execute([':cid' => $customerId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Pick a "nice" y-axis ceiling for the weekly bar chart.
 *
 * The rule: never scale to the raw maximum — that makes a first-time
 * spender's single purchase fill the entire chart. Instead snap up to
 * a rounded step so the tallest bar always reads at ~40–80% of the
 * chart height, leaving headroom for future bars to visibly grow.
 *
 * @param float $maxAmount
 * @return array{ceiling:float, step:float, gridlines:array<int,float>}
 */
function getChartScale(float $maxAmount): array
{
    if ($maxAmount <= 0) {
        return [
            'ceiling'   => 100.0,
            'step'      => 50.0,
            'gridlines' => [0.0, 50.0, 100.0],
        ];
    }

    $magnitude  = 10 ** floor(log10($maxAmount));
    $normalized = $maxAmount / $magnitude;

    $stepMultiplier = match (true) {
        $normalized <= 1.5 => 0.25,
        $normalized <= 3.0 => 0.5,
        $normalized <= 7.0 => 1.0,
        default            => 2.0,
    };

    $step    = $magnitude * $stepMultiplier;
    $ceiling = ceil($maxAmount / $step) * $step;

    if ($ceiling < $maxAmount * 2) {
        $ceiling += $step;
    }

    $gridlines = [];
    for ($v = 0.0; $v <= $ceiling + 0.001; $v += $step) {
        $gridlines[] = round($v, 2);
    }

    return [
        'ceiling'   => round($ceiling, 2),
        'step'      => round($step, 2),
        'gridlines' => $gridlines,
    ];
}